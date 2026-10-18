<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\TraineePersonalityDiagnosisToken;
use App\Models\Trainer;
use App\Support\PersonalityDiagnosisQuestions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AssertsPersonalityDiagnosisName;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 性格診断の案内の発行・取消・表示（POST／DELETE /trainees/{trainee}/personality-diagnosis-tokens、
 * GET …/print〔S-0312〕。2026-10 追加。性格診断 段階2）と、案内の URL で開くお客様の回答の画面（S-1415）。
 *
 * - 愛犬アンケート案内と同じ作り。違いは、前の結果（回答済みの案内）があっても発行できること
 * - 発行のあとは S-0312 を開き、完了メッセージは載せない。取消のあとは会員詳細に戻り、上端の success
 * - アクセスログに create_／delete_trainee_personality_diagnosis_token（target_type='Trainee'）が残る
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 */
class TraineePersonalityDiagnosisTokenControllerTest extends TestCase
{
    use AssertsPersonalityDiagnosisName;
    use CreatesClientCountTables;

    private Client $client;

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createAccessLogsTable();
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $this->actingAsRole('staff');
        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        $this->trainee = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'モモ']);
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => 'テスト', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    private function insertToken(?string $answeredAt = null, ?string $token = null): int
    {
        return DB::table('trainee_personality_diagnosis_tokens')->insertGetId([
            'token' => $token ?? bin2hex(random_bytes(16)), 'trainee_id' => $this->trainee->id,
            'answered_at' => $answeredAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertResult(int $tokenId): int
    {
        return TraineePersonalityDiagnosisResult::query()->create([
            'trainee_id' => $this->trainee->id, 'trainee_personality_diagnosis_token_id' => $tokenId,
            'answered_at' => '2026-10-15 09:00:00', 'question_version' => 'v1', 'answers' => ['Q01' => 0],
            'score_people' => '0.00', 'score_dogs' => '0.00', 'score_possessiveness' => '0.00', 'score_separation' => '0.00',
            'score_fear' => '0.00', 'score_trainability' => '0.00', 'score_excitability' => '0.00', 'personality_type' => 'calm',
        ])->id;
    }

    private function logs(): array
    {
        return AccessLog::query()->orderBy('id')->get()->all();
    }

    private function printPage(): string
    {
        return $this->get(route('trainee-personality-diagnosis-tokens.print', $this->trainee))->assertOk()->getContent();
    }

    private function copyText(string $html): string
    {
        $this->assertSame(1, preg_match('/data-copy-text="([^"]*)"/', $html, $m));

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    // ---- 発行 ----

    public function test_有効な案内がなければ作り会員詳細に戻って完了メッセージを出しアクセスログが1件残る(): void
    {
        $response = $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee));

        // 発行のあとは会員詳細（ページの上）に戻る。S-0312 には移らない（2026-10-17 変更）
        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', 'モモの愛犬性格診断の案内を発行しました。');

        $token = TraineePersonalityDiagnosisToken::query()->sole();
        $this->assertSame($this->trainee->id, (int) $token->trainee_id);
        $this->assertSame(200, (int) $token->created_by);
        $this->assertNull($token->answered_at);
        $this->assertSame(32, strlen($token->token));

        $logs = $this->logs();
        $this->assertCount(1, $logs);
        $this->assertSame('create_trainee_personality_diagnosis_token', $logs[0]->action);
        $this->assertSame('Trainee', $logs[0]->target_type);
        $this->assertSame($this->trainee->id, (int) $logs[0]->target_id);
        $this->assertSame('愛犬性格診断案内発行', AccessLog::actionLabels()[$logs[0]->action]);
    }

    public function test_名前がないトレーニーは完了メッセージで名前未登録と出す(): void
    {
        $this->trainee->forceFill(['name' => null])->save();

        $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee))
            ->assertRedirect(route('clients.show', $this->client))
            ->assertSessionHas('success', '名前未登録の愛犬性格診断の案内を発行しました。');
    }

    public function test_有効な案内があれば作らず完了メッセージも出さずに会員詳細に戻る(): void
    {
        $tokenId = $this->insertToken();

        $response = $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee));

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionMissing('success');
        $this->assertSame([$tokenId], TraineePersonalityDiagnosisToken::query()->pluck('id')->all());
    }

    public function test_二重に送っても有効な案内は1つのまま(): void
    {
        $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee));
        $this->flushSession();
        $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee))
            ->assertRedirect(route('clients.show', $this->client))
            ->assertSessionMissing('success');

        $this->assertSame(1, TraineePersonalityDiagnosisToken::query()->count());
    }

    public function test_回答済みの案内と結果があっても新しく発行できる(): void
    {
        $answered = $this->insertToken('2026-10-15 09:00:00');
        $this->insertResult($answered);

        $response = $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee));

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', 'モモの愛犬性格診断の案内を発行しました。');
        $this->assertSame(2, TraineePersonalityDiagnosisToken::query()->count());
        $this->assertNotNull($this->trainee->fresh()->activePersonalityDiagnosisToken);
        $this->assertSame(1, TraineePersonalityDiagnosisResult::query()->count());
    }

    // ---- 取消 ----

    public function test_取消は有効な案内だけを消し会員詳細に戻りアクセスログが1件残る(): void
    {
        $answered = $this->insertToken('2026-10-15 09:00:00');
        $resultId = $this->insertResult($answered);
        $active = $this->insertToken();

        $response = $this->delete(route('trainee-personality-diagnosis-tokens.destroy', $this->trainee));

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', '愛犬性格診断の案内を取り消しました。');
        $this->assertSame([$answered], TraineePersonalityDiagnosisToken::query()->pluck('id')->all());
        $this->assertFalse(TraineePersonalityDiagnosisToken::query()->whereKey($active)->exists());
        // 回答済みの案内と結果は消えない
        $this->assertSame($answered, (int) TraineePersonalityDiagnosisResult::query()->findOrFail($resultId)->trainee_personality_diagnosis_token_id);

        $logs = $this->logs();
        $this->assertCount(1, $logs);
        $this->assertSame('delete_trainee_personality_diagnosis_token', $logs[0]->action);
        $this->assertSame('Trainee', $logs[0]->target_type);
        $this->assertSame($this->trainee->id, (int) $logs[0]->target_id);
        $this->assertSame('愛犬性格診断案内取消', AccessLog::actionLabels()[$logs[0]->action]);
    }

    public function test_有効な案内がなければ取消は409(): void
    {
        $this->insertToken('2026-10-15 09:00:00');

        $this->delete(route('trainee-personality-diagnosis-tokens.destroy', $this->trainee))->assertStatus(409);
        $this->assertSame(1, TraineePersonalityDiagnosisToken::query()->count());
    }

    // ---- 権限 ----

    public function test_管理者も発行と取消ができる(): void
    {
        $this->actingAsRole('admin');

        $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee))
            ->assertRedirect(route('clients.show', $this->client));
        $this->delete(route('trainee-personality-diagnosis-tokens.destroy', $this->trainee))
            ->assertRedirect(route('clients.show', $this->client));
    }

    public function test_システム管理者は発行も取消も表示も403(): void
    {
        $this->actingAsRole('system_admin');
        $this->insertToken();

        $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee))->assertForbidden();
        $this->delete(route('trainee-personality-diagnosis-tokens.destroy', $this->trainee))->assertForbidden();
        $this->get(route('trainee-personality-diagnosis-tokens.print', $this->trainee))->assertForbidden();
        $this->assertSame(1, TraineePersonalityDiagnosisToken::query()->count());
    }

    public function test_ログインしていなければ使えない(): void
    {
        auth('web')->logout();

        $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee))->assertRedirect();
        $this->assertSame(0, TraineePersonalityDiagnosisToken::query()->count());
    }

    // ---- 案内の画面（S-0312） ----

    /** QR コードの欄（data-invitation-qr の section）の HTML */
    private function qrPanel(string $html): string
    {
        $start = strpos($html, 'data-invitation-qr>');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</section>', $start) - $start);
    }

    /** 画面に出している文面（data-invitation-message の中身を戻したもの） */
    private function shownText(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="invitation-message" data-invitation-message>(.*?)<\/div>/su', $html, $m));

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    // 2026-10-18 変更：印刷して渡す作りをやめ、「LINE・メールで送る」「その場で読み取ってもらう」の 2 つの欄にした（設計書 S-0312・S-0307）

    public function test_S0312は紫の帯の見出し_2つの欄_QRコード_1回かぎり_戻る_文面をコピーを出す(): void
    {
        $this->insertToken(null, 'personality-diagnosis-token-000001');
        $url = route('client-portal.personality-diagnosis.show', ['token' => 'personality-diagnosis-token-000001']);

        $html = $this->printPage();

        $this->assertSame('/client-portal/personality-diagnosis/personality-diagnosis-token-000001', parse_url($url, PHP_URL_PATH));
        // 帯＝見出し（h1。紫。アイコンは読み上げない）。見出しは 1 つだけで、すぐ下に 2 つの欄
        $this->assertMatchesRegularExpression('/<h1 class="print-band print-band--personality"><span class="print-band-label"><i class="bi bi-heart-pulse" aria-hidden="true"><\/i>愛犬性格診断のご案内<\/span><\/h1>\s*<div class="row g-3 align-items-start">/u', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<title>愛犬性格診断のご案内', $html);
        $this->assertLessThan(strpos($html, 'QR コード</h2>'), strpos($html, 'LINE・メールで送る</h2>'));
        $this->assertStringContainsString('<canvas data-qr-url="'.$url.'" data-qr-size="280" aria-label="愛犬性格診断の案内の QR コード"></canvas>', $html);
        // QR コードの欄は見出し「QR コード」と QR コードだけ（2026-10-18 変更：その前は見出し「その場で読み取ってもらう」と、
        // 下にお客様向けの一文・有効期限〔または 1 回かぎりの文〕。QR コードはメール・LINE に添付することもあり、説明は過剰なため）
        $qr = $this->qrPanel($html);
        $this->assertStringContainsString('<h2 class="h6 mb-0" id="invitation-qr-heading">QR コード</h2>', $qr);
        foreach (['その場で読み取ってもらう', 'スマホのカメラで読み取ってください。', '有効期限', 'ご回答は1回かぎり', 'data-invitation-qr-note'] as $text) {
            $this->assertStringNotContainsString($text, $qr, $text);
        }
        $this->assertStringNotContainsString('その場で読み取ってもらう', $html);
        $this->assertStringNotContainsString('スマホのカメラで読み取ってください。', $html);
        $this->assertStringNotContainsString('有効期限', $html);
        // 印刷のための作り・読み取りの手順は出さない
        foreach (['d-print-none', 'print-actions', 'print-qr', 'print-url', 'print-steps', 'ご回答の手順', '上の QR コードを読み取ります', '上の QR コードを読み取るか'] as $text) {
            $this->assertStringNotContainsString($text, $html, $text);
        }
        // ［戻る］（会員詳細へのふつうのリンク）と［コピー］（青の塗り）
        $this->assertStringContainsString('<a href="'.route('clients.show', $this->client).'" class="btn btn-outline-secondary btn-sm">戻る</a>', $html);
        // ［コピー］は「LINE・メールで送る」の見出しの行の右（青の塗り・小さいボタン。読み上げは「文面をコピー」）。文面の下にボタンはない
        // （2026-10-18 変更：その前は文面の下の右に［文面をコピー］）。ボタンの左に知らせの場所（aria-live。押すと「コピーしました」を出す）。
        // ボタンの文字は［コピー］のまま変えず、最低の幅も付けない（2026-10-18 変更：その前はボタンの文字を変え、「コピーしました」の幅を最低の幅にしていた）。
        // ボタンは小さいボタン（btn-sm。2026-10-18 変更：その前はふつうの大きさ）
        $this->assertMatchesRegularExpression('/<div class="card-header invitation-panel-header invitation-send-header" data-copy-area>\\s*'
            .'<h2 class="h6 mb-0" id="invitation-message-heading">LINE・メールで送る<\\/h2>\\s*'
            .'<span class="invitation-copy-status small" role="status" aria-live="polite" data-copy-status><\\/span>\\s*'
            .'<button type="button" class="btn btn-primary btn-sm" aria-label="文面をコピー" data-copy-message data-copy-text="[^"]*">コピー<\\/button>\\s*<\\/div>\\s*'
            .'<div class="card-body">\\s*<div class="invitation-message" data-invitation-message>[^<]*<\\/div>\\s*<\\/div>/u', $html);
        $this->assertSame(1, substr_count($html, 'data-copy-message '));
        $this->assertSame(1, substr_count($html, 'aria-live="polite" data-copy-status'));
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*data-copy-message[^>]*style=/u', $html);
        $this->assertStringNotContainsString('min-width', $html);
        $this->assertStringNotContainsString('>文面をコピー<', $html);
        $this->assertMatchesRegularExpression('/<div class="card-header invitation-panel-header d-flex align-items-center">\\s*<h2 class="h6 mb-0" id="invitation-qr-heading">QR コード<\\/h2>/u', $html);
        $this->assertStringContainsString('copy-message', $html);
        $this->assertStringContainsString('qr-code', $html);
        $this->assertStringNotContainsString('close-tab', $html);
        $this->assertStringNotContainsString('閉じる', $html);
    }

    public function test_S0312の文面_画面に出す文とコピーする文は同じで氏名_訓練所名_呼び名_問題の数_URLが入る(): void
    {
        $this->insertToken(null, 'personality-diagnosis-token-000001');
        $url = route('client-portal.personality-diagnosis.show', ['token' => 'personality-diagnosis-token-000001']);

        config(['app.client_portal_company' => 'テスト訓練所']);
        $html = $this->printPage();
        $expected = implode("\n", [
            '山田 太郎 様',
            '',
            'テスト訓練所です。',
            'モモちゃんの愛犬性格診断のご案内をお送りします。',
            '',
            '下のリンクから、ご回答をお願いいたします。（全50問です）',
            '',
            $url,
            '',
            'ご回答は1回かぎりです。送信すると、このリンクは使えなくなります。',
            'ご不明な点がございましたら、お気軽にお問い合わせください。',
        ]);
        $this->assertSame($expected, $this->copyText($html));
        $this->assertSame($expected, $this->shownText($html));
        // 問題の数は今の設問の数から出す
        $this->assertSame(50, count(PersonalityDiagnosisQuestions::questions()));

        // 訓練所名が未設定なら、その行を出さない。名前が空なら呼び名は「愛犬」
        config(['app.client_portal_company' => null]);
        $this->trainee->update(['name' => null]);
        // 名前が空なら「{呼び名}の」を付けない（2026-10-18 変更：「愛犬の愛犬性格診断」と「愛犬」が重なっていた）
        $html = $this->printPage();
        $this->assertStringStartsWith("山田 太郎 様\n\n愛犬性格診断のご案内をお送りします。\n", $this->copyText($html));
        $this->assertStringNotContainsString('愛犬の愛犬性格診断', $this->copyText($html));
        $this->assertSame($this->copyText($html), $this->shownText($html));
    }

    public function test_有効な案内がないときは帯の下にないことを出しQRとコピーは出さない(): void
    {
        $this->insertToken('2026-10-15 09:00:00');

        $html = $this->printPage();

        $this->assertMatchesRegularExpression('/<h1 class="print-band print-band--personality">/u', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<p class="invitation-empty"><strong>有効な愛犬性格診断の案内がありません。</strong></p>', $html);
        $this->assertStringNotContainsString('data-qr-url', $html);
        $this->assertStringNotContainsString('data-invitation-message', $html);
        $this->assertStringNotContainsString('data-copy-message', $html);
        $this->assertStringNotContainsString('data-copy-status', $html);
        $this->assertStringContainsString('btn-sm">戻る</a>', $html);
    }

    public function test_表示はアクセスログに記録しない(): void
    {
        $this->insertToken();

        $this->printPage();

        $this->assertSame(0, AccessLog::query()->count());
    }

    // ---- お客様の URL（S-1415。段階2 では仮の受け口だった。段階3 で回答の画面にした） ----

    public function test_S0312のURLを開くとお客様の回答の画面が出る(): void
    {
        $this->insertToken(null, 'personality-diagnosis-token-000001');
        auth('web')->logout();

        $html = $this->get(route('client-portal.personality-diagnosis.show', ['token' => 'personality-diagnosis-token-000001']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="mb-2">愛犬性格診断</h1>', $html);
        $this->assertSame(50, substr_count($html, '<fieldset class="mb-3" id="q-Q'));
    }

    // ---- 発行の URL を GET で開いたとき（2026-10-17 追加。405 にしない） ----

    public function test_発行のURLをGETで開くと発行せず会員詳細に移る(): void
    {
        $this->get('/trainees/'.$this->trainee->id.'/personality-diagnosis-tokens')
            ->assertRedirect(route('clients.show', $this->client));
        $this->assertSame(0, TraineePersonalityDiagnosisToken::query()->count());
        $this->assertSame([], $this->logs());
    }

    public function test_発行のURLをGETで開いたとき_ないトレーニーは404_ログインしていなければログインへ_システム管理者は403(): void
    {
        $this->get('/trainees/999/personality-diagnosis-tokens')->assertNotFound();

        $this->actingAsRole('system_admin');
        $this->get('/trainees/'.$this->trainee->id.'/personality-diagnosis-tokens')->assertForbidden();

        auth('web')->logout();
        $this->get('/trainees/'.$this->trainee->id.'/personality-diagnosis-tokens')->assertRedirect(route('login'));
        $this->assertSame(0, TraineePersonalityDiagnosisToken::query()->count());
    }

    // ---- 愛犬性格診断の名前（2026-10-18 変更：正式名称に決まった。その前は「性格診断」） ----

    public function test_S0312は愛犬性格診断の名前で出し性格診断は単独で出さない(): void
    {
        $this->insertToken();

        $html = $this->printPage();

        $this->assertStringContainsString('<title>愛犬性格診断のご案内', $html);
        $this->assertMatchesRegularExpression('/<h1 class="print-band print-band--personality">.*愛犬性格診断のご案内/su', $html);
        $this->assertStringContainsString('aria-label="愛犬性格診断の案内の QR コード"', $html);
        $this->assertStringContainsString('モモちゃんの愛犬性格診断のご案内をお送りします。', $this->copyText($html));
        $this->assertOnlyFullPersonalityDiagnosisName($html);
    }

    public function test_完了メッセージと操作の記録の表示も愛犬性格診断(): void
    {
        $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee))
            ->assertSessionHas('success', 'モモの愛犬性格診断の案内を発行しました。');
        $this->delete(route('trainee-personality-diagnosis-tokens.destroy', $this->trainee))
            ->assertSessionHas('success', '愛犬性格診断の案内を取り消しました。');

        $labels = AccessLog::actionLabels();
        $this->assertSame('愛犬性格診断案内発行', $labels['create_trainee_personality_diagnosis_token']);
        $this->assertSame('愛犬性格診断案内取消', $labels['delete_trainee_personality_diagnosis_token']);
        $this->assertSame('愛犬性格診断結果削除', $labels['delete_trainee_personality_diagnosis_result']);
    }

    public function test_S0312と完了メッセージの名前は文のファイルの1か所から読む(): void
    {
        $this->overridePersonalityDiagnosisName('テスト診断');
        $this->insertToken();

        $html = $this->printPage();
        $this->assertStringContainsString('<title>テスト診断のご案内', $html);
        $this->assertStringNotContainsString('性格診断', $html);
        // 文面（画面に出す文・コピーする文）にも差し替えた名前が出る（2026-10-18 追加）
        $this->assertStringContainsString('モモちゃんのテスト診断のご案内をお送りします。', $this->copyText($html));

        $this->delete(route('trainee-personality-diagnosis-tokens.destroy', $this->trainee))
            ->assertSessionHas('success', 'テスト診断の案内を取り消しました。');
        $this->assertSame('テスト診断案内発行', AccessLog::actionLabels()['create_trainee_personality_diagnosis_token']);
    }
}

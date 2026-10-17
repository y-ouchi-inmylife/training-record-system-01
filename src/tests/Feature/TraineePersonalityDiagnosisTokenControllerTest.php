<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\TraineePersonalityDiagnosisToken;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 性格診断の案内の発行・取消・表示（POST／DELETE /trainees/{trainee}/personality-diagnosis-tokens、
 * GET …/print〔S-0312〕。2026-10 追加。性格診断 段階2）と、案内の URL で開くお客様の回答の画面（S-1415）。
 *
 * - 受付アンケート案内と同じ作り。違いは、前の結果（回答済みの案内）があっても発行できること
 * - 発行のあとは S-0312 を開き、完了メッセージは載せない。取消のあとは会員詳細に戻り、上端の success
 * - アクセスログに create_／delete_trainee_personality_diagnosis_token（target_type='Trainee'）が残る
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 */
class TraineePersonalityDiagnosisTokenControllerTest extends TestCase
{
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

    public function test_有効な案内がなければ作りS0312を開きアクセスログが1件残る(): void
    {
        $response = $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee));

        $response->assertRedirect(route('trainee-personality-diagnosis-tokens.print', $this->trainee));
        $response->assertSessionMissing('success');

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
        $this->assertSame('性格診断案内発行', AccessLog::actionLabels()[$logs[0]->action]);
    }

    public function test_有効な案内があれば作らず今ある案内のS0312を開く(): void
    {
        $tokenId = $this->insertToken();

        $response = $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee));

        $response->assertRedirect(route('trainee-personality-diagnosis-tokens.print', $this->trainee));
        $response->assertSessionMissing('success');
        $this->assertSame([$tokenId], TraineePersonalityDiagnosisToken::query()->pluck('id')->all());
    }

    public function test_二重に送っても有効な案内は1つのまま(): void
    {
        $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee));
        $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee))
            ->assertRedirect(route('trainee-personality-diagnosis-tokens.print', $this->trainee));

        $this->assertSame(1, TraineePersonalityDiagnosisToken::query()->count());
    }

    public function test_回答済みの案内と結果があっても新しく発行できる(): void
    {
        $answered = $this->insertToken('2026-10-15 09:00:00');
        $this->insertResult($answered);

        $response = $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee));

        $response->assertRedirect(route('trainee-personality-diagnosis-tokens.print', $this->trainee));
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
        $response->assertSessionHas('success', '性格診断の案内を取り消しました。');
        $this->assertSame([$answered], TraineePersonalityDiagnosisToken::query()->pluck('id')->all());
        $this->assertFalse(TraineePersonalityDiagnosisToken::query()->whereKey($active)->exists());
        // 回答済みの案内と結果は消えない
        $this->assertSame($answered, (int) TraineePersonalityDiagnosisResult::query()->findOrFail($resultId)->trainee_personality_diagnosis_token_id);

        $logs = $this->logs();
        $this->assertCount(1, $logs);
        $this->assertSame('delete_trainee_personality_diagnosis_token', $logs[0]->action);
        $this->assertSame('Trainee', $logs[0]->target_type);
        $this->assertSame($this->trainee->id, (int) $logs[0]->target_id);
        $this->assertSame('性格診断案内取消', AccessLog::actionLabels()[$logs[0]->action]);
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
            ->assertRedirect(route('trainee-personality-diagnosis-tokens.print', $this->trainee));
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

    public function test_S0312は紫の帯の見出し_QR_URL_手順_1回かぎり_戻る_文面をコピーを出す(): void
    {
        $this->insertToken(null, 'personality-diagnosis-token-000001');
        $url = route('client-portal.personality-diagnosis.show', ['token' => 'personality-diagnosis-token-000001']);

        $html = $this->printPage();

        $this->assertSame('/client-portal/personality-diagnosis/personality-diagnosis-token-000001', parse_url($url, PHP_URL_PATH));
        // 帯＝見出し（h1。紫。アイコンは読み上げない）。見出しは 1 つだけで、すぐ下に QR コード
        $this->assertMatchesRegularExpression('/<h1 class="print-band print-band--personality"><span class="print-band-label"><i class="bi bi-heart-pulse" aria-hidden="true"><\/i>性格診断のご案内<\/span><\/h1>\s*<div class="print-qr">/u', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<title>性格診断のご案内', $html);
        $this->assertStringContainsString('<canvas data-qr-url="'.$url.'" data-qr-size="240"', $html);
        $this->assertStringContainsString('aria-label="性格診断の案内の QR コード"', $html);
        $this->assertStringContainsString('上の QR コードを読み取るか、下の URL をブラウザに入力してください。', $html);
        $this->assertStringContainsString('<div class="print-url">'.$url.'</div>', $html);
        $this->assertStringContainsString('<p class="print-section-title">ご回答の手順</p>', $html);
        $this->assertStringContainsString('<li>上の QR コードを読み取ります</li>', $html);
        $this->assertStringContainsString('<li>開いたページで、50の質問にお答えください</li>', $html);
        $this->assertStringContainsString('<li>「送信する」を押すと完了です</li>', $html);
        $this->assertStringContainsString('ご回答は1回かぎりです。送信すると、この案内は使えなくなります。', $html);
        $this->assertStringNotContainsString('有効期限', $html);
        // 愛犬の名前・お客様の氏名は出さない
        $this->assertStringNotContainsString('モモ', $html);
        $this->assertStringNotContainsString('山田', $html);
        // ［戻る］（会員詳細へのふつうのリンク。グレーの白抜き。2026-10-17 変更：その前は［閉じる］）と［文面をコピー］。操作エリアは印刷では出さない
        $this->assertStringContainsString('<div class="print-actions d-print-none d-flex flex-wrap gap-2 mb-3">', $html);
        $this->assertStringContainsString('<a href="'.route('clients.show', $this->client).'" class="btn btn-outline-secondary btn-sm">戻る</a>', $html);
        $this->assertMatchesRegularExpression('/<button type="button"\s+class="btn btn-outline-primary btn-sm"\s+data-copy-message\s+data-copy-text="[^"]*">文面をコピー<\/button>/u', $html);
        $this->assertStringContainsString('copy-message', $html);
        $this->assertStringContainsString('qr-code', $html);
        $this->assertStringNotContainsString('close-tab', $html);
        $this->assertStringNotContainsString('閉じる', $html);
    }

    public function test_S0312のコピーの文面_事業者名があるときとないとき(): void
    {
        $this->insertToken(null, 'personality-diagnosis-token-000001');
        $url = route('client-portal.personality-diagnosis.show', ['token' => 'personality-diagnosis-token-000001']);

        config(['app.client_portal_company' => 'テスト訓練所']);
        $this->assertSame(implode("\n", [
            'トレーニングの参考にするため、性格診断へのご回答をお願いします。',
            '下のリンクから、50の質問にお答えください。',
            '',
            $url,
            '',
            'ご回答は1回かぎりです。送信すると、このリンクは使えなくなります。',
            '',
            'テスト訓練所',
        ]), $this->copyText($this->printPage()));

        config(['app.client_portal_company' => null]);
        $this->assertStringEndsWith("\n\nご回答は1回かぎりです。送信すると、このリンクは使えなくなります。", $this->copyText($this->printPage()));
    }

    public function test_有効な案内がないときは帯の下にないことを出しQRとコピーは出さない(): void
    {
        $this->insertToken('2026-10-15 09:00:00');

        $html = $this->printPage();

        $this->assertMatchesRegularExpression('/<h1 class="print-band print-band--personality">/u', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('<p class="print-lead"><strong>有効な性格診断の案内がありません。</strong></p>', $html);
        $this->assertStringNotContainsString('data-qr-url', $html);
        $this->assertStringNotContainsString('<div class="print-url">', $html);
        $this->assertStringNotContainsString('文面をコピー', $html);
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

        $this->assertStringContainsString('<h1 class="mb-2">性格診断</h1>', $html);
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
}

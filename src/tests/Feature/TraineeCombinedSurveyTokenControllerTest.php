<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Trainee;
use App\Models\Trainer;
use App\Support\CombinedSurveyName;
use App\Support\PersonalityDiagnosisQuestions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 愛犬アンケート＆愛犬性格診断の案内の発行・取消・ご案内の画面（POST／DELETE /trainees/{trainee}/combined-survey-tokens、
 * GET …/print〔S-0314〕。2026-10-19 追加。要件定義書 6-16-8・6-16-11）。
 *
 * - 発行で 2 つの案内（愛犬アンケート・愛犬性格診断）を作って結び付け、期限は 2 つとも同じ
 * - 「1 頭に 1 つまで」：組み合わせは 1 つの案内として数える。回答待ちがあれば発行しない。組み合わせの回答待ちがあれば単独も発行しない
 * - 取消は、まだ答えていない分をまとめて消す
 * - ご案内の画面の帯・文面・QR コードの中身（愛犬アンケートの案内のリンク）
 * - アクセスログは操作ごとに 1 件（create_／delete_trainee_combined_survey_token）
 *
 * 「今日」は 2026-10-19 に固定する（期限は 7 日後の日の終わり＝2026-10-26 23:59:59）。
 */
class TraineeCombinedSurveyTokenControllerTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-19 10:00:00');
        config(['app.client_portal_company' => 'テスト訓練所']);

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
        $this->createTraineeIntakeSurveyTokensTable();

        $trainer = new Trainer(['name' => 'テスト', 'role' => 'staff']);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        $this->trainee = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'モモ']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function issue()
    {
        return $this->post(route('trainee-combined-survey-tokens.store', $this->trainee));
    }

    private function intakeRows()
    {
        return DB::table('trainee_intake_survey_tokens')->orderBy('id')->get();
    }

    private function personalityRows()
    {
        return DB::table('trainee_personality_diagnosis_tokens')->orderBy('id')->get();
    }

    private function printPage(): string
    {
        return $this->get(route('trainee-combined-survey-tokens.print', $this->trainee))->assertOk()->getContent();
    }

    private function copyText(string $html): string
    {
        $this->assertSame(1, preg_match('/data-copy-text="([^"]*)"/', $html, $m));

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    private function shownText(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="invitation-message" data-invitation-message>(.*?)<\/div>/su', $html, $m));

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    // ---- 発行 ----

    public function test_組み合わせで発行すると2つの案内ができて結び付き期限が同じで会員詳細に戻る(): void
    {
        $this->issue()
            ->assertRedirect(route('clients.show', $this->client))
            ->assertSessionHas('success', 'モモの愛犬アンケート＆愛犬性格診断の案内を発行しました。');

        $intake = $this->intakeRows();
        $personality = $this->personalityRows();
        $this->assertCount(1, $intake);
        $this->assertCount(1, $personality);
        $this->assertSame($personality[0]->id, (int) $intake[0]->trainee_personality_diagnosis_token_id);
        $this->assertSame('2026-10-26 23:59:59', $intake[0]->expires_at);
        $this->assertSame($intake[0]->expires_at, $personality[0]->expires_at);
        $this->assertSame(200, (int) $intake[0]->created_by);
        $this->assertSame(200, (int) $personality[0]->created_by);
        $this->assertNotSame($intake[0]->token, $personality[0]->token);
        // 愛犬性格診断の案内は、愛犬アンケートの案内より前の日時にならない（期限切れの行の「いちばん新しい案内」のため）
        $this->assertGreaterThanOrEqual($intake[0]->created_at, $personality[0]->created_at);

        // 操作の記録は 1 件（組み合わせの発行。単独の 2 つの種類としては記録しない）
        $logs = AccessLog::query()->get();
        $this->assertCount(1, $logs);
        $this->assertSame('create_trainee_combined_survey_token', $logs[0]->action);
        $this->assertSame('Trainee', $logs[0]->target_type);
        $this->assertSame($this->trainee->id, (int) $logs[0]->target_id);
    }

    public function test_回答待ちがあれば組み合わせを発行しない(): void
    {
        foreach (['trainee_intake_survey_tokens', 'trainee_personality_diagnosis_tokens'] as $table) {
            DB::table('trainee_intake_survey_tokens')->delete();
            DB::table('trainee_personality_diagnosis_tokens')->delete();
            DB::table($table)->insert(['token' => 'active-'.$table, 'trainee_id' => $this->trainee->id, 'created_at' => now(), 'updated_at' => now()]);

            $this->issue()
                ->assertRedirect(route('clients.show', $this->client))
                ->assertSessionHas('error', 'モモには、すでに案内中の案内があります。');

            $this->assertSame(1, DB::table('trainee_intake_survey_tokens')->count() + DB::table('trainee_personality_diagnosis_tokens')->count(), $table);
        }
    }

    public function test_組み合わせの回答待ちがあれば単独の案内も組み合わせも発行しない(): void
    {
        $this->issue();

        foreach (['trainee-intake-survey-tokens.store', 'trainee-combined-survey-tokens.store'] as $route) {
            $this->post(route($route, $this->trainee))
                ->assertSessionHas('error', 'モモには、すでに案内中の案内があります。');
        }
        // 単独の愛犬性格診断は、回答待ちの有無にかかわらず新しく発行しない（2026-10-19 変更）
        $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee))
            ->assertSessionHas('error', '愛犬性格診断だけの案内は、新しく発行できません。「愛犬アンケート＆愛犬性格診断」の案内を発行してください。');
        $this->assertCount(1, $this->intakeRows());
        $this->assertCount(1, $this->personalityRows());
    }

    public function test_愛犬アンケートだけ答えたあとの残りの愛犬性格診断は表示と取消ができ単独では発行し直せない(): void
    {
        // 2026-10-19 変更：単独の愛犬性格診断は新しく発行しないが、組み合わせの残りは今までどおり使える
        $this->issue()->assertSessionHas('success');
        DB::table('trainee_intake_survey_tokens')->update(['answered_at' => '2026-10-19 11:00:00']);
        $remaining = $this->personalityRows()->sole();

        // 残りは有効な単独の愛犬性格診断の案内として扱われ、ご案内の画面（S-0312）が出る
        $this->assertSame($remaining->id, $this->trainee->fresh()->activePersonalityDiagnosisToken?->id);
        $html = $this->get(route('trainee-personality-diagnosis-tokens.print', $this->trainee))->assertOk()->getContent();
        $this->assertStringContainsString(route('client-portal.personality-diagnosis.show', ['token' => $remaining->token]), $html);

        // 単独の発行は受け付けない（残りの案内はそのまま）
        $this->post(route('trainee-personality-diagnosis-tokens.store', $this->trainee))
            ->assertSessionHas('error', '愛犬性格診断だけの案内は、新しく発行できません。「愛犬アンケート＆愛犬性格診断」の案内を発行してください。');
        $this->assertSame([$remaining->id], $this->personalityRows()->pluck('id')->all());

        // 取消は今までどおり（単独の愛犬性格診断の取消）
        $this->delete(route('trainee-personality-diagnosis-tokens.destroy', $this->trainee))
            ->assertRedirect(route('clients.show', $this->client))
            ->assertSessionHas('success', '愛犬性格診断の案内を取り消しました。');
        $this->assertCount(0, $this->personalityRows());
        $this->assertCount(1, $this->intakeRows());
    }

    public function test_期限が切れた組み合わせの案内があっても発行し直せる(): void
    {
        $this->issue();
        Carbon::setTestNow('2026-10-27 00:00:00');

        $this->issue()->assertSessionHas('success');

        $this->assertCount(2, $this->intakeRows());
        $this->assertCount(2, $this->personalityRows());
    }

    public function test_発行のURLをGETで開くと会員詳細に移る(): void
    {
        $this->get(route('trainee-combined-survey-tokens.store', $this->trainee))->assertRedirect(route('clients.show', $this->client));
        $this->assertCount(0, $this->intakeRows());
    }

    // ---- 取消 ----

    public function test_取消はまだ答えていない分をまとめて消し会員詳細に戻る(): void
    {
        $this->issue();

        $this->delete(route('trainee-combined-survey-tokens.destroy', $this->trainee))
            ->assertRedirect(route('clients.show', $this->client))
            ->assertSessionHas('success', '愛犬アンケート＆愛犬性格診断の案内を取り消しました。');

        $this->assertCount(0, $this->intakeRows());
        $this->assertCount(0, $this->personalityRows());
        $this->assertSame('delete_trainee_combined_survey_token', AccessLog::query()->orderByDesc('id')->value('action'));
    }

    public function test_取消は回答済みと期限切れの案内には触らず取り消すものがなければ409(): void
    {
        // 愛犬アンケートだけ答えたあと：組み合わせの取消では消さない（残りの愛犬性格診断は単独の取消で消す）
        $this->issue();
        DB::table('trainee_intake_survey_tokens')->update(['answered_at' => now()]);
        $this->delete(route('trainee-combined-survey-tokens.destroy', $this->trainee))->assertStatus(409);
        $this->assertCount(1, $this->personalityRows());

        // 単独の愛犬性格診断の取消で、残りの愛犬性格診断が消える
        $this->delete(route('trainee-personality-diagnosis-tokens.destroy', $this->trainee))->assertRedirect();
        $this->assertCount(0, $this->personalityRows());

        // 期限が切れた組み合わせは取り消せない（記録として残す）
        $this->issue();
        Carbon::setTestNow('2026-10-27 00:00:00');
        $this->delete(route('trainee-combined-survey-tokens.destroy', $this->trainee))->assertStatus(409);
        $this->assertCount(2, $this->intakeRows());
        $this->assertCount(1, $this->personalityRows());
    }

    // ---- ご案内の画面（S-0314） ----

    public function test_ご案内の画面は帯の見出しと文面とQRコードの中身が組み合わせのもの(): void
    {
        $this->issue();
        $intakeToken = $this->intakeRows()[0]->token;
        $url = route('client-portal.intake-survey.show', ['token' => $intakeToken]);

        $html = $this->printPage();

        $this->assertStringContainsString('<title>愛犬アンケート＆愛犬性格診断のご案内', $html);
        $this->assertStringContainsString(
            '<h1 class="print-band print-band--combined"><span class="print-band-label"><i class="bi bi-clipboard-heart" aria-hidden="true"></i>愛犬アンケート＆愛犬性格診断のご案内</span></h1>',
            $html
        );
        // QR コードの中身は渡すリンク（愛犬アンケートの案内のリンク）
        $this->assertStringContainsString('<canvas data-qr-url="'.$url.'" data-qr-size="280" aria-label="愛犬アンケート＆愛犬性格診断の案内の QR コード"></canvas>', $html);

        $count = count(PersonalityDiagnosisQuestions::questions());
        $expected = implode("\n", [
            '山田 太郎 様',
            '',
            'テスト訓練所です。',
            'モモちゃんの愛犬アンケートと愛犬性格診断のご案内をお送りします。',
            '',
            '下のリンクから、続けてご回答をお願いいたします。（愛犬性格診断は全'.$count.'問です）',
            '',
            $url,
            '',
            'リンクの有効期限：2026年10月26日（月）まで',
            'ご不明な点がございましたら、お気軽にお問い合わせください。',
        ]);
        // 画面に出す文とコピーされる文は同じ
        $this->assertSame($expected, $this->copyText($html));
        $this->assertSame($expected, $this->shownText($html));
    }

    public function test_呼び名が空なら呼び名を付けない文面にする(): void
    {
        DB::table('trainees')->where('id', $this->trainee->id)->update(['name' => null]);
        $this->issue();

        $text = $this->copyText($this->printPage());

        $this->assertStringContainsString("テスト訓練所です。\n愛犬アンケートと愛犬性格診断のご案内をお送りします。\n", $text);
    }

    public function test_2つとも回答待ちでなければご案内の画面は有効な案内がないと出す(): void
    {
        $html = $this->printPage();
        $this->assertStringContainsString('有効な愛犬アンケート＆愛犬性格診断の案内がありません。', $html);
        $this->assertStringNotContainsString('data-qr-url', $html);

        // 愛犬アンケートだけ答えたあと（会員詳細の［表示］は愛犬性格診断のご案内を開く）
        $this->issue();
        DB::table('trainee_intake_survey_tokens')->update(['answered_at' => now()]);
        $this->assertStringContainsString('有効な愛犬アンケート＆愛犬性格診断の案内がありません。', $this->printPage());
    }

    // ---- 名前 ----

    public function test_名前を差し替えると組み合わせの名前と記録の種類の名前に反映される(): void
    {
        // 先に文のファイル（messages）を読み込んでおく（読み込む前に addLines すると、ファイルのほかの文が読まれなくなる）
        app('translator')->load('*', 'messages', 'ja');
        app('translator')->addLines(['messages.intake_survey_name' => 'テストアンケート', 'messages.personality_diagnosis_name' => 'テスト診断'], 'ja');

        $this->assertSame('テストアンケート＆テスト診断', CombinedSurveyName::name());
        // 会員詳細で 2 行に分けて出すときの行も、差し替えた名前から作る（2026-10-19 追加）
        $this->assertSame(['テストアンケート＆', 'テスト診断'], CombinedSurveyName::lines());
        $this->assertSame('テストアンケート＆テスト診断案内発行', AccessLog::actionLabels()['create_trainee_combined_survey_token']);
        $this->assertSame('テストアンケート＆テスト診断案内取消', AccessLog::actionLabels()['delete_trainee_combined_survey_token']);

        $this->issue()->assertSessionHas('success', 'モモのテストアンケート＆テスト診断の案内を発行しました。');
        $html = $this->printPage();
        $this->assertStringContainsString('テストアンケート＆テスト診断のご案内</span></h1>', $html);
        $this->assertStringContainsString('モモちゃんのテストアンケートとテスト診断のご案内をお送りします。', $this->copyText($html));
    }
}

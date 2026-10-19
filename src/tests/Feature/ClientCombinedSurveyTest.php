<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 愛犬アンケート＆愛犬性格診断の案内の、会員側の流れ（2026-10-19 追加。設計書 S-1413・S-1415「進み具合」「続けて進む」）。
 *
 * - 組み合わせのリンク（愛犬アンケートの案内のリンク）を開くと愛犬アンケート（1/2）→ 送信で愛犬性格診断（2/2）→ 送信で完了
 * - 単独のリンクには進み具合が出ず、続けて進まない
 * - 愛犬アンケートが回答済みの組み合わせのリンクを開くと、愛犬性格診断へ進む（途中でやめた続き）
 * - 期限切れのときは今の期限切れの画面
 *
 * 「今日」は 2026-10-19 に固定する（組み合わせの期限は 2026-10-26 23:59:59）。
 */
class ClientCombinedSurveyTest extends TestCase
{
    use CreatesClientCountTables;

    private const INTAKE = 'intaketokenintaketokenintaketoke';

    private const PERSONALITY = 'personalitytokenpersonalitytoken';

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->createClientsTable();
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->string('breed', 100)->nullable();
            $table->string('sex', 20)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->unsignedTinyInteger('birth_month')->nullable();
            $table->unsignedTinyInteger('birth_day')->nullable();
            $table->string('neuter_status', 20)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createTraineeIntakeSurveyTokensTable();
        // 完了の画面のマイページ登録のお誘いで、会員に有効なマイページ登録の案内があるかを見る（2026-10-19 追加）
        $this->createClientEmailRegistrationTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        $this->trainee = Trainee::query()->create(['client_id' => $client->id, 'name' => 'モモ']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 組み合わせの案内（$combined が false なら結び付けない 2 つの単独の案内） */
    private function issue(bool $combined = true): void
    {
        $row = ['trainee_id' => $this->trainee->id, 'expires_at' => '2026-10-26 23:59:59', 'created_at' => now(), 'updated_at' => now()];
        $personalityId = DB::table('trainee_personality_diagnosis_tokens')->insertGetId(['token' => self::PERSONALITY, ...$row]);
        DB::table('trainee_intake_survey_tokens')->insert([
            'token' => self::INTAKE, 'trainee_personality_diagnosis_token_id' => $combined ? $personalityId : null, ...$row,
        ]);
    }

    private function intakeUrl(): string
    {
        return route('client-portal.intake-survey.show', ['token' => self::INTAKE]);
    }

    private function personalityUrl(): string
    {
        return route('client-portal.personality-diagnosis.show', ['token' => self::PERSONALITY]);
    }

    private function submitIntake()
    {
        return $this->post(route('client-portal.intake-survey.store', ['token' => self::INTAKE]), ['name' => 'モモ']);
    }

    /** @param  array<string, string>  $life  暮らしの様子の 4 問（列名 → 答え） */
    private function submitPersonality(array $life = [])
    {
        $json = json_decode(file_get_contents(__DIR__.'/../fixtures/personality-diagnosis-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $answers = array_map(fn ($v) => (string) $v, collect($json['cases'])->firstWhere('name', 'past-1')['answers']);

        return $this->post(route('client-portal.personality-diagnosis.store', ['token' => self::PERSONALITY]), ['answers' => $answers] + $life);
    }

    public function test_組み合わせのリンクで愛犬アンケート1_2から送信で愛犬性格診断2_2へ進み送信で完了(): void
    {
        $this->issue();

        // 1/2：愛犬アンケート
        $html = $this->get($this->intakeUrl())->assertOk()->getContent();
        $this->assertStringContainsString('<p class="c-survey-progress" data-survey-progress="1/2">', $html);
        // 説明文は単独のリンクと同じ（2026-10-19 変更：契約前のお客様にもそぐう文）
        $this->assertStringContainsString('<p class="mb-4"><span class="c-sentence">愛犬のことを教えてください。</span><span class="c-sentence">愛犬に合ったご提案の参考にさせていただきます。</span></p>', $html);
        $this->assertStringNotContainsString('トレーニングの参考に', $html);
        $this->assertStringContainsString('<span class="c-survey-progress-count" aria-hidden="true">1/2</span>', $html);
        $this->assertStringContainsString('<span class="visually-hidden">2つのうち1つ目：</span>', $html);
        $this->assertStringContainsString('<span>愛犬アンケート＆愛犬性格診断</span>', $html);
        $this->assertLessThan(strpos($html, '<h1 class="mb-2">愛犬アンケート</h1>'), strpos($html, 'data-survey-progress'));
        // 1/2 の愛犬アンケートは基本情報だけ。暮らしの様子の 4 問は出さない（2026-10-19 変更：愛犬性格診断へ移した）
        $this->assertStringNotContainsString('暮らしの様子', $html);
        $this->assertStringNotContainsString('<textarea', $html);

        // 送信すると、完了の画面ではなく愛犬性格診断の回答の画面へ
        $this->submitIntake()->assertRedirect($this->personalityUrl());
        $this->assertSame(1, DB::table('trainee_intake_survey_responses')->count());
        $this->assertNotNull(DB::table('trainee_intake_survey_tokens')->value('answered_at'));

        // 2/2：愛犬性格診断。愛犬アンケートを受け付けたことを一言で伝える
        $html = $this->get($this->personalityUrl())->assertOk()->getContent();
        $this->assertStringContainsString('<p class="c-survey-progress" data-survey-progress="2/2">', $html);
        $this->assertStringContainsString('<span class="visually-hidden">2つのうち2つ目：</span>', $html);
        $this->assertStringContainsString('愛犬アンケートを受け付けました。続けて愛犬性格診断にお答えください。', $html);
        // 2/2 の愛犬性格診断は 50 問のあとに、区切りの線・「以下の質問にお答えください。」と暮らしの様子の 4 問（2026-10-19 追加・変更）
        $this->assertSame(50, substr_count($html, '<fieldset class="mb-3" id="q-Q'));
        $this->assertGreaterThan(strpos($html, 'id="q-Q50"'), strpos($html, 'data-life-divider'));
        $this->assertGreaterThan(strpos($html, 'data-life-divider'), strpos($html, '<legend class="fs-6 mb-3">以下の質問にお答えください。</legend>'));
        $this->assertStringNotContainsString('暮らしの様子', $html);
        $this->assertStringNotContainsString('思いつくことをご自由にお書きください。', $html);
        $this->assertSame(4, substr_count($html, '<textarea'));

        // 送信すると、今の愛犬性格診断の完了の画面。暮らしの様子の答えは愛犬性格診断の結果に保存する
        $this->submitPersonality(['wish' => 'お留守番が上手になってほしい'])->assertRedirect(route('client-portal.personality-diagnosis.thanks'));
        $this->assertSame(1, DB::table('trainee_personality_diagnosis_results')->count());
        $this->assertSame('お留守番が上手になってほしい', DB::table('trainee_personality_diagnosis_results')->value('wish'));

        // 最後の完了の画面の 1 文目も契約前のお客様にもそぐう文（2026-10-19 変更）
        $html = $this->get(route('client-portal.personality-diagnosis.thanks'))->assertOk()->getContent();
        $this->assertStringContainsString('<p>いただいた内容は、愛犬に合ったご提案の参考にさせていただきます。</p>', $html);
        $this->assertStringNotContainsString('トレーニングの参考に', $html);
    }

    public function test_受け付けた一言は送信して進んだときだけで開き直したときは出さない(): void
    {
        $this->issue();
        $this->submitIntake();
        $this->get($this->personalityUrl());

        $html = $this->get($this->personalityUrl())->assertOk()->getContent();

        $this->assertStringContainsString('data-survey-progress="2/2"', $html);
        $this->assertStringNotContainsString('data-combined-intake-answered', $html);
    }

    public function test_単独のリンクには進み具合が出ず続けて進まない(): void
    {
        $this->issue(combined: false);

        $html = $this->get($this->intakeUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('data-survey-progress', $html);
        $this->submitIntake()->assertRedirect(route('client-portal.intake-survey.thanks'));

        $html = $this->get($this->personalityUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('data-survey-progress', $html);
        $this->assertStringNotContainsString('data-combined-intake-answered', $html);
    }

    public function test_愛犬アンケートが回答済みの組み合わせのリンクを開くと愛犬性格診断へ進む(): void
    {
        $this->issue();
        $this->submitIntake();

        // 途中でやめたあと、同じリンク（組み合わせのリンク）を開き直す
        $this->get($this->intakeUrl())->assertRedirect($this->personalityUrl());

        // 愛犬性格診断も答えたあとは、今の回答済みの画面
        $this->submitPersonality();
        $html = $this->get($this->intakeUrl())->assertOk()->getContent();
        $this->assertStringContainsString('このアンケートはすでにご回答いただいています。', $html);
    }

    public function test_愛犬性格診断を取り消したあとは愛犬アンケートだけの流れになる(): void
    {
        $this->issue();
        DB::table('trainee_personality_diagnosis_tokens')->delete();
        // 本番の外部キー（nullOnDelete）と同じく、結び付けの列を空にする（テストの表には外部キーを付けていない）
        DB::table('trainee_intake_survey_tokens')->update(['trainee_personality_diagnosis_token_id' => null]);

        $this->assertStringNotContainsString('data-survey-progress', $this->get($this->intakeUrl())->getContent());
        $this->submitIntake()->assertRedirect(route('client-portal.intake-survey.thanks'));
    }

    public function test_期限切れのときは今の期限切れの画面(): void
    {
        $this->issue();
        Carbon::setTestNow('2026-10-27 00:00:00');

        $this->assertStringContainsString('このリンクは有効期限が切れています。', $this->get($this->intakeUrl())->assertOk()->getContent());
        $this->assertStringContainsString('このリンクは有効期限が切れています。', $this->get($this->personalityUrl())->assertOk()->getContent());

        // 愛犬アンケートは答えて愛犬性格診断が期限切れ：組み合わせのリンクは回答済みの画面（進む先がないため）
        Carbon::setTestNow('2026-10-19 10:00:00');
        $this->submitIntake();
        Carbon::setTestNow('2026-10-27 00:00:00');
        $this->assertStringContainsString('このアンケートはすでにご回答いただいています。', $this->get($this->intakeUrl())->assertOk()->getContent());
    }
}

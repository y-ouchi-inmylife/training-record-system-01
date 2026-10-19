<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SystemSetting;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\Trainer;
use App\Services\EvaluationDraft\EvaluationDraft;
use App\Services\EvaluationDraft\EvaluationDraftFailedException;
use App\Services\EvaluationDraft\EvaluationDraftGenerator;
use App\Services\EvaluationDraft\PersonalityEvaluationPrompt;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 愛犬性格診断の評価文とスタッフ用メモの AI の下書き（2026-10-19 追加。要件定義書 6-16-19、設計書 S-0313「AIで下書き」・S-0602）。
 *
 * 外部の AI は呼ばない（EvaluationDraftGenerator をテストで差し替える。指示文の組み立て・返事の分け方はそれぞれのクラスだけで確かめる）。
 * 「今日」は 2026-10-19 に固定する。
 */
class PersonalityEvaluationDraftTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-19 10:00:00');

        $this->createClientsTable();
        $this->createAccessLogsTable();
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
        });
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->string('breed', 100)->nullable();
            $table->string('sex', 20)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();
        $this->createTraineeIntakeSurveyResponsesTable();

        DB::table('trainers')->insert(['id' => 200, 'name' => '佐藤', 'role' => 'staff']);
        $this->actingAsRole('staff');
        $this->client = Client::query()->create([
            'internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎', 'email' => 'yamada@example.com',
            'phone1' => '090-1111-2222', 'address3' => '桜町1-2-3',
        ]);
        $this->trainee = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'モモ', 'breed' => '柴犬', 'sex' => 'female', 'birth_year' => 2020]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => '佐藤', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    private function makeResult(array $scores = [], ?string $type = 'guardian', string $answeredAt = '2026-10-15 10:00:00', ?Trainee $trainee = null): TraineePersonalityDiagnosisResult
    {
        $attributes = [
            'trainee_id' => ($trainee ?? $this->trainee)->id, 'answered_at' => $answeredAt, 'question_version' => 'v1',
            'answers' => ['Q01' => 2], 'personality_type' => $type,
        ];
        $defaults = ['people' => '2.50', 'dogs' => '2.88', 'possessiveness' => '2.75', 'separation' => '2.00', 'fear' => '2.94', 'trainability' => '2.71', 'excitability' => '2.50'];
        foreach (array_merge($defaults, $scores) as $category => $score) {
            $attributes['score_'.$category] = $score;
        }

        return TraineePersonalityDiagnosisResult::query()->create($attributes);
    }

    private function intakeResponse(string $answeredAt, array $answers): void
    {
        DB::table('trainee_intake_survey_responses')->insert([
            'trainee_id' => $this->trainee->id, 'answered_at' => $answeredAt, 'created_at' => $answeredAt, 'updated_at' => $answeredAt,
            'name' => 'モモ', 'breed' => '柴犬', ...$answers,
        ]);
    }

    /** 下書きを作るサービスを差し替える（$draft が例外なら失敗させる） */
    private function fakeGenerator(EvaluationDraft|\Throwable $draft): void
    {
        $this->app->instance(EvaluationDraftGenerator::class, new class($draft) implements EvaluationDraftGenerator
        {
            public function __construct(private EvaluationDraft|\Throwable $draft) {}

            public function generate(TraineePersonalityDiagnosisResult $result): EvaluationDraft
            {
                if ($this->draft instanceof \Throwable) {
                    throw $this->draft;
                }

                return $this->draft;
            }
        });
    }

    private function draftUrl(TraineePersonalityDiagnosisResult $result, ?Trainee $trainee = null): string
    {
        return route('trainee-personality-diagnosis-results.evaluation-draft', [$trainee ?? $this->trainee, $result]);
    }

    // ---- データの組み立て ----

    public function test_データは名前とタイプとスコアと暮らしの様子の4問で会員の個人情報や犬種は入らない(): void
    {
        $result = $this->makeResult(['dogs' => null]);
        $this->intakeResponse('2026-10-10 09:00:00', [
            'wish' => 'お留守番が上手になってほしい', 'recent_events' => '散歩で吠える', 'ideal_days' => 'ドッグランで遊びたい', 'health_concerns' => '膝が弱い',
        ]);

        $data = PersonalityEvaluationPrompt::data($result);

        $this->assertSame([
            '{{犬の名前}}' => 'モモ',
            '{{タイプ}}' => '慎重派・しっかり番犬タイプ',
            // 小数第1位。カテゴリの順と名前は categories() のとおり。点数なしは「—」
            '{{スコア}}' => '対人:2.5, 対犬:—, 所有欲:2.8, 分離不安:2.0, 恐怖心:2.9, 訓練性能:2.7, 興奮性:2.5',
            // 悩み＝こうなったらいいな、背景＝最近の様子、理想＝理想の毎日、健康＝体のこと
            '{{悩み}}' => 'お留守番が上手になってほしい',
            '{{背景}}' => '散歩で吠える',
            '{{理想}}' => 'ドッグランで遊びたい',
            '{{健康}}' => '膝が弱い',
        ], $data);

        $prompt = PersonalityEvaluationPrompt::render($result);
        foreach (['山田', '太郎', 'yamada@example.com', '090-1111-2222', '桜町', '柴犬', '女の子', '2020'] as $private) {
            $this->assertStringNotContainsString($private, $prompt, $private);
        }
    }

    public function test_名前が空で判定なしで回答がなければ空のまま(): void
    {
        DB::table('trainees')->where('id', $this->trainee->id)->update(['name' => null]);
        $all = array_fill_keys(['people', 'dogs', 'possessiveness', 'separation', 'fear', 'trainability', 'excitability'], null);
        $result = $this->makeResult($all, null);

        $data = PersonalityEvaluationPrompt::data($result->fresh());

        $this->assertSame('', $data['{{犬の名前}}']);
        $this->assertSame('判定なし', $data['{{タイプ}}']);
        $this->assertSame('対人:—, 対犬:—, 所有欲:—, 分離不安:—, 恐怖心:—, 訓練性能:—, 興奮性:—', $data['{{スコア}}']);
        foreach (['{{悩み}}', '{{背景}}', '{{理想}}', '{{健康}}'] as $key) {
            $this->assertSame('', $data[$key], $key);
        }
    }

    public function test_使う回答は診断の日以前でいちばん新しいものでなければいちばん新しいもの(): void
    {
        $result = $this->makeResult(answeredAt: '2026-10-15 10:00:00');
        $this->intakeResponse('2026-10-01 09:00:00', ['wish' => '古い答え']);
        $this->intakeResponse('2026-10-15 10:00:00', ['wish' => '診断の日の答え']);
        $this->intakeResponse('2026-10-18 09:00:00', ['wish' => '診断より後の答え']);

        // 診断の日以前でいちばん新しい
        $this->assertSame('診断の日の答え', PersonalityEvaluationPrompt::data($result)['{{悩み}}']);

        // 診断の日以前の回答がなければ、いちばん新しい回答
        DB::table('trainee_intake_survey_responses')->where('answered_at', '<=', '2026-10-15 10:00:00')->delete();
        $this->assertSame('診断より後の答え', PersonalityEvaluationPrompt::data($result)['{{悩み}}']);
    }

    // ---- 指示文 ----

    public function test_指示文は設定がなければ既定の文で設定があればその文で差し込みの印が置き換わる(): void
    {
        $result = $this->makeResult();

        $prompt = PersonalityEvaluationPrompt::render($result);
        $this->assertStringStartsWith('ドッグトレーナーとして、以下のデータから「お客様用レポート」と「スタッフ用指示書」を作成してください。', $prompt);
        $this->assertStringContainsString("【犬の名前】モモ\n【タイプ】慎重派・しっかり番犬タイプ\n【スコア】対人:2.5, ", $prompt);
        $this->assertStringContainsString('形式：お客様用レポート（800字程度）、次に「#####」、最後にスタッフ用メモ（300字程度）。', $prompt);
        $this->assertStringNotContainsString('{{', $prompt);

        SystemSetting::query()->create(['key' => 'evaluation_prompt_current', 'value' => '名前は{{犬の名前}}、タイプは{{タイプ}}です。']);
        $this->assertSame('名前はモモ、タイプは慎重派・しっかり番犬タイプです。', PersonalityEvaluationPrompt::render($result));
    }

    // ---- 返事の分け方 ----

    public function test_返事は最初の区切りで分け前後の空白を取る(): void
    {
        $draft = EvaluationDraft::fromResponse("\n  お客様用の文\n\n#####\n　スタッフ用の文\n##### 続き\n");

        $this->assertSame('お客様用の文', $draft->evaluation);
        $this->assertSame("スタッフ用の文\n##### 続き", $draft->staffMemo);
        $this->assertFalse($draft->staffMemoMissing);
    }

    public function test_区切りがなければ全部を評価文にしてスタッフ用メモは空(): void
    {
        $draft = EvaluationDraft::fromResponse("お客様用の文だけ\n");

        $this->assertSame('お客様用の文だけ', $draft->evaluation);
        $this->assertSame('', $draft->staffMemo);
        $this->assertTrue($draft->staffMemoMissing);
    }

    public function test_返事が空なら失敗(): void
    {
        $this->expectException(EvaluationDraftFailedException::class);

        EvaluationDraft::fromResponse(" \n　");
    }

    // ---- 下書きのルート ----

    public function test_下書きはJSONで返し何も保存しない(): void
    {
        $result = $this->makeResult();
        $result->update(['evaluation_text' => '保存してある評価文', 'staff_memo' => '保存してあるメモ']);
        $this->fakeGenerator(new EvaluationDraft('お客様用の下書き', 'スタッフ用の下書き', false));

        $this->postJson($this->draftUrl($result))
            ->assertOk()
            ->assertExactJson(['evaluation' => 'お客様用の下書き', 'staff_memo' => 'スタッフ用の下書き', 'staff_memo_missing' => false]);

        $fresh = $result->fresh();
        $this->assertSame('保存してある評価文', $fresh->evaluation_text);
        $this->assertSame('保存してあるメモ', $fresh->staff_memo);
        $this->assertNull($fresh->evaluation_updated_at);
        // 操作の記録（アクセスログ）には残さない
        $this->assertSame(0, DB::table('access_logs')->count());
    }

    public function test_公開済みでも作れて管理者も一般のトレーナーも作れる(): void
    {
        $result = $this->makeResult();
        $result->update(['evaluation_text' => '公開した文', 'evaluation_published_at' => '2026-10-18 10:00:00', 'evaluation_published_by' => 200]);
        $this->fakeGenerator(new EvaluationDraft('下書き', 'メモ', false));

        foreach (['staff', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->postJson($this->draftUrl($result))->assertOk()->assertJsonPath('evaluation', '下書き');
        }
        $this->assertSame('公開した文', $result->fresh()->evaluation_text);
    }

    public function test_ログインしていなければ作れない(): void
    {
        $result = $this->makeResult();
        $this->fakeGenerator(new EvaluationDraft('下書き', 'メモ', false));
        auth('web')->logout();

        $response = $this->postJson($this->draftUrl($result));

        $this->assertContains($response->getStatusCode(), [302, 401]);
    }

    public function test_ほかのトレーニーの結果は弾く(): void
    {
        $other = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'タマ']);
        $result = $this->makeResult(trainee: $other);
        $this->fakeGenerator(new EvaluationDraft('下書き', 'メモ', false));

        $this->postJson($this->draftUrl($result, $this->trainee))->assertNotFound();
    }

    public function test_AIの失敗のときは決まった文のエラーを返す(): void
    {
        $result = $this->makeResult();
        $this->fakeGenerator(new EvaluationDraftFailedException('timeout'));

        $this->postJson($this->draftUrl($result))
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AIの下書きを作れませんでした。時間をおいてもう一度お試しください。']);
        $this->assertNull($result->fresh()->evaluation_text);
    }

    public function test_区切りがなかったことをJSONで伝える(): void
    {
        $result = $this->makeResult();
        $this->fakeGenerator(EvaluationDraft::fromResponse('全部お客様用'));

        $this->postJson($this->draftUrl($result))
            ->assertOk()
            ->assertExactJson(['evaluation' => '全部お客様用', 'staff_memo' => '', 'staff_memo_missing' => true]);
    }

    // ---- スタッフ用メモの保存 ----

    private function staffMemoUrl(TraineePersonalityDiagnosisResult $result): string
    {
        return route('trainee-personality-diagnosis-results.staff-memo.update', [$this->trainee, $result]);
    }

    public function test_スタッフ用メモを保存でき評価文は変わらない(): void
    {
        $result = $this->makeResult();
        $result->update(['evaluation_text' => '評価文', 'evaluation_updated_at' => '2026-10-18 10:00:00', 'evaluation_updated_by' => 200]);

        $this->put($this->staffMemoUrl($result), ['staff_memo' => "メモ\r\n2行目"])
            ->assertRedirect(route('trainee-personality-diagnosis-results.show', [$this->trainee, $result]))
            ->assertSessionHas('success', 'スタッフ用メモを更新しました。');

        $fresh = $result->fresh();
        $this->assertSame("メモ\n2行目", $fresh->staff_memo);
        $this->assertSame('評価文', $fresh->evaluation_text);
        $this->assertSame('2026-10-18 10:00:00', $fresh->evaluation_updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('edit_trainee_personality_diagnosis_staff_memo', DB::table('access_logs')->value('action'));

        // 空にすると NULL
        $this->put($this->staffMemoUrl($result), ['staff_memo' => '  ']);
        $this->assertNull($result->fresh()->staff_memo);
    }

    public function test_スタッフ用メモは10000字まで(): void
    {
        $result = $this->makeResult();

        $this->put($this->staffMemoUrl($result), ['staff_memo' => str_repeat('あ', 10000)])->assertSessionHasNoErrors();
        $this->put($this->staffMemoUrl($result), ['staff_memo' => str_repeat('あ', 10001)])->assertSessionHasErrors('staff_memo');
        $this->assertSame(10000, mb_strlen($result->fresh()->staff_memo));
    }

    public function test_評価文の更新ではスタッフ用メモが変わらない(): void
    {
        $result = $this->makeResult();
        $result->update(['staff_memo' => 'メモ']);

        $this->put(route('trainee-personality-diagnosis-results.update', [$this->trainee, $result]), ['evaluation_text' => '新しい評価文'])->assertRedirect();

        $this->assertSame('メモ', $result->fresh()->staff_memo);
        $this->assertSame('新しい評価文', $result->fresh()->evaluation_text);
    }
}

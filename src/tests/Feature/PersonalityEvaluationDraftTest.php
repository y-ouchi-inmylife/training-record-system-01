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
 * 愛犬性格診断のアドバイスとスタッフ用メモの AI の下書き（2026-10-19 追加。要件定義書 6-16-19、設計書 S-0313「AIで下書き」・S-0602）。
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

    /** @param  array<string, string|null>  $life  自由記述の質問（4 問）（列名 → 答え。2026-10-19 から診断の結果に持つ） */
    private function makeResult(array $scores = [], ?string $type = 'guardian', string $answeredAt = '2026-10-15 10:00:00', ?Trainee $trainee = null, array $life = []): TraineePersonalityDiagnosisResult
    {
        $attributes = [
            'trainee_id' => ($trainee ?? $this->trainee)->id, 'answered_at' => $answeredAt, 'question_version' => 'v1',
            'answers' => ['Q01' => 2], 'personality_type' => $type,
        ] + $life;
        $defaults = ['people' => '2.50', 'dogs' => '2.88', 'possessiveness' => '2.75', 'separation' => '2.00', 'fear' => '2.94', 'trainability' => '2.71', 'excitability' => '2.50'];
        foreach (array_merge($defaults, $scores) as $category => $score) {
            $attributes['score_'.$category] = $score;
        }

        return TraineePersonalityDiagnosisResult::query()->create($attributes);
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

    public function test_データは名前とタイプとスコアと自由記述の質問の4問で会員の個人情報や犬種は入らない(): void
    {
        $result = $this->makeResult(['dogs' => null], life: [
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

    public function test_自由記述の質問はその診断の結果の4問を使いほかの診断や愛犬アンケートの回答は使わない(): void
    {
        // 2026-10-19 変更：4 問を愛犬性格診断へ移し、その診断の結果に保存した答えを使う（その前は、愛犬アンケートの回答のうち
        // 診断の日以前でいちばん新しいもの〔なければいちばん新しいもの〕を探して使っていた）
        $older = $this->makeResult(answeredAt: '2026-10-01 09:00:00', life: ['wish' => '前の診断の答え', 'health_concerns' => '前の診断の体のこと']);
        $result = $this->makeResult(answeredAt: '2026-10-15 10:00:00', life: ['wish' => 'この診断の答え', 'recent_events' => "1行目\n2行目"]);
        $this->makeResult(answeredAt: '2026-10-18 09:00:00', life: ['wish' => '後の診断の答え', 'ideal_days' => '後の診断の理想']);
        // 愛犬アンケートの回答（基本情報だけ。自由記述の質問の列はもうない）があっても使わない
        DB::table('trainee_intake_survey_responses')->insert([
            'trainee_id' => $this->trainee->id, 'answered_at' => '2026-10-14 09:00:00', 'name' => 'モモ', 'breed' => '柴犬',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = PersonalityEvaluationPrompt::data($result);

        $this->assertSame('この診断の答え', $data['{{悩み}}']);
        $this->assertSame("1行目\n2行目", $data['{{背景}}']);
        // この診断で答えなかった問いは空（ほかの診断の答えで埋めない）
        $this->assertSame('', $data['{{理想}}']);
        $this->assertSame('', $data['{{健康}}']);
        $this->assertSame('前の診断の答え', PersonalityEvaluationPrompt::data($older)['{{悩み}}']);
        $this->assertSame('前の診断の体のこと', PersonalityEvaluationPrompt::data($older)['{{健康}}']);
    }

    public function test_差し込みの印の説明は愛犬性格診断で答えた自由記述の質問の答え(): void
    {
        $placeholders = PersonalityEvaluationPrompt::placeholders();

        $this->assertSame('この愛犬性格診断で答えた自由記述の質問の「こうなったらいいな」の答え', $placeholders['{{悩み}}']);
        $this->assertSame('この愛犬性格診断で答えた自由記述の質問の「最近の様子」の答え', $placeholders['{{背景}}']);
        $this->assertSame('この愛犬性格診断で答えた自由記述の質問の「理想の毎日」の答え', $placeholders['{{理想}}']);
        $this->assertSame('この愛犬性格診断で答えた自由記述の質問の「体のこと」の答え', $placeholders['{{健康}}']);
        $this->assertStringNotContainsString('愛犬アンケート', implode('', $placeholders));
        // 2026-10-19 変更：呼び名「暮らしの様子」をなくし、「自由記述の質問」にした
        $this->assertStringNotContainsString('暮らしの様子', implode('', $placeholders));
        // 設定画面（S-0602）にもこの説明が出る
        $this->actingAsRole('admin');
        $html = $this->get(route('settings.evaluation-prompts.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('この愛犬性格診断で答えた自由記述の質問の「こうなったらいいな」の答え', $html);
        $this->assertStringNotContainsString('愛犬アンケート', $html);
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

    public function test_区切りがなければ全部をアドバイスにしてスタッフ用メモは空(): void
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
        $result->update(['evaluation_text' => '保存してあるアドバイス', 'staff_memo' => '保存してあるメモ']);
        $this->fakeGenerator(new EvaluationDraft('お客様用の下書き', 'スタッフ用の下書き', false));

        $this->postJson($this->draftUrl($result))
            ->assertOk()
            ->assertExactJson(['evaluation' => 'お客様用の下書き', 'staff_memo' => 'スタッフ用の下書き', 'staff_memo_missing' => false]);

        $fresh = $result->fresh();
        $this->assertSame('保存してあるアドバイス', $fresh->evaluation_text);
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

    public function test_AIの失敗のときは決まった文のエラーを返す_AIで自動生成できませんでした(): void
    {
        $result = $this->makeResult();
        $this->fakeGenerator(new EvaluationDraftFailedException('timeout'));

        $this->postJson($this->draftUrl($result))
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AIで自動生成できませんでした。時間をおいてもう一度お試しください。']);
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

    // ---- スタッフ用メモの保存（2026-10-19 変更：アドバイスと同じ［更新］〔PUT …/{result}〕で保存する。その前は PUT …/staff-memo） ----

    private function updateUrl(TraineePersonalityDiagnosisResult $result): string
    {
        return route('trainee-personality-diagnosis-results.update', [$this->trainee, $result]);
    }

    public function test_スタッフ用メモは更新で保存し空にするとNULL(): void
    {
        $result = $this->makeResult();
        $result->update(['evaluation_text' => 'アドバイス', 'evaluation_updated_at' => '2026-10-18 10:00:00', 'evaluation_updated_by' => 200]);

        $this->put($this->updateUrl($result), ['evaluation_text' => 'アドバイス', 'staff_memo' => "メモ\r\n2行目"])
            ->assertRedirect(route('trainee-personality-diagnosis-results.show', [$this->trainee, $result]))
            ->assertSessionHas('success', 'アドバイスとスタッフ用メモを更新しました。');

        $fresh = $result->fresh();
        $this->assertSame("メモ\n2行目", $fresh->staff_memo);
        $this->assertSame('アドバイス', $fresh->evaluation_text);
        // アドバイスは変わっていないので最終更新は変えず、記録はスタッフ用メモの編集だけ
        $this->assertSame('2026-10-18 10:00:00', $fresh->evaluation_updated_at->format('Y-m-d H:i:s'));
        $this->assertSame(['edit_trainee_personality_diagnosis_staff_memo'], DB::table('access_logs')->pluck('action')->all());

        // 空にすると NULL
        $this->put($this->updateUrl($result), ['evaluation_text' => 'アドバイス', 'staff_memo' => '  ']);
        $this->assertNull($result->fresh()->staff_memo);
    }

    public function test_スタッフ用メモは10000字まで(): void
    {
        $result = $this->makeResult();

        $this->put($this->updateUrl($result), ['evaluation_text' => '', 'staff_memo' => str_repeat('あ', 10000)])->assertSessionHasNoErrors();
        $this->put($this->updateUrl($result), ['evaluation_text' => '', 'staff_memo' => str_repeat('あ', 10001)])->assertSessionHasErrors('staff_memo');
        $this->assertSame(10000, mb_strlen($result->fresh()->staff_memo));
    }

    public function test_スタッフ用メモを送らなければスタッフ用メモは変わらない(): void
    {
        $result = $this->makeResult();
        $result->update(['staff_memo' => 'メモ']);

        $this->put(route('trainee-personality-diagnosis-results.update', [$this->trainee, $result]), ['evaluation_text' => '新しいアドバイス'])->assertRedirect();

        $this->assertSame('メモ', $result->fresh()->staff_memo);
        $this->assertSame('新しいアドバイス', $result->fresh()->evaluation_text);
    }
}

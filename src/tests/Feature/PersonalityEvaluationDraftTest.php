<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SystemSetting;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\Trainer;
use App\Services\EvaluationDraft\ClaudeEvaluationDraftGenerator;
use App\Services\EvaluationDraft\EvaluationDraft;
use App\Services\EvaluationDraft\EvaluationDraftFailedException;
use App\Services\EvaluationDraft\EvaluationDraftGenerator;
use App\Services\EvaluationDraft\EvaluationDraftProviders;
use App\Services\EvaluationDraft\GeminiEvaluationDraftGenerator;
use App\Services\EvaluationDraft\OpenAiEvaluationDraftGenerator;
use App\Services\EvaluationDraft\PersonalityEvaluationPrompt;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        // 3 社のキーとモデル（2026-10-20 追加）。開発の .env に左右されないよう、テストの値を入れる
        config([
            'services.anthropic.api_key' => 'test-anthropic-key', 'services.anthropic.model' => 'claude-test',
            'openai.api_key' => 'test-openai-key', 'services.openai.evaluation_model' => 'openai-test',
            'services.gemini.api_key' => 'test-gemini-key', 'services.gemini.model' => 'gemini-test',
        ]);

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
    /**
     * HTTP の送り手を差し替えた Claude の実装と、送った要求の記録（外部の AI は呼ばない）
     *
     * @return array{0: ClaudeEvaluationDraftGenerator, 1: \ArrayObject}
     */
    private function fakeClaude(string $text): array
    {
        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.model' => 'claude-test']);
        $requests = new \ArrayObject;
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-test',
                'content' => [['type' => 'text', 'text' => $text]],
                'stop_reason' => 'end_turn', 'stop_sequence' => null,
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ])),
        ]));
        $stack->push(Middleware::history($requests));

        return [new ClaudeEvaluationDraftGenerator(new GuzzleClient(['handler' => $stack])), $requests];
    }

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

    /**
     * 下書きを作るサービスを差し替える（$draft が例外なら失敗させる）。2026-10-20 変更：AI の会社ごとの実装を差し替える
     * （その前は EvaluationDraftGenerator を 1 つ差し替えていた）。呼ばれた回数を返す
     */
    private function fakeGenerator(EvaluationDraft|\Throwable $draft, string $provider = 'claude'): \ArrayObject
    {
        $calls = new \ArrayObject;
        $this->app->instance(EvaluationDraftProviders::PROVIDERS[$provider]['class'], new class($draft, $calls) implements EvaluationDraftGenerator
        {
            public function __construct(private EvaluationDraft|\Throwable $draft, private \ArrayObject $calls) {}

            public function generate(TraineePersonalityDiagnosisResult $result): EvaluationDraft
            {
                $this->calls->append($result->id);
                if ($this->draft instanceof \Throwable) {
                    throw $this->draft;
                }

                return $this->draft;
            }
        });

        return $calls;
    }

    private function draftUrl(TraineePersonalityDiagnosisResult $result, ?Trainee $trainee = null): string
    {
        return route('trainee-personality-diagnosis-results.evaluation-draft', [$trainee ?? $this->trainee, $result]);
    }

    /** 下書きを作る（押したボタンの AI を送る。2026-10-20 追加） */
    private function postDraft(TraineePersonalityDiagnosisResult $result, mixed $provider = 'claude', ?Trainee $trainee = null)
    {
        return $this->postJson($this->draftUrl($result, $trainee), $provider === null ? [] : ['provider' => $provider]);
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
        // 2 つめの「##### 」は区切りでなく見出しの記号として後始末で外れる（2026-10-19 変更：その前は「##### 続き」のまま）
        $this->assertSame("スタッフ用の文\n続き", $draft->staffMemo);
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

    // ---- 念のための後始末（2026-10-19 追加：AI が Markdown の記号で書き、会員様の画面に記号が出てしまうため） ----

    public function test_後始末で見出しと太字と区切り線と箇条書きの記号が外れる(): void
    {
        $this->assertSame(
            "【見出し】\n見出し2\n小見出し\n深い見出し\n本文の太字と2つめの太字です。\n\n・項目1\n・項目2\n  ・字下げした項目\n・太字の項目\n終わり",
            EvaluationDraft::stripMarkdown(
                "# 【見出し】\n## 見出し2\n### 小見出し\n######　深い見出し\n本文の**太字**と**2つめの太字**です。\n---\n\n- 項目1\n* 項目2\n  - 字下げした項目\n- **太字の項目**\n***\n___\n- - -\n終わり"
            )
        );
    }

    public function test_後始末は文の中の普通の記号を壊さない(): void
    {
        $text = "3*4=12、2-1=1、A-B の対比です。\n#タグ風の文（空白なし）\n文中の # や * や - はそのまま\n**閉じていない太字\n-- 2 つだけの線\n・もとから中黒の項目\n「#####」の話";

        $this->assertSame($text, EvaluationDraft::stripMarkdown($text));
    }

    public function test_区切りで分けてから後始末をしてそれぞれ前後の空白を取る(): void
    {
        $draft = EvaluationDraft::fromResponse("## お客様用レポート\n**モモちゃん**は慎重派です。\n- 散歩を楽しむ\n---\n#####\n## スタッフ用メモ\n* 吠えに注意\n");

        // 最初の行の呼び名の見出しも外れる（2026-10-19 変更：その前は「お客様用レポート」「スタッフ用メモ」の行が残っていた）
        $this->assertSame("モモちゃんは慎重派です。\n・散歩を楽しむ", $draft->evaluation);
        $this->assertSame('・吠えに注意', $draft->staffMemo);
        $this->assertFalse($draft->staffMemoMissing);

        // 区切りがないときも全部に後始末をする
        $draft = EvaluationDraft::fromResponse("# 見出し\n- 項目\n");
        $this->assertSame("見出し\n・項目", $draft->evaluation);
        $this->assertTrue($draft->staffMemoMissing);
    }

    // ---- 呼び名の行を外す（2026-10-19 追加：AI が指示文の呼び名を見出しとして書き、会員様の画面に出てしまうため） ----

    public function test_最初の行が呼び名だけなら外し先頭の空の行も取る(): void
    {
        $cases = [
            'お客様用レポート', '【お客様用レポート】', 'お客様用レポート：', 'お客様用レポート:', '「お客様用レポート」', '『お客様用』',
            '＜スタッフ用メモ＞', '<スタッフ用メモ>', 'スタッフ用指示書', '　【 スタッフ用メモ 】　', 'お客様用',
        ];
        foreach ($cases as $label) {
            $this->assertSame("本文の1行目\n本文の2行目", EvaluationDraft::stripLeadingLabel($label."\n\n　\n本文の1行目\n本文の2行目"), $label);
        }
        // 先頭の空の行は飛ばして最初の行を見る
        $this->assertSame('本文', EvaluationDraft::stripLeadingLabel("\n\n【お客様用レポート】\n本文"));
        // 呼び名だけの返事なら空になる
        $this->assertSame('', EvaluationDraft::stripLeadingLabel("スタッフ用メモ\n"));
    }

    public function test_呼び名の行はMarkdownの記号が付いていても外れる(): void
    {
        foreach (['## お客様用レポート', '**スタッフ用メモ**', '# 【お客様用レポート】', '### **スタッフ用指示書**：'] as $label) {
            $draft = EvaluationDraft::fromResponse($label."\n本文です。\n#####\n".$label."\nメモです。");
            $this->assertSame('本文です。', $draft->evaluation, $label);
            $this->assertSame('メモです。', $draft->staffMemo, $label);
            $this->assertFalse($draft->staffMemoMissing, $label);
        }
    }

    public function test_呼び名の行を外すのは最初の行の呼び名だけの行に限る(): void
    {
        $texts = [
            // 文が続く最初の行
            "お客様用レポートとして、モモちゃんの様子をまとめます。\n本文",
            "お客様用レポート：モモちゃんは慎重派です。\n本文",
            '【お客様用レポート】モモちゃんは慎重派です。',
            // 本文の途中の呼び名・2 行目以降の呼び名の行
            'モモちゃんは慎重派です。お客様用レポートの続きです。',
            "モモちゃんは慎重派です。\nお客様用レポート\n本文",
            "【見出し】\nスタッフ用メモ\n本文",
            // 呼び名に似た別の言葉
            "お客様用のレポート\n本文",
            "スタッフ用メモ欄\n本文",
        ];
        foreach ($texts as $text) {
            $this->assertSame($text, EvaluationDraft::stripLeadingLabel($text), $text);
        }
    }

    // ---- Claude に渡すもの（2026-10-19 追加。SDK の HTTP の送り手を差し替え、外部の AI は呼ばない） ----

    public function test_書式の決まりはsystemに入りユーザーの発言は今までどおりの指示文(): void
    {
        $result = $this->makeResult();
        [$generator, $requests] = $this->fakeClaude("## 見出し\n**お客様用**\n#####\n- スタッフ用");

        $draft = $generator->generate($result);

        $body = json_decode((string) $requests[0]['request']->getBody(), true);
        $this->assertSame(PersonalityEvaluationPrompt::FORMAT_RULES, $body['system']);
        $this->assertSame([['role' => 'user', 'content' => PersonalityEvaluationPrompt::render($result)]], $body['messages']);
        $this->assertStringContainsString('などの呼び名を見出しとして書かず、それぞれ本文から書き始めてください。', $body['system']);
        $this->assertStringStartsWith('ドッグトレーナーとして、', $body['messages'][0]['content']);
        $this->assertStringNotContainsString('次の決まりを必ず守ってください。', $body['messages'][0]['content']);
        $this->assertStringNotContainsString('プレーンテキスト', $body['messages'][0]['content']);
        // 長さの上限は今のまま
        $this->assertSame(3000, $body['max_tokens']);
        // 返事は分けてから後始末される
        $this->assertSame("見出し\nお客様用", $draft->evaluation);
        $this->assertSame('・スタッフ用', $draft->staffMemo);
    }

    public function test_設定画面で指示文を変えてもsystemの書式の決まりは付く(): void
    {
        $result = $this->makeResult();
        SystemSetting::query()->create(['key' => 'evaluation_prompt_current', 'value' => '{{犬の名前}}のレポートを書いてください。']);
        [$generator, $requests] = $this->fakeClaude('お客様用');

        $generator->generate($result);

        $body = json_decode((string) $requests[0]['request']->getBody(), true);
        $this->assertSame(PersonalityEvaluationPrompt::FORMAT_RULES, $body['system']);
        $this->assertSame([['role' => 'user', 'content' => 'モモのレポートを書いてください。']], $body['messages']);
    }

    public function test_書式の決まりの文(): void
    {
        $this->assertSame(
            "次の決まりを必ず守ってください。\n"
            ."・出力は普通の文章（プレーンテキスト）で書き、Markdown の記号（#、*、**、-、---、>、表など）は使わないでください。\n"
            ."・見出しが必要なときは「【】」で囲んでください。\n"
            ."・箇条書きが必要なときは、行の頭に「・」を付けてください。\n"
            ."・「お客様用レポート」「スタッフ用指示書」「スタッフ用メモ」などの呼び名を見出しとして書かず、それぞれ本文から書き始めてください。\n"
            .'・お客様用レポートとスタッフ用メモの区切りの「#####」だけは、そのまま出力してください。',
            PersonalityEvaluationPrompt::FORMAT_RULES
        );
        // 既定の指示文（トレーナーさんの文）には混ぜない
        $this->assertStringNotContainsString('次の決まりを必ず守ってください。', PersonalityEvaluationPrompt::DEFAULT_TEMPLATE);
    }

    // ---- 下書きのルート ----

    public function test_下書きはJSONで返し何も保存しない(): void
    {
        $result = $this->makeResult();
        $result->update(['evaluation_text' => '保存してあるアドバイス', 'staff_memo' => '保存してあるメモ']);
        $this->fakeGenerator(new EvaluationDraft('お客様用の下書き', 'スタッフ用の下書き', false));

        $this->postDraft($result)
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
            foreach (['claude', 'openai', 'gemini'] as $provider) {
                $this->fakeGenerator(new EvaluationDraft('下書き '.$provider, 'メモ', false), $provider);
                $this->postDraft($result, $provider)->assertOk()->assertJsonPath('evaluation', '下書き '.$provider);
            }
        }
        $this->assertSame('公開した文', $result->fresh()->evaluation_text);
    }

    public function test_ログインしていなければ作れない(): void
    {
        $result = $this->makeResult();
        $this->fakeGenerator(new EvaluationDraft('下書き', 'メモ', false));
        auth('web')->logout();

        $calls = $this->fakeGenerator(new EvaluationDraft('下書き', 'メモ', false));

        $response = $this->postDraft($result);

        $this->assertContains($response->getStatusCode(), [302, 401]);
        $this->assertCount(0, $calls);
    }

    public function test_ほかのトレーニーの結果は弾く(): void
    {
        $other = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'タマ']);
        $result = $this->makeResult(trainee: $other);
        $this->fakeGenerator(new EvaluationDraft('下書き', 'メモ', false));

        $this->postDraft($result, 'claude', $this->trainee)->assertNotFound();
    }

    public function test_AIの失敗のときは決まった文のエラーを返す_AIで自動生成できませんでした(): void
    {
        $result = $this->makeResult();
        $this->fakeGenerator(new EvaluationDraftFailedException('timeout'));

        $this->postDraft($result)
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AIで自動生成できませんでした。時間をおいてもう一度お試しください。']);
        $this->assertNull($result->fresh()->evaluation_text);
    }

    public function test_区切りがなかったことをJSONで伝える(): void
    {
        $result = $this->makeResult();
        $this->fakeGenerator(EvaluationDraft::fromResponse('全部お客様用'));

        $this->postDraft($result)
            ->assertOk()
            ->assertExactJson(['evaluation' => '全部お客様用', 'staff_memo' => '', 'staff_memo_missing' => true]);
    }

    // ---- 3 社の AI（2026-10-20 追加：しばらく Claude・OpenAI・Gemini を比べながら使い、最終的に 1 つに絞るため） ----

    public function test_providerごとにその会社の実装を呼ぶ(): void
    {
        $result = $this->makeResult();
        $calls = [];
        foreach (['claude', 'openai', 'gemini'] as $provider) {
            $calls[$provider] = $this->fakeGenerator(new EvaluationDraft('お客様用 '.$provider, 'メモ '.$provider, false), $provider);
        }

        foreach (['claude', 'openai', 'gemini'] as $provider) {
            $this->postDraft($result, $provider)->assertOk()
                ->assertExactJson(['evaluation' => 'お客様用 '.$provider, 'staff_memo' => 'メモ '.$provider, 'staff_memo_missing' => false]);
        }
        foreach ($calls as $provider => $list) {
            $this->assertCount(1, $list, $provider);
        }
        // 対応表は 1 か所（AI の名前 → ボタンの名前・実装）。並びはボタンの並び
        $this->assertSame(['claude' => 'Claude', 'openai' => 'OpenAI', 'gemini' => 'Gemini'], app(EvaluationDraftProviders::class)->available());
        $this->assertSame(ClaudeEvaluationDraftGenerator::class, EvaluationDraftProviders::PROVIDERS['claude']['class']);
        $this->assertSame(OpenAiEvaluationDraftGenerator::class, EvaluationDraftProviders::PROVIDERS['openai']['class']);
        $this->assertSame(GeminiEvaluationDraftGenerator::class, EvaluationDraftProviders::PROVIDERS['gemini']['class']);
        // AI の会社を選ぶ設定（EVALUATION_DRAFT_DRIVER）は外した
        $this->assertNull(config('services.evaluation_draft'));
    }

    public function test_決まった3つ以外と設定が空のAIはAIを呼ばずにエラー(): void
    {
        $result = $this->makeResult();
        $calls = [];
        foreach (['claude', 'openai', 'gemini'] as $provider) {
            $calls[$provider] = $this->fakeGenerator(new EvaluationDraft('下書き', 'メモ', false), $provider);
        }
        $message = ['message' => 'AIで自動生成できませんでした。時間をおいてもう一度お試しください。'];

        foreach (['anthropic', 'gpt', 'CLAUDE', '', null, ['claude']] as $provider) {
            $this->postDraft($result, $provider)->assertStatus(422)->assertExactJson($message);
        }
        // キーかモデルが空の AI
        config(['services.openai.evaluation_model' => '', 'services.gemini.api_key' => null]);
        $this->postDraft($result, 'openai')->assertStatus(422)->assertExactJson($message);
        $this->postDraft($result, 'gemini')->assertStatus(422)->assertExactJson($message);
        $this->assertSame(['claude' => 'Claude'], app(EvaluationDraftProviders::class)->available());
        config(['services.anthropic.api_key' => '']);
        $this->postDraft($result, 'claude')->assertStatus(422)->assertExactJson($message);

        foreach ($calls as $provider => $list) {
            $this->assertCount(0, $list, $provider);
        }
        $this->assertNull($result->fresh()->evaluation_text);
    }

    /**
     * HTTP の送り手を差し替えた OpenAI の実装と、送った要求の記録（外部の AI は呼ばない）
     *
     * @return array{0: OpenAiEvaluationDraftGenerator, 1: \ArrayObject}
     */
    private function fakeOpenAi(string $text, int $status = 200): array
    {
        $requests = new \ArrayObject;
        $body = $status === 200
            ? [
                'id' => 'chatcmpl-test', 'object' => 'chat.completion', 'created' => 1760000000, 'model' => 'openai-test',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 22, 'total_tokens' => 33],
            ]
            : ['error' => ['message' => $text, 'type' => 'invalid_request_error', 'code' => null, 'param' => null]];
        $stack = HandlerStack::create(new MockHandler([new Response($status, ['Content-Type' => 'application/json'], json_encode($body))]));
        $stack->push(Middleware::history($requests));

        return [new OpenAiEvaluationDraftGenerator(new GuzzleClient(['handler' => $stack])), $requests];
    }

    public function test_OpenAIはsystemに書式の決まりuserに指示文を入れて長さの上限を付けて送る(): void
    {
        $result = $this->makeResult();
        [$generator, $requests] = $this->fakeOpenAi("## お客様用レポート\n**モモちゃん**は慎重派です。\n#####\n**スタッフ用メモ**\n- 吠えに注意");

        $draft = $generator->generate($result);

        $request = $requests[0]['request'];
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('openai-test', $body['model']);
        $this->assertSame([
            ['role' => 'system', 'content' => PersonalityEvaluationPrompt::FORMAT_RULES],
            ['role' => 'user', 'content' => PersonalityEvaluationPrompt::render($result)],
        ], $body['messages']);
        $this->assertSame(3000, $body['max_completion_tokens']);
        $this->assertStringNotContainsString('次の決まりを必ず守ってください。', $body['messages'][1]['content']);
        // キーは Authorization ヘッダー（URL には入れない）
        $this->assertSame('Bearer test-openai-key', $request->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString('test-openai-key', (string) $request->getUri());
        $this->assertStringEndsWith('/chat/completions', (string) $request->getUri());
        // 返事の後始末は 3 社とも同じ（「#####」で分ける → Markdown → 呼び名の行）
        $this->assertSame('モモちゃんは慎重派です。', $draft->evaluation);
        $this->assertSame('・吠えに注意', $draft->staffMemo);
        $this->assertFalse($draft->staffMemoMissing);

        // 指示文を設定画面で変えても、system の書式の決まりは付く
        SystemSetting::query()->create(['key' => 'evaluation_prompt_current', 'value' => '{{犬の名前}}のレポートを書いてください。']);
        [$generator, $requests] = $this->fakeOpenAi('お客様用');
        $generator->generate($result);
        $body = json_decode((string) $requests[0]['request']->getBody(), true);
        $this->assertSame(PersonalityEvaluationPrompt::FORMAT_RULES, $body['messages'][0]['content']);
        $this->assertSame('モモのレポートを書いてください。', $body['messages'][1]['content']);
    }

    public function test_Geminiはキーをヘッダーで送りURLに入れずsystemInstructionに書式の決まりを入れる(): void
    {
        $result = $this->makeResult();
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [
                        ['text' => '考えた過程（出さない）', 'thought' => true],
                        ['text' => "【お客様用レポート】\n**モモちゃん**は慎重派です。\n#####\n## スタッフ用メモ\n* 吠えに注意"],
                    ]],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 11, 'candidatesTokenCount' => 22, 'thoughtsTokenCount' => 5],
            ]),
        ]);

        $draft = app(GeminiEvaluationDraftGenerator::class)->generate($result);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($result) {
            $this->assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-test:generateContent', $request->url());
            // キーは URL に入れず、ヘッダー x-goog-api-key で送る
            $this->assertStringNotContainsString('test-gemini-key', $request->url());
            $this->assertStringNotContainsString('key=', $request->url());
            $this->assertSame(['test-gemini-key'], $request->header('x-goog-api-key'));
            $this->assertSame([], $request->header('Authorization'));
            $this->assertSame(['parts' => [['text' => PersonalityEvaluationPrompt::FORMAT_RULES]]], $request['systemInstruction']);
            $this->assertSame([['role' => 'user', 'parts' => [['text' => PersonalityEvaluationPrompt::render($result)]]]], $request['contents']);
            $this->assertSame(3000, $request['generationConfig']['maxOutputTokens']);

            return true;
        });
        // 考えた過程は入れず、返事の後始末は 3 社とも同じ
        $this->assertSame('モモちゃんは慎重派です。', $draft->evaluation);
        $this->assertSame('・吠えに注意', $draft->staffMemo);
    }

    public function test_Geminiも指示文を設定画面で変えるとその文で送り書式の決まりは付く(): void
    {
        $result = $this->makeResult();
        SystemSetting::query()->create(['key' => 'evaluation_prompt_current', 'value' => '{{犬の名前}}のレポートを書いてください。']);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'お客様用']]], 'finishReason' => 'STOP']]])]);

        app(GeminiEvaluationDraftGenerator::class)->generate($result);

        Http::assertSent(function (HttpRequest $request) {
            return $request['systemInstruction']['parts'][0]['text'] === PersonalityEvaluationPrompt::FORMAT_RULES
                && $request['contents'][0]['parts'][0]['text'] === 'モモのレポートを書いてください。';
        });
    }

    public function test_ログにAIの名前とモデルが残り送った文と返ってきた文とキーは残らない(): void
    {
        $result = $this->makeResult(life: ['wish' => '引っ張らずに歩きたい']);
        $logs = [];
        Log::listen(function ($event) use (&$logs) {
            $logs[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });
        $secrets = ['test-openai-key', 'test-gemini-key', 'test-anthropic-key', 'モモ', '引っ張らずに歩きたい', '返ってきたお客様用の文', '返ってきたメモ'];

        // 成功（OpenAI・Gemini）
        [$generator] = $this->fakeOpenAi("返ってきたお客様用の文\n#####\n返ってきたメモ");
        $generator->generate($result);
        // Gemini の返事は、成功 → 失敗（キーを含むエラー）の順
        Http::fakeSequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => "返ってきたお客様用の文\n#####\n返ってきたメモ"]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 2]])
            ->push(['error' => ['message' => 'API key not valid: test-gemini-key']], 400);
        app(GeminiEvaluationDraftGenerator::class)->generate($result);

        $info = array_values(array_filter($logs, fn ($log) => $log['level'] === 'info'));
        $this->assertSame(['openai', 'gemini'], array_column(array_column($info, 'context'), 'provider'));
        $this->assertSame(['openai-test', 'gemini-test'], array_column(array_column($info, 'context'), 'model'));
        $this->assertSame([11, 1], array_column(array_column($info, 'context'), 'input_tokens'));
        $this->assertSame([22, 2], array_column(array_column($info, 'context'), 'output_tokens'));
        foreach (['result_id', 'elapsed_ms', 'stop_reason'] as $key) {
            $this->assertArrayHasKey($key, $info[0]['context']);
            $this->assertArrayHasKey($key, $info[1]['context']);
        }

        // 失敗：エラーの中にキーが入っていても伏せる
        $logs = [];
        [$generator] = $this->fakeOpenAi('Incorrect API key provided: test-openai-key', 401);
        try {
            $generator->generate($result);
            $this->fail('例外にならなかった');
        } catch (EvaluationDraftFailedException) {
        }
        try {
            app(GeminiEvaluationDraftGenerator::class)->generate($result);
            $this->fail('例外にならなかった');
        } catch (EvaluationDraftFailedException) {
        }
        $errors = array_values(array_filter($logs, fn ($log) => $log['level'] === 'error'));
        $this->assertSame(['openai', 'gemini'], array_column(array_column($errors, 'context'), 'provider'));
        $this->assertStringContainsString('[REDACTED]', $errors[0]['context']['error']);
        $this->assertStringContainsString('[REDACTED]', $errors[1]['context']['error']);

        $this->assertNotEmpty($logs);
        foreach ([...$info, ...$errors] as $log) {
            $all = $log['message'].json_encode($log['context'], JSON_UNESCAPED_UNICODE);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $all, $secret);
            }
        }
    }

    public function test_Claudeのログにも名前が残る(): void
    {
        $result = $this->makeResult();
        $logs = [];
        Log::listen(function ($event) use (&$logs) {
            $logs[] = $event->context;
        });
        [$generator] = $this->fakeClaude('お客様用');

        $generator->generate($result);

        $this->assertSame('claude', $logs[0]['provider']);
        $this->assertSame('claude-test', $logs[0]['model']);
    }

    public function test_GeminiとOpenAIの返事が空なら失敗(): void
    {
        $result = $this->makeResult();
        [$generator] = $this->fakeOpenAi('');
        try {
            $generator->generate($result);
            $this->fail('例外にならなかった');
        } catch (EvaluationDraftFailedException) {
        }
        // 考えた過程だけで答えがない（出す長さの上限に届いたときなど）
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '考えた過程', 'thought' => true]]], 'finishReason' => 'MAX_TOKENS']]])]);
        $this->expectException(EvaluationDraftFailedException::class);
        app(GeminiEvaluationDraftGenerator::class)->generate($result);
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

<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\TraineePersonalityDiagnosisToken;
use App\Support\PersonalityDiagnosisQuestions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 性格診断の回答（S-1415 回答画面・送信、S-1416 回答完了。要件定義書 6-15-18。2026-10 追加。性格診断 段階3）。
 *
 * - 回答の画面：見出し・説明文（愛犬の名前は使わない）・7 カテゴリの見出し・50 問の fieldset／legend・選択肢 6 つ・初期値なし
 * - 送信：採点して結果を保存し、案内を使い切りにして S-1416 へ。トレーニーは書き換えない
 * - 未回答・正しくない値・回答済み・取消済みのときは保存しない
 */
class ClientPersonalityDiagnosisTest extends TestCase
{
    use CreatesClientCountTables;

    private const TOKEN = 'personalitydiagnosistoken0000001';

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-16 14:32:00');

        $this->createClientsTable();
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->string('breed', 100)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        $this->trainee = Trainee::query()->create(['client_id' => $client->id, 'name' => 'モモ', 'breed' => '柴']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function issueToken(?string $answeredAt = null): int
    {
        return DB::table('trainee_personality_diagnosis_tokens')->insertGetId([
            'token' => self::TOKEN, 'trainee_id' => $this->trainee->id, 'answered_at' => $answeredAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 固定データの 1 件（回答と期待値） */
    private function fixtureCase(string $name): array
    {
        $json = json_decode(file_get_contents(__DIR__.'/../fixtures/personality-diagnosis-cases.json'), true, 512, JSON_THROW_ON_ERROR);

        return collect($json['cases'])->firstWhere('name', $name);
    }

    private function showPage(): string
    {
        return $this->get(route('client-portal.personality-diagnosis.show', ['token' => self::TOKEN]))->assertOk()->getContent();
    }

    private function submit(array $answers): TestResponse
    {
        return $this->from(route('client-portal.personality-diagnosis.show', ['token' => self::TOKEN]))
            ->post(route('client-portal.personality-diagnosis.store', ['token' => self::TOKEN]), ['answers' => $answers]);
    }

    // ---- 回答の画面 ----

    public function test_有効な案内なら回答の画面を出す(): void
    {
        $this->issueToken();

        $html = $this->showPage();

        $this->assertStringContainsString('<h1 class="mb-2">性格診断</h1>', $html);
        $this->assertStringContainsString('愛犬についてお答えください。ふだんの様子に近いものを選んでください。その状況になったことがなければ「未経験」を選んでください。', $html);
        // 愛犬の名前・お客様の氏名は出さない
        $this->assertStringNotContainsString('モモ', $html);
        $this->assertStringNotContainsString('山田', $html);
        // 7 カテゴリの見出し（この順）
        $this->assertMatchesRegularExpression('/>対人<\/h2>.*>対犬<\/h2>.*>所有欲<\/h2>.*>分離不安<\/h2>.*>恐怖心<\/h2>.*>訓練性能<\/h2>.*>興奮性<\/h2>/su', $html);
        // 50 問の fieldset／legend と、各問の選択肢 6 つ
        $this->assertSame(50, substr_count($html, '<fieldset class="mb-3" id="q-Q'));
        $this->assertStringContainsString('<legend class="fs-6 mb-2">①知らない人が家に入ってきた時に吠える・唸る</legend>', $html);
        $this->assertStringContainsString('<legend class="fs-6 mb-2">⑧ 散歩から帰ってきても、体力が有り余っていてすぐに遊びたがる</legend>', $html);
        $this->assertSame(300, substr_count($html, 'type="radio" class="btn-check" name="answers['));
        $this->assertSame(6, substr_count($html, 'name="answers[Q01]"'));
        foreach (PersonalityDiagnosisQuestions::choices() as $value => $label) {
            $this->assertStringContainsString('id="answer-Q01-'.$value.'" value="'.$value.'"', $html);
            $this->assertStringContainsString('<label class="c-scale-choice-label" for="answer-Q01-'.$value.'">'.$label.'</label>', $html);
        }
        // 初期値はなし
        $this->assertStringNotContainsString('checked', $html);
        // 送信先と［送信する］
        $this->assertStringContainsString('action="'.route('client-portal.personality-diagnosis.store', ['token' => self::TOKEN]).'"', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-primary">送信する</button>', $html);
    }

    public function test_プライバシーポリシーの同意文は設定があるときだけ出す(): void
    {
        $this->issueToken();

        config(['app.client_portal_privacy_url' => 'https://example.com/privacy']);
        $this->assertStringContainsString('https://example.com/privacy', $this->showPage());

        config(['app.client_portal_privacy_url' => null]);
        $this->assertStringNotContainsString('プライバシーポリシー', $this->showPage());
    }

    public function test_回答済みの案内ならすでに回答の画面(): void
    {
        $this->issueToken('2026-10-15 09:00:00');

        $html = $this->showPage();

        $this->assertStringContainsString('この性格診断はすでにご回答いただいています。', $html);
        $this->assertStringNotContainsString('<fieldset', $html);
    }

    public function test_存在しない案内や取消済みの案内なら使えない画面(): void
    {
        $html = $this->showPage();

        $this->assertStringContainsString('<h1 class="mb-4">性格診断</h1>', $html);
        $this->assertStringContainsString('この性格診断はご利用いただけません。お手数ですがトレーナーにお問い合わせください。', $html);
        $this->assertStringNotContainsString('<fieldset', $html);
    }

    // ---- 送信 ----

    public function test_全問答えると採点して結果を保存しS1416へ移る(): void
    {
        $tokenId = $this->issueToken();
        $case = $this->fixtureCase('past-1');

        $response = $this->submit($case['answers']);

        $response->assertRedirect(route('client-portal.personality-diagnosis.thanks'));
        $result = TraineePersonalityDiagnosisResult::query()->sole();
        $this->assertSame($this->trainee->id, (int) $result->trainee_id);
        $this->assertSame($tokenId, (int) $result->trainee_personality_diagnosis_token_id);
        $this->assertSame('2026-10-16 14:32:00', $result->answered_at->format('Y-m-d H:i:s'));
        $this->assertSame('v1', $result->question_version);
        $this->assertSame($case['answers'], $result->answers);
        foreach ($case['expected']['scores'] as $category => $score) {
            $this->assertSame($score, $result->{'score_'.$category}, $category);
        }
        $this->assertSame($case['expected']['type'], $result->personality_type);
        // 案内は使い切り
        $this->assertSame('2026-10-16 14:32:00', TraineePersonalityDiagnosisToken::query()->findOrFail($tokenId)->answered_at->format('Y-m-d H:i:s'));
        // トレーニーは書き換えない
        $fresh = $this->trainee->fresh();
        $this->assertSame('モモ', $fresh->name);
        $this->assertSame('柴', $fresh->breed);
        $this->assertEquals($this->trainee->updated_at, $fresh->updated_at);
    }

    public function test_回答は文字列で送られても0から4は整数で保存する(): void
    {
        $this->issueToken();
        $answers = array_map(fn ($v) => (string) $v, $this->fixtureCase('past-2')['answers']);

        $this->submit($answers)->assertRedirect(route('client-portal.personality-diagnosis.thanks'));

        $this->assertSame($this->fixtureCase('past-2')['answers'], TraineePersonalityDiagnosisResult::query()->sole()->answers);
    }

    public function test_未回答があれば保存せずまとめと各問のエラーを出し答えた分を残す(): void
    {
        $this->issueToken();
        $answers = $this->fixtureCase('past-1')['answers'];
        unset($answers['Q05'], $answers['Q30'], $answers['Q50']);

        $response = $this->submit($answers);

        $response->assertRedirect(route('client-portal.personality-diagnosis.show', ['token' => self::TOKEN]));
        $this->assertSame(0, TraineePersonalityDiagnosisResult::query()->count());
        $this->assertNull(TraineePersonalityDiagnosisToken::query()->sole()->answered_at);

        $html = $this->followRedirects($response)->getContent();
        $this->assertStringContainsString('まだ答えていない質問が 3 問あります。', $html);
        $this->assertStringContainsString('<a href="#q-Q05" class="alert-link">最初の未回答の質問へ</a>', $html);
        $this->assertStringContainsString('<fieldset class="mb-3" id="q-Q05"  aria-describedby="answers-Q05-error" >', $html);
        $this->assertStringContainsString('<div class="invalid-feedback d-block" id="answers-Q05-error">選んでください。</div>', $html);
        $this->assertSame(3, substr_count($html, '>選んでください。</div>'));
        // 答えた分は選ばれたまま（Q01 は na、Q02 は 4）
        $this->assertMatchesRegularExpression('/id="answer-Q01-na" value="na" autocomplete="off"\s*checked>/u', $html);
        $this->assertMatchesRegularExpression('/id="answer-Q02-4" value="4" autocomplete="off"\s*checked>/u', $html);
        $this->assertSame(47, substr_count($html, 'checked>'));
    }

    public function test_正しくない値は保存しない(): void
    {
        $this->issueToken();
        foreach (['5', 'abc', '-1'] as $bad) {
            $answers = $this->fixtureCase('all-zero')['answers'];
            $answers['Q10'] = $bad;

            $this->submit($answers)->assertSessionHasErrors(['answers.Q10' => '選択された値は有効ではありません。']);
        }
        $this->assertSame(0, TraineePersonalityDiagnosisResult::query()->count());
    }

    public function test_回答済みの案内に送ると保存せずすでに回答の画面(): void
    {
        $this->issueToken();
        $answers = $this->fixtureCase('all-zero')['answers'];
        $this->submit($answers)->assertRedirect(route('client-portal.personality-diagnosis.thanks'));

        $response = $this->submit($answers);

        $response->assertOk();
        $response->assertSee('この性格診断はすでにご回答いただいています。');
        $this->assertSame(1, TraineePersonalityDiagnosisResult::query()->count());
    }

    public function test_取消された案内に送ると保存せず使えない画面(): void
    {
        $this->issueToken();
        DB::table('trainee_personality_diagnosis_tokens')->delete();

        $response = $this->submit($this->fixtureCase('all-zero')['answers']);

        $response->assertOk();
        $response->assertSee('この性格診断はご利用いただけません。お手数ですがトレーナーにお問い合わせください。');
        $this->assertSame(0, TraineePersonalityDiagnosisResult::query()->count());
    }

    // ---- 回答完了 ----

    public function test_送信のあとに回答完了を出し直接開いても同じ文面(): void
    {
        $this->issueToken();
        $response = $this->submit($this->fixtureCase('all-zero')['answers']);

        $html = $this->followRedirects($response)->getContent();
        // 受付アンケートの回答完了（S-1414）と同じ作り・同じ文言（2026-10 変更）
        $this->assertStringContainsString('<h1 class="mb-4">ご回答ありがとうございました</h1>', $html);
        $this->assertStringContainsString('<p>いただいた内容は、トレーニングの参考にさせていただきます。</p>', $html);
        $this->assertStringContainsString('<p class="mb-0">このページは閉じていただいて構いません。</p>', $html);
        // 結果も名前も出さない
        $this->assertStringNotContainsString('タイプ', $html);
        $this->assertStringNotContainsString('モモ', $html);

        $direct = $this->get(route('client-portal.personality-diagnosis.thanks'))->assertOk()->getContent();
        $this->assertStringContainsString('<h1 class="mb-4">ご回答ありがとうございました</h1>', $direct);
        $this->assertStringContainsString('このページは閉じていただいて構いません。', $direct);
    }
}

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
use Tests\Concerns\AssertsPersonalityDiagnosisName;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 性格診断の回答（S-1415 回答画面・送信、S-1416 回答完了。要件定義書 6-15-18。2026-10 追加。性格診断 段階3）。
 *
 * - 回答の画面：見出し・説明文（愛犬の名前は使わない）・7 カテゴリの見出し・50 問の fieldset／legend・選択肢 6 つ・初期値なし
 * - 送信：採点して結果を保存し、案内を使い切りにして S-1416 へ。トレーニーは書き換えない
 * - 未回答・正しくない値・回答済み・取消済みのときは保存しない
 * - 暮らしの様子の 4 問（2026-10-19 に愛犬アンケートから移した）：50 問のあとに出し、送信でその結果に保存する。どれも任意・2,000 文字以内。
 *   初期値は空（50 問と同じく、答え直しでも前回の答えは入れない）
 */
class ClientPersonalityDiagnosisTest extends TestCase
{
    use AssertsPersonalityDiagnosisName;
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
        // 完了の画面のマイページ登録のお誘いで、会員に有効なマイページ登録の案内があるかを見る（2026-10-19 追加）
        $this->createClientEmailRegistrationTokensTable();
        // 愛犬アンケート＆愛犬性格診断の案内か（進み具合 2/2 を出すか）を、愛犬アンケートの案内の表で確かめる（2026-10-19 追加）
        $this->createTraineeIntakeSurveyTokensTable();
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

    /** @param  array<string, mixed>  $life  暮らしの様子の 4 問（列名 → 答え） */
    private function submit(array $answers, array $life = []): TestResponse
    {
        return $this->from(route('client-portal.personality-diagnosis.show', ['token' => self::TOKEN]))
            ->post(route('client-portal.personality-diagnosis.store', ['token' => self::TOKEN]), ['answers' => $answers] + $life);
    }

    // ---- 回答の画面 ----

    public function test_有効な案内なら回答の画面を出す(): void
    {
        $this->issueToken();

        $html = $this->showPage();

        $this->assertStringContainsString('<h1 class="mb-2">愛犬性格診断</h1>', $html);
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
        // Q11 は文末を直した文（2026-10-18 変更：当初は「…喧嘩になり」）
        $this->assertStringContainsString('<legend class="fs-6 mb-2">③ドッグランなどで他の犬に追いかけられると、本気で怒って喧嘩になる</legend>', $html);
        $this->assertStringNotContainsString('喧嘩になり', $html);
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

        $this->assertStringContainsString('この愛犬性格診断はすでにご回答いただいています。', $html);
        $this->assertStringNotContainsString('<fieldset', $html);
    }

    public function test_存在しない案内や取消済みの案内なら使えない画面(): void
    {
        $html = $this->showPage();

        $this->assertStringContainsString('<h1 class="mb-4">愛犬性格診断</h1>', $html);
        $this->assertStringContainsString('この愛犬性格診断はご利用いただけません。お手数ですがトレーナーにお問い合わせください。', $html);
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
        $response->assertSee('この愛犬性格診断はすでにご回答いただいています。');
        $this->assertSame(1, TraineePersonalityDiagnosisResult::query()->count());
    }

    public function test_取消された案内に送ると保存せず使えない画面(): void
    {
        $this->issueToken();
        DB::table('trainee_personality_diagnosis_tokens')->delete();

        $response = $this->submit($this->fixtureCase('all-zero')['answers']);

        $response->assertOk();
        $response->assertSee('この愛犬性格診断はご利用いただけません。お手数ですがトレーナーにお問い合わせください。');
        $this->assertSame(0, TraineePersonalityDiagnosisResult::query()->count());
    }

    // ---- 暮らしの様子の 4 問（2026-10-19 追加。愛犬アンケートから移した。設計書 S-1415「暮らしの様子」） ----

    public function test_50問のあとに小見出し暮らしの様子と4問を出しプライバシーと送信の前に置く(): void
    {
        $this->issueToken();

        $html = $this->showPage();

        $heading = '<h2 class="fs-6 fw-bold mb-3 mt-4">暮らしの様子<span class="fw-normal small text-muted">（思いつくことをご自由にお書きください。）</span></h2>';
        $this->assertSame(1, substr_count($html, $heading));
        // 50 問（最後の Q50）のあと、送信の前
        $this->assertGreaterThan(strpos($html, 'id="q-Q50"'), strpos($html, $heading));
        $positions = [];
        foreach (PersonalityDiagnosisQuestions::lifeQuestions() as $column => $question) {
            // 設問の先頭の「●」は読み上げない。textarea（rows 4・2,000 文字以内）。どれも任意（required を付けない）
            $this->assertStringContainsString('<label for="'.$column.'" class="form-label d-flex"><span class="me-1" aria-hidden="true">●</span><span>'.$question['question'].'</span></label>', $html);
            $this->assertMatchesRegularExpression('/<textarea class="form-control\s*"\s+id="'.$column.'" name="'.$column.'" rows="4" maxlength="2000"><\/textarea>/u', $html);
            $positions[] = strpos($html, 'id="'.$column.'"');
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertGreaterThan(strpos($html, $heading), $positions[0]);
        $this->assertLessThan(strpos($html, '<button type="submit" class="btn btn-primary">送信する</button>'), end($positions));
        $this->assertSame(4, substr_count($html, '●'));
        $this->assertStringNotContainsString(' required', substr($html, strpos($html, $heading)));
        $this->assertStringNotContainsString('例：', $html);
    }

    public function test_答え直しでも暮らしの様子の初期値は空(): void
    {
        // 50 問と同じく、前回の結果の答えは入れない
        TraineePersonalityDiagnosisResult::query()->create([
            'trainee_id' => $this->trainee->id, 'answered_at' => '2026-10-01 09:00:00', 'question_version' => 'v1',
            'answers' => $this->fixtureCase('all-zero')['answers'], 'score_people' => 0, 'score_dogs' => 0, 'score_possessiveness' => 0,
            'score_separation' => 0, 'score_fear' => 0, 'score_trainability' => 0, 'score_excitability' => 0, 'personality_type' => 'calm',
            'wish' => '前回の答え', 'health_concerns' => '前回の体のこと',
        ]);
        $this->issueToken();

        $html = $this->showPage();

        $this->assertSame(4, preg_match_all('/rows="4" maxlength="2000"><\/textarea>/u', $html));
        $this->assertStringNotContainsString('前回の答え', $html);
        $this->assertStringNotContainsString('前回の体のこと', $html);
        $this->assertStringNotContainsString('checked', $html);
    }

    public function test_送信で4問をその結果に保存し空や空白だけはnullで改行はLFにそろえる(): void
    {
        $this->issueToken();

        $this->submit($this->fixtureCase('past-1')['answers'], [
            'wish' => "留守番中に鳴き続けてしまう\r\nのを直したい",
            'recent_events' => '',
            'ideal_days' => "混在\r\nCRLF\rCR\nLF",
            'health_concerns' => '   ',
        ])->assertRedirect(route('client-portal.personality-diagnosis.thanks'));

        $result = TraineePersonalityDiagnosisResult::query()->sole();
        $this->assertSame("留守番中に鳴き続けてしまう\nのを直したい", $result->wish);
        $this->assertNull($result->recent_events);
        $this->assertSame("混在\nCRLF\nCR\nLF", $result->ideal_days);
        $this->assertNull($result->health_concerns);
        // 50 問の採点は今までどおり
        $this->assertSame($this->fixtureCase('past-1')['expected']['type'], $result->personality_type);
    }

    public function test_4問とも送らなくても送れて4列はnull(): void
    {
        $this->issueToken();

        $this->submit($this->fixtureCase('all-zero')['answers'])->assertRedirect(route('client-portal.personality-diagnosis.thanks'));

        $result = TraineePersonalityDiagnosisResult::query()->sole();
        foreach (array_keys(PersonalityDiagnosisQuestions::lifeQuestions()) as $column) {
            $this->assertNull($result->{$column}, $column);
        }
        $this->assertFalse($result->hasLifeAnswers());
    }

    public function test_4問は2000文字以内で項目名は設問の短い呼び名(): void
    {
        $this->issueToken();
        $answers = $this->fixtureCase('all-zero')['answers'];
        $life = array_fill_keys(array_keys(PersonalityDiagnosisQuestions::lifeQuestions()), str_repeat('あ', 2001));

        $this->submit($answers, $life)->assertSessionHasErrors([
            'wish' => 'こうなったらいいなは2000文字以内で入力してください。',
            'recent_events' => '最近の様子は2000文字以内で入力してください。',
            'ideal_days' => '理想の毎日は2000文字以内で入力してください。',
            'health_concerns' => '体のことは2000文字以内で入力してください。',
        ]);
        $this->assertSame(0, TraineePersonalityDiagnosisResult::query()->count());

        $this->submit($answers, array_map(fn () => str_repeat('あ', 2000), $life))->assertRedirect(route('client-portal.personality-diagnosis.thanks'));
        $this->assertSame(2000, mb_strlen(TraineePersonalityDiagnosisResult::query()->sole()->wish));
    }

    public function test_4問はCRLFの改行をLFに直してから数える(): void
    {
        // ブラウザの maxlength は改行を 1 文字と数えるが、送信では CRLF（2 文字）になる。LF に直すと 2,000 字、CRLF のままなら 2,009 字
        $this->issueToken();
        $answers = $this->fixtureCase('all-zero')['answers'];
        $text = implode("\r\n", array_fill(0, 10, str_repeat('あ', 199))).'あ';
        $this->assertSame(2009, mb_strlen($text));

        $this->submit($answers, ['wish' => $text.'あ'])->assertSessionHasErrors(['wish' => 'こうなったらいいなは2000文字以内で入力してください。']);
        $this->submit($answers, ['wish' => $text])->assertRedirect(route('client-portal.personality-diagnosis.thanks'));

        $saved = TraineePersonalityDiagnosisResult::query()->sole()->wish;
        $this->assertSame(2000, mb_strlen($saved));
        $this->assertStringNotContainsString("\r", $saved);
    }

    public function test_項目名は設問の短い呼び名と食い違わない(): void
    {
        foreach (PersonalityDiagnosisQuestions::lifeQuestions() as $column => $question) {
            $this->assertSame($question['label'], __('validation.attributes.'.$column));
        }
    }

    public function test_未回答で戻ったとき4問の入力が残る(): void
    {
        $this->issueToken();
        $answers = $this->fixtureCase('past-1')['answers'];
        unset($answers['Q50']);

        $response = $this->submit($answers, ['wish' => "1行目\n2行目", 'ideal_days' => 'ドッグラン']);

        $html = $this->followRedirects($response)->getContent();
        $this->assertStringContainsString('id="wish" name="wish" rows="4" maxlength="2000">'."1行目\n2行目".'</textarea>', $html);
        $this->assertStringContainsString('id="ideal_days" name="ideal_days" rows="4" maxlength="2000">ドッグラン</textarea>', $html);
        $this->assertStringContainsString('id="recent_events" name="recent_events" rows="4" maxlength="2000"></textarea>', $html);
    }

    // ---- 回答完了 ----

    public function test_送信のあとに回答完了を出し直接開いても同じ文面(): void
    {
        $this->issueToken();
        $response = $this->submit($this->fixtureCase('all-zero')['answers']);

        $html = $this->followRedirects($response)->getContent();
        // 愛犬アンケートの回答完了（S-1414）と同じ作り・同じ文言（2026-10 変更）
        $this->assertStringContainsString('<h1 class="mb-4">ご回答ありがとうございました</h1>', $html);
        // 1 文目は契約前のお客様にもそぐう文（2026-10-19 変更：その前は「いただいた内容は、トレーニングの参考にさせていただきます。」）
        $this->assertStringContainsString('<p>いただいた内容は、愛犬に合ったご提案の参考にさせていただきます。</p>', $html);
        $this->assertStringNotContainsString('トレーニングの参考に', $html);
        $this->assertStringContainsString('<p class="mb-0">このページは閉じていただいて構いません。</p>', $html);
        // 結果も名前も出さない
        $this->assertStringNotContainsString('タイプ', $html);
        $this->assertStringNotContainsString('モモ', $html);

        $direct = $this->get(route('client-portal.personality-diagnosis.thanks'))->assertOk()->getContent();
        $this->assertStringContainsString('<h1 class="mb-4">ご回答ありがとうございました</h1>', $direct);
        $this->assertStringContainsString('このページは閉じていただいて構いません。', $direct);
    }

    // ---- 愛犬性格診断の名前（2026-10-18 変更：正式名称に決まった。その前は「性格診断」） ----

    public function test_回答の画面と使えない案内の画面は愛犬性格診断の名前で出し性格診断は単独で出さない(): void
    {
        $this->issueToken();
        $html = $this->showPage();
        $this->assertStringContainsString('<h1 class="mb-2">愛犬性格診断</h1>', $html);
        $this->assertMatchesRegularExpression('/<title>愛犬性格診断/u', $html);
        $this->assertOnlyFullPersonalityDiagnosisName($html, 'S-1415');

        DB::table('trainee_personality_diagnosis_tokens')->update(['answered_at' => '2026-10-15 09:00:00']);
        $html = $this->showPage();
        $this->assertStringContainsString('<h1 class="mb-4">愛犬性格診断</h1>', $html);
        $this->assertStringContainsString('この愛犬性格診断はすでにご回答いただいています。', $html);
        $this->assertOnlyFullPersonalityDiagnosisName($html, '使えない案内');
    }

    public function test_ありがとうの画面は名前を出さず性格診断の文字も出ない(): void
    {
        // S-1416 は見出し「ご回答ありがとうございました」だけで、診断の名前は出さない（今までどおり）
        $html = $this->get(route('client-portal.personality-diagnosis.thanks'))->assertOk()->getContent();
        $this->assertStringNotContainsString('性格診断', $html);
    }

    public function test_回答の画面の名前は文のファイルの1か所から読む(): void
    {
        $this->overridePersonalityDiagnosisName('テスト診断');
        $this->issueToken();

        $html = $this->showPage();

        $this->assertStringContainsString('<h1 class="mb-2">テスト診断</h1>', $html);
        $this->assertStringNotContainsString('性格診断', $html);
    }

    // ---- 有効期限（2026-10-19 追加：案内に 1 週間の有効期限を付けた。「今日」を固定する） ----

    public function test_期限の日の23時59分59秒までは開け翌日0時からは期限切れの画面で送っても保存しない(): void
    {
        $this->issueToken();
        DB::table('trainee_personality_diagnosis_tokens')->update(['expires_at' => '2026-10-26 23:59:59']);

        $this->travelTo(Carbon::parse('2026-10-26 23:59:59.900000'));
        $this->assertStringContainsString('<h1 class="mb-2">愛犬性格診断</h1>', $this->showPage());

        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));
        $html = $this->showPage();
        $this->assertStringContainsString('このリンクは有効期限が切れています。お手数ですがトレーナーにお問い合わせください。', $html);
        $this->assertStringNotContainsString('<fieldset', $html);

        $this->submit($this->fixtureCase('all-zero')['answers'])->assertOk()->assertSee('このリンクは有効期限が切れています。お手数ですがトレーナーにお問い合わせください。');
        $this->assertSame(0, DB::table('trainee_personality_diagnosis_results')->count());
    }

    public function test_期限が空の前からの案内は今までどおり開ける(): void
    {
        $this->issueToken();
        $this->travelTo(Carbon::parse('2030-01-01 10:00:00'));

        $this->assertStringContainsString('<h1 class="mb-2">愛犬性格診断</h1>', $this->showPage());
    }
}

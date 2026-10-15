<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyResponse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 受付アンケート回答画面（S-1413）・回答完了画面（S-1414）・使えない URL の画面（2026-10 追加。
 * 要件定義書 6-15-17）の表示。
 *
 * ログインしない画面。テストでは会員側のホスト（CLIENT_HOST）が空なので、ホストではなくパスで確かめる。
 */
class ClientIntakeSurveyShowTest extends TestCase
{
    use CreatesClientCountTables;

    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

    protected function setUp(): void
    {
        parent::setUp();

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
        $this->createTraineeIntakeSurveyResponsesTable();
    }

    private function traineeWithToken(?string $name, ?string $answeredAt = null, array $attributes = []): Trainee
    {
        $client = Client::query()->create([
            'internal_id' => '0001', 'last_name' => '山田', 'first_name' => '花子',
            'phone1' => '090-1111-2222', 'address3' => '桜町1-2-3',
        ]);
        $trainee = Trainee::query()->create(array_merge(['client_id' => $client->id, 'name' => $name], $attributes));
        DB::table('trainee_intake_survey_tokens')->insert([
            'token' => self::TOKEN, 'trainee_id' => $trainee->id, 'answered_at' => $answeredAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $trainee;
    }

    private function showPath(string $token = self::TOKEN): string
    {
        return '/client-portal/intake-survey/'.$token;
    }

    // ---- ルート ----

    public function test_ルート名からパスを作れる(): void
    {
        $this->assertSame('/client-portal/intake-survey/abc', parse_url(route('client-portal.intake-survey.show', ['token' => 'abc']), PHP_URL_PATH));
        $this->assertSame('/client-portal/intake-survey/abc', parse_url(route('client-portal.intake-survey.store', ['token' => 'abc']), PHP_URL_PATH));
        $this->assertSame('/client-portal/intake-survey/thanks', parse_url(route('client-portal.intake-survey.thanks'), PHP_URL_PATH));
    }

    public function test_thanksはトークンとして扱われず完了画面になる(): void
    {
        $response = $this->get('/client-portal/intake-survey/thanks');

        $response->assertOk();
        $response->assertSee('ご回答ありがとうございました');
        $response->assertDontSee('このアンケートはご利用いただけません。');
    }

    // ---- 回答画面 ----

    public function test_認証なしで開ける(): void
    {
        $this->traineeWithToken('モモ');
        $this->assertGuest('web');
        $this->assertGuest('client');

        $this->get($this->showPath())->assertOk()->assertSee('受付アンケート');
    }

    public function test_名前が登録済みでも説明文に名前を使わず名前は初期値の入った必須の入力欄(): void
    {
        $this->traineeWithToken('モモ');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="mb-2">受付アンケート</h1>', $html);
        // 説明文に愛犬の名前は使わない（2026-10 変更。設計書 S-1413）
        $this->assertStringContainsString('<p class="mb-4">愛犬のことを教えてください。トレーニングの参考にさせていただきます。</p>', $html);
        $this->assertStringNotContainsString('モモちゃん', $html);
        // 名前は表示だけの行ではなく、常に必須の入力欄（2026-10 変更）。初期値はトレーニーの名前
        $this->assertMatchesRegularExpression('/<label for="name" class="form-label">名前 <span class="text-danger">\*<\/span><\/label>/u', $html);
        $this->assertMatchesRegularExpression('/id="name" name="name" required maxlength="50" autocomplete="off"\s*value="モモ"/u', $html);
        $this->assertStringNotContainsString('<div class="text-muted small">名前</div>', $html);
    }

    public function test_名前が未登録なら説明文は愛犬で名前は空の必須の入力欄(): void
    {
        $this->traineeWithToken(null);

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertStringContainsString('愛犬のことを教えてください。', $html);
        $this->assertStringNotContainsString('愛犬ちゃん', $html);
        $this->assertMatchesRegularExpression('/<label for="name" class="form-label">名前 <span class="text-danger">\*<\/span><\/label>/u', $html);
        $this->assertMatchesRegularExpression('/id="name" name="name" required maxlength="50" autocomplete="off"\s*value=""/u', $html);
    }

    public function test_トレーナーが入れた犬種と誕生日を初期値で出す(): void
    {
        $this->traineeWithToken('モモ', null, ['breed' => 'トイプードル', 'birth_year' => 2020, 'birth_month' => 3, 'birth_day' => 15]);

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="breed" name="breed" maxlength="100" autocomplete="off"\s*value="トイプードル"/u', $html);
        $this->assertMatchesRegularExpression('/data-birthday-part="year"[^>]*value="2020"/u', $html);
        $this->assertMatchesRegularExpression('/data-birthday-part="month"[^>]*value="3"/u', $html);
        $this->assertMatchesRegularExpression('/data-birthday-part="day"[^>]*value="15"/u', $html);
    }

    /**
     * 誕生日の 3 つの欄の初期値（年月日／年月／年／未登録）
     */
    public function test_誕生日の初期値は登録されている部分だけ(): void
    {
        $cases = [
            [[2020, 3, 15], ['2020', '3', '15']],
            [[2020, 3, null], ['2020', '3', '']],
            [[2020, null, null], ['2020', '', '']],
            [[null, null, null], ['', '', '']],
        ];
        $this->traineeWithToken('モモ');
        foreach ($cases as [$stored, $expected]) {
            DB::table('trainees')->update(['birth_year' => $stored[0], 'birth_month' => $stored[1], 'birth_day' => $stored[2]]);

            $html = $this->get($this->showPath())->assertOk()->getContent();

            foreach (['year', 'month', 'day'] as $i => $part) {
                $this->assertMatchesRegularExpression('/data-birthday-part="'.$part.'"[^>]*value="'.$expected[$i].'"/u', $html, json_encode($stored));
            }
        }
    }

    /**
     * 性別・去勢・避妊の初期値：トレーニーの値を選んだ状態。未登録ならどれも選ばない
     */
    public function test_性別と去勢避妊の初期値はトレーニーの値を選んだ状態(): void
    {
        $this->traineeWithToken('モモ');
        $cases = [
            'sex' => ['male', 'female', 'unknown', null],
            'neuter_status' => ['neutered', 'not_neutered', 'unknown', null],
        ];
        foreach ($cases as $column => $values) {
            foreach ($values as $stored) {
                DB::table('trainees')->update(['sex' => null, 'neuter_status' => null, $column => $stored]);

                $html = $this->get($this->showPath())->assertOk()->getContent();

                preg_match_all('/name="'.$column.'" id="[^"]+" value="([^"]+)"\s*checked/u', $html, $m);
                $this->assertSame($stored === null ? [] : [$stored], $m[1], $column.'='.var_export($stored, true));
            }
        }
    }

    public function test_小見出しと区切り線と送信先(): void
    {
        $this->traineeWithToken('モモ');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertStringContainsString('<div class="c-login c-login--wide">', $html);
        // 小見出し「愛犬のこと」は置かない（2026-10 変更。説明文と重なるため）。カードは名前の欄から始まる
        $this->assertStringNotContainsString('>愛犬のこと<', $html);
        $this->assertSame(1, substr_count($html, '<h2 '));
        $this->assertMatchesRegularExpression('/novalidate>\s*<input type="hidden" name="_token"[^>]*>\s*<div class="mb-3">\s*<label for="name"/u', $html);
        // 「暮らしの様子」の同じ行の後ろに控えめなカッコ書き。「どれも任意です。…」の 1 行はなくした（2026-10 変更）
        $this->assertStringContainsString('<h2 class="fs-6 fw-bold mb-3">暮らしの様子<span class="fw-normal small text-muted">（思いつくことをご自由にお書きください。）</span></h2>', $html);
        $this->assertStringNotContainsString('どれも任意です', $html);
        // 区切り線は 1 本だけ（去勢・避妊のまとまりの下）
        $this->assertSame(1, substr_count($html, 'border-bottom'));
        $this->assertLessThan(strpos($html, '暮らしの様子'), strpos($html, 'border-bottom'));
        $action = preg_quote(route('client-portal.intake-survey.store', ['token' => self::TOKEN]), '/');
        $this->assertMatchesRegularExpression('/<form method="POST" action="'.$action.'" novalidate>/u', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-primary">送信する</button>', $html);
    }

    public function test_性別と去勢避妊はラジオボタンで不明を置き分からないは置かない(): void
    {
        $this->traineeWithToken('モモ');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertStringContainsString('<div class="c-choice" role="radiogroup" aria-labelledby="sex-label">', $html);
        $this->assertStringContainsString('<div class="c-choice" role="radiogroup" aria-labelledby="neuter_status-label">', $html);
        foreach (['sex' => ['male' => '男の子', 'female' => '女の子', 'unknown' => '不明'], 'neuter_status' => ['neutered' => 'している', 'not_neutered' => 'していない', 'unknown' => '不明']] as $name => $options) {
            foreach ($options as $value => $label) {
                $this->assertMatchesRegularExpression('/type="radio"\s+name="'.$name.'" id="'.$name.'-'.$value.'" value="'.$value.'"/u', $html);
                $this->assertStringContainsString('<label class="form-check-label" for="'.$name.'-'.$value.'">'.$label.'</label>', $html);
            }
        }
        $this->assertStringNotContainsString('dont_know', $html);
        $this->assertStringNotContainsString('分からない', $html);
        // トレーニーの性別・去勢・避妊が未登録なら、何も選ばれていない
        $this->assertStringNotContainsString('checked', $html);
    }

    /**
     * 性別・去勢・避妊の選択肢は 1 行に横並び（2026-10 変更。設計書 S-1413「選択肢の並び」）。
     * 見た目はブラウザで確かめる。ここではマークアップ（.c-choice の直下に選択肢が並ぶこと）と、
     * ビルドした CSS に横並び・折り返しの指定があることだけを確かめる
     */
    public function test_選択肢はc_choiceの直下に横並びで並び折り返せる(): void
    {
        $this->traineeWithToken('モモ');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        foreach (['sex', 'neuter_status'] as $name) {
            // 選択肢（.form-check）が 3 つ、.c-choice の直下に並ぶ（行や列で囲まない）
            $group = $this->radioGroup($html, $name);
            $this->assertSame(3, substr_count($group, '<div class="form-check">'), $name);
            $this->assertMatchesRegularExpression('/aria-labelledby="'.$name.'-label">\s*<div class="form-check">/u', $group);
        }

        // ビルドした会員側の CSS に、横並び・折り返し・選択肢の間隔の指定がある
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $css = file_get_contents(public_path('build/'.$manifest['resources/sass/client.scss']['file']));
        $this->assertStringContainsString('.c-choice{display:flex;flex-wrap:wrap;column-gap:1.5rem}', $css);
        $this->assertMatchesRegularExpression('/\.c-choice \.form-check\{[^}]*min-height:2\.75rem/', $css);
        $this->assertMatchesRegularExpression('/\.c-choice \.form-check-label\{[^}]*white-space:nowrap/', $css);
    }

    /** ラジオボタンのまとまり（.c-choice）の中身 */
    private function radioGroup(string $html, string $name): string
    {
        $start = strpos($html, '<div class="c-choice" role="radiogroup" aria-labelledby="'.$name.'-label">');
        $this->assertNotFalse($start, $name);
        $end = strpos($html, '<div id="', $start + 1);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    public function test_誕生日はトレーナー側と同じ3つの欄と入力中の表示の部品(): void
    {
        $this->traineeWithToken('モモ');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertStringContainsString('data-birthday-input', $html);
        foreach (['year' => 4, 'month' => 2, 'day' => 2] as $part => $max) {
            $this->assertMatchesRegularExpression('/id="birth_'.$part.'" name="birth_'.$part.'" inputmode="numeric" maxlength="'.$max.'" data-birthday-part="'.$part.'"/u', $html);
        }
        $this->assertStringContainsString('<span class="ms-2" data-birthday-display aria-live="polite"></span>', $html);
        $this->assertStringContainsString('<div id="birth_date-help" class="form-text">※ 年だけ、または年と月だけでも入力できます。</div>', $html);
        $this->assertStringNotContainsString('分かるところだけで構いません', $html);
        $this->assertMatchesRegularExpression('/<script type="module" src="[^"]*trainee-birthday-input[^"]*\.js"/u', $html);
    }

    public function test_自由記述は設問文の先頭に読み上げない印を付け例文は出さない(): void
    {
        $this->traineeWithToken('モモ');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $positions = [];
        foreach (TraineeIntakeSurveyResponse::questions() as $column => $question) {
            // 設問の先頭に「●」。aria-hidden で読み上げでは読まず、設問文は別の要素（折り返しは「●」の右から）
            $this->assertStringContainsString('<label for="'.$column.'" class="form-label d-flex"><span class="me-1" aria-hidden="true">●</span><span>'.$question['question'].'</span></label>', $html);
            $this->assertMatchesRegularExpression('/<textarea class="form-control\s*"\s+id="'.$column.'" name="'.$column.'" rows="4" maxlength="2000"/u', $html);
            $positions[] = strpos($html, 'id="'.$column.'"');
        }
        // 例文は出さない（2026-10 変更。回答を例文に誘導しないため）
        $this->assertStringNotContainsString('例：', $html);
        $this->assertStringNotContainsString('-examples', $html);
        $this->assertSame(4, substr_count($html, '<span class="me-1" aria-hidden="true">●</span>'));
        // こうなったらいいな → 最近の様子 → 理想の毎日 → 体のこと の順
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    public function test_会員の氏名や連絡先は出さない(): void
    {
        $this->traineeWithToken('モモ');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertStringNotContainsString('山田', $html);
        $this->assertStringNotContainsString('花子', $html);
        $this->assertStringNotContainsString('090-1111-2222', $html);
        $this->assertStringNotContainsString('桜町', $html);
    }

    public function test_プライバシーポリシーの設定があれば同意文を出す(): void
    {
        config(['app.client_portal_privacy_url' => 'https://example.com/privacy']);
        $this->traineeWithToken('モモ');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertStringContainsString('<a href="https://example.com/privacy" target="_blank" rel="noopener">プライバシーポリシー</a>', $html);
        $this->assertLessThan(strpos($html, '>送信する<'), strpos($html, 'c-consent-note'));
    }

    public function test_プライバシーポリシーの設定がなければ同意文を出さない(): void
    {
        config(['app.client_portal_privacy_url' => null]);
        $this->traineeWithToken('モモ');

        $this->get($this->showPath())->assertOk()->assertDontSee('c-consent-note', false);
    }

    public function test_会社名があればフッターに出す(): void
    {
        config(['app.client_portal_company' => '株式会社テスト']);
        $this->traineeWithToken('モモ');

        $this->get($this->showPath())->assertOk()->assertSee('<p class="c-login-footer">&copy; '.date('Y').' 株式会社テスト</p>', false);
    }

    public function test_開いただけでは使い切りにしない(): void
    {
        $this->traineeWithToken('モモ');

        $this->get($this->showPath())->assertOk();
        $this->get($this->showPath())->assertOk()->assertSee('送信する');

        $this->assertNull(DB::table('trainee_intake_survey_tokens')->value('answered_at'));
    }

    // ---- 使えない URL の画面 ----

    private function assertUnavailable(string $html, string $message): void
    {
        $this->assertStringContainsString('<h1 class="mb-4">受付アンケート</h1>', $html);
        $this->assertMatchesRegularExpression('/<div class="card-body p-4">\s*<p class="mb-0">'.preg_quote($message, '/').'<\/p>/u', $html);
        $this->assertStringNotContainsString('次にできること', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function test_存在しないトークンは使えない画面(): void
    {
        $html = $this->get($this->showPath('nonexistent-token'))->assertOk()->getContent();

        $this->assertUnavailable($html, 'このアンケートはご利用いただけません。お手数ですがトレーナーにお問い合わせください。');
    }

    public function test_取消済みのトークンは使えない画面(): void
    {
        $this->traineeWithToken('モモ');
        DB::table('trainee_intake_survey_tokens')->delete();

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertUnavailable($html, 'このアンケートはご利用いただけません。お手数ですがトレーナーにお問い合わせください。');
    }

    public function test_トレーニーが削除されていれば使えない画面(): void
    {
        // 本番では外部キーの CASCADE でトークンも消えるが、トークンだけが残った場合も使えない画面にする
        $this->traineeWithToken('モモ');
        DB::table('trainees')->delete();

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertUnavailable($html, 'このアンケートはご利用いただけません。お手数ですがトレーナーにお問い合わせください。');
    }

    public function test_回答済みのトークンは回答済みの画面(): void
    {
        $this->traineeWithToken('モモ', '2026-10-16 10:00:00');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertUnavailable($html, 'このアンケートはすでにご回答いただいています。');
        $this->assertStringNotContainsString('モモ', $html);
    }

    // ---- 完了画面 ----

    public function test_完了画面は固定の文面で名前もマイページへのリンクも出さない(): void
    {
        $this->traineeWithToken('モモ');

        $html = $this->get(route('client-portal.intake-survey.thanks'))->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="mb-4">ご回答ありがとうございました</h1>', $html);
        $this->assertStringContainsString('いただいた内容は、トレーニングの参考にさせていただきます。', $html);
        $this->assertStringContainsString('このページは閉じていただいて構いません。', $html);
        $this->assertStringNotContainsString('モモ', $html);
        $this->assertStringNotContainsString('client-portal/login', $html);
        $this->assertStringNotContainsString('マイページ', $html);
    }
}

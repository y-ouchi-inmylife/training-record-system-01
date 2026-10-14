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
            $table->text('note')->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
    }

    private function traineeWithToken(?string $name, ?string $answeredAt = null): Trainee
    {
        $client = Client::query()->create([
            'internal_id' => '0001', 'last_name' => '山田', 'first_name' => '花子',
            'phone1' => '090-1111-2222', 'address3' => '桜町1-2-3',
        ]);
        $trainee = Trainee::query()->create(['client_id' => $client->id, 'name' => $name]);
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

    public function test_名前が登録済みでも説明文に名前を使わず名前は表示だけ(): void
    {
        $this->traineeWithToken('モモ');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="mb-2">受付アンケート</h1>', $html);
        // 説明文に愛犬の名前は使わない（2026-10 変更。設計書 S-1413）
        $this->assertStringContainsString('<p class="mb-4">愛犬のことを教えてください。トレーニングの参考にさせていただきます。</p>', $html);
        $this->assertStringNotContainsString('モモちゃん', $html);
        $this->assertMatchesRegularExpression('/<div class="text-muted small">名前<\/div>\s*<div>モモ<\/div>/u', $html);
        $this->assertStringNotContainsString('name="name"', $html);
    }

    public function test_名前が未登録なら説明文は愛犬で名前は必須の入力欄(): void
    {
        $this->traineeWithToken(null);

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertStringContainsString('愛犬のことを教えてください。', $html);
        $this->assertStringNotContainsString('愛犬ちゃん', $html);
        $this->assertMatchesRegularExpression('/<label for="name" class="form-label">名前 <span class="text-danger">\*<\/span><\/label>/u', $html);
        $this->assertMatchesRegularExpression('/id="name" name="name" required maxlength="50"/u', $html);
    }

    public function test_小見出しと区切り線と送信先(): void
    {
        $this->traineeWithToken('モモ');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertStringContainsString('<div class="c-login c-login--wide">', $html);
        $this->assertStringContainsString('<h2 class="fs-6 fw-bold mb-2">愛犬のこと</h2>', $html);
        $this->assertStringContainsString('<h2 class="fs-6 fw-bold mb-2">暮らしの様子</h2>', $html);
        $this->assertStringContainsString('どれも任意です。思いつくことを自由にお書きください。', $html);
        // 区切り線は 1 本だけ（去勢・避妊のまとまりの下）
        $this->assertSame(1, substr_count($html, 'border-bottom'));
        $this->assertLessThan(strpos($html, '暮らしの様子'), strpos($html, 'border-bottom'));
        $action = preg_quote(route('client-portal.intake-survey.store', ['token' => self::TOKEN]), '/');
        $this->assertMatchesRegularExpression('/<form method="POST" action="'.$action.'" novalidate>/u', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-primary">送信する</button>', $html);
    }

    public function test_性別と去勢避妊はラジオボタンで不明を置かない(): void
    {
        $this->traineeWithToken('モモ');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $this->assertStringContainsString('<div class="c-choice" role="radiogroup" aria-labelledby="sex-label">', $html);
        $this->assertStringContainsString('<div class="c-choice" role="radiogroup" aria-labelledby="neuter_status-label">', $html);
        foreach (['sex' => ['male' => '男の子', 'female' => '女の子'], 'neuter_status' => ['neutered' => 'している', 'not_neutered' => 'していない', 'dont_know' => '分からない']] as $name => $options) {
            foreach ($options as $value => $label) {
                $this->assertMatchesRegularExpression('/type="radio"\s+name="'.$name.'" id="'.$name.'-'.$value.'" value="'.$value.'"/u', $html);
                $this->assertStringContainsString('<label class="form-check-label" for="'.$name.'-'.$value.'">'.$label.'</label>', $html);
            }
        }
        $this->assertStringNotContainsString('value="unknown"', $html);
        $this->assertStringNotContainsString('不明', $html);
        // 初めて開いたときは何も選ばれていない
        $this->assertStringNotContainsString('checked', $html);
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
        $this->assertStringContainsString('<div id="birth_date-help" class="form-text">分かるところだけで構いません。年はおおよそでも大丈夫です。</div>', $html);
        $this->assertMatchesRegularExpression('/<script type="module" src="[^"]*trainee-birthday-input[^"]*\.js"/u', $html);
    }

    public function test_自由記述は設問文をラベルに例文を下に出す(): void
    {
        $this->traineeWithToken('モモ');

        $html = $this->get($this->showPath())->assertOk()->getContent();

        $positions = [];
        foreach (TraineeIntakeSurveyResponse::questions() as $column => $question) {
            $this->assertStringContainsString('<label for="'.$column.'" class="form-label">'.$question['question'].'</label>', $html);
            $this->assertMatchesRegularExpression('/<textarea class="form-control\s*"\s+id="'.$column.'" name="'.$column.'" rows="4" maxlength="2000"/u', $html);
            foreach ($question['examples'] as $example) {
                $this->assertStringContainsString('<div>例：「'.e($example).'」</div>', $html);
            }
            $positions[] = strpos($html, 'id="'.$column.'"');
        }
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

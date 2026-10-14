<?php

namespace Tests\Feature;

use App\Http\Requests\ClientIntakeSurveyRequest;
use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyResponse;
use App\Models\TraineeIntakeSurveyToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 受付アンケートの回答の送信（POST /client-portal/intake-survey/{token}。2026-10 追加。要件定義書 6-15-17）。
 *
 * - 回答を答えたとおりに保存し、トレーニーの空欄の項目だけに反映し、案内を使い切りにして完了画面へ（PRG）
 * - 2 回目の送信は回答済みの画面になる
 *
 * 注意：テストの DB（SQLite）では lockForUpdate() は何もしないため、同時送信の排他（MySQL の行ロック）は
 * テストでは確かめられない。ここで確かめるのは「回答済みなら受け付けない」という論理だけ。
 */
class ClientIntakeSurveySubmitTest extends TestCase
{
    use CreatesClientCountTables;

    private const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function traineeWithToken(array $attributes = []): Trainee
    {
        $client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田']);
        $trainee = Trainee::query()->create(array_merge(['client_id' => $client->id, 'name' => 'モモ'], $attributes));
        DB::table('trainee_intake_survey_tokens')->insert([
            'token' => self::TOKEN, 'trainee_id' => $trainee->id,
            'created_at' => '2026-10-15 09:00:00', 'updated_at' => '2026-10-15 09:00:00',
        ]);
        // updated_by・updated_at が反映で変わるかを確かめるため、作ったあとの値を固定する
        DB::table('trainees')->where('id', $trainee->id)->update(['updated_by' => 100, 'updated_at' => '2026-10-01 00:00:00']);

        return $trainee->fresh();
    }

    private function submit(array $data = [])
    {
        return $this->post('/client-portal/intake-survey/'.self::TOKEN, $data);
    }

    private function fullAnswer(): array
    {
        return [
            'breed' => 'トイプードル',
            'sex' => 'female',
            'birth_year' => '2022',
            'birth_month' => '3',
            'birth_day' => '',
            'neuter_status' => 'dont_know',
            'wish' => "留守番中に鳴き続けてしまう\nのを直したい",
            'recent_events' => '',
            'ideal_days' => 'ドッグランで遊びたい',
            'health_concerns' => null,
        ];
    }

    // ---- 保存と使い切り ----

    public function test_正しい入力で回答を保存し完了画面へ移り案内を使い切りにする(): void
    {
        $trainee = $this->traineeWithToken();

        $response = $this->submit($this->fullAnswer());

        $response->assertRedirect(route('client-portal.intake-survey.thanks'));
        $saved = TraineeIntakeSurveyResponse::query()->sole();
        $token = TraineeIntakeSurveyToken::query()->sole();
        $this->assertSame($trainee->id, (int) $saved->trainee_id);
        $this->assertSame($token->id, (int) $saved->trainee_intake_survey_token_id);
        $this->assertSame('2026-10-16 14:32:00', $saved->answered_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-16 14:32:00', $token->answered_at->format('Y-m-d H:i:s'));
        // 答えたとおり
        $this->assertSame('モモ', $saved->name);
        $this->assertSame('トイプードル', $saved->breed);
        $this->assertSame('female', $saved->sex);
        $this->assertSame(2022, $saved->birth_year);
        $this->assertSame(3, $saved->birth_month);
        $this->assertNull($saved->birth_day);
        $this->assertSame('dont_know', $saved->neuter_status);
        $this->assertSame("留守番中に鳴き続けてしまう\nのを直したい", $saved->wish);
        $this->assertNull($saved->recent_events);
        $this->assertSame('ドッグランで遊びたい', $saved->ideal_days);
        $this->assertNull($saved->health_concerns);
    }

    public function test_何も答えなくても名前が登録済みなら送信できる(): void
    {
        $this->traineeWithToken();

        $this->submit([])->assertRedirect(route('client-portal.intake-survey.thanks'));

        $saved = TraineeIntakeSurveyResponse::query()->sole();
        $this->assertSame('モモ', $saved->name);
        $this->assertNull($saved->breed);
    }

    public function test_2回目の送信は回答済みの画面になり回答は1件のまま(): void
    {
        $this->traineeWithToken();
        $this->submit($this->fullAnswer())->assertRedirect(route('client-portal.intake-survey.thanks'));

        $response = $this->submit(['breed' => '柴']);

        $response->assertOk();
        $response->assertSee('このアンケートはすでにご回答いただいています。');
        $this->assertSame(1, TraineeIntakeSurveyResponse::query()->count());
        $this->assertSame('トイプードル', TraineeIntakeSurveyResponse::query()->value('breed'));
        // 回答済みの案内を開いても回答済みの画面
        $this->get('/client-portal/intake-survey/'.self::TOKEN)->assertOk()->assertSee('このアンケートはすでにご回答いただいています。');
    }

    public function test_存在しないトークンへの送信は使えない画面で何も保存しない(): void
    {
        $this->traineeWithToken();

        $response = $this->post('/client-portal/intake-survey/nonexistent', $this->fullAnswer());

        $response->assertOk();
        $response->assertSee('このアンケートはご利用いただけません。お手数ですがトレーナーにお問い合わせください。');
        $this->assertSame(0, TraineeIntakeSurveyResponse::query()->count());
    }

    public function test_トレーニーが削除されていれば使えない画面で何も保存しない(): void
    {
        $this->traineeWithToken();
        DB::table('trainees')->delete();

        $response = $this->submit($this->fullAnswer());

        $response->assertOk();
        $response->assertSee('このアンケートはご利用いただけません。');
        $this->assertSame(0, TraineeIntakeSurveyResponse::query()->count());
        $this->assertNull(DB::table('trainee_intake_survey_tokens')->value('answered_at'));
    }

    // ---- トレーニーへの反映 ----

    public function test_空欄の項目だけに反映しdont_knowは不明に読み替える(): void
    {
        $trainee = $this->traineeWithToken();

        $this->submit($this->fullAnswer());

        $trainee->refresh();
        $this->assertSame('モモ', $trainee->name);
        $this->assertSame('トイプードル', $trainee->breed);
        $this->assertSame('female', $trainee->sex);
        $this->assertSame(2022, (int) $trainee->birth_year);
        $this->assertSame(3, (int) $trainee->birth_month);
        $this->assertNull($trainee->birth_day);
        $this->assertSame('unknown', $trainee->neuter_status);
    }

    public function test_入力済みの項目は変えない(): void
    {
        $trainee = $this->traineeWithToken([
            'breed' => '柴', 'sex' => 'male', 'neuter_status' => 'neutered',
            'birth_year' => 2020, 'birth_month' => 5, 'birth_day' => 1,
        ]);

        $this->submit($this->fullAnswer());

        $trainee->refresh();
        $this->assertSame('柴', $trainee->breed);
        $this->assertSame('male', $trainee->sex);
        $this->assertSame('neutered', $trainee->neuter_status);
        $this->assertSame([2020, 5, 1], [(int) $trainee->birth_year, (int) $trainee->birth_month, (int) $trainee->birth_day]);
        // 回答は答えたとおりに残る
        $this->assertSame('トイプードル', TraineeIntakeSurveyResponse::query()->value('breed'));
    }

    public function test_トレーナーが選んだ不明は上書きしない(): void
    {
        $trainee = $this->traineeWithToken(['sex' => 'unknown', 'neuter_status' => 'unknown']);

        $this->submit(['sex' => 'female', 'neuter_status' => 'neutered']);

        $trainee->refresh();
        $this->assertSame('unknown', $trainee->sex);
        $this->assertSame('unknown', $trainee->neuter_status);
    }

    public function test_誕生日は3つとも空のときだけ3つまとめて反映する(): void
    {
        // 年だけ入っているトレーニーには反映しない（一部だけ入っている場合）
        $trainee = $this->traineeWithToken(['birth_year' => 2021]);

        $this->submit(['birth_year' => '2022', 'birth_month' => '3', 'birth_day' => '15']);

        $trainee->refresh();
        $this->assertSame(2021, (int) $trainee->birth_year);
        $this->assertNull($trainee->birth_month);
        $this->assertNull($trainee->birth_day);
    }

    public function test_誕生日が3つとも空なら年月日をそのまま反映する(): void
    {
        $trainee = $this->traineeWithToken();

        $this->submit(['birth_year' => '2022', 'birth_month' => '3', 'birth_day' => '15']);

        $trainee->refresh();
        $this->assertSame([2022, 3, 15], [(int) $trainee->birth_year, (int) $trainee->birth_month, (int) $trainee->birth_day]);
    }

    public function test_自由記述は反映しない(): void
    {
        $trainee = $this->traineeWithToken(['note' => '保護犬']);

        $this->submit(['wish' => 'こうなったらいい', 'health_concerns' => 'パテラ']);

        $this->assertSame('保護犬', $trainee->fresh()->note);
    }

    public function test_最終更新者は変えない(): void
    {
        $trainee = $this->traineeWithToken();

        $this->submit($this->fullAnswer());

        $this->assertSame(100, (int) $trainee->fresh()->updated_by);
    }

    public function test_反映するものがなければトレーニーを更新しない(): void
    {
        $trainee = $this->traineeWithToken(['breed' => '柴']);

        $this->submit(['breed' => 'トイプードル']);

        $this->assertSame('2026-10-01 00:00:00', $trainee->fresh()->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, TraineeIntakeSurveyResponse::query()->count());
    }

    // ---- 名前 ----

    public function test_名前が未登録なら名前が必須(): void
    {
        $this->traineeWithToken(['name' => null]);

        $response = $this->submit(['breed' => 'トイプードル']);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['name' => '名前を入力してください。']);
        $this->assertSame(0, TraineeIntakeSurveyResponse::query()->count());
        $this->assertNull(DB::table('trainee_intake_survey_tokens')->value('answered_at'));
    }

    public function test_名前が未登録なら回答の名前をトレーニーに反映する(): void
    {
        $trainee = $this->traineeWithToken(['name' => null]);

        $this->submit(['name' => 'ハナ'])->assertRedirect(route('client-portal.intake-survey.thanks'));

        $this->assertSame('ハナ', $trainee->fresh()->name);
        $this->assertSame('ハナ', TraineeIntakeSurveyResponse::query()->value('name'));
    }

    public function test_名前は50文字以内(): void
    {
        $this->traineeWithToken(['name' => null]);

        $this->submit(['name' => str_repeat('あ', 51)])
            ->assertSessionHasErrors(['name' => '名前は50文字以内で入力してください。']);
    }

    public function test_名前が登録済みなら名前を送っても使わない(): void
    {
        $trainee = $this->traineeWithToken();

        $this->submit(['name' => 'ハナ'])->assertRedirect(route('client-portal.intake-survey.thanks'));

        $this->assertSame('モモ', $trainee->fresh()->name);
        $this->assertSame('モモ', TraineeIntakeSurveyResponse::query()->value('name'));
    }

    public function test_検証のあとに名前が消されていたら名前の入力エラーで戻す(): void
    {
        // 検証（ロックの前）では名前があり、ロックのあとでは名前がない、という食い違いを作る：
        // フォームリクエストの検証（afterResolving で動く）の直後に名前を消す。検証で名前のエラーに
        // なった場合は、前の画面（from がないため /）へ戻るので、下の戻り先の確かめで区別できる
        $trainee = $this->traineeWithToken();
        $this->app->afterResolving(ClientIntakeSurveyRequest::class, function () use ($trainee) {
            DB::table('trainees')->where('id', $trainee->id)->update(['name' => null]);
        });

        $response = $this->submit(['breed' => 'トイプードル']);

        $response->assertRedirect('/client-portal/intake-survey/'.self::TOKEN);
        $response->assertSessionHasErrors(['name' => '名前を入力してください。']);
        $response->assertSessionHasInput('breed', 'トイプードル');
        $this->assertSame(0, TraineeIntakeSurveyResponse::query()->count());
        $this->assertNull(DB::table('trainee_intake_survey_tokens')->value('answered_at'));
    }

    // ---- 入力のルール ----

    public function test_全角の数字の誕生日を受け付ける(): void
    {
        $this->traineeWithToken();

        $this->submit(['birth_year' => '２０２２', 'birth_month' => '０３', 'birth_day' => '１５'])
            ->assertRedirect(route('client-portal.intake-survey.thanks'));

        $saved = TraineeIntakeSurveyResponse::query()->sole();
        $this->assertSame([2022, 3, 15], [$saved->birth_year, $saved->birth_month, $saved->birth_day]);
    }

    public function test_誕生日の判定はトレーナー側と同じ(): void
    {
        $this->traineeWithToken();

        $this->submit(['birth_month' => '3'])->assertSessionHasErrors(['birth_date' => __('validation.custom.birth_date.month_without_year')]);
        $this->submit(['birth_year' => '2027'])->assertSessionHasErrors(['birth_date' => __('validation.custom.birth_date.future')]);
        $this->assertSame(0, TraineeIntakeSurveyResponse::query()->count());
    }

    public function test_性別に不明は受け付けない(): void
    {
        $this->traineeWithToken();

        $this->submit(['sex' => 'unknown'])->assertSessionHasErrors(['sex' => '選択された性別は有効ではありません。']);
    }

    public function test_去勢避妊はトレーニーの不明の値を受け付けない(): void
    {
        $this->traineeWithToken();

        $this->submit(['neuter_status' => 'unknown'])->assertSessionHasErrors(['neuter_status' => '選択された去勢・避妊は有効ではありません。']);
    }

    public function test_犬種は100文字以内(): void
    {
        $this->traineeWithToken();

        $this->submit(['breed' => str_repeat('あ', 101)])->assertSessionHasErrors(['breed' => '犬種は100文字以内で入力してください。']);
    }

    public function test_自由記述は2000文字以内で項目名は設問の短い呼び名(): void
    {
        $this->traineeWithToken();

        $data = [];
        foreach (array_keys(TraineeIntakeSurveyResponse::questions()) as $column) {
            $data[$column] = str_repeat('あ', 2001);
        }
        $this->submit($data)->assertSessionHasErrors([
            'wish' => 'こうなったらいいなは2000文字以内で入力してください。',
            'recent_events' => '最近の様子は2000文字以内で入力してください。',
            'ideal_days' => '理想の毎日は2000文字以内で入力してください。',
            'health_concerns' => '体のことは2000文字以内で入力してください。',
        ]);

        $data = array_map(fn () => str_repeat('あ', 2000), $data);
        $this->submit($data)->assertRedirect(route('client-portal.intake-survey.thanks'));
    }

    public function test_自由記述はCRLFの改行をLFに直してから数えるのでちょうど2000字なら受け付ける(): void
    {
        // ブラウザの maxlength は改行を 1 文字と数えるが、送信では CRLF（2 文字）になる。
        // LF に直すと 2,000 字（改行 9 個を含む）、CRLF のままなら 2,009 字の文章
        $this->traineeWithToken();
        $text = implode("\r\n", array_fill(0, 10, str_repeat('あ', 199))).'あ';
        $this->assertSame(2009, mb_strlen($text));
        $this->assertSame(2000, mb_strlen(str_replace("\r\n", "\n", $text)));

        $this->submit(['wish' => $text])->assertRedirect(route('client-portal.intake-survey.thanks'));

        $saved = TraineeIntakeSurveyResponse::query()->sole()->wish;
        $this->assertSame(2000, mb_strlen($saved));
        $this->assertStringNotContainsString("\r", $saved);
    }

    public function test_自由記述はLFに直しても2001字ならエラー(): void
    {
        $this->traineeWithToken();
        $text = implode("\r\n", array_fill(0, 10, str_repeat('あ', 199))).'ああ';

        $this->submit(['wish' => $text])
            ->assertSessionHasErrors(['wish' => 'こうなったらいいなは2000文字以内で入力してください。']);
        $this->assertSame(0, TraineeIntakeSurveyResponse::query()->count());
    }

    public function test_自由記述の改行は4欄ともLFにそろえて保存しCRを残さない(): void
    {
        $this->traineeWithToken();

        $this->submit([
            'wish' => "1行目\r\n2行目",
            'recent_events' => "CRだけ\r改行",
            'ideal_days' => "混在\r\nCRLF\rCR\nLF",
            'health_concerns' => "LFだけ\n改行",
        ])->assertRedirect(route('client-portal.intake-survey.thanks'));

        $saved = TraineeIntakeSurveyResponse::query()->sole();
        $this->assertSame("1行目\n2行目", $saved->wish);
        $this->assertSame("CRだけ\n改行", $saved->recent_events);
        $this->assertSame("混在\nCRLF\nCR\nLF", $saved->ideal_days);
        $this->assertSame("LFだけ\n改行", $saved->health_concerns);
    }

    public function test_改行を直しても誕生日の全角の数字の受け付けは変わらない(): void
    {
        $this->traineeWithToken();

        $this->submit(['birth_year' => '２０２２', 'birth_month' => '３', 'wish' => "a\r\nb"])
            ->assertRedirect(route('client-portal.intake-survey.thanks'));

        $saved = TraineeIntakeSurveyResponse::query()->sole();
        $this->assertSame([2022, 3], [$saved->birth_year, $saved->birth_month]);
        $this->assertSame("a\nb", $saved->wish);
    }

    public function test_項目名は設問の短い呼び名と食い違わない(): void
    {
        foreach (TraineeIntakeSurveyResponse::questions() as $column => $question) {
            $this->assertSame($question['label'], __('validation.attributes.'.$column));
        }
    }

    public function test_検証エラーで戻ったとき入力が残る(): void
    {
        $this->traineeWithToken(['name' => null]);
        $data = $this->fullAnswer();

        $this->from('/client-portal/intake-survey/'.self::TOKEN)->submit($data)
            ->assertRedirect('/client-portal/intake-survey/'.self::TOKEN);

        $html = $this->get('/client-portal/intake-survey/'.self::TOKEN)->assertOk()->getContent();
        $this->assertStringContainsString('<div class="alert alert-danger" role="alert">', $html);
        $this->assertStringContainsString('名前を入力してください。', $html);
        $this->assertMatchesRegularExpression('/id="breed" name="breed" maxlength="100" autocomplete="off"\s*value="トイプードル"/u', $html);
        $this->assertMatchesRegularExpression('/id="sex-female" value="female"\s*checked/u', $html);
        $this->assertMatchesRegularExpression('/id="neuter_status-dont_know" value="dont_know"\s*checked/u', $html);
        $this->assertMatchesRegularExpression('/data-birthday-part="year"[^>]*value="2022"/u', $html);
        $this->assertStringContainsString(">留守番中に鳴き続けてしまう\nのを直したい</textarea>", $html);
    }
}

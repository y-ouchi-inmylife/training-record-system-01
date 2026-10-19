<?php

namespace Tests\Feature;

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
 * 愛犬アンケートの回答の送信（POST /client-portal/intake-survey/{token}。2026-10 追加。要件定義書 6-15-17）。
 *
 * - 回答を送られてきたとおりに保存し、回答の基本情報でトレーニーを上書きし（空・未選択の項目は変えない。
 *   2026-10 変更。当初は空欄の項目だけに反映していた）、案内を使い切りにして完了画面へ（PRG）
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
            $table->string('photo_path', 500)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createTraineeIntakeSurveyTokensTable();
        // 完了の画面のマイページ登録のお誘いで、会員に有効なマイページ登録の案内があるかを見る（2026-10-19 追加）
        $this->createClientEmailRegistrationTokensTable();
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

    /**
     * 回答を送る。名前は常に必須なので、$data に name がなければトレーニーの初期値と同じ「モモ」を送る
     * （名前の欄はトレーニーの名前が初期値で入っているため、お客様が触らなければその値が送られる）
     */
    private function submit(array $data = [])
    {
        if (! array_key_exists('name', $data)) {
            $data = ['name' => 'モモ'] + $data;
        }

        return $this->post('/client-portal/intake-survey/'.self::TOKEN, $data);
    }

    private function fullAnswer(): array
    {
        return [
            'name' => 'モモ',
            'breed' => 'トイプードル',
            'sex' => 'female',
            'birth_year' => '2022',
            'birth_month' => '3',
            'birth_day' => '',
            'neuter_status' => 'unknown',
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
        // 送られてきたとおり
        $this->assertSame('モモ', $saved->name);
        $this->assertSame('トイプードル', $saved->breed);
        $this->assertSame('female', $saved->sex);
        $this->assertSame(2022, $saved->birth_year);
        $this->assertSame(3, $saved->birth_month);
        $this->assertNull($saved->birth_day);
        $this->assertSame('unknown', $saved->neuter_status);
        $this->assertSame("留守番中に鳴き続けてしまう\nのを直したい", $saved->wish);
        $this->assertNull($saved->recent_events);
        $this->assertSame('ドッグランで遊びたい', $saved->ideal_days);
        $this->assertNull($saved->health_concerns);
    }

    public function test_名前のほかに何も答えなくても送信できる(): void
    {
        $this->traineeWithToken();

        $this->submit(['name' => 'モモ'])->assertRedirect(route('client-portal.intake-survey.thanks'));

        $saved = TraineeIntakeSurveyResponse::query()->sole();
        $this->assertSame('モモ', $saved->name);
        $this->assertNull($saved->breed);
        $this->assertNull($saved->sex);
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
        // 2 回目の送信ではトレーニーも変わらない
        $this->assertSame('トイプードル', Trainee::query()->value('breed'));
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

    // ---- トレーニーへの反映（2026-10 変更：回答で上書きする。空・未選択の項目は変えない） ----

    public function test_未登録の項目は回答の値で埋まる(): void
    {
        $trainee = $this->traineeWithToken(['name' => null]);

        $this->submit(['name' => 'ハナ'] + $this->fullAnswer())->assertRedirect(route('client-portal.intake-survey.thanks'));

        $trainee->refresh();
        $this->assertSame('ハナ', $trainee->name);
        $this->assertSame('トイプードル', $trainee->breed);
        $this->assertSame('female', $trainee->sex);
        $this->assertSame([2022, 3], [(int) $trainee->birth_year, (int) $trainee->birth_month]);
        $this->assertNull($trainee->birth_day);
        $this->assertSame('unknown', $trainee->neuter_status);
    }

    public function test_登録済みの項目も回答の値で上書きする(): void
    {
        $trainee = $this->traineeWithToken([
            'breed' => '柴', 'sex' => 'male', 'neuter_status' => 'neutered',
            'birth_year' => 2020, 'birth_month' => 5, 'birth_day' => 1,
        ]);

        $this->submit([
            'name' => 'モモ子', 'breed' => 'トイプードル', 'sex' => 'female', 'neuter_status' => 'not_neutered',
            'birth_year' => '2021', 'birth_month' => '6', 'birth_day' => '7',
        ]);

        $trainee->refresh();
        $this->assertSame('モモ子', $trainee->name);
        $this->assertSame('トイプードル', $trainee->breed);
        $this->assertSame('female', $trainee->sex);
        $this->assertSame('not_neutered', $trainee->neuter_status);
        $this->assertSame([2021, 6, 7], [(int) $trainee->birth_year, (int) $trainee->birth_month, (int) $trainee->birth_day]);
    }

    public function test_不明を選ぶと不明で上書きする(): void
    {
        $trainee = $this->traineeWithToken(['sex' => 'male', 'neuter_status' => 'neutered']);

        $this->submit(['sex' => 'unknown', 'neuter_status' => 'unknown']);

        $trainee->refresh();
        $this->assertSame('unknown', $trainee->sex);
        $this->assertSame('unknown', $trainee->neuter_status);
    }

    public function test_空や未選択の項目は変えない(): void
    {
        // トレーナーが選んだ「不明」も、未選択ならそのまま
        $trainee = $this->traineeWithToken([
            'breed' => '柴', 'sex' => 'unknown', 'neuter_status' => 'unknown',
            'birth_year' => 2020, 'birth_month' => 5, 'birth_day' => 1,
        ]);

        $this->submit(['breed' => '', 'birth_year' => '', 'birth_month' => '', 'birth_day' => ''])
            ->assertRedirect(route('client-portal.intake-survey.thanks'));

        $trainee->refresh();
        $this->assertSame('柴', $trainee->breed);
        $this->assertSame('unknown', $trainee->sex);
        $this->assertSame('unknown', $trainee->neuter_status);
        $this->assertSame([2020, 5, 1], [(int) $trainee->birth_year, (int) $trainee->birth_month, (int) $trainee->birth_day]);
        // 回答には送られてきたとおり（空）が残る
        $saved = TraineeIntakeSurveyResponse::query()->sole();
        $this->assertNull($saved->breed);
        $this->assertNull($saved->sex);
        $this->assertNull($saved->birth_year);
    }

    public function test_誕生日は年だけ入れて送ると年だけに上書きする(): void
    {
        $trainee = $this->traineeWithToken(['birth_year' => 2020, 'birth_month' => 5, 'birth_day' => 1]);

        $this->submit(['birth_year' => '2021', 'birth_month' => '', 'birth_day' => '']);

        $trainee->refresh();
        $this->assertSame(2021, (int) $trainee->birth_year);
        $this->assertNull($trainee->birth_month);
        $this->assertNull($trainee->birth_day);
    }

    public function test_自由記述は反映しない(): void
    {
        $trainee = $this->traineeWithToken();

        $this->submit(['wish' => 'こうなったらいい', 'health_concerns' => 'パテラ']);

        // 自由記述はトレーニーのどの列にも入らない（2026-10 変更：以前は備考〔note〕が変わらないことで確かめていた。
        // トレーニーの備考はなくした）
        $row = json_encode(DB::table('trainees')->where('id', $trainee->id)->first(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('こうなったらいい', $row);
        $this->assertStringNotContainsString('パテラ', $row);
    }

    public function test_最終更新者は変えない(): void
    {
        $trainee = $this->traineeWithToken();

        $this->submit($this->fullAnswer());

        $fresh = $trainee->fresh();
        $this->assertSame('トイプードル', $fresh->breed);
        $this->assertSame(100, (int) $fresh->updated_by);
    }

    public function test_反映で値が変わらなければトレーニーを更新しない(): void
    {
        // 初期値のまま送った場合（誕生日は文字列で送られるが、整数の値と同じとみなす）
        $trainee = $this->traineeWithToken([
            'breed' => '柴', 'sex' => 'male', 'neuter_status' => 'neutered',
            'birth_year' => 2020, 'birth_month' => 5, 'birth_day' => 1,
        ]);

        $this->submit([
            'name' => 'モモ', 'breed' => '柴', 'sex' => 'male', 'neuter_status' => 'neutered',
            'birth_year' => '2020', 'birth_month' => '05', 'birth_day' => '1',
        ])->assertRedirect(route('client-portal.intake-survey.thanks'));

        $this->assertSame('2026-10-01 00:00:00', $trainee->fresh()->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, TraineeIntakeSurveyResponse::query()->count());
    }

    // ---- 名前（2026-10 変更：常に必須で、回答の値で上書きする） ----

    public function test_名前は必須でトレーナーが名前を入れていても空ならエラー(): void
    {
        $trainee = $this->traineeWithToken();

        $response = $this->submit(['name' => '', 'breed' => 'トイプードル']);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['name' => '名前を入力してください。']);
        $this->assertSame(0, TraineeIntakeSurveyResponse::query()->count());
        $this->assertNull(DB::table('trainee_intake_survey_tokens')->value('answered_at'));
        $this->assertSame('モモ', $trainee->fresh()->name);
    }

    public function test_名前が未登録でも空ならエラー(): void
    {
        $this->traineeWithToken(['name' => null]);

        $this->submit(['name' => ''])->assertSessionHasErrors(['name' => '名前を入力してください。']);
        $this->assertSame(0, TraineeIntakeSurveyResponse::query()->count());
    }

    public function test_名前を直すと上書きする(): void
    {
        $trainee = $this->traineeWithToken();

        $this->submit(['name' => 'ハナ'])->assertRedirect(route('client-portal.intake-survey.thanks'));

        $this->assertSame('ハナ', $trainee->fresh()->name);
        $this->assertSame('ハナ', TraineeIntakeSurveyResponse::query()->value('name'));
    }

    public function test_名前は50文字以内(): void
    {
        $this->traineeWithToken();

        $this->submit(['name' => str_repeat('あ', 51)])
            ->assertSessionHasErrors(['name' => '名前は50文字以内で入力してください。']);
    }

    public function test_開いてから送るまでにトレーナーが値を変えてもお客様の回答で上書きする(): void
    {
        $trainee = $this->traineeWithToken(['breed' => '柴']);
        // 回答画面を開いたあとに、トレーナーが犬種と名前を直した
        DB::table('trainees')->where('id', $trainee->id)->update(['name' => 'モモタロウ', 'breed' => '豆柴']);

        $this->submit(['name' => 'モモ', 'breed' => '柴']);

        $fresh = $trainee->fresh();
        $this->assertSame('モモ', $fresh->name);
        $this->assertSame('柴', $fresh->breed);
    }

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

    public function test_性別はトレーナー側と同じ値だけを受け付ける(): void
    {
        $this->traineeWithToken();

        $this->submit(['sex' => 'other'])->assertSessionHasErrors(['sex' => '選択された性別は有効ではありません。']);
        $this->submit(['sex' => 'unknown'])->assertRedirect(route('client-portal.intake-survey.thanks'));
    }

    public function test_去勢避妊のdont_knowはエラー(): void
    {
        // 2026-10 に「分からない」（dont_know）をやめ、トレーナー側と同じ「不明」（unknown）にした
        $this->traineeWithToken();

        $this->submit(['neuter_status' => 'dont_know'])->assertSessionHasErrors(['neuter_status' => '選択された去勢・避妊は有効ではありません。']);
        $this->assertSame(0, TraineeIntakeSurveyResponse::query()->count());
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

    public function test_検証エラーで戻ったとき入力が残りトレーニーの値より優先される(): void
    {
        // トレーニーの値（柴・男の子・していない・2019年）と違う値を入れ、名前を空にして送る
        $this->traineeWithToken(['breed' => '柴', 'sex' => 'male', 'neuter_status' => 'not_neutered', 'birth_year' => 2019]);
        $data = ['name' => ''] + $this->fullAnswer();

        $this->from('/client-portal/intake-survey/'.self::TOKEN)->submit($data)
            ->assertRedirect('/client-portal/intake-survey/'.self::TOKEN);

        $html = $this->get('/client-portal/intake-survey/'.self::TOKEN)->assertOk()->getContent();
        $this->assertStringContainsString('<div class="alert alert-danger" role="alert">', $html);
        $this->assertStringContainsString('名前を入力してください。', $html);
        $this->assertMatchesRegularExpression('/id="breed" name="breed" maxlength="100" autocomplete="off"\s*value="トイプードル"/u', $html);
        $this->assertMatchesRegularExpression('/id="sex-female" value="female"\s*checked/u', $html);
        $this->assertMatchesRegularExpression('/id="neuter_status-unknown" value="unknown"\s*checked/u', $html);
        $this->assertDoesNotMatchRegularExpression('/id="sex-male" value="male"\s*checked/u', $html);
        $this->assertDoesNotMatchRegularExpression('/id="neuter_status-not_neutered" value="not_neutered"\s*checked/u', $html);
        $this->assertMatchesRegularExpression('/data-birthday-part="year"[^>]*value="2022"/u', $html);
        // 空で送った名前は空のまま（トレーニーの名前に戻さない）
        $this->assertMatchesRegularExpression('/id="name" name="name" required maxlength="50" autocomplete="off"\s*value=""/u', $html);
        $this->assertStringContainsString(">留守番中に鳴き続けてしまう\nのを直したい</textarea>", $html);
    }

    // ---- 答え直し（2026-10-19 追加：回答したあとも、いつでも案内を発行し直して答え直してもらえる） ----

    public function test_答え直すとトレーニーの属性と暮らしの様子が新しい値になり前回の回答は残る(): void
    {
        $trainee = $this->traineeWithToken(['breed' => '柴犬', 'sex' => 'male']);
        // 前回の回答（前の案内で答えたもの）
        $previousTokenId = DB::table('trainee_intake_survey_tokens')->insertGetId([
            'token' => 'previous-token-000000000000000001', 'trainee_id' => $trainee->id,
            'answered_at' => '2026-10-10 09:00:00', 'created_at' => '2026-10-09 09:00:00', 'updated_at' => '2026-10-10 09:00:00',
        ]);
        DB::table('trainee_intake_survey_responses')->insert([
            'trainee_id' => $trainee->id, 'trainee_intake_survey_token_id' => $previousTokenId,
            'answered_at' => '2026-10-10 09:00:00', 'name' => 'モモ', 'breed' => '柴犬', 'sex' => 'male',
            'wish' => '前回の答え', 'ideal_days' => '前回の理想', 'created_at' => '2026-10-10 09:00:00', 'updated_at' => '2026-10-10 09:00:00',
        ]);

        $this->submit($this->fullAnswer())->assertRedirect(route('client-portal.intake-survey.thanks'));

        // 回答は新しい行として増え、前回の回答は消えずに残る
        $responses = TraineeIntakeSurveyResponse::query()->orderBy('answered_at')->get();
        $this->assertCount(2, $responses);
        $this->assertSame('前回の答え', $responses[0]->wish);
        $this->assertSame('前回の理想', $responses[0]->ideal_days);
        $this->assertSame('2026-10-10 09:00:00', $responses[0]->answered_at->format('Y-m-d H:i:s'));
        $this->assertSame("留守番中に鳴き続けてしまう\nのを直したい", $responses[1]->wish);
        // 新しい答えが今の答え（最新の回答）
        $latest = $trainee->fresh()->latestIntakeSurveyResponse;
        $this->assertSame($responses[1]->id, $latest->id);
        $this->assertSame('2026-10-16 14:32:00', $latest->answered_at->format('Y-m-d H:i:s'));
        // トレーニーの属性は、今の回答と同じく送られた値で上書きする
        $fresh = $trainee->fresh();
        $this->assertSame('トイプードル', $fresh->breed);
        $this->assertSame('female', $fresh->sex);
        // 答え直しの案内は使い切り、前の案内はそのまま
        $this->assertSame('2026-10-16 14:32:00', TraineeIntakeSurveyToken::query()->where('token', self::TOKEN)->sole()->answered_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-10 09:00:00', TraineeIntakeSurveyToken::query()->whereKey($previousTokenId)->sole()->answered_at->format('Y-m-d H:i:s'));
    }

    // ---- 有効期限（2026-10-19 追加：案内に 1 週間の有効期限を付けた。「今日」を固定する） ----

    public function test_期限が切れた案内に送ると保存せず期限切れの画面(): void
    {
        $trainee = $this->traineeWithToken();
        DB::table('trainee_intake_survey_tokens')->update(['expires_at' => '2026-10-26 23:59:59']);
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));

        $html = $this->submit($this->fullAnswer())->assertOk()->getContent();

        $this->assertStringContainsString('このリンクは有効期限が切れています。お手数ですがトレーナーにお問い合わせください。', $html);
        $this->assertSame(0, TraineeIntakeSurveyResponse::query()->count());
        $this->assertNull(TraineeIntakeSurveyToken::query()->sole()->answered_at);
        $this->assertNull($trainee->fresh()->breed);
    }

    public function test_期限の日のうちに送れば保存する(): void
    {
        $this->traineeWithToken();
        DB::table('trainee_intake_survey_tokens')->update(['expires_at' => '2026-10-26 23:59:59']);
        $this->travelTo(Carbon::parse('2026-10-26 23:59:59'));

        $this->submit($this->fullAnswer())->assertRedirect(route('client-portal.intake-survey.thanks'));
        $this->assertSame(1, TraineeIntakeSurveyResponse::query()->count());
    }
}

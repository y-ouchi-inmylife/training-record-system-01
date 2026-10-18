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
 * 会員の登録情報（S-1406）の「愛犬の情報」の欄と、愛犬の情報の変更（S-1417）（2026-10-18 追加。要件定義書 6-15-20）。
 *
 * - S-1406：自分の愛犬ごとのカード（マイページと同じ並び・呼び名）に、名前・犬種・性別・誕生日・去勢・避妊と
 *   暮らしの様子（愛犬アンケートの 4 問。見るだけ）。愛犬がいなければ欄を出さない。トレーニーの数でクエリが増えない
 * - S-1417：今の値を入れて開き、保存すると登録情報に戻って完了メッセージ。入力のきまりは愛犬アンケートと同じ。
 *   暮らしの様子・写真・最終更新者は変えない。操作の記録は残さない。ほかの会員の愛犬は 403、ログインしていなければログインの画面へ
 */
class ClientTraineeInfoTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

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
        $this->createAccessLogsTable();

        $this->client = $this->makeClient('山田');
        // 年齢は「今日」で変わるので固定する（日本時間）
        $this->travelTo(Carbon::parse('2026-10-18 10:00:00', 'Asia/Tokyo'));
    }

    private function makeClient(string $lastName): Client
    {
        return Client::query()->create([
            'internal_id' => 'C-'.$lastName, 'last_name' => $lastName, 'first_name' => '太郎', 'email' => $lastName.'@example.com',
        ]);
    }

    private function trainee(?string $name, array $attributes = [], ?Client $client = null): int
    {
        return DB::table('trainees')->insertGetId([
            'client_id' => ($client ?? $this->client)->id, 'name' => $name,
            'updated_by' => 100, 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ] + $attributes);
    }

    private function answer(int $traineeId, array $answers, string $answeredAt = '2026-10-10 09:00:00'): void
    {
        DB::table('trainee_intake_survey_responses')->insert([
            'trainee_id' => $traineeId, 'answered_at' => $answeredAt, 'name' => 'x',
            'created_at' => $answeredAt, 'updated_at' => $answeredAt,
        ] + $answers);
    }

    private function profile(?Client $client = null): string
    {
        return $this->actingAs($client ?? $this->client, 'client')->get(route('client-portal.profile.show'))->assertOk()->getContent();
    }

    /** 「愛犬の情報」の欄のカード（data-client-trainee-info から次のカードの手前まで）を、出ている順に */
    private function cards(string $html): array
    {
        $parts = explode('data-client-trainee-info', $html);
        array_shift($parts);

        return $parts;
    }

    /** カードの項目（項目名 => 値） */
    private function items(string $card): array
    {
        preg_match_all('/<div class="text-muted small mb-1">(.*?)<\/div>\s*<div style="min-height: 1.5rem;">(.*?)<\/div>/su', $card, $m);

        return array_combine($m[1], $m[2]);
    }

    /** カードの暮らしの様子（設問 => 回答の dd の HTML） */
    private function lifeAnswers(string $card): array
    {
        preg_match_all('/<dt class="fw-normal text-muted d-flex"><span class="me-1" aria-hidden="true">●<\/span><span>(.*?)<\/span><\/dt>\s*(<dd.*?<\/dd>)/su', $card, $m);

        return array_combine($m[1], $m[2]);
    }

    private function update(int $traineeId, array $data, ?Client $client = null)
    {
        return $this->actingAs($client ?? $this->client, 'client')->put(route('client-portal.trainees.update', $traineeId), $data);
    }

    private const FULL = ['breed' => 'マルチーズ', 'sex' => 'female', 'birth_year' => 2020, 'birth_month' => 1, 'birth_day' => 23, 'neuter_status' => 'neutered'];

    // ---- 登録情報（S-1406）の「愛犬の情報」の欄 ----

    public function test_登録情報の下に自分の愛犬ごとのカードを登録の順に出し項目を会員詳細と同じ書き方で出す(): void
    {
        $this->trainee('アイ', self::FULL);
        $this->trainee('アムロ', ['breed' => '柴犬', 'sex' => 'unknown', 'birth_year' => 2020, 'birth_month' => 3, 'neuter_status' => 'unknown']);
        $this->trainee('ハナ', ['birth_year' => 2020]);

        $html = $this->profile();

        // 今の登録内容と 3 つの入口ボタンの下に、見出しとカード
        $this->assertGreaterThan(strpos($html, '>登録情報を変更</a>'), strpos($html, '<h2 class="h4 mt-5 mb-3">愛犬の情報</h2>'));
        $cards = $this->cards($html);
        $this->assertCount(3, $cards);
        $this->assertStringContainsString('<h3 class="h5 mb-0">アイちゃん</h3>', $cards[0]);
        $this->assertStringContainsString('<h3 class="h5 mb-0">アムロちゃん</h3>', $cards[1]);
        $this->assertStringContainsString('<h3 class="h5 mb-0">ハナちゃん</h3>', $cards[2]);

        $this->assertSame(['名前' => 'アイ', '犬種' => 'マルチーズ', '性別' => '女の子', '誕生日' => '2020/01/23（6歳8ヶ月）', '去勢・避妊' => 'している'], $this->items($cards[0]));
        // 性別の「不明」も出す。誕生日が年月だけ
        $this->assertSame(['名前' => 'アムロ', '犬種' => '柴犬', '性別' => '不明', '誕生日' => '2020年3月生まれ（6歳7ヶ月）', '去勢・避妊' => '不明'], $this->items($cards[1]));
        // 値がない項目は項目名を残して値を空にする。誕生日が年だけ
        $this->assertSame(['名前' => 'ハナ', '犬種' => '', '性別' => '', '誕生日' => '2020年生まれ（約6歳）', '去勢・避妊' => ''], $this->items($cards[2]));
        // 写真は出さない
        $this->assertStringNotContainsString('<img', implode('', $cards));
    }

    public function test_名前がない愛犬のカードの見出しは愛犬で名前の値は空(): void
    {
        $id = $this->trainee(null);

        $card = $this->cards($this->profile())[0];

        $this->assertStringContainsString('<h3 class="h5 mb-0">愛犬</h3>', $card);
        $this->assertSame('', $this->items($card)['名前']);
        $this->assertStringContainsString('<a href="'.route('client-portal.trainees.edit', $id).'" class="btn btn-primary">愛犬の情報を変更</a>', $card);
    }

    public function test_カードの下に呼び名の情報を変更のボタンを入口ボタンと同じ見た目で出す(): void
    {
        $id = $this->trainee('アイ', self::FULL);

        $card = $this->cards($this->profile())[0];

        $this->assertStringContainsString('<a href="'.route('client-portal.trainees.edit', $id).'" class="btn btn-primary">アイちゃんの情報を変更</a>', $card);
    }

    public function test_暮らしの様子は4問の設問と最新の回答を出し答えていない問いは未回答(): void
    {
        $id = $this->trainee('アイ', self::FULL);
        $this->answer($id, ['wish' => '古い回答'], '2026-10-01 09:00:00');
        $this->answer($id, ['wish' => "引っ張らずに歩きたい\n吠えないでほしい", 'recent_events' => null, 'ideal_days' => '', 'health_concerns' => '膝のこと']);

        $answers = $this->lifeAnswers($this->cards($this->profile())[0]);

        $this->assertSame([
            '日々の暮らしの中で、もっとこうなったらいいな、と感じることはありますか？',
            '最近の様子で、ふと気になったことや、印象に残っている出来事があれば教えてください。',
            'これから一緒に、どんな楽しいことをしたいですか？『理想の毎日』を聞かせてください。',
            '体のことで気になることや、お医者様から言われていることがあれば教えてください。',
        ], array_keys($answers));
        $answers = array_values($answers);
        $this->assertSame('<dd class="mb-3" style="white-space: pre-wrap;">引っ張らずに歩きたい'."\n".'吠えないでほしい</dd>', $answers[0]);
        $this->assertSame('<dd class="mb-3 text-muted">（未回答）</dd>', $answers[1]);
        $this->assertSame('<dd class="mb-3 text-muted">（未回答）</dd>', $answers[2]);
        $this->assertSame('<dd class="mb-0" style="white-space: pre-wrap;">膝のこと</dd>', $answers[3]);
    }

    public function test_愛犬アンケートに答えていない愛犬も基本情報を出し暮らしの様子は4問とも未回答(): void
    {
        $this->trainee('アイ', self::FULL);

        $card = $this->cards($this->profile())[0];

        $this->assertSame('アイ', $this->items($card)['名前']);
        $answers = $this->lifeAnswers($card);
        $this->assertCount(4, $answers);
        $this->assertSame(4, substr_count(implode('', $answers), '（未回答）'));
    }

    public function test_ほかの会員の愛犬は出さない(): void
    {
        $this->trainee('アイ', self::FULL);
        $this->trainee('ほかの犬', [], $this->makeClient('佐藤'));

        $html = $this->profile();

        $this->assertCount(1, $this->cards($html));
        $this->assertStringNotContainsString('ほかの犬', $html);
    }

    public function test_愛犬がいないときは愛犬の情報の欄を出さない(): void
    {
        $this->trainee('ほかの犬', [], $this->makeClient('佐藤'));

        $html = $this->profile();

        $this->assertStringNotContainsString('愛犬の情報', $html);
        $this->assertSame([], $this->cards($html));
        $this->assertStringContainsString('>登録情報を変更</a>', $html);
    }

    public function test_登録情報のクエリの数は愛犬の数で増えない(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->profile();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $id = $this->trainee('アイ', self::FULL);
        $this->answer($id, ['wish' => 'a']);
        $one = $count();

        foreach (['アムロ', 'ハナ', 'モモ'] as $name) {
            $this->answer($this->trainee($name, self::FULL), ['wish' => 'b']);
        }
        $this->assertCount(4, $this->cards($this->profile()));
        $this->assertSame($one, $count());
    }

    // ---- 愛犬の情報の変更（S-1417） ----

    public function test_変更の画面は今の値を入れて開く(): void
    {
        $id = $this->trainee('アイ', self::FULL);

        $html = $this->actingAs($this->client, 'client')->get(route('client-portal.trainees.edit', $id))->assertOk()->getContent();

        $this->assertStringContainsString('<title>アイちゃんの情報の変更', $html);
        $this->assertStringContainsString('<h1 class="mb-4">アイちゃんの情報の変更</h1>', $html);
        $this->assertStringContainsString('<a href="'.route('client-portal.profile.show').'" class="c-detail-back">', $html);
        $this->assertStringContainsString('action="'.route('client-portal.trainees.update', $id).'" novalidate>', $html);
        $this->assertStringContainsString('<input type="hidden" name="_method" value="PUT">', $html);
        $this->assertMatchesRegularExpression('/id="name" name="name"[^>]*\s+value="アイ">/u', $html);
        $this->assertMatchesRegularExpression('/id="breed" name="breed"[^>]*\s+value="マルチーズ">/u', $html);
        $this->assertMatchesRegularExpression('/id="sex-female" value="female"\s+checked>/u', $html);
        $this->assertMatchesRegularExpression('/id="birth_year"[^>]*value="2020"/u', $html);
        $this->assertMatchesRegularExpression('/id="birth_month"[^>]*value="1"/u', $html);
        $this->assertMatchesRegularExpression('/id="birth_day"[^>]*value="23"/u', $html);
        $this->assertMatchesRegularExpression('/id="neuter_status-neutered" value="neutered"\s+checked>/u', $html);
        // 印が付くのは性別と去勢・避妊の 2 つ
        $this->assertSame(2, preg_match_all('/\schecked>/', $html));
        // 愛犬アンケートと同じ説明文。暮らしの様子・写真の欄はない。最後の欄に区切り線は引かない
        $this->assertStringContainsString('※ 年だけ、または年と月だけでも入力できます。', $html);
        $this->assertStringNotContainsString('<textarea', $html);
        $this->assertStringNotContainsString('type="file"', $html);
        $this->assertStringNotContainsString('pb-3 border-bottom', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-primary">変更する</button>', $html);
    }

    public function test_保存すると値を変え登録情報に戻って完了メッセージを出す(): void
    {
        $id = $this->trainee('アイ', self::FULL);

        $response = $this->update($id, ['name' => 'ハナ', 'breed' => 'トイ・プードル', 'sex' => 'male', 'birth_year' => '２０２１', 'birth_month' => '4', 'birth_day' => '5', 'neuter_status' => 'not_neutered']);

        $response->assertRedirect(route('client-portal.profile.show'));
        $response->assertSessionHas('success', 'ハナちゃんの情報を変更しました。');
        $trainee = Trainee::query()->find($id);
        $this->assertSame(['ハナ', 'トイ・プードル', 'male', 2021, 4, 5, 'not_neutered'], [$trainee->name, $trainee->breed, $trainee->sex, $trainee->birth_year, $trainee->birth_month, $trainee->birth_day, $trainee->neuter_status]);
        // 最終更新者は変えない（会員の操作）。操作の記録は残さない（会員側の操作）
        $this->assertSame(100, (int) $trainee->updated_by);
        $this->assertSame(0, DB::table('access_logs')->count());

        // 戻った登録情報の画面の上部に完了メッセージ、カードは変えたあとの値
        $html = $this->profile();
        $this->assertStringContainsString('ハナちゃんの情報を変更しました。', $html);
        $this->assertSame('2021/04/05（5歳6ヶ月）', $this->items($this->cards($html)[0])['誕生日']);
    }

    public function test_空にした項目と選ばなかった項目は空にする(): void
    {
        $id = $this->trainee('アイ', self::FULL);

        $this->update($id, ['name' => 'アイ', 'breed' => '', 'birth_year' => '', 'birth_month' => '', 'birth_day' => ''])
            ->assertRedirect(route('client-portal.profile.show'));

        $trainee = Trainee::query()->find($id);
        $this->assertSame(['アイ', null, null, null, null, null, null], [$trainee->name, $trainee->breed, $trainee->sex, $trainee->birth_year, $trainee->birth_month, $trainee->birth_day, $trainee->neuter_status]);
    }

    public function test_誕生日は年だけと年と月だけでも保存できる(): void
    {
        $id = $this->trainee('アイ', self::FULL);

        $this->update($id, array_merge(self::FULL, ['name' => 'アイ', 'birth_year' => '2020', 'birth_month' => '', 'birth_day' => '']))->assertSessionHasNoErrors();
        $trainee = Trainee::query()->find($id);
        $this->assertSame([2020, null, null], [$trainee->birth_year, $trainee->birth_month, $trainee->birth_day]);

        $this->update($id, array_merge(self::FULL, ['name' => 'アイ', 'birth_year' => '2020', 'birth_month' => '3', 'birth_day' => '']))->assertSessionHasNoErrors();
        $trainee = Trainee::query()->find($id);
        $this->assertSame([2020, 3, null], [$trainee->birth_year, $trainee->birth_month, $trainee->birth_day]);
    }

    public function test_名前が空なら愛犬アンケートと同じ文言のエラーで値を変えない(): void
    {
        $id = $this->trainee('アイ', self::FULL);

        $this->actingAs($this->client, 'client')->from(route('client-portal.trainees.edit', $id));
        $response = $this->update($id, array_merge(self::FULL, ['name' => '']));

        $response->assertRedirect(route('client-portal.trainees.edit', $id));
        $response->assertSessionHasErrors(['name' => '名前を入力してください。']);
        $this->assertSame('アイ', Trainee::query()->find($id)->name);
    }

    public function test_正しくない誕生日は愛犬アンケートと同じきまりでエラーにする(): void
    {
        $id = $this->trainee('アイ', self::FULL);

        // 実在しない日付
        $this->update($id, array_merge(self::FULL, ['name' => 'アイ', 'birth_year' => '2023', 'birth_month' => '2', 'birth_day' => '30']))
            ->assertSessionHasErrors(['birth_date' => __('validation.custom.birth_date.invalid_date')]);
        // 年がなく月だけ
        $this->update($id, array_merge(self::FULL, ['name' => 'アイ', 'birth_year' => '', 'birth_month' => '3', 'birth_day' => '']))
            ->assertSessionHasErrors('birth_date');
        $trainee = Trainee::query()->find($id);
        $this->assertSame([2020, 1, 23], [$trainee->birth_year, $trainee->birth_month, $trainee->birth_day]);
    }

    public function test_入力エラーで戻ったときは入力した値だけを使う(): void
    {
        $id = $this->trainee('アイ', self::FULL);

        $this->actingAs($this->client, 'client')->from(route('client-portal.trainees.edit', $id));
        $html = $this->followingRedirects()->put(route('client-portal.trainees.update', $id), ['name' => '', 'breed' => '柴犬'])->getContent();

        $this->assertStringContainsString('入力内容に誤りがあります。', $html);
        $this->assertMatchesRegularExpression('/id="name" name="name"[^>]*\s+value="">/u', $html);
        $this->assertMatchesRegularExpression('/id="breed" name="breed"[^>]*\s+value="柴犬">/u', $html);
        // 選ばずに送った性別・去勢・避妊にトレーニーの値を戻さない
        $this->assertSame(0, preg_match_all('/\schecked>/', $html));
    }

    public function test_暮らしの様子と写真は送っても変えない(): void
    {
        $id = $this->trainee('アイ', self::FULL + ['photo_path' => 'trainees/1/photo.jpg']);
        $this->answer($id, ['wish' => '元の回答']);

        $this->update($id, array_merge(self::FULL, ['name' => 'アイ', 'wish' => '書き換え', 'health_concerns' => '書き換え', 'photo_path' => null, 'client_id' => 999]))
            ->assertRedirect(route('client-portal.profile.show'));

        $response = DB::table('trainee_intake_survey_responses')->sole();
        $this->assertSame('元の回答', $response->wish);
        $this->assertNull($response->health_concerns);
        $trainee = Trainee::query()->find($id);
        $this->assertSame('trainees/1/photo.jpg', $trainee->photo_path);
        $this->assertSame($this->client->id, (int) $trainee->client_id);
    }

    public function test_値が変わらなければ保存しない(): void
    {
        $id = $this->trainee('アイ', self::FULL);

        $this->update($id, array_merge(self::FULL, ['name' => 'アイ', 'birth_year' => '2020', 'birth_month' => '01', 'birth_day' => '23']))
            ->assertSessionHas('success', 'アイちゃんの情報を変更しました。');

        $this->assertSame('2026-10-01 00:00:00', DB::table('trainees')->where('id', $id)->value('updated_at'));
    }

    public function test_愛犬アンケートに答えていない愛犬も変更でき回答待ちの愛犬アンケートには変えたあとの値が入る(): void
    {
        $id = $this->trainee('アイ', ['breed' => 'マルチーズ']);
        DB::table('trainee_intake_survey_tokens')->insert([
            'token' => 'abcdefghijklmnopqrstuvwxyz012345', 'trainee_id' => $id,
            'created_at' => '2026-10-15 09:00:00', 'updated_at' => '2026-10-15 09:00:00',
        ]);

        $this->actingAs($this->client, 'client')->get(route('client-portal.trainees.edit', $id))->assertOk();
        $this->update($id, ['name' => 'ハナ', 'breed' => '柴犬', 'sex' => 'female'])->assertRedirect(route('client-portal.profile.show'));

        $html = $this->get('/client-portal/intake-survey/abcdefghijklmnopqrstuvwxyz012345')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="name" name="name"[^>]*\s+value="ハナ">/u', $html);
        $this->assertMatchesRegularExpression('/id="breed" name="breed"[^>]*\s+value="柴犬">/u', $html);
        $this->assertMatchesRegularExpression('/id="sex-female" value="female"\s+checked>/u', $html);
    }

    // ---- 見られる範囲・守り ----

    public function test_ほかの会員の愛犬の変更の画面と保存は403で値を変えない(): void
    {
        $other = $this->trainee('ほかの犬', ['breed' => '柴犬'], $this->makeClient('佐藤'));

        $this->actingAs($this->client, 'client')->get(route('client-portal.trainees.edit', $other))->assertForbidden();
        $this->update($other, ['name' => '書き換え'])->assertForbidden();
        // 正しくない値でも、入力エラーではなく 403
        $this->update($other, ['name' => ''])->assertForbidden();

        $this->assertSame('ほかの犬', Trainee::query()->find($other)->name);
    }

    public function test_ない愛犬は404(): void
    {
        $this->actingAs($this->client, 'client')->get(route('client-portal.trainees.edit', 999))->assertNotFound();
        $this->update(999, ['name' => 'ハナ'])->assertNotFound();
    }

    public function test_ログインしていなければログインの画面へ(): void
    {
        $id = $this->trainee('アイ', self::FULL);

        $this->get(route('client-portal.profile.show'))->assertRedirect('/client-portal/login');
        $this->get(route('client-portal.trainees.edit', $id))->assertRedirect('/client-portal/login');
        $this->put(route('client-portal.trainees.update', $id), ['name' => 'ハナ'])->assertRedirect('/client-portal/login');
        $this->assertSame('アイ', Trainee::query()->find($id)->name);
    }
}

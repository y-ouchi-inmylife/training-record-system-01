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
 * 会員のマイページ（S-1402）の愛犬のカードの犬種・性別・誕生日（2026-10-18 追加。要件定義書 6-15-3）。
 *
 * - 写真（または「写真を登録」の枠）のすぐ下に 2 行（1 行目「犬種（性別）」、2 行目は誕生日）。写真の幅の中で中央揃え。
 *   犬種・性別は会員詳細（S-0305）と同じ値。誕生日はマイページだけ「2020/01/23生（6歳8ヶ月）」の書き方
 *   （Trainee::client_profile_parts・TraineeAge::clientBirthdayParts）。「犬種」と「（性別）」、「…生」と「（年齢）」の間でだけ折り返す
 * - 性別の「不明」と値がない項目は出さない。3 つともなければ何も出さない。去勢・避妊は出さない。自分のトレーニーだけ。
 *   トレーニーの数でクエリが増えない。名前の行は足す前の形（2026-10-18 変更：最初は名前の下の 1 行、次に写真の下に 3 行だった）
 */
class ClientDashboardTraineeProfileTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        // マイページのご連絡先の登録のお願いで、契約があるかを見る（2026-10-18 追加。中身は空）
        $this->createClientCountTables();
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->date('training_date');
            $table->time('training_time')->nullable();
            $table->unsignedBigInteger('trainer1_id')->nullable();
            $table->unsignedBigInteger('trainer2_id')->nullable();
        });
        Schema::create('media_records', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('media_record_training_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('media_record_id');
            $table->unsignedBigInteger('training_record_id');
            $table->integer('sort_order')->default(0);
        });
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name')->nullable();
            $table->string('breed')->nullable();
            $table->string('sex', 10)->nullable();
            $table->smallInteger('birth_year')->nullable();
            $table->tinyInteger('birth_month')->nullable();
            $table->tinyInteger('birth_day')->nullable();
            $table->string('neuter_status', 10)->nullable();
            $table->string('photo_path', 500)->nullable();
        });
        Schema::create('trainee_measurements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id');
            $table->date('measured_date');
            $table->time('measured_time');
            $table->decimal('weight_kg', 5, 2);
        });
        // 愛犬アンケート（回答待ちの案内のお知らせの判定に使う。2026-10 追加）
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

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

    private function trainee(string $name, array $attributes = [], ?Client $client = null): int
    {
        return DB::table('trainees')->insertGetId(['client_id' => ($client ?? $this->client)->id, 'name' => $name] + $attributes);
    }

    private function open(): string
    {
        return $this->actingAs($this->client, 'client')->get(route('client-portal.dashboard'))->assertOk()->getContent();
    }

    /** その愛犬のカード（article）の HTML */
    private function card(string $html, string $callName): string
    {
        $heading = strpos($html, '>'.$callName.'</h2>');
        $this->assertNotFalse($heading, $callName.' のカードがない');
        $start = strrpos(substr($html, 0, $heading), '<article');

        return substr($html, $start, strpos($html, '</article>', $heading) - $start);
    }

    /** 写真の下の行（最大 3 行。行ごとの HTML。なければ null） */
    private function profileLines(string $html, string $callName): ?array
    {
        $card = $this->card($html, $callName);
        $start = strpos($card, '<div class="small text-muted text-center mt-2" data-client-trainee-profile>');
        if ($start === false) {
            return null;
        }
        // 行の div（word-break: keep-all）はこのまとまりの中にだけある
        preg_match_all('/<div style="word-break: keep-all;">(.*?)<\/div>/u', substr($card, $start), $lines);

        return $lines[1];
    }

    private const FULL = ['breed' => 'トイ・プードル', 'sex' => 'female', 'birth_year' => 2020, 'birth_month' => 5, 'birth_day' => 1, 'neuter_status' => 'done'];

    public function test_写真の下に犬種と性別の1行と誕生日の1行を中央揃えで出す(): void
    {
        $this->trainee('アイ', self::FULL);

        $html = $this->open();
        $card = $this->card($html, 'アイちゃん');

        // 2 行だけ。1 行目は「犬種」と「（性別）」の間、2 行目は「…生」と「（年齢）」の間に折り返しの印
        $this->assertSame(['トイ・プードル<wbr>（女の子）', '2020/05/01生<wbr>（6歳5ヶ月）'], $this->profileLines($html, 'アイちゃん'));
        // 写真の幅の中で中央揃え
        $this->assertStringContainsString('<div class="small text-muted text-center mt-2" data-client-trainee-profile>', $card);
        $this->assertStringNotContainsString(' ／ ', $card);
        // 写真（ここでは「写真を登録」の枠）のあと、体重のグラフ（計測値がないときの文）より前
        $this->assertTrue(strpos($card, '写真を登録</span>') < strpos($card, 'data-client-trainee-profile'));
        $this->assertTrue(strpos($card, 'data-client-trainee-profile') < strpos($card, 'まだ計測値がありません'));
        // 写真の列（.c-trainee-photo-col）の中
        $this->assertMatchesRegularExpression('/<div class="c-trainee-photo-col[^"]*">(?:(?!<\/article>).)*data-client-trainee-profile/su', $card);
    }

    public function test_名前のすぐ下には行を出さず名前の見出しは足す前の形(): void
    {
        $this->trainee('アイ', self::FULL);

        $card = $this->card($this->open(), 'アイちゃん');

        $this->assertStringContainsString('<h2 class="mb-2" style="font-size: 1.1rem;">アイちゃん</h2>', $card);
        $this->assertDoesNotMatchRegularExpression('/アイちゃん<\/h2>\s*<(p|div)[^>]*data-client-trainee-profile/u', $card);
    }

    public function test_誕生日はマイページだけ生を付け年齢は今日を基準に会員詳細と同じ計算(): void
    {
        $cases = [
            '前日' => [[2020, 10, 19], '2020/10/19生<wbr>（5歳11ヶ月）'], // 誕生日の前日（今日 2026/10/18）
            '当日' => [[2020, 10, 18], '2020/10/18生<wbr>（6歳0ヶ月）'],
            '翌日' => [[2020, 10, 17], '2020/10/17生<wbr>（6歳0ヶ月）'],
            '1歳未満' => [[2026, 1, 10], '2026/01/10生<wbr>（9ヶ月）'],
            '先の日付' => [[2026, 12, 1], '2026/12/01生'],
            '年と月だけ' => [[2020, 3, null], '2020年3月生<wbr>（6歳7ヶ月）'],
            '年だけ' => [[2020, null, null], '2020年生<wbr>（約6歳）'],
        ];
        foreach ($cases as $name => [[$year, $month, $day], $expected]) {
            $this->trainee($name, ['birth_year' => $year, 'birth_month' => $month, 'birth_day' => $day]);
        }

        $html = $this->open();

        foreach ($cases as $name => [, $expected]) {
            // 犬種・性別がないので 2 行目（誕生日）だけ
            $this->assertSame([$expected], $this->profileLines($html, $name.'ちゃん'), $name);
        }
    }

    public function test_会員詳細の誕生日の書き方は変えない(): void
    {
        $id = $this->trainee('アイ', self::FULL);

        $trainee = Trainee::query()->find($id);

        $this->assertSame('2020/05/01（6歳5ヶ月）', $trainee->birthday_with_age_label);
        $trainee->birth_day = null;
        $this->assertSame('2020年5月生まれ（6歳5ヶ月）', $trainee->birthday_with_age_label);
    }

    public function test_1行目は値がないとき犬種だけ性別だけになりどちらもなければ出さない(): void
    {
        $this->trainee('両方', ['breed' => '柴犬', 'sex' => 'male']);
        $this->trainee('性別なし', ['breed' => '柴犬', 'birth_year' => 2020, 'birth_month' => 5, 'birth_day' => 1]);
        $this->trainee('性別不明', ['breed' => '柴犬', 'sex' => 'unknown']);
        $this->trainee('犬種なし', ['sex' => 'male', 'birth_year' => 2020, 'birth_month' => 5, 'birth_day' => 1]);
        $this->trainee('どちらもなし', ['birth_year' => 2020, 'birth_month' => 5, 'birth_day' => 1]);

        $html = $this->open();

        $this->assertSame(['柴犬<wbr>（男の子）'], $this->profileLines($html, '両方ちゃん'));
        $this->assertSame(['柴犬', '2020/05/01生<wbr>（6歳5ヶ月）'], $this->profileLines($html, '性別なしちゃん'));
        // 「不明」は値がないのと同じ扱い（括弧も付けない）
        $this->assertSame(['柴犬'], $this->profileLines($html, '性別不明ちゃん'));
        $this->assertSame(['男の子', '2020/05/01生<wbr>（6歳5ヶ月）'], $this->profileLines($html, '犬種なしちゃん'));
        $this->assertSame(['2020/05/01生<wbr>（6歳5ヶ月）'], $this->profileLines($html, 'どちらもなしちゃん'));
    }

    public function test_3つともないときは何も出さない(): void
    {
        $this->trainee('モモ', ['sex' => 'unknown', 'neuter_status' => 'done']);

        $html = $this->open();

        $this->assertNull($this->profileLines($html, 'モモちゃん'));
        $this->assertStringNotContainsString('data-client-trainee-profile', $html);
    }

    public function test_去勢避妊は出さない(): void
    {
        $this->trainee('アイ', self::FULL);

        $card = $this->card($this->open(), 'アイちゃん');

        $this->assertStringNotContainsString('去勢', $card);
        $this->assertStringNotContainsString('避妊', $card);
        $this->assertStringNotContainsString('済み', implode('', $this->profileLines($this->open(), 'アイちゃん')));
    }

    public function test_値はエスケープしてから折り返しの印でつなぐ(): void
    {
        $this->trainee('アイ', ['breed' => '<b>柴</b>', 'sex' => 'female']);

        $html = $this->open();

        $this->assertSame(['&lt;b&gt;柴&lt;/b&gt;<wbr>（女の子）'], $this->profileLines($html, 'アイちゃん'));
        $this->assertStringNotContainsString('<b>柴</b>', $html);
    }

    public function test_ほかの会員のトレーニーの値は出さない(): void
    {
        $this->trainee('アイ', self::FULL);
        $other = $this->makeClient('佐藤');
        $this->trainee('ハナ', ['breed' => 'ダックスフンド', 'sex' => 'male'], $other);

        $html = $this->open();

        $this->assertStringNotContainsString('ダックスフンド', $html);
        $this->assertStringNotContainsString('ハナちゃん', $html);
    }

    /** 計測値を 1 件入れる（2026-10-27 追加。最新体重の行のテスト用） */
    private function measurement(int $traineeId, string $date, string $time, float $weight): void
    {
        DB::table('trainee_measurements')->insert([
            'trainee_id' => $traineeId, 'measured_date' => $date, 'measured_time' => $time, 'weight_kg' => $weight,
        ]);
    }

    public function test_最新体重_計測値が0件なら体重の行を出さない(): void
    {
        $this->trainee('アイ', self::FULL);

        $html = $this->open();

        $this->assertSame(['トイ・プードル<wbr>（女の子）', '2020/05/01生<wbr>（6歳5ヶ月）'], $this->profileLines($html, 'アイちゃん'));
        $this->assertStringNotContainsString('体重', implode('', $this->profileLines($html, 'アイちゃん')));
    }

    public function test_最新体重_計測値が1件ならその値と日付を3行目に出す(): void
    {
        $id = $this->trainee('アイ', self::FULL);
        $this->measurement($id, '2026-10-05', '08:00:00', 5);

        $html = $this->open();

        // 小数第 2 位まで・日付はゼロ埋め・時刻は出さない。「体重 5.00 kg」と「（日付）」の間でだけ折り返す
        $this->assertSame([
            'トイ・プードル<wbr>（女の子）',
            '2020/05/01生<wbr>（6歳5ヶ月）',
            '体重 5.00 kg<wbr>（2026/10/05）',
        ], $this->profileLines($html, 'アイちゃん'));
    }

    public function test_最新体重_同じ日に2件なら時刻が遅いほうを出す(): void
    {
        $id = $this->trainee('アイ', self::FULL);
        // 入れる順は時刻の逆にしておく（id の順ではなく時刻で決まることを確かめる）
        $this->measurement($id, '2026-10-15', '18:00:00', 5.25);
        $this->measurement($id, '2026-10-15', '08:00:00', 5.1);

        $lines = $this->profileLines($this->open(), 'アイちゃん');

        $this->assertSame('体重 5.25 kg<wbr>（2026/10/15）', end($lines));
    }

    public function test_最新体重_別の日に複数件なら日付が新しいほうを出す(): void
    {
        $id = $this->trainee('アイ', self::FULL);
        // 新しい日の時刻のほうが早くても、日付が新しいほうが最新
        $this->measurement($id, '2026-10-15', '07:00:00', 5.4);
        $this->measurement($id, '2026-09-30', '20:00:00', 4.95);
        $this->measurement($id, '2026-10-01', '12:00:00', 5);

        $lines = $this->profileLines($this->open(), 'アイちゃん');

        $this->assertSame('体重 5.40 kg<wbr>（2026/10/15）', end($lines));
        $this->assertCount(3, $lines);
    }

    public function test_最新体重_犬種性別誕生日がすべてなくても体重の行だけで欄を出す(): void
    {
        $id = $this->trainee('モモ', ['sex' => 'unknown']);
        $this->measurement($id, '2026-10-15', '08:00:00', 3.5);

        $html = $this->open();

        $this->assertSame(['体重 3.50 kg<wbr>（2026/10/15）'], $this->profileLines($html, 'モモちゃん'));
        $this->assertStringContainsString('data-client-trainee-profile', $html);
    }

    public function test_トレーニーの数でクエリが増えない(): void
    {
        $count = function (int $trainees): int {
            DB::table('trainees')->delete();
            for ($i = 1; $i <= $trainees; $i++) {
                // 計測値もある（最新体重の行でもクエリが増えないことを確かめる。2026-10-27 追加）
                $this->measurement($this->trainee('犬'.$i, self::FULL), '2026-10-15', '08:00:00', 5);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->open();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame($count(1), $count(3));
    }
}

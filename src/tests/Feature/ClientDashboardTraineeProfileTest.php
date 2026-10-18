<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員のマイページ（S-1402）の愛犬のカードの犬種・性別・誕生日（2026-10-18 追加。要件定義書 6-15-3）。
 *
 * - 名前のすぐ下の 1 行に「犬種 ／ 性別 ／ 誕生日（年齢）」。値はトレーナー側の会員詳細（S-0305）と同じ出し方
 *   （Trainee::client_profile_parts。誕生日は birthday_with_age_label）。性別の「不明」と値がない項目は出さない
 * - 3 つともなければ行を出さない。去勢・避妊は出さない。自分のトレーニーだけ。トレーニーの数でクエリが増えない
 */
class ClientDashboardTraineeProfileTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
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
        // 受付アンケート（回答待ちの案内のお知らせの判定に使う。2026-10 追加）
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

    /** 名前の見出しのすぐ後ろの 1 行（なければ null） */
    private function profileLine(string $html, string $callName): ?string
    {
        $pattern = '/>'.preg_quote($callName, '/').'<\/h2>\s*<p class="mb-0 small text-muted" data-client-trainee-profile>(.*?)<\/p>/u';

        return preg_match($pattern, $html, $m) === 1 ? $m[1] : null;
    }

    private const FULL = ['breed' => 'トイ・プードル', 'sex' => 'female', 'birth_year' => 2020, 'birth_month' => 5, 'birth_day' => 1, 'neuter_status' => 'done'];

    public function test_名前のすぐ下に犬種と性別と誕生日を区切りでつないで出す(): void
    {
        $this->trainee('アイ', self::FULL);

        $line = $this->profileLine($this->open(), 'アイちゃん');

        $this->assertSame('<span class="d-inline-block">トイ・プードル</span> ／ <span class="d-inline-block">女の子</span> ／ <span class="d-inline-block">2020/05/01（6歳5ヶ月）</span>', $line);
    }

    public function test_年齢は今日を基準に会員詳細と同じ出し方で計算する(): void
    {
        $cases = [
            '前日' => [[2020, 10, 19], '2020/10/19（5歳11ヶ月）'], // 誕生日の前日（今日 2026/10/18）
            '当日' => [[2020, 10, 18], '2020/10/18（6歳0ヶ月）'],
            '翌日' => [[2020, 10, 17], '2020/10/17（6歳0ヶ月）'],
            '1歳未満' => [[2026, 1, 10], '2026/01/10（9ヶ月）'],
            '先の日付' => [[2026, 12, 1], '2026/12/01'],
            '年と月だけ' => [[2020, 3, null], '2020年3月生まれ（6歳7ヶ月）'],
            '年だけ' => [[2020, null, null], '2020年生まれ（約6歳）'],
        ];
        foreach ($cases as $name => [[$year, $month, $day], $expected]) {
            $this->trainee($name, ['birth_year' => $year, 'birth_month' => $month, 'birth_day' => $day]);
        }

        $html = $this->open();

        foreach ($cases as $name => [, $expected]) {
            $this->assertSame('<span class="d-inline-block">'.$expected.'</span>', $this->profileLine($html, $name.'ちゃん'), $name);
        }
    }

    public function test_値がない項目と性別の不明は出さず区切りも詰める(): void
    {
        $this->trainee('性別なし', ['breed' => '柴犬', 'birth_year' => 2020, 'birth_month' => 5, 'birth_day' => 1]);
        $this->trainee('性別不明', ['breed' => '柴犬', 'sex' => 'unknown']);
        $this->trainee('犬種なし', ['sex' => 'male', 'birth_year' => 2020, 'birth_month' => 5, 'birth_day' => 1]);
        $this->trainee('性別だけ', ['sex' => 'male']);

        $html = $this->open();

        $this->assertSame('<span class="d-inline-block">柴犬</span> ／ <span class="d-inline-block">2020/05/01（6歳5ヶ月）</span>', $this->profileLine($html, '性別なしちゃん'));
        $this->assertSame('<span class="d-inline-block">柴犬</span>', $this->profileLine($html, '性別不明ちゃん'));
        $this->assertSame('<span class="d-inline-block">男の子</span> ／ <span class="d-inline-block">2020/05/01（6歳5ヶ月）</span>', $this->profileLine($html, '犬種なしちゃん'));
        $this->assertSame('<span class="d-inline-block">男の子</span>', $this->profileLine($html, '性別だけちゃん'));
        // 性別の「不明」は値がないのと同じ扱い（名前の行の中に出さない）
        preg_match_all('/data-client-trainee-profile>(.*?)<\\/p>/u', $html, $m);
        $this->assertCount(4, $m[1]);
        foreach ($m[1] as $line) {
            $this->assertStringNotContainsString('不明', $line);
        }
    }

    public function test_3つともないときは行を出さず名前の見出しは今までどおり(): void
    {
        $this->trainee('モモ', ['sex' => 'unknown', 'neuter_status' => 'done']);

        $html = $this->open();

        $this->assertNull($this->profileLine($html, 'モモちゃん'));
        $this->assertStringNotContainsString('data-client-trainee-profile', $html);
        $this->assertStringContainsString('<h2 class="mb-2" style="font-size: 1.1rem;">モモちゃん</h2>', $html);
    }

    public function test_去勢避妊は出さない(): void
    {
        $this->trainee('アイ', self::FULL);

        $html = $this->open();

        $this->assertStringNotContainsString('去勢', $html);
        $this->assertStringNotContainsString('避妊', $html);
        $this->assertStringNotContainsString('済み', $this->profileLine($html, 'アイちゃん'));
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

    public function test_愛犬性格診断の結果があるときは名前の行の左の列に置き右のタイプの位置は変えない(): void
    {
        $id = $this->trainee('アイ', self::FULL);
        $json = json_decode(file_get_contents(__DIR__.'/../fixtures/personality-diagnosis-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $case = collect($json['cases'])->firstWhere('name', 'past-1');
        $row = ['trainee_id' => $id, 'answered_at' => '2026-10-01 09:00:00', 'question_version' => 'v1',
            'answers' => json_encode($case['answers']), 'personality_type' => $case['expected']['type'], 'created_at' => now(), 'updated_at' => now()];
        foreach ($case['expected']['scores'] as $category => $score) {
            $row['score_'.$category] = $score;
        }
        DB::table('trainee_personality_diagnosis_results')->insert($row);

        $html = $this->open();

        $this->assertMatchesRegularExpression(
            '/<div class="row g-3 align-items-baseline mb-2" data-trainee-name-row>\s*<div class="col-12 col-lg-8 col-xxl-9">\s*'
            .'<h2 class="mb-0" style="font-size: 1.1rem;">アイちゃん<\/h2>.*?data-client-trainee-profile>.*?<\/p>\s*<\/div>\s*'
            .'<div class="col-lg-4 col-xxl-3 d-none d-lg-block" data-client-personality-type-wide>/su',
            $html
        );
    }

    public function test_トレーニーの数でクエリが増えない(): void
    {
        $count = function (int $trainees): int {
            DB::table('trainees')->delete();
            for ($i = 1; $i <= $trainees; $i++) {
                $this->trainee('犬'.$i, self::FULL);
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

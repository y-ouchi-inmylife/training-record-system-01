<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Support\PersonalityDiagnosisQuestions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AssertsPersonalityDiagnosisName;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員のマイページ（S-1402）の愛犬のカードの性格診断（タイプ・レーダーチャート。2026-10 追加。要件定義書 6-15-19）。
 *
 * - 回答したらすぐ出す（公開の操作はない）。結果がなければ性格診断の部分を出さない（カードは今までどおり）
 * - タイプは最新の結果。レーダーチャートはトレーナー側（S-0309）と同じ値（Trainee::personality_radar_data。今回・前回・初回）
 * - lg 以上は 写真｜体重のグラフ｜性格診断 を横に並べ（992〜1399px は col-lg-8／col-lg-4、1400px 以上は col-xxl-9／col-xxl-3）、それより狭いときは下に置く
 * - 点数の表・50 問の回答・［回答］［削除］・案内の操作は出さない。自分のトレーニーの結果だけ
 *
 * 実際の HTTP で開く（ClientPortalContractRenderingTest と同じく、CLIENT_HOST は phpunit.xml で空）。
 */
class ClientDashboardPersonalityDiagnosisTest extends TestCase
{
    use AssertsPersonalityDiagnosisName;
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
    }

    private function makeClient(string $lastName): Client
    {
        return Client::query()->create([
            'internal_id' => 'C-'.$lastName, 'last_name' => $lastName, 'first_name' => '太郎', 'email' => $lastName.'@example.com',
        ]);
    }

    private function trainee(string $name, ?Client $client = null): int
    {
        return DB::table('trainees')->insertGetId(['client_id' => ($client ?? $this->client)->id, 'name' => $name]);
    }

    private function measurement(int $traineeId): void
    {
        DB::table('trainee_measurements')->insert([
            'trainee_id' => $traineeId, 'measured_date' => '2026-10-10', 'measured_time' => '09:00:00', 'weight_kg' => '5.00',
        ]);
    }

    private function fixtureCase(string $name): array
    {
        $json = json_decode(file_get_contents(__DIR__.'/../fixtures/personality-diagnosis-cases.json'), true, 512, JSON_THROW_ON_ERROR);

        return collect($json['cases'])->firstWhere('name', $name);
    }

    /** 固定データの 1 件を結果として入れる */
    private function addResult(int $traineeId, string $caseName, string $answeredAt): void
    {
        $case = $this->fixtureCase($caseName);
        $row = [
            'trainee_id' => $traineeId, 'answered_at' => $answeredAt, 'question_version' => 'v1',
            'answers' => json_encode($case['answers']), 'personality_type' => $case['expected']['type'],
            'created_at' => now(), 'updated_at' => now(),
        ];
        foreach ($case['expected']['scores'] as $category => $score) {
            $row['score_'.$category] = $score;
        }
        DB::table('trainee_personality_diagnosis_results')->insert($row);
    }

    private function open(?Client $client = null): string
    {
        return $this->actingAs($client ?? $this->client, 'client')
            ->get(route('client-portal.dashboard'))->assertOk()->getContent();
    }

    /** 愛犬 1 頭分のカードの並びの枠（data-trainee-card-row から次の article の手前まで） */
    private function cardRow(string $html, string $callName): string
    {
        $start = strpos($html, '>'.$callName.'</h2>');
        $this->assertNotFalse($start, $callName.' のカードがない');
        $end = strpos($html, '</article>', $start);

        return substr($html, $start, $end - $start);
    }

    /** canvas の data-personality-radar（JSON を戻したもの） */
    private function radarData(string $card): array
    {
        $this->assertSame(1, preg_match('/data-personality-radar="([^"]*)"/u', $card, $m));

        return json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
    }

    private function series(string $key, string $caseName, string $date): array
    {
        $display = $this->fixtureCase($caseName)['expected']['display'];

        return [
            'key' => $key,
            'date' => $date,
            'values' => array_map(fn ($c) => $display[$c], array_keys(PersonalityDiagnosisQuestions::categories())),
        ];
    }

    public function test_結果があれば最新のタイプとレーダーチャートと凡例の枠を出す(): void
    {
        $id = $this->trainee('モモ');
        $this->measurement($id);
        $this->addResult($id, 'past-2', '2026-03-10 11:00:00');
        $this->addResult($id, 'tie-possessiveness-fear', '2026-06-01 09:00:00');

        $html = $this->open();
        $card = $this->cardRow($html, 'モモちゃん');

        // 小見出しと最新のタイプ（「・」のあとでだけ折り返す）
        // 2026-10 変更：カードを低くするため、小見出しとタイプの間・タイプとグラフの間を詰めた
        // 2026-10 変更：lg 未満だけ、性格診断の列の中に小見出しとタイプ（lg 以上は名前の行の右。下のテスト）
        $this->assertStringContainsString('<p class="eyebrow mb-0 text-center d-lg-none">愛犬性格診断</p>', $card);
        $this->assertStringContainsString(
            '<p class="mb-1 fw-bold text-center d-lg-none" style="word-break: keep-all;" data-client-personality-type>愛情たっぷり・<wbr>甘えん坊タイプ</p>',
            $card
        );
        $this->assertStringNotContainsString('慎重派', $card);
        // レーダーチャート（読み上げは点数を言わない）と凡例の枠。id はトレーニーごと
        $this->assertMatchesRegularExpression(
            '/<canvas id="personality-radar-'.$id.'" role="img" aria-label="モモちゃんの愛犬性格診断のグラフ"\s*data-personality-radar="[^"]*"\s*data-personality-radar-compact><\/canvas>/u',
            $card
        );
        $this->assertStringContainsString('data-personality-radar-legend="personality-radar-'.$id.'"', $card);
        $this->assertStringNotContainsString('点数は', $card);
        // 2 件なら今回・前回
        $this->assertSame([
            $this->series('current', 'tie-possessiveness-fear', '2026/06/01'),
            $this->series('previous', 'past-2', '2026/03/10'),
        ], $this->radarData($card)['series']);
        // スクリプトはトレーナー側と共有の personality-radar.js
        $this->assertStringContainsString('personality-radar', $html);
    }

    public function test_lg以上はタイプを名前の行の右に出しlg未満は性格診断の列に出す(): void
    {
        $id = $this->trainee('モモ');
        $this->measurement($id);
        $this->addResult($id, 'past-2', '2026-03-10 11:00:00');
        $this->addResult($id, 'rounding-two-decimals', '2026-06-01 09:00:00');

        $html = $this->open();
        $start = strpos($html, '<div class="row g-3 align-items-baseline mb-2" data-trainee-name-row>');
        $this->assertNotFalse($start);
        $card = substr($html, $start, strpos($html, '</article>', $start) - $start);

        // 名前の行：下の段と同じ 8：4。名前（見出し）の右に、lg 以上だけ「性格診断：{最新のタイプ}」（中央寄せ）。文字の下の線をそろえる
        $this->assertMatchesRegularExpression(
            '/<div class="row g-3 align-items-baseline mb-2" data-trainee-name-row>\s*'
            .'<div class="col-12 col-lg-8 col-xxl-9">\s*<h2 class="mb-0" style="font-size: 1\.1rem;">モモちゃん<\/h2>\s*<\/div>\s*'
            .'<div class="col-lg-4 col-xxl-3 d-none d-lg-block" data-client-personality-type-wide>\s*'
            .'<p class="mb-0 small text-center" style="word-break: keep-all;"><span class="text-muted">愛犬性格診断：<\/span>'
            .'<span class="fw-bold">エネルギッシュ・<wbr>アスリートタイプ<\/span><\/p>\s*<\/div>\s*<\/div>/u',
            $card
        );
        // lg 未満だけの小見出しとタイプも、同じ最新のタイプ。幅でどちらか一方だけが出る（隠れたほうは display: none で読まれない）
        $this->assertStringContainsString('<p class="mb-1 fw-bold text-center d-lg-none" style="word-break: keep-all;" data-client-personality-type>エネルギッシュ・<wbr>アスリートタイプ</p>', $card);
        $this->assertSame(2, substr_count($card, 'エネルギッシュ・<wbr>アスリートタイプ'));
        $this->assertStringNotContainsString('慎重派', $card);
        // 名前の見出しが先、性格診断が後（読み上げの順）
        $this->assertLessThan(strpos($card, '愛犬性格診断：'), strpos($card, 'モモちゃん</h2>'));
    }

    public function test_lg以上の右の列はレーダーと凡例だけ(): void
    {
        $id = $this->trainee('モモ');
        $this->addResult($id, 'past-1', '2026-06-01 09:00:00');

        $card = $this->cardRow($this->open(), 'モモちゃん');
        $start = strpos($card, '<div class="col-12 col-lg-4 col-xxl-3" data-client-personality-diagnosis>');
        $this->assertNotFalse($start);
        $column = substr($card, $start);

        // 列の中の小見出しとタイプは lg 未満だけ（d-lg-none）。lg 以上で見えるのはレーダーと凡例だけ
        preg_match_all('/<p class="([^"]*)"/u', $column, $m);
        $this->assertCount(2, $m[1]);
        foreach ($m[1] as $class) {
            $this->assertStringContainsString('d-lg-none', $class);
        }
        $this->assertStringNotContainsString('text-lg-start', $column);
        $this->assertStringContainsString('<div class="c-personality-radar mx-auto">', $column);
        $this->assertStringContainsString('data-personality-radar-legend="personality-radar-'.$id.'"', $column);
    }

    public function test_結果がなければ名前の行は今までどおりで性格診断を出さない(): void
    {
        $id = $this->trainee('モモ');
        $this->measurement($id);

        $html = $this->open();

        $this->assertStringContainsString('<h2 class="mb-2" style="font-size: 1.1rem;">モモちゃん</h2>', $html);
        $this->assertStringNotContainsString('data-trainee-name-row', $html);
        $this->assertStringNotContainsString('data-client-personality-type-wide', $html);
        $this->assertStringNotContainsString('性格診断：', $html);
    }

    public function test_結果が3件以上ならグラフに渡すのは今回と前回と初回でトレーナー側と同じ値(): void
    {
        $id = $this->trainee('モモ');
        $this->addResult($id, 'past-4', '2025-09-01 10:00:00');
        $this->addResult($id, 'past-3', '2025-12-01 10:00:00');
        $this->addResult($id, 'past-2', '2026-03-10 11:00:00');
        $this->addResult($id, 'past-1', '2026-06-01 09:00:00');

        $data = $this->radarData($this->cardRow($this->open(), 'モモちゃん'));

        $this->assertSame(array_values(PersonalityDiagnosisQuestions::categories()), $data['labels']);
        $this->assertSame([
            $this->series('current', 'past-1', '2026/06/01'),
            $this->series('previous', 'past-2', '2026/03/10'),
            $this->series('initial', 'past-4', '2025/09/01'),
        ], $data['series']);
        // トレーナー側と同じ組み立て（Trainee::personality_radar_data）
        $trainee = Trainee::query()->with('personalityDiagnosisResults')->findOrFail($id);
        $this->assertSame($trainee->personality_radar_data, $data);
    }

    public function test_結果がなければ性格診断の部分を出さず今までどおりの横幅(): void
    {
        $id = $this->trainee('モモ');
        $this->measurement($id);

        $html = $this->open();
        $card = $this->cardRow($html, 'モモちゃん');

        $this->assertStringNotContainsString('data-client-personality-diagnosis', $card);
        $this->assertStringNotContainsString('性格診断', $card);
        $this->assertStringNotContainsString('data-personality-radar', $card);
        // 写真＋体重のグラフは col-12（今までどおりの横幅）
        $this->assertMatchesRegularExpression('/<div class="row g-3 align-items-start" data-trainee-card-row>\s*<div class="col-12">/u', $card);
        $this->assertStringNotContainsString('col-lg-8', $card);
        // 結果のある愛犬がいなければスクリプトも読み込まない
        $this->assertStringNotContainsString('personality-radar', $html);
    }

    public function test_回答待ちの案内があっても結果がなければ出さない(): void
    {
        $id = $this->trainee('モモ');
        DB::table('trainee_personality_diagnosis_tokens')->insert([
            'token' => bin2hex(random_bytes(16)), 'trainee_id' => $id, 'answered_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $card = $this->cardRow($this->open(), 'モモちゃん');

        $this->assertStringNotContainsString('data-client-personality-diagnosis', $card);
    }

    public function test_計測値がなく結果だけあれば計測値の案内と性格診断を並べる(): void
    {
        $id = $this->trainee('モモ');
        $this->addResult($id, 'past-1', '2026-06-01 09:00:00');

        $card = $this->cardRow($this->open(), 'モモちゃん');

        $this->assertStringContainsString('まだ計測値がありません。', $card);
        $this->assertStringContainsString('data-client-personality-diagnosis', $card);
        $this->assertLessThan(strpos($card, 'data-client-personality-diagnosis'), strpos($card, 'まだ計測値がありません。'));
        $this->assertStringNotContainsString('data-measurement-chart', $card);
    }

    public function test_lg以上で写真と体重のグラフと性格診断を横に並べそれより狭いときは下に置く(): void
    {
        $id = $this->trainee('モモ');
        $this->measurement($id);
        $this->addResult($id, 'past-1', '2026-06-01 09:00:00');

        $card = $this->cardRow($this->open(), 'モモちゃん');

        // 1 行（上をそろえる）に、写真＋体重のグラフ（lg で 8/12、xxl で 9/12）と性格診断（lg で 4/12、xxl で 3/12）。
        // lg 未満はどちらも col-12（縦）。2026-10 変更：xxl（1400px）以上は 9：3（体重のグラフと性格診断のグラフの間を詰めるため）。
        // xl（1200px）では右の列にレーダーの枠（横 260px）が入らないので、xl では 9：3 にしない
        $this->assertMatchesRegularExpression(
            '/<div class="row g-3 align-items-start" data-trainee-card-row>\s*<div class="col-12 col-lg-8 col-xxl-9">\s*'
            .'<div class="d-flex flex-column flex-sm-row gap-3 align-items-start">/u',
            $card
        );
        $this->assertStringContainsString('<div class="col-12 col-lg-4 col-xxl-3" data-client-personality-diagnosis>', $card);
        // 名前の行も同じ割合（test_lg以上はタイプを名前の行の右に出しlg未満は性格診断の列に出す で確かめる）
        $this->assertStringNotContainsString('col-xl-', $card);
        // 並びは 写真 → 体重のグラフ → 性格診断
        $this->assertLessThan(strpos($card, 'data-measurement-chart'), strpos($card, 'c-trainee-photo-frame'));
        $this->assertLessThan(strpos($card, 'data-client-personality-diagnosis'), strpos($card, 'data-measurement-chart'));
        // レーダーは会員側の小さな枠（.c-personality-radar。横 260px・高さ 200px）で、compact の設定（2026-10 変更：当初は最大 320px の正方形）。
        // 凡例は文字を小さく（.c-personality-legend）、横に並べたとき縦に、それより狭いときは 1 行
        $this->assertStringContainsString('<div class="c-personality-radar mx-auto">', $card);
        $this->assertStringNotContainsString('max-width: 320px', $card);
        $this->assertStringContainsString('<ul class="c-personality-legend list-unstyled d-flex flex-wrap flex-lg-column align-items-lg-start justify-content-center column-gap-3 row-gap-0 mb-0"', $card);
        // 写真（180px の枠のクラス）は変えない。結果がある愛犬の体重のグラフは 240px（下の空きを小さくするため）
        $this->assertStringContainsString('c-trainee-photo-frame', $card);
        $this->assertStringContainsString('<div style="position: relative; height: 240px;">', $card);
        $this->assertStringNotContainsString('height: 180px;', $card);
    }

    public function test_結果がない愛犬の体重のグラフは今までどおり180pxで結果がある愛犬は240px(): void
    {
        $with = $this->trainee('モモ');
        $this->measurement($with);
        $this->addResult($with, 'past-1', '2026-06-01 09:00:00');
        $without = $this->trainee('ハナ');
        $this->measurement($without);
        // 計測値がなく結果だけある愛犬：「まだ計測値がありません。」の枠も 240px
        $noMeasurement = $this->trainee('ソラ');
        $this->addResult($noMeasurement, 'past-1', '2026-06-01 09:00:00');

        $html = $this->open();

        $this->assertStringContainsString('<div style="position: relative; height: 240px;">', $this->cardRow($html, 'モモちゃん'));
        $this->assertStringContainsString('<div style="position: relative; height: 180px;">', $this->cardRow($html, 'ハナちゃん'));
        $this->assertMatchesRegularExpression('/style="height: 240px;">\s*まだ計測値がありません。/u', $this->cardRow($html, 'ソラちゃん'));
    }

    public function test_会員側の小さなグラフと凡例の大きさはclient_scssにありトレーナー側のscssにはない(): void
    {
        $client = file_get_contents(resource_path('sass/client.scss'));
        $trainer = file_get_contents(resource_path('sass/app.scss'));

        $this->assertMatchesRegularExpression('/\.c-personality-radar \{\s*position: relative;\s*max-width: 260px;\s*height: 200px;\s*\}/u', $client);
        $this->assertMatchesRegularExpression('/\.c-personality-legend \{\s*font-size: 0\.75rem;\s*line-height: 1\.3;\s*\}/u', $client);
        $this->assertStringNotContainsString('c-personality-radar', $trainer);
        $this->assertStringNotContainsString('c-personality-legend', $trainer);
    }

    public function test_点数の表や回答や削除や案内の操作は出さない(): void
    {
        $id = $this->trainee('モモ');
        $this->addResult($id, 'past-2', '2026-03-10 11:00:00');
        $this->addResult($id, 'past-1', '2026-06-01 09:00:00');

        $card = $this->cardRow($this->open(), 'モモちゃん');

        $this->assertStringNotContainsString('<table', $card);
        $this->assertStringNotContainsString('data-personality-diagnosis-results', $card);
        $this->assertStringNotContainsString('50問の回答', $card);
        $this->assertStringNotContainsString('>回答</button>', $card);
        $this->assertStringNotContainsString('>削除</button>', $card);
        $this->assertStringNotContainsString('案内を', $card);
        foreach (PersonalityDiagnosisQuestions::categories() as $label) {
            // 項目名はグラフ（canvas）の中にだけ描かれ、HTML の表や見出しには出さない
            $this->assertStringNotContainsString('>'.$label.'<', $card);
        }
    }

    public function test_ほかの会員のトレーニーの結果は出さない(): void
    {
        $mine = $this->trainee('モモ');
        $this->addResult($mine, 'past-1', '2026-06-01 09:00:00');
        $other = $this->makeClient('佐藤');
        $othersTrainee = $this->trainee('ハナ', $other);
        $this->addResult($othersTrainee, 'tie-possessiveness-fear', '2026-06-01 09:00:00');

        $html = $this->open();

        $this->assertStringNotContainsString('ハナ', $html);
        $this->assertStringNotContainsString('愛情たっぷり', $html);
        $this->assertStringNotContainsString('personality-radar-'.$othersTrainee, $html);
        $this->assertSame(1, substr_count($html, 'data-client-personality-diagnosis'));
    }

    public function test_結果の読み込みはトレーニーの数で増えない(): void
    {
        $id = $this->trainee('モモ');
        $this->addResult($id, 'past-1', '2026-06-01 09:00:00');
        $three = $this->makeClient('佐藤');
        foreach (['ハル', 'ナツ', 'アキ'] as $name) {
            $tid = $this->trainee($name, $three);
            $this->addResult($tid, 'past-2', '2026-03-10 11:00:00');
            $this->addResult($tid, 'past-1', '2026-06-01 09:00:00');
        }

        $count = function (Client $client): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->open($client);
            $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'trainee_personality_diagnosis_results'));
            DB::disableQueryLog();

            return $queries->count();
        };

        $this->assertSame(1, $count($this->client));
        $this->assertSame(1, $count($three));
    }

    // ---- 愛犬性格診断の名前（2026-10-18 変更：正式名称に決まった。その前は「性格診断」） ----

    public function test_マイページは愛犬性格診断の名前で出し性格診断は単独で出さない(): void
    {
        $id = $this->trainee('モモ');
        $this->addResult($id, 'past-1', '2026-06-01 09:00:00');

        $html = $this->open();

        $this->assertStringContainsString('<span class="text-muted">愛犬性格診断：</span>', $html);
        $this->assertStringContainsString('<p class="eyebrow mb-0 text-center d-lg-none">愛犬性格診断</p>', $html);
        $this->assertStringContainsString('の愛犬性格診断のグラフ"', $html);
        $this->assertOnlyFullPersonalityDiagnosisName($html);
    }

    // ---- 点数なし・判定なし（2026-10-18 追加） ----

    public function test_マイページは判定なしを出し今回に点数なしがあれば注記を出す(): void
    {
        $id = $this->trainee('モモ');
        $this->addResult($id, 'all-na', '2026-06-01 09:00:00');

        $html = $this->open();

        $this->assertStringContainsString('<span class="text-muted">愛犬性格診断：</span><span class="fw-bold">判定なし</span>', $html);
        $this->assertStringContainsString('data-client-personality-type>判定なし</p>', $html);
        $this->assertStringContainsString('data-personality-radar-missing-note>※ 対人・対犬・所有欲・分離不安・恐怖心・訓練性能・興奮性は、答えがすべて「未経験」のため点数がありません。</p>', $html);
    }

    public function test_マイページは前回だけ点数なしなら注記を出さない(): void
    {
        $id = $this->trainee('モモ');
        $this->addResult($id, 'category-all-na', '2026-03-10 09:00:00');
        $this->addResult($id, 'past-1', '2026-06-01 09:00:00');

        $this->assertStringNotContainsString('data-personality-radar-missing-note', $this->open());
    }
}

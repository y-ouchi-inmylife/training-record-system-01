<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Support\PersonalityDiagnosisQuestions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員のマイページ（S-1402）の愛犬のカードの性格診断（タイプ・レーダーチャート。2026-10 追加。要件定義書 6-15-19）。
 *
 * - 回答したらすぐ出す（公開の操作はない）。結果がなければ性格診断の部分を出さない（カードは今までどおり）
 * - タイプは最新の結果。レーダーチャートはトレーナー側（S-0309）と同じ値（Trainee::personality_radar_data。今回・前回・初回）
 * - lg 以上は 写真｜体重のグラフ｜性格診断 を横に並べ（col-lg-8／col-lg-4）、それより狭いときは下に置く
 * - 点数の表・50 問の回答・［回答］［削除］・案内の操作は出さない。自分のトレーニーの結果だけ
 *
 * 実際の HTTP で開く（ClientPortalContractRenderingTest と同じく、CLIENT_HOST は phpunit.xml で空）。
 */
class ClientDashboardPersonalityDiagnosisTest extends TestCase
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
            $table->string('photo_path', 500)->nullable();
        });
        Schema::create('trainee_measurements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id');
            $table->date('measured_date');
            $table->time('measured_time');
            $table->decimal('weight_kg', 5, 2);
        });
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
        $this->assertStringContainsString('<p class="eyebrow mb-1 text-center text-lg-start">性格診断</p>', $card);
        $this->assertStringContainsString(
            '<p class="mb-2 fw-bold text-center text-lg-start" style="word-break: keep-all;" data-client-personality-type>愛情たっぷり・<wbr>甘えん坊タイプ</p>',
            $card
        );
        $this->assertStringNotContainsString('慎重派', $card);
        // レーダーチャート（読み上げは点数を言わない）と凡例の枠。id はトレーニーごと
        $this->assertMatchesRegularExpression(
            '/<canvas id="personality-radar-'.$id.'" role="img" aria-label="モモちゃんの性格診断のグラフ"\s*data-personality-radar="/u',
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

        // 1 行（上をそろえる）に、写真＋体重のグラフ（lg で 8/12）と性格診断（lg で 4/12）。lg 未満はどちらも col-12（縦）
        $this->assertMatchesRegularExpression(
            '/<div class="row g-3 align-items-start" data-trainee-card-row>\s*<div class="col-12 col-lg-8">\s*'
            .'<div class="d-flex flex-column flex-sm-row gap-3 align-items-start">/u',
            $card
        );
        $this->assertStringContainsString('<div class="col-12 col-lg-4" data-client-personality-diagnosis>', $card);
        // 並びは 写真 → 体重のグラフ → 性格診断
        $this->assertLessThan(strpos($card, 'data-measurement-chart'), strpos($card, 'c-trainee-photo-frame'));
        $this->assertLessThan(strpos($card, 'data-client-personality-diagnosis'), strpos($card, 'data-measurement-chart'));
        // レーダーの大きさは最大 320px。凡例は横に並べたとき縦に、それより狭いときは 1 行
        $this->assertStringContainsString('<div class="mx-auto" style="max-width: 320px;">', $card);
        $this->assertStringContainsString('<ul class="list-unstyled d-flex flex-wrap flex-lg-column align-items-lg-start justify-content-center column-gap-3 row-gap-1 small mb-0"', $card);
        // 写真（180px の枠のクラス）と体重のグラフの高さ（180px）は変えない
        $this->assertStringContainsString('c-trainee-photo-frame', $card);
        $this->assertStringContainsString('height: 180px;', $card);
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
}

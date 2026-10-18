<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\Trainer;
use App\Models\TrainingRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 評価文を公開して作ったトレーニング記録の見え方（2026-10-18 追加。要件定義書 6-4-3〜6-4-5・6-15-6・6-15-7・6-16-16）。
 *
 * - 記録の詳細（トレーナー側 S-0403・会員側 S-1404）に「愛犬性格診断」の欄（タイプと、その結果の時点のレーダーチャート）。
 *   トレーナー側は S-0313 へのリンク。会員側は呼び名の見出し・小さなグラフで、点数の表とリンクは出さない
 * - 結び付きがない・結果が削除された記録には欄も JS も出さない
 * - 一覧の印（トレーナー側 S-0402、マイページの最新の記録と、これまでの記録）
 * - 記録の更新（S-0404）は会員と結び付けを受け取らない
 */
class TrainingRecordPersonalityDiagnosisTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createAccessLogsTable();
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->integer('display_order')->default(0);
        });
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
        Schema::create('trainee_measurements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id');
            $table->date('measured_date');
            $table->time('measured_time');
            $table->decimal('weight_kg', 5, 2);
        });
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();
        $this->createTrainingRecordsTableForPersonalityDiagnosis();
        Schema::create('media_records', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('media_record_training_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('media_record_id');
            $table->unsignedBigInteger('training_record_id');
            $table->integer('sort_order')->default(0);
        });

        DB::table('trainers')->insert([['id' => 200, 'name' => '佐藤', 'role' => 'staff'], ['id' => 201, 'name' => '田中', 'role' => 'staff']]);
        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎', 'email' => 'yamada@example.com']);
        $this->trainee = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'アイ']);
    }

    private function actingAsTrainer(string $role = 'staff'): void
    {
        $trainer = new Trainer(['name' => '佐藤', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    private function fixtureCase(string $name): array
    {
        $json = json_decode(file_get_contents(__DIR__.'/../fixtures/personality-diagnosis-cases.json'), true, 512, JSON_THROW_ON_ERROR);

        return collect($json['cases'])->firstWhere('name', $name);
    }

    private function addResult(string $caseName, string $answeredAt): TraineePersonalityDiagnosisResult
    {
        $case = $this->fixtureCase($caseName);
        $attributes = [
            'trainee_id' => $this->trainee->id, 'answered_at' => $answeredAt, 'question_version' => 'v1',
            'answers' => $case['answers'], 'personality_type' => $case['expected']['type'],
        ];
        foreach ($case['expected']['scores'] as $category => $score) {
            $attributes['score_'.$category] = $score;
        }

        return TraineePersonalityDiagnosisResult::query()->create($attributes);
    }

    /** 記録を入れる（結果を渡せば結び付ける） */
    private function record(string $date, ?TraineePersonalityDiagnosisResult $result = null, ?Client $client = null): TrainingRecord
    {
        $id = DB::table('training_records')->insertGetId([
            'client_id' => ($client ?? $this->client)->id, 'training_date' => $date, 'trainer1_id' => 200,
            'record_content' => 'ノート '.$date, 'impression' => '所感 '.$date,
            'trainee_personality_diagnosis_result_id' => $result?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return TrainingRecord::query()->find($id);
    }

    /** レーダーチャートの canvas の data-personality-radar（JSON を戻したもの） */
    private function radarData(string $html): array
    {
        $this->assertSame(1, preg_match('/<canvas id="record-personality-radar"[^>]*data-personality-radar="([^"]*)"/u', $html, $m));

        return json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
    }

    private function trainerShow(TrainingRecord $record): string
    {
        $this->actingAsTrainer();

        return $this->get(route('training-records.show', $record))->assertOk()->getContent();
    }

    private function clientShow(TrainingRecord $record, ?Client $client = null): string
    {
        return $this->actingAs($client ?? $this->client, 'client')
            ->get(route('client-portal.training-records.show', $record))->assertOk()->getContent();
    }

    // ---- トレーナー側の記録の詳細（S-0403） ----

    public function test_トレーナー側の詳細はノートの上に欄を出しタイプとその時点のグラフと詳細へのリンク(): void
    {
        $this->addResult('past-3', '2025-12-01 10:00:00');
        $this->addResult('past-2', '2026-03-10 11:00:00');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $record = $this->record('2026-06-02', $result);

        $html = $this->trainerShow($record);

        $this->assertStringContainsString('<h6 class="mb-0">アイの愛犬性格診断（2026/06/01）</h6>', $html);
        $this->assertStringContainsString('data-record-personality-type>繊細・<wbr>デリケートタイプ</p>', $html);
        $this->assertStringContainsString('<a href="'.route('trainee-personality-diagnosis-results.show', [$this->trainee, $result]).'">愛犬性格診断の詳細</a>', $html);
        $this->assertSame(['2026/06/01', '2026/03/10', '2025/12/01'], array_column($this->radarData($html)['series'], 'date'));
        $this->assertStringContainsString('class="trainee-personality-radar mx-auto"', $html);
        $this->assertStringContainsString('personality-radar', $html);
        // メディアの下・ノートの上
        $section = strpos($html, 'data-record-personality-diagnosis');
        $this->assertLessThan($section, strpos($html, '>メディア（0件）</h6>'));
        $this->assertGreaterThan($section, strpos($html, '>トレーナーからのノート</h6>'));
    }

    public function test_あとで新しい結果が増えても記録の中のグラフはその時点のまま(): void
    {
        $this->addResult('past-2', '2026-03-10 11:00:00');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $record = $this->record('2026-06-02', $result);
        $before = $this->radarData($this->trainerShow($record));

        $this->addResult('past-3', '2026-09-01 09:00:00');

        $after = $this->trainerShow($record);
        $this->assertSame($before, $this->radarData($after));
        $this->assertSame(['2026/06/01', '2026/03/10'], array_column($before['series'], 'date'));
        // 値は今回の結果のもの
        $this->assertSame(array_values($this->fixtureCase('past-1')['expected']['display']), $before['series'][0]['values']);
    }

    public function test_結び付きのない記録には欄もJSも出さない(): void
    {
        $html = $this->trainerShow($this->record('2026-06-02'));

        $this->assertStringNotContainsString('data-record-personality-diagnosis', $html);
        $this->assertStringNotContainsString('personality-radar', $html);
        $html = $this->clientShow($this->record('2026-06-03'));
        $this->assertStringNotContainsString('data-record-personality-diagnosis', $html);
        $this->assertStringNotContainsString('personality-radar', $html);
    }

    public function test_結果を削除すると記録は残り欄は出ない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $record = $this->record('2026-06-02', $result);

        $result->delete();

        $html = $this->trainerShow($record->fresh());
        $this->assertStringContainsString('ノート 2026-06-02', $html);
        $this->assertStringNotContainsString('data-record-personality-diagnosis', $html);
        $this->assertStringNotContainsString('data-record-personality-diagnosis', $this->clientShow($record->fresh()));
    }

    // ---- 会員側の記録の詳細（S-1404） ----

    public function test_会員側の詳細は呼び名の見出しとタイプと小さなグラフで点数とリンクは出さない(): void
    {
        $this->addResult('past-2', '2026-03-10 11:00:00');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $record = $this->record('2026-06-02', $result);
        // あとから足した結果は使わない
        $this->addResult('past-3', '2026-09-01 09:00:00');

        $html = $this->clientShow($record);

        $this->assertStringContainsString('<p class="eyebrow" id="c-personality-heading">アイちゃんの愛犬性格診断（2026/06/01）</p>', $html);
        $this->assertStringContainsString('data-record-personality-type>繊細・<wbr>デリケートタイプ</p>', $html);
        $this->assertSame(['2026/06/01', '2026/03/10'], array_column($this->radarData($html)['series'], 'date'));
        $this->assertMatchesRegularExpression('/<canvas id="record-personality-radar" role="img" aria-label="アイちゃんの愛犬性格診断のグラフ"[^>]*data-personality-radar-compact><\/canvas>/u', $html);
        $this->assertStringContainsString('personality-radar', $html);
        // 点数の表・S-0313 へのリンク・評価文の状態・所感・注記は出さない
        $this->assertStringNotContainsString('personality-diagnosis-results', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('公開済み', $html);
        $this->assertStringNotContainsString('所感', $html);
        $this->assertStringNotContainsString('data-personality-radar-missing-note', $html);
        // 写真と動画の下（ここでは写真と動画がないので見出しの下）・ノートの上
        $this->assertLessThan(strpos($html, 'id="c-note-heading"'), strpos($html, 'data-record-personality-diagnosis'));
    }

    public function test_会員はほかの会員の記録を見られない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $record = $this->record('2026-06-02', $result);
        $other = Client::query()->create(['internal_id' => '0002', 'last_name' => '佐藤', 'email' => 'sato@example.com']);

        $this->actingAs($other, 'client')->get(route('client-portal.training-records.show', $record))->assertForbidden();
    }

    // ---- 一覧の印 ----

    public function test_トレーナー側の一覧は結び付いた記録だけに印を出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->record('2026-06-02', $result);
        $this->record('2026-06-03');
        $this->actingAsTrainer();

        $html = $this->get(route('training-records.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-record-personality-badge'));
        $this->assertStringContainsString('<td class="text-nowrap">2026/06/02<span class="badge rounded-pill text-bg-light border fw-normal ms-2" data-record-personality-badge>愛犬性格診断</span></td>', $html);
        $this->assertStringContainsString('<td class="text-nowrap">2026/06/03</td>', $html);
    }

    public function test_マイページは最新の記録とこれまでの記録の結び付いた記録だけに印を出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $old = $this->addResult('past-2', '2026-03-10 11:00:00');
        $this->record('2026-06-02', $result);
        $this->record('2026-05-01');
        $this->record('2026-03-11', $old);

        $html = $this->actingAs($this->client, 'client')->get(route('client-portal.dashboard'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-record-personality-badge'));
        $this->assertStringContainsString('<p class="c-session-tag mb-2" data-record-personality-badge>愛犬性格診断</p>', $html);
        // 最新の記録（2026/06/02）のカードに 1 つ
        $hero = substr($html, strpos($html, 'id="c-latest-heading"'), strpos($html, 'id="c-past-heading"') - strpos($html, 'id="c-latest-heading"'));
        $this->assertSame(1, substr_count($hero, 'data-record-personality-badge'));
    }

    // ---- 記録の更新（S-0404） ----

    public function test_記録の更新は会員と結び付けを受け取らない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $record = $this->record('2026-06-02', $result);
        $other = Client::query()->create(['internal_id' => '0002', 'last_name' => '佐藤']);
        $otherResult = $this->addResult('past-2', '2026-03-10 11:00:00');
        $this->actingAsTrainer();

        $this->put(route('training-records.update', $record), [
            'client_id' => $other->id,
            'trainee_personality_diagnosis_result_id' => $otherResult->id,
            'training_date' => '2026-06-05',
            'trainer1_id' => 201,
            'record_content' => '直したノート',
        ])->assertRedirect(route('training-records.show', $record));

        $fresh = $record->fresh();
        $this->assertSame($this->client->id, (int) $fresh->client_id);
        $this->assertSame($result->id, (int) $fresh->trainee_personality_diagnosis_result_id);
        // ほかの項目は今までどおり変わる。ノートを直しても結果の側の評価文は変わらない
        $this->assertSame('2026-06-05', $fresh->training_date->format('Y-m-d'));
        $this->assertSame(201, (int) $fresh->trainer1_id);
        $this->assertSame('直したノート', $fresh->record_content);
        $this->assertNull($result->fresh()->evaluation_text);
    }

    public function test_記録の更新は会員を送らなくても通る(): void
    {
        $record = $this->record('2026-06-02');
        $this->actingAsTrainer();

        $this->put(route('training-records.update', $record), ['training_date' => '2026-06-05', 'trainer1_id' => 200])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('training-records.show', $record));
        $this->assertSame($this->client->id, (int) $record->fresh()->client_id);
    }

    public function test_記録の登録は結び付けを受け取らない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->actingAsTrainer();

        $this->post(route('training-records.store'), [
            'client_id' => $this->client->id, 'training_date' => '2026-06-05', 'trainer1_id' => 200,
            'trainee_personality_diagnosis_result_id' => $result->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull(TrainingRecord::query()->sole()->trainee_personality_diagnosis_result_id);
        $this->assertSame('なし', $result->fresh()->evaluation_status_label);
    }
}

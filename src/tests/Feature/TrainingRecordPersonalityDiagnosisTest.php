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
 * トレーニング記録と愛犬性格診断の評価文（2026-10-18 追加。要件定義書 6-4-3〜6-4-5・6-15-6・6-15-7・6-16-16）。
 *
 * 2026-10-18 変更：評価文の公開でトレーニング記録を作らなくなったため（評価文と記録の本文の二重持ちをやめた）、
 * 段階1 の②で作った「記録の詳細の愛犬性格診断の欄」「一覧の印」を外した。ここでは外したことを確かめる。
 * - 記録の詳細（トレーナー側 S-0403・会員側 S-1404）に愛犬性格診断の欄もレーダーの JS も出さない（公開済みの評価文があっても）
 * - 一覧（トレーナー側 S-0402、マイページ）に印を出さない
 * - 記録の更新（S-0404）は会員（client_id）を受け取らない（この直しは残す）
 * 公開済みの評価文をマイページ・会員詳細に混ぜて出すのは次の段階
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

    /** 記録を入れる */
    private function record(string $date, ?Client $client = null): TrainingRecord
    {
        $id = DB::table('training_records')->insertGetId([
            'client_id' => ($client ?? $this->client)->id, 'training_date' => $date, 'trainer1_id' => 200,
            'record_content' => 'ノート '.$date, 'impression' => '所感 '.$date,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return TrainingRecord::query()->find($id);
    }

    /** 結果の評価文を公開済みにする */
    private function publishEvaluation(TraineePersonalityDiagnosisResult $result): void
    {
        $result->update(['evaluation_text' => '公開した評価文', 'evaluation_published_at' => '2026-06-02 10:00:00', 'evaluation_published_by' => 200]);
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

    // ---- 記録の詳細・一覧（②で作った愛犬性格診断の欄・印を外した。2026-10-18 変更） ----

    public function test_トレーナー側の記録の詳細には愛犬性格診断の欄もJSも出さない(): void
    {
        $this->publishEvaluation($this->addResult('past-1', '2026-06-01 09:00:00'));
        $record = $this->record('2026-06-02');

        $html = $this->trainerShow($record);

        $this->assertStringContainsString('ノート 2026-06-02', $html);
        $this->assertStringNotContainsString('data-record-personality-diagnosis', $html);
        $this->assertStringNotContainsString('愛犬性格診断', $html);
        $this->assertStringNotContainsString('personality-radar', $html);
    }

    public function test_会員側の記録の詳細には愛犬性格診断の欄もJSも出さない(): void
    {
        $this->publishEvaluation($this->addResult('past-1', '2026-06-01 09:00:00'));
        $record = $this->record('2026-06-02');

        $html = $this->clientShow($record);

        $this->assertStringContainsString('ノート 2026-06-02', $html);
        $this->assertStringNotContainsString('data-record-personality-diagnosis', $html);
        $this->assertStringNotContainsString('personality-radar', $html);
        $this->assertStringNotContainsString('公開した評価文', $html);
    }

    public function test_会員はほかの会員の記録を見られない(): void
    {
        $record = $this->record('2026-06-02');
        $other = Client::query()->create(['internal_id' => '0002', 'last_name' => '佐藤', 'email' => 'sato@example.com']);

        $this->actingAs($other, 'client')->get(route('client-portal.training-records.show', $record))->assertForbidden();
    }

    public function test_トレーナー側の一覧に愛犬性格診断の印を出さない(): void
    {
        $this->publishEvaluation($this->addResult('past-1', '2026-06-01 09:00:00'));
        $this->record('2026-06-02');
        $this->actingAsTrainer();

        $html = $this->get(route('training-records.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<td>2026/06/02</td>', $html);
        $this->assertStringNotContainsString('data-record-personality-badge', $html);
    }

    public function test_マイページの記録に愛犬性格診断の印を出さない(): void
    {
        $this->publishEvaluation($this->addResult('past-1', '2026-06-01 09:00:00'));
        $this->record('2026-06-02');
        $this->record('2026-05-01');

        $html = $this->actingAs($this->client, 'client')->get(route('client-portal.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-record-personality-badge', $html);
        $this->assertStringNotContainsString('<p class="c-session-tag', $html);
    }

    // ---- 記録の更新（S-0404） ----

    public function test_記録の更新は会員を受け取らない(): void
    {
        $record = $this->record('2026-06-02');
        $other = Client::query()->create(['internal_id' => '0002', 'last_name' => '佐藤']);
        $this->actingAsTrainer();

        $this->put(route('training-records.update', $record), [
            'client_id' => $other->id,
            'training_date' => '2026-06-05',
            'trainer1_id' => 201,
            'record_content' => '直したノート',
        ])->assertRedirect(route('training-records.show', $record));

        $fresh = $record->fresh();
        $this->assertSame($this->client->id, (int) $fresh->client_id);
        // ほかの項目は今までどおり変わる
        $this->assertSame('2026-06-05', $fresh->training_date->format('Y-m-d'));
        $this->assertSame(201, (int) $fresh->trainer1_id);
        $this->assertSame('直したノート', $fresh->record_content);
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
}

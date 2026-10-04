<?php

namespace Tests\Feature;

use App\Models\AudioRecord;
use App\Models\TrainingRecord;
use App\Services\TrainingRecordAutoCreateService;
use Tests\Concerns\CreatesRecordingTables;
use Tests\TestCase;

/**
 * トレーニング記録の自動作成の処理（TrainingRecordAutoCreateService。2026-10 に
 * TrainingRecordController::autoCreate から取り出した）が、取り出す前と同じ中身の記録を作ることの確認。
 */
class TrainingRecordAutoCreateServiceTest extends TestCase
{
    use CreatesRecordingTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAudioRecordsTable();
        $this->createTrainingRecordsTable();
    }

    public function test_音声記録の要約を記録内容にしてトレーニング記録を作る(): void
    {
        $audio = AudioRecord::forceCreate([
            'title' => 't',
            'status' => AudioRecord::STATUS_COMPLETED,
            'summary_text' => '要約の本文',
        ]);

        $record = app(TrainingRecordAutoCreateService::class)->create([
            'audio_record_id' => $audio->id,
            'client_id' => 3,
            'training_date' => '2026-10-04',
            'training_time' => '10:30',
            'trainer1_id' => 5,
            'trainer2_id' => 6,
        ]);

        $this->assertInstanceOf(TrainingRecord::class, $record);
        $fresh = TrainingRecord::findOrFail($record->id);
        $this->assertSame(3, (int) $fresh->client_id);
        $this->assertSame('2026-10-04', $fresh->training_date->format('Y-m-d'));
        $this->assertStringStartsWith('10:30', (string) $fresh->training_time);
        $this->assertSame(5, (int) $fresh->trainer1_id);
        $this->assertSame(6, (int) $fresh->trainer2_id);
        $this->assertSame('要約の本文', $fresh->record_content);
        $this->assertNull($fresh->impression);
    }

    public function test_時刻と担当2は省略できる(): void
    {
        $audio = AudioRecord::forceCreate([
            'title' => 't',
            'status' => AudioRecord::STATUS_COMPLETED,
            'summary_text' => '要約',
        ]);

        $record = app(TrainingRecordAutoCreateService::class)->create([
            'audio_record_id' => $audio->id,
            'client_id' => 3,
            'training_date' => '2026-10-04',
            'trainer1_id' => 5,
        ]);

        $fresh = TrainingRecord::findOrFail($record->id);
        $this->assertNull($fresh->training_time);
        $this->assertNull($fresh->trainer2_id);
    }
}

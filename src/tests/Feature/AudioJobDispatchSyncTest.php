<?php

namespace Tests\Feature;

use App\Http\Controllers\AudioRecordController;
use App\Jobs\SummarizeJob;
use App\Jobs\TranscribeAudioJob;
use App\Models\AudioRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 文字起こし・要約のジョブを、キューの設定にかかわらずその場で動かす（dispatchSync）ことの確認（2026-10）。
 *
 * 本プロジェクトのマイグレーションは MySQL 専用の CHECK 制約を生 SQL で追加するため、
 * テスト用 SQLite では RefreshDatabase が使えない（AudioRecordUpdateJsonTest と同じ事情）。
 * そこで、コントローラーが読み書きする列だけの audio_records テーブルをテストの中で作る。
 * ジョブは Bus::fake() で差し替え、実際の文字起こし・要約（外部の API）は動かさない。
 */
class AudioJobDispatchSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('audio_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainer_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('title')->nullable();
            $table->string('source')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('status');
            $table->text('transcription_text')->nullable();
            $table->text('summary_text')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->integer('file_size')->nullable();
            $table->timestamp('summarized_at')->nullable();
            $table->timestamps();
        });

        // 本番を想定して、キューの接続を database にする（文字起こし・要約はそれでもキューに乗らないこと）
        config([
            'queue.default' => 'database',
            'openai.api_key' => 'test-key',
            'services.anthropic.api_key' => 'test-key',
        ]);
    }

    public function test_文字起こしはキューがdatabaseでもその場で動かす(): void
    {
        Bus::fake();
        $record = AudioRecord::forceCreate([
            'title' => 't',
            'file_path' => 'audio/test.webm',
            'status' => AudioRecord::STATUS_UNPROCESSED,
        ]);

        app(AudioRecordController::class)->transcribe($record);

        Bus::assertDispatchedSync(TranscribeAudioJob::class);
        $this->assertCount(0, Bus::dispatched(TranscribeAudioJob::class), 'キューに積まれている');
    }

    public function test_要約はキューがdatabaseでもその場で動かす(): void
    {
        Bus::fake();
        $record = AudioRecord::forceCreate([
            'title' => 't',
            'transcription_text' => '文字起こし',
            'status' => AudioRecord::STATUS_TRANSCRIBED,
        ]);

        app(AudioRecordController::class)->summarize($record);

        Bus::assertDispatchedSync(SummarizeJob::class);
        $this->assertCount(0, Bus::dispatched(SummarizeJob::class), 'キューに積まれている');
    }
}

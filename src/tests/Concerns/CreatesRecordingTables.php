<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 録音のあとの処理（文字起こし・要約・トレーニング記録の自動作成）のテストで使うテーブルを作る。
 *
 * 本プロジェクトのマイグレーションは MySQL 専用の CHECK 制約を生 SQL で追加するため、
 * テスト用 SQLite では RefreshDatabase が使えない。そこで、テストが読み書きする列だけの
 * テーブルをテストの中で作る（AudioJobDispatchSyncTest・MediaQueueStatusTest と同じ事情）。
 */
trait CreatesRecordingTables
{
    protected function createAudioRecordsTable(): void
    {
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
    }

    protected function createTrainingRecordsTable(): void
    {
        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->date('training_date');
            $table->time('training_time')->nullable();
            $table->unsignedBigInteger('trainer1_id');
            $table->unsignedBigInteger('trainer2_id')->nullable();
            $table->text('record_content')->nullable();
            $table->text('impression')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
    }

    /**
     * 検証の exists のために、clients・trainers を id だけで作る
     */
    protected function createClientsAndTrainersTables(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
        });
    }
}

<?php

namespace App\Services;

use App\Models\AudioRecord;
use App\Models\TrainingRecord;
use Illuminate\Support\Facades\DB;

/**
 * 録音のあとのトレーニング記録の自動作成（2026-10 に TrainingRecordController::autoCreate から取り出した）
 *
 * 音声記録の要約を記録内容にして、トレーニング記録を 1 件作る。
 * コントローラー（API）とジョブ（キューで後ろで動かす）の両方から使う。中身は取り出す前と同じ。
 */
class TrainingRecordAutoCreateService
{
    /**
     * トレーニング記録を作る
     *
     * @param  array{audio_record_id: int, client_id: int, training_date: string, training_time?: ?string, trainer1_id: int, trainer2_id?: ?int}  $validated  検証済みの入力
     */
    public function create(array $validated): TrainingRecord
    {
        return DB::transaction(function () use ($validated) {
            $audioRecord = AudioRecord::findOrFail($validated['audio_record_id']);

            $record = TrainingRecord::create([
                'client_id' => $validated['client_id'],
                'training_date' => $validated['training_date'],
                'training_time' => $validated['training_time'] ?? null,
                'trainer1_id' => $validated['trainer1_id'],
                'trainer2_id' => $validated['trainer2_id'] ?? null,
                'record_content' => $audioRecord->summary_text,
            ]);

            return $record;
        });
    }
}

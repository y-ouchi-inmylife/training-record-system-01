<?php

namespace App\Jobs;

use App\Models\AudioRecord;
use App\Services\TrainingRecordAutoCreateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 録音のあとのトレーニング記録の自動作成ジョブ（2026-10）
 *
 * 録音実行の「作成する」から、「文字起こし → 要約 → トレーニング記録の作成」のひとつながりの
 * 最後として呼ばれる。記録の中身は TrainingRecordAutoCreateService（今の autoCreate の処理を
 * 取り出したもの）をそのまま使う。担当1・担当2 などの入力は、受け付けたときに検証してから渡す。
 *
 * キューの設定（QUEUE_CONNECTION）に従って、並び audio で動く。
 */
class CreateTrainingRecordFromAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ジョブを試行する回数（1 回だけ。くり返すと記録が二重にできるおそれがあるため）
     */
    public int $tries = 1;

    /**
     * @param  array{audio_record_id: int, client_id: int, training_date: string, training_time?: ?string, trainer1_id: int, trainer2_id?: ?int}  $input  検証済みの入力
     */
    public function __construct(
        private readonly array $input
    ) {
        $this->onQueue('audio');
    }

    public function handle(TrainingRecordAutoCreateService $service): void
    {
        $audioRecordId = $this->input['audio_record_id'];
        $audioRecord = AudioRecord::find($audioRecordId);

        if (!$audioRecord) {
            Log::warning("CreateTrainingRecordFromAudioJob: 音声記録が見つかりません (ID: {$audioRecordId})");
            return;
        }

        // 要約まで終わっていないとき（前の段階が例外を投げずに止まった場合など）は、記録を作らない。
        // 次の段階に進まない、というひとつながりの決まりに合わせる
        if ($audioRecord->status !== AudioRecord::STATUS_COMPLETED) {
            Log::info("CreateTrainingRecordFromAudioJob: 要約が完了していないためスキップ (ID: {$audioRecordId}, status: {$audioRecord->status})");
            return;
        }

        $record = $service->create($this->input);

        Log::info("CreateTrainingRecordFromAudioJob: トレーニング記録を作成しました (音声記録 ID: {$audioRecordId}, トレーニング記録 ID: {$record->id})");
    }

    /**
     * ジョブが失敗したときに呼ばれる。
     *
     * この段階では音声記録は要約まで終わっている（完了）ため、音声記録の状態は変えない（処理中のときだけ
     * エラーにする、というほかのジョブと同じ考え方）。ログだけ残す。
     */
    public function failed(?\Throwable $e): void
    {
        Log::error('CreateTrainingRecordFromAudioJob: トレーニング記録の作成に失敗しました (音声記録 ID: ' . $this->input['audio_record_id'] . '): ' . ($e?->getMessage() ?? '不明'));
    }
}

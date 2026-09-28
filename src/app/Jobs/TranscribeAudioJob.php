<?php

namespace App\Jobs;

use App\Exceptions\TranscriptionInputTooLargeException;
use App\Models\AudioRecord;
use App\Services\TranscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 音声ファイルの文字起こしジョブ
 *
 * Laravelキューで非同期実行される。
 * Whisper APIに音声ファイルを送信し、文字起こし結果をDBに保存する。
 *
 */
class TranscribeAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ジョブを試行する回数
     */
    public int $tries = 3;

    /**
     * ジョブがタイムアウトするまでの秒数
     */
    public int $timeout = 600;

    /**
     * リトライ間隔（秒）
     */
    public int $backoff = 60;

    public function __construct(
        private readonly int $audioRecordId
    ) {}

    public function handle(TranscriptionService $transcriptionService): void
    {
        $audioRecord = AudioRecord::find($this->audioRecordId);

        if (!$audioRecord) {
            Log::warning("TranscribeAudioJob: 音声ファイルが見つかりません (ID: {$this->audioRecordId})");
            return;
        }

        // 既に別の状態に遷移している場合はスキップ
        if ($audioRecord->status !== AudioRecord::STATUS_TRANSCRIBING) {
            Log::info("TranscribeAudioJob: ステータスが transcribing ではないためスキップ (ID: {$this->audioRecordId}, status: {$audioRecord->status})");
            return;
        }

        try {
            $result = $transcriptionService->transcribe($audioRecord->file_path);

            $audioRecord->update([
                'transcription_text' => $result['text'],
                'duration_seconds' => $result['duration'] ? (int) round($result['duration']) : null,
                'status' => AudioRecord::STATUS_TRANSCRIBED,
            ]);

            Log::info("TranscribeAudioJob: 文字起こし完了 (ID: {$this->audioRecordId})");

        } catch (TranscriptionInputTooLargeException $e) {
            // 再試行しても結果は変わらないため、即エラー確定
            Log::warning("TranscribeAudioJob: 変換後も上限超過のため中断 (ID: {$this->audioRecordId}): {$e->getMessage()}");
            $audioRecord->update(['status' => AudioRecord::STATUS_ERROR]);
            // 将来 QUEUE_CONNECTION をキューに切り替えたとき、tries を消費せず即失敗させる
            // （sync 実行時は fail() は実質何もしないが、キュー切替時の防御として明示的に呼ぶ）
            $this->fail($e);
            // sync 実行時：呼び出し元（コントローラ）にメッセージを伝える
            throw $e;

        } catch (\Throwable $e) {
            Log::error("TranscribeAudioJob: 文字起こし失敗 (ID: {$this->audioRecordId}, 試行 {$this->attempts()}/{$this->tries}): {$e->getMessage()}");

            // 最終試行時のみエラーステータスに更新
            if ($this->attempts() >= $this->tries) {
                $audioRecord->update([
                    'status' => AudioRecord::STATUS_ERROR,
                ]);
            }

            throw $e;
        }
    }
}

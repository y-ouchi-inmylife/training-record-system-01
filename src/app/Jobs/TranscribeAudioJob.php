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
 * Whisper APIに音声ファイルを送信し、文字起こし結果をDBに保存する。
 *
 * キューの設定（QUEUE_CONNECTION）に従って、並び audio で動く（本番は database ＋ 音声用のワーカー、
 * 開発はふだん sync でその場で動く。2026-10）。音声記録一覧の「文字起こし」から単独で、
 * 録音実行の「作成する」から「文字起こし → 要約 → トレーニング記録の作成」のひとつながりの最初として呼ばれる。
 */
class TranscribeAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * ジョブを試行する回数（外部の API を何度も呼ぶと費用がかかるため 1 回だけ。2026-10）
     */
    public int $tries = 1;

    /**
     * ジョブがタイムアウトするまでの秒数
     */
    public int $timeout = 600;

    public function __construct(
        private readonly int $audioRecordId
    ) {
        // 音声のジョブは並び audio で動かす（メディアの media と分け、文字起こしの間もサムネイルを待たせない）
        $this->onQueue('audio');
    }

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

        // キューで順番を待っていた間を数えないよう、処理の始めで updated_at を進める
        // （止まったとみなす判定〔PROCESSING_STALL_MINUTES〕が、文字起こしの段階の始めから数えられる。2026-10）
        $audioRecord->touch();

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

    /**
     * ジョブが失敗したときに呼ばれる（2026-10）。
     *
     * handle() の catch に入らない失敗（ワーカーの時間切れ〔--timeout〕でプロセスが止められた、
     * ワーカーが落ちて retry_after を過ぎ、試行の回数を超えた、など）でも、状態を「エラー」にする。
     * 文字起こし中のままのときだけ変える（handle() の catch ですでにエラーにした場合は何もしない）。
     */
    public function failed(?\Throwable $e): void
    {
        $updated = AudioRecord::where('id', $this->audioRecordId)
            ->where('status', AudioRecord::STATUS_TRANSCRIBING)
            ->update(['status' => AudioRecord::STATUS_ERROR]);

        if ($updated > 0) {
            Log::error("TranscribeAudioJob: 失敗のため状態をエラーにしました (ID: {$this->audioRecordId}): " . ($e?->getMessage() ?? '不明'));
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\AudioRecord;
use App\Services\SummarizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 文字起こしテキストの要約ジョブ
 *
 * Claude APIにテキストを送信し、要約結果をDBに保存する。
 *
 * キューの設定（QUEUE_CONNECTION）に従って、並び audio で動く（本番は database ＋ 音声用のワーカー、
 * 開発はふだん sync でその場で動く。2026-10）。音声記録一覧の「要約」から単独で、
 * 録音実行の「作成する」から文字起こしに続くひとつながりの 2 番目として呼ばれる。
 */
class SummarizeJob implements ShouldQueue
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

    /**
     * @param  bool  $continueFromTranscription  ひとつながり（文字起こし → 要約）の 2 番目として動くか。
     *   単独で呼ぶときはコントローラーが「要約中」にしてから渡すが、ひとつながりのときは文字起こしのジョブが
     *   「文字起こし済み」で終わるため、このジョブの始めで「要約中」にする（2026-10）
     */
    public function __construct(
        private readonly int $audioRecordId,
        private readonly bool $continueFromTranscription = false,
    ) {
        // 音声のジョブは並び audio で動かす（メディアの media と分け、文字起こしの間もサムネイルを待たせない）
        $this->onQueue('audio');
    }

    public function handle(SummarizationService $summarizationService): void
    {
        $audioRecord = AudioRecord::find($this->audioRecordId);

        if (!$audioRecord) {
            Log::warning("SummarizeJob: 音声ファイルが見つかりません (ID: {$this->audioRecordId})");
            return;
        }

        // ひとつながりの 2 番目のときは、文字起こし済みからここで「要約中」にする。
        // 状態が変わるので updated_at も進み、止まったとみなす判定が要約の段階の始めから数えられる
        if ($this->continueFromTranscription && $audioRecord->status === AudioRecord::STATUS_TRANSCRIBED) {
            $audioRecord->update(['status' => AudioRecord::STATUS_SUMMARIZING]);
        }

        // 既に別の状態に遷移している場合はスキップ
        if ($audioRecord->status !== AudioRecord::STATUS_SUMMARIZING) {
            Log::info("SummarizeJob: ステータスが summarizing ではないためスキップ (ID: {$this->audioRecordId}, status: {$audioRecord->status})");
            return;
        }

        // 文字起こしテキストが存在するか確認
        if (empty($audioRecord->transcription_text)) {
            Log::error("SummarizeJob: 文字起こしテキストが存在しません (ID: {$this->audioRecordId})");
            $audioRecord->update(['status' => AudioRecord::STATUS_ERROR]);
            return;
        }

        try {
            $summary = $summarizationService->summarize($audioRecord->transcription_text);

            $audioRecord->update([
                'summary_text' => $summary,
                'status' => AudioRecord::STATUS_COMPLETED,
                'summarized_at' => now(),
            ]);

            Log::info("SummarizeJob: 要約完了 (ID: {$this->audioRecordId})");

        } catch (\Throwable $e) {
            Log::error("SummarizeJob: 要約失敗 (ID: {$this->audioRecordId}, 試行 {$this->attempts()}/{$this->tries}): {$e->getMessage()}");

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
     * handle() の catch に入らない失敗（ワーカーの時間切れ・試行の回数を超えた、など）でも、状態を「エラー」にする。
     * 要約中のままのときだけ変える（handle() の catch ですでにエラーにした場合は何もしない）。
     */
    public function failed(?\Throwable $e): void
    {
        $updated = AudioRecord::where('id', $this->audioRecordId)
            ->where('status', AudioRecord::STATUS_SUMMARIZING)
            ->update(['status' => AudioRecord::STATUS_ERROR]);

        if ($updated > 0) {
            Log::error("SummarizeJob: 失敗のため状態をエラーにしました (ID: {$this->audioRecordId}): " . ($e?->getMessage() ?? '不明'));
        }
    }
}

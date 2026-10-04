<?php

namespace App\Jobs;

use App\Models\MediaRecord;
use App\Services\MediaConversionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * メディア表示用変換ジョブ
 *
 * 原本（heic/heif/mov）→ 表示用（jpeg/mp4）の変換を実行する。
 * キューの設定（QUEUE_CONNECTION）に従って動く：開発はふだん sync（その場で動く）、
 * 本番は database ＋ supervisor のワーカー（後ろで動く。アプリ構築手順書 第10段階）。
 * 画面は状態を返す API を問い合わせるため、どちらでも動く（2026-10）。
 *
 * type に応じて写真（heic/heif → jpeg）/ 動画（mov → mp4）の変換メソッドを振り分ける。
 */
class ConvertMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 変換の失敗は、くり返しても直らないことが多く、時間だけがかかるため 1 回だけ試す（2026-10）。
    // 1 回だけなので、handle() の「最後の試行のときだけエラーにする」は 1 回目で効く。
    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(
        private readonly int $mediaRecordId
    ) {}

    public function handle(MediaConversionService $conversionService): void
    {
        $mediaRecord = MediaRecord::find($this->mediaRecordId);

        if (!$mediaRecord) {
            Log::warning("ConvertMediaJob: メディアレコードが見つかりません (ID: {$this->mediaRecordId})");
            return;
        }

        // 既に別の状態に遷移している場合はスキップ（controller で processing にしてから dispatch）
        if ($mediaRecord->conversion_status !== MediaRecord::CONVERSION_PROCESSING) {
            Log::info("ConvertMediaJob: ステータスが processing ではないためスキップ (ID: {$this->mediaRecordId}, status: {$mediaRecord->conversion_status})");
            return;
        }

        try {
            // type で写真/動画を振り分け。default は DB CHECK 制約があるので実質到達しないが、
            // type 列挙が将来増えたとき気づけるよう保険として残す。
            $displayPath = match ($mediaRecord->type) {
                MediaRecord::TYPE_PHOTO => $conversionService->convertPhotoToJpeg($mediaRecord->original_path),
                MediaRecord::TYPE_VIDEO => $conversionService->convertVideoToMp4($mediaRecord->original_path),
                default => throw new \RuntimeException("未対応のメディア種別: {$mediaRecord->type}"),
            };

            $mediaRecord->update([
                'display_path' => $displayPath,
                'conversion_status' => MediaRecord::CONVERSION_DONE,
            ]);

            Log::info("ConvertMediaJob: 変換完了 (ID: {$this->mediaRecordId})");

        } catch (\Throwable $e) {
            Log::error("ConvertMediaJob: 変換失敗 (ID: {$this->mediaRecordId}, 試行 {$this->attempts()}/{$this->tries}): {$e->getMessage()}");

            // 最終試行時のみエラーステータスに更新（音声ジョブと同型）
            if ($this->attempts() >= $this->tries) {
                $mediaRecord->update([
                    'conversion_status' => MediaRecord::CONVERSION_ERROR,
                ]);
            }

            throw $e;
        }
    }

    /**
     * ジョブが失敗したときに呼ばれる。
     *
     * handle() の catch に入らない失敗（ワーカーの時間切れ〔--timeout〕でプロセスが止められた、
     * ワーカーが落ちて retry_after を過ぎ、試行の回数を超えた、など）でも、状態を「エラー」にする。
     * 処理中のままのときだけ変える（handle() の catch ですでにエラーにした場合は何もしない）。
     */
    public function failed(?\Throwable $e): void
    {
        $updated = MediaRecord::where('id', $this->mediaRecordId)
            ->where('conversion_status', MediaRecord::CONVERSION_PROCESSING)
            ->update(['conversion_status' => MediaRecord::CONVERSION_ERROR]);

        if ($updated > 0) {
            Log::error("ConvertMediaJob: 失敗のため状態をエラーにしました (ID: {$this->mediaRecordId}): " . ($e?->getMessage() ?? '不明'));
        }
    }
}

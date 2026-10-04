<?php

namespace App\Console\Commands;

use App\Models\AudioRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 保存期間を過ぎた音声ファイルを自動削除するコマンド
 *
 * スケジューラーから毎日実行される（時刻は bootstrap/app.php の withSchedule）。
 * 保存期間は --days の指定、なければ設定（.env の AUDIO_RETENTION_DAYS、初期値7）。有効な範囲は1〜30日。
 */
class DeleteExpiredAudioRecords extends Command
{
    protected $signature = 'audio-records:delete-expired {--days= : 保存期間（日数）。省略時は設定（AUDIO_RETENTION_DAYS）。1〜30}';
    protected $description = '保存期間を過ぎた音声ファイルを自動削除';

    public function handle(): int
    {
        Log::info('[DeleteExpiredAudioRecords] 音声ファイル自動削除バッチを開始します');

        // 保存期間が有効な範囲の外なら、削除の処理に進まずに終わる。
        // 設定の誤りに気づけるよう、warning ではなく error でログに残す（2026-10）
        $retentionDays = $this->resolveDays();
        if ($retentionDays === null) {
            return Command::FAILURE;
        }

        try {
            // 削除対象: 保存期間超過かつ音声ファイルがまだ残っているもの
            $expiredFiles = AudioRecord::where('created_at', '<', now()->subDays($retentionDays))
                ->whereNotNull('file_path')
                ->get();

            $deletedCount = 0;
            $deletedSize = 0;

            foreach ($expiredFiles as $file) {
                // 音声ファイルを削除
                if (Storage::exists($file->file_path)) {
                    $deletedSize += $file->file_size ?? 0;
                    Storage::delete($file->file_path);
                }

                // file_path を NULL に更新（文字起こし・要約は保持）
                $file->update(['file_path' => null]);
                $deletedCount++;
            }

            $sizeMb = round($deletedSize / 1024 / 1024, 2);
            $message = "[DeleteExpiredAudioRecords] 音声ファイル自動削除完了: {$deletedCount}件（{$sizeMb} MB）保存期間: {$retentionDays}日。文字起こし・要約データは保持";

            $this->info($message);
            Log::info($message);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $errorMessage = '[DeleteExpiredAudioRecords] エラー: ' . $e->getMessage();
            $this->error($errorMessage);
            Log::error($errorMessage, ['exception' => $e]);

            return Command::FAILURE;
        }
    }

    /**
     * 使う日数を決め、有効な範囲（1〜30日）の整数かを確かめる。
     * --days の指定がなければ、設定（config/batch.php の audio_retention_days。.env の AUDIO_RETENTION_DAYS）を使う。
     * 範囲の外・整数でないときは、エラーをログに残して null を返す（範囲の端に丸めず、処理に進ませない。2026-10）
     */
    private function resolveDays(): ?int
    {
        $option = $this->option('days');
        $source = $option !== null ? '--days' : 'AUDIO_RETENTION_DAYS';
        $value = $option ?? config('batch.audio_retention_days');

        // .env の true などが整数に読み替えられないよう、整数か文字列のときだけ確かめる
        $days = (is_int($value) || is_string($value))
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]])
            : false;

        if ($days === false) {
            $message = "[DeleteExpiredAudioRecords] 保存期間が有効な範囲の外のため、何もせずに終了します: {$source}=" . var_export($value, true) . '（有効な範囲は1〜30日の整数）';
            $this->error($message);
            Log::error($message);

            return null;
        }

        return $days;
    }
}

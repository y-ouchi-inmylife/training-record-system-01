<?php

namespace App\Console\Commands;

use App\Models\Trainer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 未使用（発行後一度もログインがない）トレーナーを自動ロックするコマンド
 *
 * 使用例:
 *   php artisan trainers:lock-unused
 *   php artisan trainers:lock-unused --days=14
 *
 * 日数は --days の指定、なければ設定（.env の COUNSELOR_LOCK_UNUSED_DAYS、初期値7）。有効な範囲は1〜90日。
 */
class LockUnusedTrainers extends Command
{
    protected $signature = 'trainers:lock-unused {--days= : 作成日からの経過日数のしきい値。省略時は設定（COUNSELOR_LOCK_UNUSED_DAYS）。1〜90}';

    protected $description = '発行後一度もログインがないトレーナーを作成日から指定日数経過でロックします';

    public function handle(): int
    {
        Log::info('[LockUnusedTrainers] アカウントロック（未使用）バッチを開始します');

        // 日数が有効な範囲の外なら、ロックの処理に進まずに終わる
        $days = $this->resolveDays();
        if ($days === null) {
            return Command::FAILURE;
        }

        try {
            $threshold = Carbon::now()->subDays($days);

            $this->info("発行後一度もログインがなく、作成から{$days}日以上経過したトレーナーをチェックします...");

            // 新規発行後に使われないままのアカウント対策のバッチ。
            // 一度もログインしていない（last_login_at が null の）アカウントのうち、作成日時が指定日数以上前のものを対象とする。
            // ログイン履歴のあるアカウント（退職・休職者対策）は trainers:lock-inactive の領域のため対象外とする（No.200）。
            $candidates = Trainer::where('is_active', true)
                ->where('is_locked', false)
                ->where('role', '!=', 'system_admin') // system_adminは自動ロック対象外
                ->whereNull('last_login_at') // 一度もログインしていないアカウントのみを対象とする
                ->where('created_at', '<', $threshold)
                ->get();

            $count = 0;
            foreach ($candidates as $trainer) {
                // 最後の有効な管理者（admin）はロックしない（system_adminは別途除外済み）
                if ($trainer->role === 'admin') {
                    $activeAdminCount = Trainer::where('role', 'admin')
                        ->where('is_active', true)
                        ->where('is_locked', false)
                        ->count();

                    if ($activeAdminCount <= 1) {
                        $this->warn("有効な管理者が1人のため、{$trainer->name} をロックしませんでした。");

                        continue;
                    }
                }

                $trainer->update(['is_locked' => true]);
                $count++;

                $createdAt = $trainer->created_at?->format('Y-m-d') ?? '不明';
                $this->info("  ロック: {$trainer->name}（作成日: {$createdAt}）");
            }

            $message = "{$count}件のトレーナーをロックしました。";
            $this->info($message);
            Log::info("[LockUnusedTrainers] {$message}");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $errorMessage = '[LockUnusedTrainers] エラー: ' . $e->getMessage();
            $this->error($errorMessage);
            Log::error($errorMessage, ['exception' => $e]);

            return Command::FAILURE;
        }
    }

    /**
     * 使う日数を決め、有効な範囲（1〜90日）の整数かを確かめる。
     * --days の指定がなければ、設定（config/batch.php の lock_unused_days。.env の COUNSELOR_LOCK_UNUSED_DAYS）を使う。
     * 範囲の外・整数でないときは、エラーをログに残して null を返す（範囲の端に丸めず、処理に進ませない。2026-10）
     */
    private function resolveDays(): ?int
    {
        $option = $this->option('days');
        $source = $option !== null ? '--days' : 'COUNSELOR_LOCK_UNUSED_DAYS';
        $value = $option ?? config('batch.lock_unused_days');

        // .env の true などが整数に読み替えられないよう、整数か文字列のときだけ確かめる
        $days = (is_int($value) || is_string($value))
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]])
            : false;

        if ($days === false) {
            $message = "[LockUnusedTrainers] 日数が有効な範囲の外のため、何もせずに終了します: {$source}=" . var_export($value, true) . '（有効な範囲は1〜90日の整数）';
            $this->error($message);
            Log::error($message);

            return null;
        }

        return $days;
    }
}

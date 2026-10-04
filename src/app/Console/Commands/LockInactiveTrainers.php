<?php

namespace App\Console\Commands;

use App\Models\Trainer;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 30日間ログインがないトレーナーを自動ロックするコマンド
 *
 * 使用例:
 *   php artisan trainers:lock-inactive
 *   php artisan trainers:lock-inactive --days=60
 *
 * 日数は --days の指定、なければ設定（.env の COUNSELOR_LOCK_INACTIVE_DAYS、初期値30）。有効な範囲は1〜90日。
 */
class LockInactiveTrainers extends Command
{
    protected $signature = 'trainers:lock-inactive {--days= : 未ログイン日数のしきい値。省略時は設定（COUNSELOR_LOCK_INACTIVE_DAYS）。1〜90}';

    protected $description = '指定日数ログインがないトレーナーを自動ロックします';

    public function handle(): int
    {
        Log::info('[LockInactiveTrainers] アカウントロック（長期間未ログイン）バッチを開始します');

        // 日数が有効な範囲の外なら、ロックの処理に進まずに終わる
        $days = $this->resolveDays();
        if ($days === null) {
            return Command::FAILURE;
        }

        try {
            $threshold = Carbon::now()->subDays($days);

            $this->info("{$days}日間ログインがないトレーナーをチェックします...");

            // 退職・休職者対策のバッチ。最終ログイン日時を持ち、かつ指定日数以上前のアカウントのみを対象とする。
            // 一度もログインしていない（last_login_at が null の）アカウントは作成直後でも誤ロックされるため対象外とし、
            // 未使用の新規発行アカウント対策は別途分離する（No.199 / No.200）。
            $candidates = Trainer::where('is_active', true)
                ->where('is_locked', false)
                ->where('role', '!=', 'system_admin') // system_adminは自動ロック対象外
                ->whereNotNull('last_login_at') // 未ログインアカウントは判定対象から除外（No.199）
                ->where('last_login_at', '<', $threshold)
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

                $lastLogin = $trainer->last_login_at?->format('Y-m-d') ?? '未ログイン';
                $this->info("  ロック: {$trainer->name}（最終ログイン: {$lastLogin}）");
            }

            $message = "{$count}件のトレーナーをロックしました。";
            $this->info($message);
            Log::info("[LockInactiveTrainers] {$message}");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $errorMessage = '[LockInactiveTrainers] エラー: ' . $e->getMessage();
            $this->error($errorMessage);
            Log::error($errorMessage, ['exception' => $e]);

            return Command::FAILURE;
        }
    }

    /**
     * 使う日数を決め、有効な範囲（1〜90日）の整数かを確かめる。
     * --days の指定がなければ、設定（config/batch.php の lock_inactive_days。.env の COUNSELOR_LOCK_INACTIVE_DAYS）を使う。
     * 範囲の外・整数でないときは、エラーをログに残して null を返す（範囲の端に丸めず、処理に進ませない。2026-10）
     */
    private function resolveDays(): ?int
    {
        $option = $this->option('days');
        $source = $option !== null ? '--days' : 'COUNSELOR_LOCK_INACTIVE_DAYS';
        $value = $option ?? config('batch.lock_inactive_days');

        // .env の true などが整数に読み替えられないよう、整数か文字列のときだけ確かめる
        $days = (is_int($value) || is_string($value))
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]])
            : false;

        if ($days === false) {
            $message = "[LockInactiveTrainers] 日数が有効な範囲の外のため、何もせずに終了します: {$source}=" . var_export($value, true) . '（有効な範囲は1〜90日の整数）';
            $this->error($message);
            Log::error($message);

            return null;
        }

        return $days;
    }
}

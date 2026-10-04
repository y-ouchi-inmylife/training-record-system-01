<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 未認証時のリダイレクト先。/client-portal/* 配下はクライアント用ログインへ振り分ける。
        // ※ redirectUsersTo のクライアント分岐と CheckIpRestriction の /client-portal/* 除外は塊E骨で対応。
        $middleware->redirectGuestsTo(function ($request) {
            return $request->is('client-portal/*') ? '/client-portal/login' : '/login';
        });
        // 認証済みユーザーがguestルートにアクセスした場合のリダイレクト先。
        // URL 判定を先頭に置くことで、$request->user()（デフォルトweb guard=Trainer 前提）を
        // クライアント認証済みリクエストで呼ばずに済ませる（?-> で null 経由で誤った先へ飛ぶ回避）。
        // トレーナー側: システム管理者は音声ファイル一覧（S-0701）、それ以外はダッシュボード（S-0101）へ。
        $middleware->redirectUsersTo(function ($request) {
            if ($request->is('client-portal/*')) {
                return '/client-portal/dashboard';
            }
            return $request->user()?->isSystemAdmin() ? '/usage-stats' : '/dashboard';
        });
        // セッションを役割別に分離するため、StartSession より前に config 上書き。
        // web/api 両経路で効かせるため、グローバル prepend で登録する
        // （web グループ限定の prepend だと、api.php の ->middleware(['web',...]) 経路に効かないため）。
        $middleware->prepend([
            \App\Http\Middleware\ConfigureSessionByRole::class,
        ]);
        // webミドルウェアグループにパスワード変更チェックを追加
        // IP制限（CheckIpRestriction）はトレーナー用サブドメインのルートにのみ適用するため、
        // web append からは外し、ミドルウェアエイリアス経由でトレーナー用グループに付与する。
        $middleware->web(append: [
            \App\Http\Middleware\CheckPasswordChange::class,
            \App\Http\Middleware\LogAccess::class,
        ]);
        // ミドルウェアエイリアス
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'admin-only' => \App\Http\Middleware\AdminOnlyMiddleware::class,
            'system-admin-only' => \App\Http\Middleware\SystemAdminOnlyMiddleware::class,
            'practitioners' => \App\Http\Middleware\PractitionersMiddleware::class,
            'check-ip' => \App\Http\Middleware\CheckIpRestriction::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // 定期的に動かすバッチ。本番の cron は schedule:run を毎分動かす 1 行だけにし、
        // 時刻はここにまとめる（php artisan schedule:list で確かめられる）。
        // 時刻は 10 分ずつずらし、バッチが重ならないようにする（2026-10）。
        // 日数は --days を付けずに呼び、設定（config/batch.php。.env）の値を使う。
        // 重なり防止の印の有効期限は 60 分（既定の 24 時間だと、落ちて印が残ったときに翌日の同じ時刻の回が飛ばされるため。各バッチは数分で終わる）。

        // B-0201 長期間ログインしていないアカウントのロック（COUNSELOR_LOCK_INACTIVE_DAYS）
        $schedule->command('trainers:lock-inactive')->dailyAt('02:00')->withoutOverlapping(60);
        // B-0202 一度も使われていないアカウントのロック（COUNSELOR_LOCK_UNUSED_DAYS）
        $schedule->command('trainers:lock-unused')->dailyAt('02:10')->withoutOverlapping(60);
        // B-0101 保存期間を過ぎた音声ファイルの削除（AUDIO_RETENTION_DAYS）
        $schedule->command('audio-records:delete-expired')->dailyAt('02:20')->withoutOverlapping(60);
        // B-0301 DB のバックアップ（R2 へ送る）。出力は、cron から直接動かしていたときと同じログに出す
        $schedule->command('db:backup')->dailyAt('02:30')->withoutOverlapping(60)
            ->appendOutputTo(storage_path('logs/cron-backup.log'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

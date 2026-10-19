<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 愛犬性格診断のアドバイスの AI の自動生成の実装は、押したボタンの AI の名前から EvaluationDraftProviders が選ぶ
        // （2026-10-20 変更：その前は、ここで設定の driver から Claude の実装を bind していた）
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Bootstrap 5のページネーションを使用
        Paginator::useBootstrapFive();
    }
}

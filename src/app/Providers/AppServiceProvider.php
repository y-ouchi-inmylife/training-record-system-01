<?php

namespace App\Providers;

use App\Services\EvaluationDraft\ClaudeEvaluationDraftGenerator;
use App\Services\EvaluationDraft\EvaluationDraftGenerator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 愛犬性格診断のアドバイスの AI の下書き（2026-10-19 追加）。設定の driver で AI の会社ごとの実装を選ぶ
        // （今は Claude だけ。Gemini にするときは実装を足して、ここの match に足す）
        $this->app->bind(EvaluationDraftGenerator::class, function () {
            return match (config('services.evaluation_draft.driver')) {
                default => new ClaudeEvaluationDraftGenerator,
            };
        });
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

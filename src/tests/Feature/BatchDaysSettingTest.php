<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * バッチの日数の設定（config/batch.php）と、範囲の確認のテスト（2026-10）。
 *
 * - 設定の初期値（.env に書いていないとき 30・7・7）が読めること
 * - 日数が有効な範囲の外・整数でないときに、各コマンドが失敗の終了コードで終わり、
 *   エラーのログを残し、ロック・削除の処理に進まないこと
 *
 * ロック・削除の処理は動かさない。処理に進まないことは、DB へのクエリが
 * 1 回も発行されないこと（beforeExecuting で数える）で確かめる。
 */
class BatchDaysSettingTest extends TestCase
{
    public function test_設定の初期値が読める(): void
    {
        $this->assertSame(30, config('batch.lock_inactive_days'));
        $this->assertSame(7, config('batch.lock_unused_days'));
        $this->assertSame(7, config('batch.audio_retention_days'));
    }

    /**
     * 範囲の外・整数でない --days の指定
     *
     * @return array<string, array{string, string}>
     */
    public static function invalidDaysOptionProvider(): array
    {
        return [
            'ロック（長期間）0' => ['trainers:lock-inactive', '0'],
            'ロック（長期間）91' => ['trainers:lock-inactive', '91'],
            'ロック（長期間）-1' => ['trainers:lock-inactive', '-1'],
            'ロック（長期間）abc' => ['trainers:lock-inactive', 'abc'],
            'ロック（長期間）1.5' => ['trainers:lock-inactive', '1.5'],
            'ロック（未使用）0' => ['trainers:lock-unused', '0'],
            'ロック（未使用）91' => ['trainers:lock-unused', '91'],
            'ロック（未使用）-1' => ['trainers:lock-unused', '-1'],
            'ロック（未使用）abc' => ['trainers:lock-unused', 'abc'],
            '音声の削除 0' => ['audio-records:delete-expired', '0'],
            '音声の削除 31' => ['audio-records:delete-expired', '31'],
            '音声の削除 -1' => ['audio-records:delete-expired', '-1'],
            '音声の削除 abc' => ['audio-records:delete-expired', 'abc'],
        ];
    }

    #[DataProvider('invalidDaysOptionProvider')]
    public function test_範囲の外の_days_の指定では処理に進まずエラーを残す(string $command, string $days): void
    {
        $this->assertStopsWithRangeError($command, ['--days' => $days], '--days=');
    }

    /**
     * 範囲の外・整数でない設定の値（.env から読んだ値を想定）
     *
     * @return array<string, array{string, string, string, mixed}>
     */
    public static function invalidDaysConfigProvider(): array
    {
        return [
            'ロック（長期間）0' => ['trainers:lock-inactive', 'batch.lock_inactive_days', 'COUNSELOR_LOCK_INACTIVE_DAYS', '0'],
            'ロック（長期間）91' => ['trainers:lock-inactive', 'batch.lock_inactive_days', 'COUNSELOR_LOCK_INACTIVE_DAYS', '91'],
            'ロック（長期間）空' => ['trainers:lock-inactive', 'batch.lock_inactive_days', 'COUNSELOR_LOCK_INACTIVE_DAYS', ''],
            'ロック（長期間）true' => ['trainers:lock-inactive', 'batch.lock_inactive_days', 'COUNSELOR_LOCK_INACTIVE_DAYS', true],
            'ロック（未使用）0' => ['trainers:lock-unused', 'batch.lock_unused_days', 'COUNSELOR_LOCK_UNUSED_DAYS', '0'],
            'ロック（未使用）abc' => ['trainers:lock-unused', 'batch.lock_unused_days', 'COUNSELOR_LOCK_UNUSED_DAYS', 'abc'],
            '音声の削除 31' => ['audio-records:delete-expired', 'batch.audio_retention_days', 'AUDIO_RETENTION_DAYS', '31'],
            '音声の削除 -1' => ['audio-records:delete-expired', 'batch.audio_retention_days', 'AUDIO_RETENTION_DAYS', '-1'],
            '音声の削除 null' => ['audio-records:delete-expired', 'batch.audio_retention_days', 'AUDIO_RETENTION_DAYS', null],
        ];
    }

    #[DataProvider('invalidDaysConfigProvider')]
    public function test_範囲の外の設定の値では処理に進まずエラーを残す(string $command, string $key, string $envName, mixed $value): void
    {
        config([$key => $value]);
        $this->assertStopsWithRangeError($command, [], $envName . '=');
    }

    /**
     * コマンドが失敗の終了コードで終わり、範囲の外のエラーを 1 回だけログに残し、
     * DB に触れない（ロック・削除の処理に進まない）ことを確かめる
     */
    private function assertStopsWithRangeError(string $command, array $parameters, string $expectedSource): void
    {
        Log::spy();

        $queries = 0;
        DB::connection()->beforeExecuting(function () use (&$queries) {
            $queries++;
        });

        $this->artisan($command, $parameters)->assertExitCode(1)->run();

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, '有効な範囲の外のため、何もせずに終了します')
                && str_contains($message, $expectedSource));
        $this->assertSame(0, $queries, 'ロック・削除の処理に進んでいる（DB へのクエリが発行された）');
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * バッチのスケジュールの登録（bootstrap/app.php の withSchedule）のテスト（2026-10）。
 *
 * 4 つのバッチが、決めた時刻（10 分ずつずらす）で、重ならない指定（withoutOverlapping）付きで
 * 登録されていることを確かめる。バッチそのものは動かさない。
 */
class BatchScheduleTest extends TestCase
{
    /**
     * 登録されているイベントを、コマンド名（artisan のあとの部分）をキーにして返す
     *
     * @return array<string, Event>
     */
    private function scheduledEvents(): array
    {
        // withSchedule の登録は Artisan の起動時に行われるため、Artisan を起動させてから取り出す
        $this->app->make(Kernel::class)->all();

        $events = [];
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            $name = trim(substr($event->command, strpos($event->command, 'artisan') + strlen('artisan')), " '\"");
            $events[$name] = $event;
        }

        return $events;
    }

    public function test_4つのバッチが決めた時刻で登録される(): void
    {
        $events = $this->scheduledEvents();

        $expected = [
            'trainers:lock-inactive' => '0 2 * * *',
            'trainers:lock-unused' => '10 2 * * *',
            'audio-records:delete-expired' => '20 2 * * *',
            'db:backup' => '30 2 * * *',
        ];

        $this->assertSame(array_keys($expected), array_keys($events));
        foreach ($expected as $command => $expression) {
            $this->assertSame($expression, $events[$command]->expression, $command);
            $this->assertTrue($events[$command]->withoutOverlapping, $command . ' に withoutOverlapping がない');
        }
    }

    public function test_日数は_days_を付けずに設定の値を使う(): void
    {
        foreach ($this->scheduledEvents() as $command => $event) {
            $this->assertStringNotContainsString('--days', $command);
        }
    }

    public function test_バックアップの出力は_cron_backup_log_に追記する(): void
    {
        $backup = $this->scheduledEvents()['db:backup'];

        $this->assertSame(storage_path('logs/cron-backup.log'), $backup->output);
        $this->assertTrue($backup->shouldAppendOutput);
    }
}

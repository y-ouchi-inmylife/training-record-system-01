<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * キューの設定の確認（2026-10）。
 *
 * database のキューの retry_after（ジョブが戻ってこないとみなすまでの秒数）が、
 * ジョブの時間の上限（600 秒）より長いこと。短いと、長いジョブが二重に動く。
 */
class QueueConfigTest extends TestCase
{
    public function test_databaseのキューのretry_afterの既定値は660秒(): void
    {
        $this->assertSame(660, config('queue.connections.database.retry_after'));
    }
}

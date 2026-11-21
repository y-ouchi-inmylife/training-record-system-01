<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientUsage;
use App\Services\UsageCapacityService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 同時に預かれる数の数え方（2026-11 追加。requirements.md 6-17-11）。
 *
 * - 利用 1 件を 1 と数える（トレーニーがない利用も 1 と数える）・事業所全体（すべての会員の利用）で数える
 * - 同じ日の中で時間帯が重なる利用を数える。時間帯は終わりを含まない
 * - 「ある時間帯の預かる数」「ある日の預かる数」は、最も多く重なる瞬間の預かる数
 * - 編集中の利用自身は数えない（除く利用）
 */
class UsageCapacityServiceTest extends TestCase
{
    use CreatesClientCountTables;

    private UsageCapacityService $service;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createTraineesTableForUsage();
        $this->createClientCountTables();

        $this->service = new UsageCapacityService;
        $this->client = $this->makeClient('0001', '山田');
    }

    private function makeClient(string $internalId, string $lastName): Client
    {
        return Client::query()->create(['internal_id' => $internalId, 'last_name' => $lastName, 'first_name' => '太郎']);
    }

    /** 利用を 1 件入れる（時刻は「HH:MM」。DB には「HH:MM:SS」で入れる） */
    private function usage(string $date, string $start, string $end, ?Client $client = null, ?int $traineeId = null): ClientUsage
    {
        return ClientUsage::query()->create([
            'client_id' => ($client ?? $this->client)->id,
            'trainee_id' => $traineeId,
            'used_on' => $date,
            'start_time' => $start.':00',
            'end_time' => $end.':00',
        ]);
    }

    // ---- ある日の預かる数 ----

    public function test_重なる利用を数える(): void
    {
        $this->usage('2026-11-26', '08:00', '19:00');
        $this->usage('2026-11-26', '13:00', '19:00');
        $this->usage('2026-11-26', '10:00', '12:00');

        // 10:00〜12:00 は 8:00〜19:00 と重なり 2、13:00〜19:00 も 2。3 つが同時に重なる瞬間はない
        $this->assertSame(2, $this->service->dayCount('2026-11-26'));
    }

    public function test_終わりを含まない_12時までと12時からは重ならない(): void
    {
        $this->usage('2026-11-26', '08:00', '12:00');
        $this->usage('2026-11-26', '12:00', '19:00');

        $this->assertSame(1, $this->service->dayCount('2026-11-26'));
    }

    public function test_一部だけ重なる利用も重なりとして数える(): void
    {
        $this->usage('2026-11-26', '08:00', '12:00');
        $this->usage('2026-11-26', '11:59', '19:00');

        $this->assertSame(2, $this->service->dayCount('2026-11-26'));
    }

    public function test_トレーニーなしの利用も1と数える(): void
    {
        DB::table('trainees')->insert(['id' => 10, 'client_id' => $this->client->id, 'name' => 'ポチ']);
        $this->usage('2026-11-26', '08:00', '19:00', traineeId: 10);
        $this->usage('2026-11-26', '08:00', '19:00'); // トレーニーなし

        $this->assertSame(2, $this->service->dayCount('2026-11-26'));
    }

    public function test_事業所全体で数える_ほかの会員の利用も入れる(): void
    {
        $other = $this->makeClient('0002', '佐藤');
        $this->usage('2026-11-26', '08:00', '19:00');
        $this->usage('2026-11-26', '09:00', '10:00', $other);

        $this->assertSame(2, $this->service->dayCount('2026-11-26'));
    }

    public function test_ほかの日の利用は数えない(): void
    {
        $this->usage('2026-11-25', '08:00', '19:00');
        $this->usage('2026-11-27', '08:00', '19:00');

        $this->assertSame(0, $this->service->dayCount('2026-11-26'));
    }

    public function test_除く利用は数えない(): void
    {
        $this->usage('2026-11-26', '08:00', '19:00');
        $self = $this->usage('2026-11-26', '08:00', '19:00');

        $this->assertSame(2, $this->service->dayCount('2026-11-26'));
        $this->assertSame(1, $this->service->dayCount('2026-11-26', $self->id));
    }

    // ---- 月の日ごとの預かる数 ----

    public function test_月の日ごとの預かる数は1以上の日だけを日付の順に返す(): void
    {
        $this->usage('2026-11-30', '08:00', '19:00');
        $this->usage('2026-11-01', '08:00', '12:00');
        $this->usage('2026-11-01', '12:00', '19:00'); // 終わりを含まないので 1のまま
        $a = $this->usage('2026-11-26', '08:00', '19:00');
        $this->usage('2026-11-26', '13:00', '19:00', $this->makeClient('0002', '佐藤'));
        // 前後の月は入れない
        $this->usage('2026-10-31', '08:00', '19:00');
        $this->usage('2026-12-01', '08:00', '19:00');

        $this->assertSame(['2026-11-01' => 1, '2026-11-26' => 2, '2026-11-30' => 1], $this->service->monthCounts('2026-11'));
        // 除く利用
        $this->assertSame(['2026-11-01' => 1, '2026-11-26' => 1, '2026-11-30' => 1], $this->service->monthCounts('2026-11', $a->id));
    }

    // ---- その時間帯に 1 件入れたときの預かる数 ----

    public function test_入れる利用も1と数え_預かる数が最も多くなる時間帯を返す(): void
    {
        $this->usage('2026-11-26', '08:00', '19:00');
        $this->usage('2026-11-26', '13:00', '19:00');
        $this->usage('2026-11-26', '15:00', '17:00');

        // 14:00〜18:00 に入れると、15:00〜17:00 が 4（8:00〜19:00・13:00〜19:00・15:00〜17:00・入れる利用）
        $this->assertSame(['count' => 4, 'start' => '15:00', 'end' => '17:00'], $this->service->peakWith('2026-11-26', '14:00', '18:00'));
    }

    public function test_重なる利用がなければ1で_時間帯はその利用の時間帯(): void
    {
        $this->usage('2026-11-26', '08:00', '12:00');

        // 12:00 からは重ならない（終わりを含まない）
        $this->assertSame(['count' => 1, 'start' => '12:00', 'end' => '19:00'], $this->service->peakWith('2026-11-26', '12:00', '19:00'));
    }

    public function test_最も多くなる時間帯はその利用の時間帯の中に切り詰める(): void
    {
        $this->usage('2026-11-26', '08:00', '19:00');

        $this->assertSame(['count' => 2, 'start' => '10:00', 'end' => '11:00'], $this->service->peakWith('2026-11-26', '10:00', '11:00'));
    }

    public function test_同じ預かる数が続く時間帯はつなげて返す(): void
    {
        // 9:00〜12:00 と 12:00〜15:00（つながっている）に重なる → 8:00〜16:00 に入れると 9:00〜15:00 がずっと 2
        $this->usage('2026-11-26', '09:00', '12:00');
        $this->usage('2026-11-26', '12:00', '15:00');

        $this->assertSame(['count' => 2, 'start' => '09:00', 'end' => '15:00'], $this->service->peakWith('2026-11-26', '08:00', '16:00'));
    }

    public function test_最も多くなる時間帯が離れて2つあるときは早いほう(): void
    {
        $this->usage('2026-11-26', '09:00', '10:00');
        $this->usage('2026-11-26', '14:00', '15:00');

        $this->assertSame(['count' => 2, 'start' => '09:00', 'end' => '10:00'], $this->service->peakWith('2026-11-26', '08:00', '19:00'));
    }

    public function test_入れる利用の時間帯の外の重なりは数えない(): void
    {
        // 8:00〜10:00 に 3重なっていても、10:00〜12:00 に入れる利用の預かる数には入れない
        $this->usage('2026-11-26', '08:00', '10:00');
        $this->usage('2026-11-26', '08:00', '10:00');
        $this->usage('2026-11-26', '08:00', '11:00');

        $this->assertSame(['count' => 2, 'start' => '10:00', 'end' => '11:00'], $this->service->peakWith('2026-11-26', '10:00', '12:00'));
    }

    public function test_編集中の利用自身は数えない(): void
    {
        $this->usage('2026-11-26', '08:00', '19:00');
        $self = $this->usage('2026-11-26', '08:00', '19:00');

        $this->assertSame(3, $this->service->peakWith('2026-11-26', '08:00', '19:00')['count']);
        $this->assertSame(2, $this->service->peakWith('2026-11-26', '08:00', '19:00', $self->id)['count']);
    }

    // ---- その日の利用 ----

    public function test_その日の利用は開始時刻_終了時刻_idの順で_除く利用は入れない(): void
    {
        $c = $this->usage('2026-11-26', '13:00', '19:00');
        $a = $this->usage('2026-11-26', '08:00', '19:00');
        $b = $this->usage('2026-11-26', '08:00', '12:00');
        $d = $this->usage('2026-11-26', '13:00', '19:00');
        $this->usage('2026-11-27', '07:00', '08:00');

        $this->assertSame([$b->id, $a->id, $c->id, $d->id], $this->service->usagesOn('2026-11-26')->pluck('id')->all());
        $this->assertSame([$b->id, $c->id, $d->id], $this->service->usagesOn('2026-11-26', $a->id)->pluck('id')->all());
    }

    public function test_時間帯の集まりの重なりの数(): void
    {
        $this->assertSame(0, UsageCapacityService::maxOverlap([]));
        $this->assertSame(1, UsageCapacityService::maxOverlap([['08:00', '12:00'], ['12:00', '19:00']]));
        $this->assertSame(3, UsageCapacityService::maxOverlap([['08:00', '19:00'], ['09:00', '10:00'], ['09:30', '09:45'], ['12:00', '13:00']]));
    }
}

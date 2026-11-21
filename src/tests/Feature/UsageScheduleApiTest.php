<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\ClientUsage;
use App\Models\SystemSetting;
use App\Models\Trainer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 利用の予約状況の内部 API（2026-11 追加。api-design.md 4-2-6、screen-design.md S-0305-M05「予約状況」）。
 *
 * - GET /api/usage-schedule/month：月の日ごとの頭数（1 以上の日だけ）・上限・今日
 * - GET /api/usage-schedule/day：その日の頭数・上限・予約の一覧（開始時刻の順。「トレーニー（会員名）」）
 * - どちらも除く利用（編集中の利用自身）を受け取る
 * - 権限は利用の登録・編集と同じ（practitioners。システム管理者は 403）。参照なのでアクセスログに記録しない
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 */
class UsageScheduleApiTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $sato;

    private Client $ouchi;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 10:00:00');

        $this->createClientsTable();
        $this->createTraineesTableForUsage();
        $this->createClientCountTables();
        $this->createAccessLogsTable();
        $this->createSystemSettingsTable();

        $this->actingAsRole('staff');

        $this->sato = Client::query()->create(['internal_id' => '0001', 'last_name' => '佐藤', 'first_name' => '一郎']);
        $this->ouchi = Client::query()->create(['internal_id' => '0002', 'last_name' => '大内', 'first_name' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => '佐藤', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    private function usage(Client $client, string $date, string $start, string $end, ?int $traineeId = null): ClientUsage
    {
        return ClientUsage::query()->create([
            'client_id' => $client->id, 'trainee_id' => $traineeId,
            'used_on' => $date, 'start_time' => $start.':00', 'end_time' => $end.':00',
        ]);
    }

    // ---- 月 ----

    public function test_月の日ごとの頭数と上限と今日を返す(): void
    {
        $this->usage($this->sato, '2026-11-26', '08:00', '19:00');
        $this->usage($this->ouchi, '2026-11-26', '13:00', '19:00');
        $this->usage($this->sato, '2026-11-24', '08:00', '12:00');
        $this->usage($this->sato, '2026-12-01', '08:00', '19:00'); // 翌月は入れない

        $this->getJson(route('api.usage-schedule.month', ['month' => '2026-11']))
            ->assertOk()
            ->assertExactJson([
                'month' => '2026-11',
                'today' => '2026-11-21',
                'capacity' => 3,
                'days' => ['2026-11-24' => 1, '2026-11-26' => 2],
            ]);
    }

    public function test_月に利用がなければ日は空のオブジェクトで_上限は設定の値(): void
    {
        SystemSetting::query()->create(['key' => 'schedule_capacity', 'value' => '5']);

        $response = $this->getJson(route('api.usage-schedule.month', ['month' => '2026-10']))->assertOk();

        $this->assertStringContainsString('"days":{}', $response->getContent());
        $this->assertSame(5, $response->json('capacity'));
    }

    public function test_月は除く利用を数えない(): void
    {
        $this->usage($this->sato, '2026-11-26', '08:00', '19:00');
        $self = $this->usage($this->ouchi, '2026-11-26', '08:00', '19:00');
        $only = $this->usage($this->ouchi, '2026-11-27', '08:00', '19:00');

        $this->getJson(route('api.usage-schedule.month', ['month' => '2026-11', 'exclude_usage_id' => $self->id]))
            ->assertOk()
            ->assertJsonPath('days', ['2026-11-26' => 1, '2026-11-27' => 1]);
        $this->getJson(route('api.usage-schedule.month', ['month' => '2026-11', 'exclude_usage_id' => $only->id]))
            ->assertOk()
            ->assertJsonPath('days', ['2026-11-26' => 2]);
    }

    public function test_月の形が正しくなければ422(): void
    {
        foreach (['', '2026-13', '2026/11', 'abc'] as $month) {
            $this->getJson(route('api.usage-schedule.month', ['month' => $month]))->assertStatus(422)->assertJsonValidationErrors('month');
        }
        $this->getJson(route('api.usage-schedule.month', ['month' => '2026-11', 'exclude_usage_id' => 'x']))
            ->assertStatus(422)->assertJsonValidationErrors('exclude_usage_id');
    }

    // ---- 日 ----

    public function test_その日の予約を開始時刻の順に_トレーニー会員名の形で返す(): void
    {
        DB::table('trainees')->insert(['id' => 10, 'client_id' => $this->sato->id, 'name' => 'アムロ']);
        DB::table('trainees')->insert(['id' => 11, 'client_id' => $this->ouchi->id, 'name' => null]);
        $this->usage($this->ouchi, '2026-11-26', '13:00', '19:00', 11);   // 名前未登録
        $this->usage($this->sato, '2026-11-26', '08:00', '19:00', 10);
        $this->usage($this->sato, '2026-11-26', '13:00', '15:00');        // トレーニーなし
        $this->usage($this->sato, '2026-11-27', '08:00', '19:00', 10);    // ほかの日

        $this->getJson(route('api.usage-schedule.day', ['date' => '2026-11-26']))
            ->assertOk()
            ->assertExactJson([
                'date' => '2026-11-26',
                'date_label' => '11/26(木)',
                'capacity' => 3,
                'count' => 3,
                'usages' => [
                    ['time_range' => '8:00〜19:00', 'label' => 'アムロ（佐藤 一郎）'],
                    ['time_range' => '13:00〜15:00', 'label' => '（佐藤 一郎）'],
                    ['time_range' => '13:00〜19:00', 'label' => '名前未登録（大内）'],
                ],
            ]);
    }

    public function test_予約がない日は空の一覧で頭数0(): void
    {
        $this->getJson(route('api.usage-schedule.day', ['date' => '2026-11-26']))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('usages', []);
    }

    public function test_その日は除く利用を一覧にも頭数にも入れない(): void
    {
        $this->usage($this->sato, '2026-11-26', '08:00', '19:00');
        $self = $this->usage($this->ouchi, '2026-11-26', '10:00', '12:00');

        $response = $this->getJson(route('api.usage-schedule.day', ['date' => '2026-11-26', 'exclude_usage_id' => $self->id]))->assertOk();

        $this->assertSame(1, $response->json('count'));
        $this->assertSame(['（佐藤 一郎）'], array_column($response->json('usages'), 'label'));
    }

    public function test_日付の形が正しくなければ422(): void
    {
        foreach (['', '2026-11-31', '2026/11/26', '2026-11'] as $date) {
            $this->getJson(route('api.usage-schedule.day', ['date' => $date]))->assertStatus(422)->assertJsonValidationErrors('date');
        }
    }

    // ---- 権限・アクセスログ ----

    public function test_管理者も一般トレーナーも読め_アクセスログには記録しない(): void
    {
        foreach (['staff', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson(route('api.usage-schedule.month', ['month' => '2026-11']))->assertOk();
            $this->getJson(route('api.usage-schedule.day', ['date' => '2026-11-26']))->assertOk();
        }

        $this->assertSame(0, AccessLog::query()->count());
    }

    public function test_システム管理者は403(): void
    {
        $this->actingAsRole('system_admin');

        $this->getJson(route('api.usage-schedule.month', ['month' => '2026-11']))->assertForbidden();
        $this->getJson(route('api.usage-schedule.day', ['date' => '2026-11-26']))->assertForbidden();
    }

    public function test_ログインしていなければ401(): void
    {
        auth()->guard('web')->logout();

        $this->getJson(route('api.usage-schedule.month', ['month' => '2026-11']))->assertUnauthorized();
        $this->getJson(route('api.usage-schedule.day', ['date' => '2026-11-26']))->assertUnauthorized();
    }
}

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
 * - GET /api/usage-schedule/month：月の日ごとの預かる数（1 以上の日だけ）・上限・今日
 * - GET /api/usage-schedule/day：その日の預かる数・上限・予約の一覧（開始時刻の順。「トレーニー（会員名）」）
 *   一覧の行には、会員の id・会員名・トレーニーの名前も返す（2026-11 追加。カレンダー画面 S-1602 の会員名のリンク）
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

    public function test_月の日ごとの預かる数と上限と今日を返す(): void
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
                // 日ごとの予約の件数（2026-11 追加）
                'usage_counts' => ['2026-11-24' => 1, '2026-11-26' => 2],
            ]);
    }

    // ---- 月の日ごとの予約の件数（2026-11 追加。カレンダーのマスの下の行で、同時に預かる数の左） ----

    public function test_月の件数は_時間帯が重ならない予約があると同時に預かる数と違う(): void
    {
        // 11/20：予約 4 件。19:00〜20:00 はほかと重ならないので、同時に預かる数は 3
        $this->usage($this->sato, '2026-11-20', '08:00', '19:00');
        $this->usage($this->sato, '2026-11-20', '08:00', '19:00');
        $this->usage($this->ouchi, '2026-11-20', '10:00', '12:00');
        $this->usage($this->ouchi, '2026-11-20', '19:00', '20:00');
        // 11/26：予約 2 件、同時に預かる数 2（同じ）
        $this->usage($this->sato, '2026-11-26', '08:00', '19:00');
        $this->usage($this->ouchi, '2026-11-26', '13:00', '19:00');

        $response = $this->getJson(route('api.usage-schedule.month', ['month' => '2026-11']))->assertOk();

        $this->assertSame(['2026-11-20' => 3, '2026-11-26' => 2], $response->json('days'));
        $this->assertSame(['2026-11-20' => 4, '2026-11-26' => 2], $response->json('usage_counts'));
    }

    public function test_月の件数はトレーニーなしも1件と数え_除く利用は数えない(): void
    {
        DB::table('trainees')->insert(['id' => 10, 'client_id' => $this->sato->id, 'name' => 'アムロ']);
        $this->usage($this->sato, '2026-11-20', '08:00', '12:00', 10);
        $this->usage($this->sato, '2026-11-20', '13:00', '15:00'); // トレーニーなし
        $self = $this->usage($this->ouchi, '2026-11-20', '16:00', '18:00');
        $only = $this->usage($this->ouchi, '2026-11-21', '08:00', '19:00');

        $this->getJson(route('api.usage-schedule.month', ['month' => '2026-11']))
            ->assertOk()->assertJsonPath('usage_counts', ['2026-11-20' => 3, '2026-11-21' => 1]);
        $this->getJson(route('api.usage-schedule.month', ['month' => '2026-11', 'exclude_usage_id' => $self->id]))
            ->assertOk()->assertJsonPath('usage_counts', ['2026-11-20' => 2, '2026-11-21' => 1]);
        // 件数が 0 になる日は入れない
        $this->getJson(route('api.usage-schedule.month', ['month' => '2026-11', 'exclude_usage_id' => $only->id]))
            ->assertOk()->assertJsonPath('usage_counts', ['2026-11-20' => 3]);
    }

    public function test_月に予約がなければ件数も空のオブジェクト(): void
    {
        $response = $this->getJson(route('api.usage-schedule.month', ['month' => '2026-10']))->assertOk();

        $this->assertStringContainsString('"usage_counts":{}', $response->getContent());
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
                // 営業時間（2026-11 追加。設定がなければ初期値 8:00〜19:00）
                'open_time' => '08:00',
                'close_time' => '19:00',
                // client_id・client_name・trainee_name（2026-11 追加）：カレンダー画面（S-1602）で会員名だけをリンクにするため、label を分けたもの
                'usages' => [
                    ['time_range' => '8:00〜19:00', 'start_time' => '08:00', 'end_time' => '19:00', 'label' => 'アムロ（佐藤 一郎）', 'client_id' => $this->sato->id, 'client_name' => '佐藤 一郎', 'trainee_name' => 'アムロ'],
                    ['time_range' => '13:00〜15:00', 'start_time' => '13:00', 'end_time' => '15:00', 'label' => '（佐藤 一郎）', 'client_id' => $this->sato->id, 'client_name' => '佐藤 一郎', 'trainee_name' => ''],
                    ['time_range' => '13:00〜19:00', 'start_time' => '13:00', 'end_time' => '19:00', 'label' => '名前未登録（大内）', 'client_id' => $this->ouchi->id, 'client_name' => '大内', 'trainee_name' => '名前未登録'],
                ],
            ]);
    }

    public function test_予約がない日は空の一覧で預かる数0(): void
    {
        $this->getJson(route('api.usage-schedule.day', ['date' => '2026-11-26']))
            ->assertOk()
            ->assertJsonPath('count', 0)
            ->assertJsonPath('usages', []);
    }

    public function test_その日は除く利用を一覧にも預かる数にも入れない(): void
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

    // ---- 保存の前の確認（GET /api/usage-schedule/check。2026-11 追加） ----

    /** @return array<string, string> */
    private function checkQuery(array $overrides = []): array
    {
        return array_merge(['used_on' => '2026-11-26', 'start_time' => '10:00', 'end_time' => '12:00'], $overrides);
    }

    /** 11/26 8:00〜19:00 に、ほかの会員の利用を n 件入れる */
    private function fill(int $n, string $date = '2026-11-26'): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->usage($this->sato, $date, '08:00', '19:00');
        }
    }

    public function test_確認_上限を超えるときは確認が要り_入れたときの数と時間帯と日付の表記を返す(): void
    {
        $this->fill(3); // 上限 3 に 1 件足すと 4

        $this->getJson(route('api.usage-schedule.check', $this->checkQuery()))
            ->assertOk()
            ->assertExactJson([
                'needs_confirmation' => true,
                'needs_capacity_confirmation' => true,
                'count' => 4,
                'capacity' => 3,
                'peak_start' => '10:00',
                'peak_end' => '12:00',
                // トレーニーを渡さないときは重なりを確かめない（2026-11 追加）
                'needs_overlap_confirmation' => false,
                'overlap_start' => null,
                'overlap_end' => null,
                'trainee_label' => null,
                'date_label' => '11/26(木)',
            ]);
    }

    public function test_確認_最も多くなる時間帯は保存時の判定と同じ(): void
    {
        $this->fill(2);
        $this->usage($this->ouchi, '2026-11-26', '09:00', '10:30');

        // 8:00〜12:00 に入れると、9:00〜10:30 が 4（8:00〜19:00 × 2・9:00〜10:30・入れる利用）
        $this->getJson(route('api.usage-schedule.check', $this->checkQuery(['start_time' => '08:00'])))
            ->assertOk()
            ->assertJson(['needs_confirmation' => true, 'count' => 4, 'peak_start' => '9:00', 'peak_end' => '10:30']);
    }

    public function test_確認_上限を超えないときと_ちょうど上限のときは確認しない(): void
    {
        $this->fill(1);
        $this->getJson(route('api.usage-schedule.check', $this->checkQuery()))
            ->assertOk()->assertJson(['needs_confirmation' => false, 'count' => 2, 'capacity' => 3]);

        $this->fill(1);
        $this->getJson(route('api.usage-schedule.check', $this->checkQuery()))
            ->assertOk()->assertJson(['needs_confirmation' => false, 'count' => 3, 'capacity' => 3]);
    }

    public function test_確認_今日の利用は確認し_今日より前の利用は確認しない(): void
    {
        $this->fill(3, '2026-11-21');
        $this->fill(3, '2026-11-20');

        $this->getJson(route('api.usage-schedule.check', $this->checkQuery(['used_on' => '2026-11-21'])))
            ->assertOk()->assertJson(['needs_confirmation' => true, 'count' => 4, 'date_label' => '11/21(土)']);
        $this->getJson(route('api.usage-schedule.check', $this->checkQuery(['used_on' => '2026-11-20'])))
            ->assertOk()->assertJson(['needs_confirmation' => false, 'count' => 4]);
    }

    public function test_確認_編集では自身を数えない(): void
    {
        $this->fill(2);
        $self = $this->usage($this->ouchi, '2026-11-26', '10:00', '12:00'); // 今 3（上限ちょうど）

        $this->getJson(route('api.usage-schedule.check', $this->checkQuery()))
            ->assertOk()->assertJson(['needs_confirmation' => true, 'count' => 4]);
        $this->getJson(route('api.usage-schedule.check', $this->checkQuery(['exclude_usage_id' => $self->id])))
            ->assertOk()->assertJson(['needs_confirmation' => false, 'count' => 3]);
    }

    public function test_確認_上限は設定の値を使う(): void
    {
        SystemSetting::query()->create(['key' => 'schedule_capacity', 'value' => '1']);
        $this->fill(1);

        $this->getJson(route('api.usage-schedule.check', $this->checkQuery()))
            ->assertOk()->assertJson(['needs_confirmation' => true, 'count' => 2, 'capacity' => 1]);
    }

    public function test_確認_入力が正しくなければ422(): void
    {
        $cases = [
            ['used_on' => ''], ['used_on' => '2026-11-31'], ['used_on' => '2026/11/26'],
            ['start_time' => ''], ['start_time' => '8時'], ['end_time' => '25:00'],
            ['end_time' => '10:00'], ['end_time' => '09:00'], ['exclude_usage_id' => 'x'],
        ];
        foreach ($cases as $overrides) {
            $this->getJson(route('api.usage-schedule.check', $this->checkQuery($overrides)))
                ->assertStatus(422)
                ->assertJsonValidationErrors(array_keys($overrides));
        }
    }

    public function test_確認_権限は予約状況と同じで_アクセスログに記録しない(): void
    {
        foreach (['staff', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->getJson(route('api.usage-schedule.check', $this->checkQuery()))->assertOk();
        }
        $this->assertSame(0, AccessLog::query()->count());

        $this->actingAsRole('system_admin');
        $this->getJson(route('api.usage-schedule.check', $this->checkQuery()))->assertForbidden();

        auth()->guard('web')->logout();
        $this->getJson(route('api.usage-schedule.check', $this->checkQuery()))->assertUnauthorized();
    }

    public function test_確認_何も変えない(): void
    {
        $this->fill(3);

        $this->getJson(route('api.usage-schedule.check', $this->checkQuery()))->assertOk();

        $this->assertSame(3, ClientUsage::query()->count());
    }

    // ---- 保存の前の確認：同じトレーニーの重なり（2026-11 追加） ----

    public function test_確認_重なりだけ(): void
    {
        DB::table('trainees')->insert(['id' => 10, 'client_id' => $this->sato->id, 'name' => 'アムロ']);
        $this->usage($this->sato, '2026-11-26', '13:00', '19:00', 10);

        $this->getJson(route('api.usage-schedule.check', $this->checkQuery(['start_time' => '14:00', 'end_time' => '15:00', 'trainee_id' => 10])))
            ->assertOk()
            ->assertExactJson([
                'needs_confirmation' => true,
                'needs_capacity_confirmation' => false,
                'count' => 2,
                'capacity' => 3,
                'peak_start' => '14:00',
                'peak_end' => '15:00',
                'needs_overlap_confirmation' => true,
                'overlap_start' => '13:00',
                'overlap_end' => '19:00',
                'trainee_label' => 'アムロ',
                'date_label' => '11/26(木)',
            ]);
    }

    public function test_確認_上限と重なりの両方(): void
    {
        DB::table('trainees')->insert(['id' => 10, 'client_id' => $this->sato->id, 'name' => null]);
        $this->fill(2);
        $this->usage($this->sato, '2026-11-26', '09:00', '11:00', 10);

        $this->getJson(route('api.usage-schedule.check', $this->checkQuery(['trainee_id' => 10])))
            ->assertOk()
            ->assertJson([
                'needs_confirmation' => true,
                'needs_capacity_confirmation' => true,
                'count' => 4,
                'needs_overlap_confirmation' => true,
                'overlap_start' => '9:00',
                'overlap_end' => '11:00',
                'trainee_label' => '名前未登録',
            ]);
    }

    public function test_確認_どちらもなし_重ならないとき(): void
    {
        DB::table('trainees')->insert(['id' => 10, 'client_id' => $this->sato->id, 'name' => 'アムロ']);
        $this->usage($this->sato, '2026-11-26', '08:00', '10:00', 10); // 10:00 まで（10:00 からとは重ならない）

        $this->getJson(route('api.usage-schedule.check', $this->checkQuery(['trainee_id' => 10])))
            ->assertOk()
            ->assertJson([
                'needs_confirmation' => false,
                'needs_capacity_confirmation' => false,
                'needs_overlap_confirmation' => false,
                'overlap_start' => null,
                'overlap_end' => null,
                'trainee_label' => 'アムロ',
            ]);
    }

    public function test_確認_トレーニーを渡さない_空のときは重なりを確かめない(): void
    {
        DB::table('trainees')->insert(['id' => 10, 'client_id' => $this->sato->id, 'name' => 'アムロ']);
        $this->usage($this->sato, '2026-11-26', '08:00', '19:00', 10);

        foreach ([[], ['trainee_id' => '']] as $overrides) {
            $this->getJson(route('api.usage-schedule.check', $this->checkQuery($overrides)))
                ->assertOk()
                ->assertJson(['needs_confirmation' => false, 'needs_overlap_confirmation' => false, 'trainee_label' => null]);
        }
        $this->getJson(route('api.usage-schedule.check', $this->checkQuery(['trainee_id' => 'x'])))
            ->assertStatus(422)->assertJsonValidationErrors('trainee_id');
    }

    public function test_確認_過去の日も重なりは確かめ_編集では自身を除く(): void
    {
        DB::table('trainees')->insert(['id' => 10, 'client_id' => $this->sato->id, 'name' => 'アムロ']);
        $self = $this->usage($this->sato, '2026-11-20', '10:00', '12:00', 10);

        $this->getJson(route('api.usage-schedule.check', $this->checkQuery(['used_on' => '2026-11-20', 'trainee_id' => 10])))
            ->assertOk()->assertJson(['needs_confirmation' => true, 'needs_overlap_confirmation' => true, 'date_label' => '11/20(金)']);
        $this->getJson(route('api.usage-schedule.check', $this->checkQuery(['used_on' => '2026-11-20', 'trainee_id' => 10, 'exclude_usage_id' => $self->id])))
            ->assertOk()->assertJson(['needs_confirmation' => false, 'needs_overlap_confirmation' => false]);
    }

    public function test_その日の営業時間は事業所情報の設定の値を返す(): void
    {
        // カレンダー画面（S-1602）のタイムテーブルの時間の幅の基本（2026-11 追加）
        DB::table('system_settings')->insert([
            ['key' => 'schedule_open_time', 'value' => '09:30'],
            ['key' => 'schedule_close_time', 'value' => '18:00'],
        ]);
        $this->usage($this->sato, '2026-11-26', '19:00', '20:05');

        $this->getJson(route('api.usage-schedule.day', ['date' => '2026-11-26']))
            ->assertOk()
            ->assertJsonPath('open_time', '09:30')
            ->assertJsonPath('close_time', '18:00')
            ->assertJsonPath('usages.0.start_time', '19:00')
            ->assertJsonPath('usages.0.end_time', '20:05');
    }
}

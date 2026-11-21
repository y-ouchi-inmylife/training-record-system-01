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
 * 利用の保存時の上限の確認（2026-11 追加。requirements.md 6-17-11、screen-design.md S-0305-M05「保存時の上限の確認」、
 * api-design.md POST /clients/{client}/usages・PUT /client-usages/{usage}）。
 *
 * - 利用日が今日以降で、その利用を入れたときの時間帯の預かる数が上限を超え、確認済みの印がなければ、保存せずに確認で戻る
 *   （会員詳細へ・入力した値を戻す・注意の文をセッションに載せる）
 * - 確認済みの印（_usage_confirmed = 1）があれば保存する
 * - 今日より前の利用は確かめない。上限と同じ預かる数は確かめない（超えるときだけ）
 * - 入力エラーがあるときは、入力エラーを先に出す
 * - 編集のときは、編集中の利用自身を数えない
 * - 確認で戻った送信はアクセスログに記録しない
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。今日は 2026-11-21（土）に固定する。
 */
class ClientUsageCapacityConfirmTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    private Client $other;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 10:00:00');

        $this->createClientsTable();
        $this->createTraineesTableForUsage();
        $this->createClientCountTables();
        $this->createAccessLogsTable();
        $this->createSystemSettingsTable();
        // 上限は 2
        SystemSetting::query()->create(['key' => 'schedule_capacity', 'value' => '2']);

        $staff = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $staff->id = 200;
        $staff->exists = true;
        $this->actingAs($staff, 'web');

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        $this->other = Client::query()->create(['internal_id' => '0002', 'last_name' => '佐藤', 'first_name' => '一郎']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usage(Client $client, string $date, string $start, string $end): ClientUsage
    {
        return ClientUsage::query()->create([
            'client_id' => $client->id, 'used_on' => $date, 'start_time' => $start.':00', 'end_time' => $end.':00',
        ]);
    }

    /** @return array<string, string> */
    private function input(array $overrides = []): array
    {
        return array_merge([
            '_form_type' => 'usage',
            'used_on' => '2026-11-26',
            'start_time' => '14:00',
            'end_time' => '18:00',
            'client_contract_id' => '',
            'note' => '',
        ], $overrides);
    }

    /** 11/26 に、ほかの会員の利用で 2（上限）が埋まっている時間帯を作る（15:00〜17:00 が 2） */
    private function fillTwo(): void
    {
        $this->usage($this->other, '2026-11-26', '08:00', '19:00');
        $this->usage($this->other, '2026-11-26', '15:00', '17:00');
    }

    // ---- 登録 ----

    public function test_上限を超えるときは保存せずに確認で戻り_注意の文と入力した値が残る(): void
    {
        $this->fillTwo();

        $response = $this->post(route('client-usages.store', $this->client), $this->input(['note' => 'メモ']));

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('usage_confirmation_warning',
            'この利用を入れると、11/26(木) 15:00〜17:00 に同時に預かる数が 3 になり、上限（2）を超えます。このまま登録する場合は、もう一度［登録］を押してください。');
        $response->assertSessionHasInput('used_on', '2026-11-26');
        $response->assertSessionHasInput('note', 'メモ');
        $response->assertSessionHasInput('_form_type', 'usage');
        $this->assertSame(0, ClientUsage::query()->where('client_id', $this->client->id)->count());
    }

    public function test_確認済みの印があれば上限を超えていても保存する(): void
    {
        $this->fillTwo();

        $this->post(route('client-usages.store', $this->client), $this->input(['_usage_confirmed' => '1']))
            ->assertRedirect(route('clients.show', $this->client))
            ->assertSessionHas('success', '利用を登録しました。')
            ->assertSessionMissing('usage_confirmation_warning');

        $this->assertSame(1, ClientUsage::query()->where('client_id', $this->client->id)->count());
    }

    public function test_印の値が1でなければ確認する(): void
    {
        $this->fillTwo();

        $this->post(route('client-usages.store', $this->client), $this->input(['_usage_confirmed' => '']))
            ->assertSessionHas('usage_confirmation_warning');
        $this->assertSame(0, ClientUsage::query()->where('client_id', $this->client->id)->count());
    }

    public function test_上限と同じ預かる数なら確認しない(): void
    {
        $this->usage($this->other, '2026-11-26', '08:00', '19:00'); // 1 ＋ 入れる利用で 2（上限と同じ）

        $this->post(route('client-usages.store', $this->client), $this->input())
            ->assertSessionHas('success', '利用を登録しました。')
            ->assertSessionMissing('usage_confirmation_warning');
    }

    public function test_時間帯が重ならなければ確認しない_終わりを含まない(): void
    {
        $this->usage($this->other, '2026-11-26', '08:00', '14:00');
        $this->usage($this->other, '2026-11-26', '08:00', '14:00');

        // 14:00 からは重ならない
        $this->post(route('client-usages.store', $this->client), $this->input(['start_time' => '14:00', 'end_time' => '18:00']))
            ->assertSessionHas('success');
    }

    public function test_今日の利用は確認し_今日より前の利用は確認しない(): void
    {
        $this->usage($this->other, '2026-11-21', '08:00', '19:00');
        $this->usage($this->other, '2026-11-21', '08:00', '19:00');
        $this->usage($this->other, '2026-11-20', '08:00', '19:00');
        $this->usage($this->other, '2026-11-20', '08:00', '19:00');

        $this->post(route('client-usages.store', $this->client), $this->input(['used_on' => '2026-11-21']))
            ->assertSessionHas('usage_confirmation_warning',
                'この利用を入れると、11/21(土) 14:00〜18:00 に同時に預かる数が 3 になり、上限（2）を超えます。このまま登録する場合は、もう一度［登録］を押してください。');

        $this->post(route('client-usages.store', $this->client), $this->input(['used_on' => '2026-11-20']))
            ->assertSessionHas('success', '利用を登録しました。');
        $this->assertSame(1, ClientUsage::query()->where('client_id', $this->client->id)->count());
    }

    public function test_入力エラーがあるときは入力エラーを先に出し確認はしない(): void
    {
        $this->fillTwo();

        $this->post(route('client-usages.store', $this->client), $this->input(['end_time' => '13:00']))
            ->assertSessionHasErrors(['end_time' => '終了時刻は開始時刻より後の時刻で入力してください。'])
            ->assertSessionMissing('usage_confirmation_warning');
        $this->assertSame(0, ClientUsage::query()->where('client_id', $this->client->id)->count());
    }

    // ---- 編集 ----

    public function test_編集のときは自身を数えない(): void
    {
        $this->usage($this->other, '2026-11-26', '08:00', '19:00');
        $self = $this->usage($this->client, '2026-11-26', '14:00', '18:00'); // 今 2（上限）

        // メモだけ変える：自身を数えなければ 2のままなので確認しない
        $this->put(route('client-usages.update', $self), $this->input(['_usage_id' => (string) $self->id, 'note' => '直した']))
            ->assertSessionHas('success', '利用を更新しました。')
            ->assertSessionMissing('usage_confirmation_warning');
        $this->assertSame('直した', $self->fresh()->note);
    }

    public function test_編集で上限を超えるときは更新の文で確認し_印があれば更新する(): void
    {
        $this->fillTwo();
        $self = $this->usage($this->client, '2026-11-27', '14:00', '18:00');

        $this->put(route('client-usages.update', $self), $this->input(['_usage_id' => (string) $self->id]))
            ->assertRedirect(route('clients.show', $this->client))
            ->assertSessionHas('usage_confirmation_warning',
                'この利用を入れると、11/26(木) 15:00〜17:00 に同時に預かる数が 3 になり、上限（2）を超えます。このまま更新する場合は、もう一度［更新］を押してください。')
            ->assertSessionHasInput('_usage_id', (string) $self->id);
        $this->assertSame('2026-11-27', $self->fresh()->used_on->toDateString(), '保存していない');

        $this->put(route('client-usages.update', $self), $this->input(['_usage_id' => (string) $self->id, '_usage_confirmed' => '1']))
            ->assertSessionHas('success', '利用を更新しました。');
        $this->assertSame('2026-11-26', $self->fresh()->used_on->toDateString());
    }

    // ---- アクセスログ ----

    public function test_確認で戻った送信はアクセスログに記録せず_確認して保存した送信は記録する(): void
    {
        $this->fillTwo();

        $this->post(route('client-usages.store', $this->client), $this->input())->assertSessionHas('usage_confirmation_warning');
        $this->assertSame(0, AccessLog::query()->count());

        $this->post(route('client-usages.store', $this->client), $this->input(['_usage_confirmed' => '1']))->assertSessionHas('success');
        $this->assertSame(['create_client_usage'], AccessLog::query()->pluck('action')->all());

        $self = ClientUsage::query()->where('client_id', $this->client->id)->sole();
        $this->put(route('client-usages.update', $self), $this->input(['_usage_id' => (string) $self->id, 'start_time' => '13:00']))
            ->assertSessionHas('usage_confirmation_warning');
        $this->assertSame(['create_client_usage'], AccessLog::query()->pluck('action')->all());
    }

    // ---- 同じトレーニーの重なり（2026-11 追加。安全網） ----

    /** この会員にトレーニーを作り、11/26 13:00〜19:00 にその利用を入れる。トレーニーの id を返す */
    private function overlapping(?string $name = 'アムロ', string $date = '2026-11-26'): int
    {
        $traineeId = DB::table('trainees')->insertGetId(['client_id' => $this->client->id, 'name' => $name]);
        ClientUsage::query()->create([
            'client_id' => $this->client->id, 'trainee_id' => $traineeId,
            'used_on' => $date, 'start_time' => '13:00:00', 'end_time' => '19:00:00',
        ]);

        return $traineeId;
    }

    public function test_重なりで保存されず_注意の文が出る(): void
    {
        $traineeId = $this->overlapping();

        $this->post(route('client-usages.store', $this->client), $this->input(['trainee_id' => (string) $traineeId]))
            ->assertRedirect(route('clients.show', $this->client))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('usage_confirmation_warning',
                'アムロ は、11/26(木) 13:00〜19:00 にすでに予約があります。このまま登録する場合は、もう一度［登録］を押してください。');
        $this->assertSame(1, ClientUsage::query()->where('client_id', $this->client->id)->count());
    }

    public function test_重なりでも印があれば保存される(): void
    {
        $traineeId = $this->overlapping();

        $this->post(route('client-usages.store', $this->client), $this->input(['trainee_id' => (string) $traineeId, '_usage_confirmed' => '1']))
            ->assertSessionHas('success', '利用を登録しました。');
        $this->assertSame(2, ClientUsage::query()->where('client_id', $this->client->id)->count());
    }

    public function test_過去の日でも重なりは確かめる(): void
    {
        $traineeId = $this->overlapping('アムロ', '2026-11-20');

        $this->post(route('client-usages.store', $this->client), $this->input(['used_on' => '2026-11-20', 'trainee_id' => (string) $traineeId]))
            ->assertSessionHas('usage_confirmation_warning',
                'アムロ は、11/20(金) 13:00〜19:00 にすでに予約があります。このまま登録する場合は、もう一度［登録］を押してください。');
    }

    public function test_重なりと上限の両方なら_重なり_上限の順に並べて最後に1回だけ(): void
    {
        $this->fillTwo();
        $traineeId = $this->overlapping(null);

        $this->post(route('client-usages.store', $this->client), $this->input(['trainee_id' => (string) $traineeId]))
            ->assertSessionHas('usage_confirmation_warning',
                '名前未登録 は、11/26(木) 13:00〜19:00 にすでに予約があります。'
                .'この利用を入れると、11/26(木) 15:00〜17:00 に同時に預かる数が 4 になり、上限（2）を超えます。'
                .'このまま登録する場合は、もう一度［登録］を押してください。');
    }

    public function test_編集では自身を除き_重なれば更新の文で確認する(): void
    {
        $traineeId = $this->overlapping();
        $self = ClientUsage::query()->create([
            'client_id' => $this->client->id, 'trainee_id' => $traineeId,
            'used_on' => '2026-11-27', 'start_time' => '14:00:00', 'end_time' => '18:00:00',
        ]);

        // 自身だけ（別の日）なら確認しない
        $this->put(route('client-usages.update', $self), $this->input(['_usage_id' => (string) $self->id, 'used_on' => '2026-11-27', 'trainee_id' => (string) $traineeId, 'note' => '直した']))
            ->assertSessionHas('success', '利用を更新しました。');
        // 11/26 に移すと、13:00〜19:00 の利用と重なる
        $this->put(route('client-usages.update', $self), $this->input(['_usage_id' => (string) $self->id, 'trainee_id' => (string) $traineeId]))
            ->assertSessionHas('usage_confirmation_warning',
                'アムロ は、11/26(木) 13:00〜19:00 にすでに予約があります。このまま更新する場合は、もう一度［更新］を押してください。');
        $this->assertSame('2026-11-27', $self->fresh()->used_on->toDateString());
    }

    public function test_重なりで戻った送信はアクセスログに記録しない(): void
    {
        $traineeId = $this->overlapping();

        $this->post(route('client-usages.store', $this->client), $this->input(['trainee_id' => (string) $traineeId]))
            ->assertSessionHas('usage_confirmation_warning');
        $this->assertSame(0, AccessLog::query()->count());

        $this->post(route('client-usages.store', $this->client), $this->input(['trainee_id' => (string) $traineeId, '_usage_confirmed' => '1']))
            ->assertSessionHas('success');
        $this->assertSame(['create_client_usage'], AccessLog::query()->pluck('action')->all());
    }
}


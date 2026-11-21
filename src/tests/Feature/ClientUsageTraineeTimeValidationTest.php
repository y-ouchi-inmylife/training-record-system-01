<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientUsage;
use App\Models\Trainee;
use App\Models\Trainer;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 利用の登録・編集のトレーニー・開始時刻・終了時刻の検証（2026-11 追加。設計書 S-0305-M05・requirements.md 6-17-4・
 * api-design.md「POST /clients/{client}/usages」）。
 *
 * - トレーニーは、その会員にトレーニーが 1 頭以上いるときは必須（空のトレーニーも数える）。この会員のトレーニーに限る
 * - トレーニーがいない会員は、トレーニーなし（NULL）で登録できる
 * - 開始時刻・終了時刻は必須・H:i。終了時刻は開始時刻より後（同じ時刻は不可）
 * - 同じトレーニーの利用が同じ日に 2 件以上あってもよい
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 */
class ClientUsageTraineeTimeValidationTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createTraineesTableForUsage();
        $this->createClientCountTables();
        $this->createAccessLogsTable();

        $staff = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $staff->id = 200;
        $staff->exists = true;
        $this->actingAs($staff, 'web');

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
    }

    private function trainee(Client $client, ?string $name): Trainee
    {
        return Trainee::query()->create(['client_id' => $client->id, 'name' => $name]);
    }

    private function store(array $input, ?Client $client = null): TestResponse
    {
        $client ??= $this->client;

        return $this->post(route('client-usages.store', $client), array_merge([
            '_form_type' => 'usage', 'used_on' => '2026-11-25', 'start_time' => '08:00', 'end_time' => '19:00',
        ], $input));
    }

    public function test_トレーニーと時刻を保存する(): void
    {
        $ai = $this->trainee($this->client, 'アイ');

        $this->store(['trainee_id' => (string) $ai->id, 'start_time' => '09:30', 'end_time' => '17:15'])
            ->assertRedirect(route('clients.show', $this->client))->assertSessionHasNoErrors();

        $usage = ClientUsage::query()->sole();
        $this->assertSame($ai->id, $usage->trainee_id);
        $this->assertSame('09:30', $usage->start_time_input);
        $this->assertSame('17:15', $usage->end_time_input);
    }

    public function test_ほかの会員のトレーニーはエラー(): void
    {
        $this->trainee($this->client, 'アイ');
        $other = Client::query()->create(['internal_id' => '0002', 'last_name' => '佐藤', 'first_name' => '花子']);
        $otherTrainee = $this->trainee($other, 'ポチ');

        $this->store(['trainee_id' => (string) $otherTrainee->id])
            ->assertSessionHasErrors(['trainee_id' => '選んだトレーニーはこの会員のトレーニーではありません。']);
        $this->assertSame(0, ClientUsage::query()->count());
    }

    public function test_トレーニーがいる会員で未選択はエラー_空のトレーニーも数える(): void
    {
        $this->trainee($this->client, null); // 空のトレーニー（名前も何もない）

        $this->store(['trainee_id' => ''])
            ->assertSessionHasErrors(['trainee_id' => 'トレーニーを選択してください。']);
        $this->assertSame(0, ClientUsage::query()->count());
    }

    public function test_トレーニーがいない会員はトレーニーなしで登録できる(): void
    {
        $this->store([])->assertRedirect(route('clients.show', $this->client))->assertSessionHasNoErrors();

        $this->assertNull(ClientUsage::query()->sole()->trainee_id);
    }

    public function test_トレーニーがいない会員はトレーニーを指定できない(): void
    {
        $other = Client::query()->create(['internal_id' => '0002', 'last_name' => '佐藤', 'first_name' => '花子']);
        $otherTrainee = $this->trainee($other, 'ポチ');

        $this->store(['trainee_id' => (string) $otherTrainee->id])
            ->assertSessionHasErrors(['trainee_id' => '選んだトレーニーはこの会員のトレーニーではありません。']);
    }

    public function test_終了時刻が開始時刻以前ならエラー(): void
    {
        foreach (['10:00' => '09:59', '12:00' => '12:00'] as $start => $end) {
            $this->store(['start_time' => $start, 'end_time' => $end])
                ->assertSessionHasErrors(['end_time' => '終了時刻は開始時刻より後の時刻で入力してください。']);
        }
        $this->assertSame(0, ClientUsage::query()->count());
    }

    public function test_時刻の必須と形式の文言_形式の誤りに前後の文言を重ねない(): void
    {
        $this->store(['start_time' => '', 'end_time' => ''])->assertSessionHasErrors([
            'start_time' => '開始時刻を入力してください。',
            'end_time' => '終了時刻を入力してください。',
        ]);
        $this->store(['start_time' => '9時', 'end_time' => '08:00'])
            ->assertSessionHasErrors(['start_time' => '開始時刻の形式が正しくありません。'])
            ->assertSessionDoesntHaveErrors(['end_time']);
        $this->store(['end_time' => '24:00'])
            ->assertSessionHasErrors(['end_time' => '終了時刻の形式が正しくありません。']);
    }

    public function test_同じトレーニーの同じ日の利用は2件登録できる(): void
    {
        $ai = $this->trainee($this->client, 'アイ');

        $this->store(['trainee_id' => (string) $ai->id, 'start_time' => '08:00', 'end_time' => '12:00'])->assertSessionHasNoErrors();
        $this->store(['trainee_id' => (string) $ai->id, 'start_time' => '08:00', 'end_time' => '12:00'])->assertSessionHasNoErrors();

        $this->assertSame(2, ClientUsage::query()->where('trainee_id', $ai->id)->whereDate('used_on', '2026-11-25')->count());
    }

    public function test_編集でもトレーニーと時刻を変えられ_検証は登録と同じ(): void
    {
        $ai = $this->trainee($this->client, 'アイ');
        $mo = $this->trainee($this->client, 'モモ');
        $usage = ClientUsage::query()->create([
            'client_id' => $this->client->id, 'trainee_id' => $ai->id, 'used_on' => '2026-11-25', 'start_time' => '08:00', 'end_time' => '19:00',
        ]);

        $this->put(route('client-usages.update', $usage), [
            '_form_type' => 'usage', '_usage_id' => (string) $usage->id,
            'trainee_id' => (string) $mo->id, 'used_on' => '2026-11-26', 'start_time' => '13:00', 'end_time' => '15:00',
        ])->assertRedirect(route('clients.show', $this->client))->assertSessionHasNoErrors();

        $usage->refresh();
        $this->assertSame($mo->id, $usage->trainee_id);
        $this->assertSame('2026-11-26', $usage->used_on->format('Y-m-d'));
        $this->assertSame('13:00', $usage->start_time_input);

        $this->put(route('client-usages.update', $usage), [
            '_form_type' => 'usage', '_usage_id' => (string) $usage->id,
            'trainee_id' => '', 'used_on' => '2026-11-26', 'start_time' => '13:00', 'end_time' => '13:00',
        ])->assertSessionHasErrors([
            'trainee_id' => 'トレーニーを選択してください。',
            'end_time' => '終了時刻は開始時刻より後の時刻で入力してください。',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\ClientContract;
use App\Models\ClientUsage;
use App\Models\Trainer;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 入力エラーで戻った送信をアクセスログに記録しない（2026-11 追加。要件定義書 6-8-9「記録しない送信」、db-schema.md DS-0500 注記）。
 *
 * - 入力エラーで戻った送信（Laravel 標準のエラーの一覧 `errors` をセッションに載せて戻すもの）は記録しない
 * - 成功した送信は今までどおり記録する
 * - LogAccess の ACTION_MAP のすべての操作が対象。ここでは利用（登録・編集）と、ほかの操作として契約（登録・編集）で確かめる
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 */
class AccessLogReturnedSubmissionTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        // 利用の検証がトレーニーを見る。外部キーが効くよう、利用の表より先に作る
        $this->createTraineesTableForUsage();
        $this->createClientCountTables();
        $this->createAccessLogsTable();

        $staff = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $staff->id = 200;
        $staff->exists = true;
        $this->actingAs($staff, 'web');

        $this->client = Client::query()->create([
            'internal_id' => '0001',
            'initial_consultation_date' => '2026-01-20',
            'last_name' => '山田',
            'first_name' => '太郎',
        ]);
    }

    /** @return array<string, string> 正しい利用の入力 */
    private function usageInput(array $overrides = []): array
    {
        return array_merge([
            '_form_type' => 'usage',
            'used_on' => '2026-10-20',
            'start_time' => '08:00',
            'end_time' => '19:00',
            'client_contract_id' => '',
            'note' => '',
        ], $overrides);
    }

    // ---- 利用 ----

    public function test_利用の登録で入力エラーなら記録しない(): void
    {
        $this->post(route('client-usages.store', $this->client), $this->usageInput(['used_on' => '']))
            ->assertRedirect()
            ->assertSessionHasErrors('used_on');

        $this->assertSame(0, ClientUsage::query()->count());
        $this->assertSame(0, AccessLog::query()->count());
    }

    public function test_利用の登録が成功すれば記録する(): void
    {
        $this->post(route('client-usages.store', $this->client), $this->usageInput())
            ->assertRedirect(route('clients.show', $this->client))
            ->assertSessionHasNoErrors();

        $this->assertSame(['create_client_usage'], AccessLog::query()->pluck('action')->all());
    }

    public function test_利用の編集で入力エラーなら記録せず_成功すれば記録する(): void
    {
        $usage = ClientUsage::query()->create([
            'client_id' => $this->client->id, 'used_on' => '2026-10-20', 'start_time' => '08:00', 'end_time' => '19:00',
        ]);

        // 終了時刻が開始時刻より前（項目どうしのチェックのエラー）
        $this->put(route('client-usages.update', $usage), $this->usageInput(['_usage_id' => (string) $usage->id, 'end_time' => '07:00']))
            ->assertSessionHasErrors('end_time');
        $this->assertSame(0, AccessLog::query()->count());

        $this->put(route('client-usages.update', $usage), $this->usageInput(['_usage_id' => (string) $usage->id, 'note' => '直した']))
            ->assertSessionHasNoErrors();
        $log = AccessLog::query()->sole();
        $this->assertSame('edit_client_usage', $log->action);
        $this->assertSame($usage->id, (int) $log->target_id);
    }

    // ---- ほかの操作（契約） ----

    public function test_契約の登録で入力エラーなら記録せず_成功すれば記録する(): void
    {
        $this->post(route('client-contracts.store', $this->client), [
            '_form_type' => 'contract', 'contracted_on' => '2026-10-12', 'count' => '', 'expires_on' => '', 'note' => '',
        ])->assertSessionHasErrors('count');
        $this->assertSame(0, AccessLog::query()->count());

        $this->post(route('client-contracts.store', $this->client), [
            '_form_type' => 'contract', 'contracted_on' => '2026-10-12', 'count' => '4', 'expires_on' => '', 'note' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['create_client_contract'], AccessLog::query()->pluck('action')->all());
    }

    public function test_契約の編集で入力エラーなら記録しない(): void
    {
        $contract = ClientContract::query()->create([
            'client_id' => $this->client->id, 'contracted_on' => '2026-10-12', 'count' => 4,
        ]);

        $this->put(route('client-contracts.update', $contract), [
            '_form_type' => 'contract', '_contract_id' => (string) $contract->id,
            'contracted_on' => '', 'count' => '4', 'expires_on' => '', 'note' => '',
        ])->assertSessionHasErrors('contracted_on');

        $this->assertSame(0, AccessLog::query()->count());
    }

    public function test_入力エラーのすぐあとの成功した送信は記録する(): void
    {
        // 前の要求で載せたエラー（次の要求では _flash.old）は見ない。直して送り直した成功の送信は今までどおり記録する
        $this->post(route('client-usages.store', $this->client), $this->usageInput(['used_on' => '']))
            ->assertSessionHasErrors('used_on');

        $this->post(route('client-usages.store', $this->client), $this->usageInput())
            ->assertSessionHasNoErrors();

        $this->assertSame(['create_client_usage'], AccessLog::query()->pluck('action')->all());
    }
}

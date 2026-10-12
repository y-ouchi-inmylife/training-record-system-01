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
 * 契約・利用の登録・編集・削除（要件定義書 6-17-1〜6-17-6、api-design.md §3「回数管理」）。
 *
 * - 一般トレーナー（staff）でも登録・編集・削除まで成功する（管理者限定の操作はない）
 * - 処理後は常に会員詳細（clients.show）に戻り、フラッシュは success
 * - updated_by に操作したトレーナーが入る
 * - 残り回数がマイナスになる利用の登録も止めない
 * - アクセスログに設計書 §5-16 の操作種別と target_type（ClientContract / ClientUsage）が残る。
 *   'create_client_contract' などは 'client' を含むため、LogAccess で会員（Client）の
 *   判定に先に引っかからないことを、6 つのルートそれぞれで確かめる。
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 */
class ClientContractUsageCrudTest extends TestCase
{
    use CreatesClientCountTables;

    private Trainer $staff;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createClientCountTables();
        $this->createAccessLogsTable();

        // 一般トレーナー（staff）。DB には保存せず、ログイン状態だけを作る
        $this->staff = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $this->staff->id = 200;
        $this->staff->exists = true;
        $this->actingAs($this->staff, 'web');

        $this->client = Client::query()->create([
            'internal_id' => '0001',
            'initial_consultation_date' => '2026-01-20',
            'last_name' => '山田',
            'first_name' => '太郎',
        ]);
    }

    /** 最後に記録されたアクセスログ */
    private function lastLog(): AccessLog
    {
        return AccessLog::query()->orderByDesc('id')->firstOrFail();
    }

    // ---- 契約 ----

    public function test_一般トレーナーが契約を登録すると会員詳細に戻る(): void
    {
        $response = $this->post(route('client-contracts.store', $this->client), [
            '_form_type' => 'contract',
            'contracted_on' => '2026-10-12',
            'count' => '4',
            'expires_on' => '2026-12-31',
            'note' => '4回コース',
        ]);

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', '契約を登録しました。');

        $contract = ClientContract::query()->sole();
        $this->assertSame($this->client->id, $contract->client_id);
        $this->assertSame('2026-10-12', $contract->contracted_on->format('Y-m-d'));
        $this->assertSame(4, $contract->count);
        $this->assertSame('2026-12-31', $contract->expires_on->format('Y-m-d'));
        $this->assertSame('4回コース', $contract->note);
        $this->assertSame(200, (int) $contract->updated_by);

        $log = $this->lastLog();
        $this->assertSame('create_client_contract', $log->action);
        $this->assertSame('ClientContract', $log->target_type);
        $this->assertNull($log->target_id, 'store では作成したレコードの id は取れない（計測値と同じ扱い）');
    }

    public function test_一般トレーナーが契約を更新すると会員詳細に戻る(): void
    {
        $contract = ClientContract::query()->create([
            'client_id' => $this->client->id, 'contracted_on' => '2026-10-12', 'count' => 4,
        ]);

        $response = $this->put(route('client-contracts.update', $contract), [
            '_form_type' => 'contract',
            '_contract_id' => (string) $contract->id,
            'contracted_on' => '2026-10-13',
            'count' => '8',
            'expires_on' => '',
            'note' => '',
        ]);

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', '契約を更新しました。');
        $contract->refresh();
        $this->assertSame('2026-10-13', $contract->contracted_on->format('Y-m-d'));
        $this->assertSame(8, $contract->count);
        $this->assertNull($contract->expires_on);
        $this->assertSame(200, (int) $contract->updated_by);

        $log = $this->lastLog();
        $this->assertSame('edit_client_contract', $log->action);
        $this->assertSame('ClientContract', $log->target_type);
        $this->assertSame($contract->id, (int) $log->target_id);
    }

    public function test_一般トレーナーが契約を削除すると会員詳細に戻る(): void
    {
        $contract = ClientContract::query()->create([
            'client_id' => $this->client->id, 'contracted_on' => '2026-10-12', 'count' => 4,
        ]);
        // 利用は契約とひも付けていないため、契約を削除しても残る
        ClientUsage::query()->create([
            'client_id' => $this->client->id, 'used_on' => '2026-10-13', 'count' => 1,
        ]);

        $response = $this->delete(route('client-contracts.destroy', $contract));

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', '契約を削除しました。');
        $this->assertFalse(ClientContract::query()->exists());
        $this->assertSame(1, ClientUsage::query()->count());

        $log = $this->lastLog();
        $this->assertSame('delete_client_contract', $log->action);
        $this->assertSame('ClientContract', $log->target_type);
        $this->assertSame($contract->id, (int) $log->target_id);
    }

    public function test_契約の入力エラーは文言どおりで送信元の種別がoldに残る(): void
    {
        $response = $this->from(route('clients.show', $this->client))
            ->post(route('client-contracts.store', $this->client), [
                '_form_type' => 'contract',
                'contracted_on' => '2026-10-12',
                'count' => '1000',
                'expires_on' => '2026-10-01',
                'note' => str_repeat('あ', 256),
            ]);

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHasErrors([
            'count' => '回数は999以下で入力してください。',
            'expires_on' => '有効期限は契約日以降の日付で入力してください。',
            'note' => 'メモは255文字以内で入力してください。',
        ]);
        $this->assertSame('contract', session('_old_input._form_type'));
        $this->assertFalse(ClientContract::query()->exists());
    }

    public function test_契約の必須と回数の下限の文言(): void
    {
        $this->post(route('client-contracts.store', $this->client), [
            '_form_type' => 'contract',
            'contracted_on' => '',
            'count' => '0',
        ])->assertSessionHasErrors([
            'contracted_on' => '契約日を入力してください。',
            'count' => '回数は1以上で入力してください。',
        ]);

        $this->post(route('client-contracts.store', $this->client), [
            '_form_type' => 'contract',
            'contracted_on' => 'not-a-date',
            'count' => '1.5',
        ])->assertSessionHasErrors([
            'contracted_on' => '契約日の形式が正しくありません。',
            'count' => '回数は整数で入力してください。',
        ]);
    }

    // ---- 利用 ----

    public function test_一般トレーナーが利用を登録すると会員詳細に戻り_残りがマイナスでも止めない(): void
    {
        // 契約が 1 件もない（残り回数がマイナス側になる）状態でも登録できる
        $response = $this->post(route('client-usages.store', $this->client), [
            '_form_type' => 'usage',
            'used_on' => '2026-10-12',
            'count' => '2',
            'note' => 'トレーニー2頭',
        ]);

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', '利用を登録しました。');

        $usage = ClientUsage::query()->sole();
        $this->assertSame($this->client->id, $usage->client_id);
        $this->assertSame('2026-10-12', $usage->used_on->format('Y-m-d'));
        $this->assertSame(2, $usage->count);
        $this->assertSame('トレーニー2頭', $usage->note);
        $this->assertSame(200, (int) $usage->updated_by);

        $log = $this->lastLog();
        $this->assertSame('create_client_usage', $log->action);
        $this->assertSame('ClientUsage', $log->target_type);
        $this->assertNull($log->target_id);
    }

    public function test_一般トレーナーが利用を更新すると会員詳細に戻る(): void
    {
        $usage = ClientUsage::query()->create([
            'client_id' => $this->client->id, 'used_on' => '2026-10-12', 'count' => 1,
        ]);

        $response = $this->put(route('client-usages.update', $usage), [
            '_form_type' => 'usage',
            '_usage_id' => (string) $usage->id,
            'used_on' => '2026-10-14',
            'count' => '3',
            'note' => '当日キャンセル',
        ]);

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', '利用を更新しました。');
        $usage->refresh();
        $this->assertSame('2026-10-14', $usage->used_on->format('Y-m-d'));
        $this->assertSame(3, $usage->count);
        $this->assertSame('当日キャンセル', $usage->note);

        $log = $this->lastLog();
        $this->assertSame('edit_client_usage', $log->action);
        $this->assertSame('ClientUsage', $log->target_type);
        $this->assertSame($usage->id, (int) $log->target_id);
    }

    public function test_一般トレーナーが利用を削除すると会員詳細に戻る(): void
    {
        $usage = ClientUsage::query()->create([
            'client_id' => $this->client->id, 'used_on' => '2026-10-12', 'count' => 1,
        ]);

        $response = $this->delete(route('client-usages.destroy', $usage));

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', '利用を削除しました。');
        $this->assertFalse(ClientUsage::query()->exists());

        $log = $this->lastLog();
        $this->assertSame('delete_client_usage', $log->action);
        $this->assertSame('ClientUsage', $log->target_type);
        $this->assertSame($usage->id, (int) $log->target_id);
    }

    public function test_利用の入力エラーは文言どおりで送信元の種別がoldに残る(): void
    {
        $response = $this->post(route('client-usages.store', $this->client), [
            '_form_type' => 'usage',
            'used_on' => '',
            'count' => '',
        ]);

        $response->assertSessionHasErrors([
            'used_on' => '利用日を入力してください。',
            'count' => '回数を入力してください。',
        ]);
        $this->assertSame('usage', session('_old_input._form_type'));
        $this->assertFalse(ClientUsage::query()->exists());
    }

    // ---- アクセスログのラベル ----

    public function test_操作種別と対象の日本語ラベル(): void
    {
        $labels = AccessLog::actionLabels();
        $this->assertSame('契約登録', $labels['create_client_contract']);
        $this->assertSame('契約編集', $labels['edit_client_contract']);
        $this->assertSame('契約削除', $labels['delete_client_contract']);
        $this->assertSame('利用登録', $labels['create_client_usage']);
        $this->assertSame('利用編集', $labels['edit_client_usage']);
        $this->assertSame('利用削除', $labels['delete_client_usage']);

        $this->assertSame('契約', AccessLog::targetLabels()['ClientContract']);
        $this->assertSame('利用', AccessLog::targetLabels()['ClientUsage']);
    }
}

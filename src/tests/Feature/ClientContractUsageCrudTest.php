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

    public function test_一般トレーナーが契約を削除すると会員詳細に戻り_割り当てた利用は契約なしで残る(): void
    {
        $contract = ClientContract::query()->create([
            'client_id' => $this->client->id, 'contracted_on' => '2026-10-12', 'count' => 4,
        ]);
        $other = ClientContract::query()->create([
            'client_id' => $this->client->id, 'contracted_on' => '2026-10-01', 'count' => 2,
        ]);
        $assigned = $this->usage($contract, '2026-10-13');
        $otherUsage = $this->usage($other, '2026-10-02');

        $response = $this->delete(route('client-contracts.destroy', $contract));

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', '契約を削除しました。');
        $this->assertFalse(ClientContract::query()->whereKey($contract->id)->exists());
        // DB の外部キー（ON DELETE SET NULL）で、削除した契約の利用は「契約なし」になって残る
        $this->assertSame(2, ClientUsage::query()->count());
        $this->assertNull($assigned->fresh()->client_contract_id);
        // ほかの契約の利用はそのまま
        $this->assertSame($other->id, (int) $otherUsage->fresh()->client_contract_id);

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
        // 契約が 1 件もない（残り回数がマイナスになる）状態でも登録できる
        $response = $this->post(route('client-usages.store', $this->client), [
            '_form_type' => 'usage',
            'used_on' => '2026-10-12',
            'note' => 'トレーニー2頭のうち1頭',
        ]);

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', '利用を登録しました。');

        $usage = ClientUsage::query()->sole();
        $this->assertSame($this->client->id, $usage->client_id);
        $this->assertSame('2026-10-12', $usage->used_on->format('Y-m-d'));
        $this->assertSame('トレーニー2頭のうち1頭', $usage->note);
        // 利用 1 件は 1 回分。回数のカラムは持たない
        $this->assertArrayNotHasKey('count', $usage->getAttributes());
        $this->assertSame(200, (int) $usage->updated_by);

        $log = $this->lastLog();
        $this->assertSame('create_client_usage', $log->action);
        $this->assertSame('ClientUsage', $log->target_type);
        $this->assertNull($log->target_id);
    }

    public function test_一般トレーナーが利用を更新すると会員詳細に戻る(): void
    {
        $usage = ClientUsage::query()->create([
            'client_id' => $this->client->id, 'used_on' => '2026-10-12',
        ]);

        $response = $this->put(route('client-usages.update', $usage), [
            '_form_type' => 'usage',
            '_usage_id' => (string) $usage->id,
            'used_on' => '2026-10-14',
            'note' => '当日キャンセル',
        ]);

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', '利用を更新しました。');
        $usage->refresh();
        $this->assertSame('2026-10-14', $usage->used_on->format('Y-m-d'));
        $this->assertSame('当日キャンセル', $usage->note);

        $log = $this->lastLog();
        $this->assertSame('edit_client_usage', $log->action);
        $this->assertSame('ClientUsage', $log->target_type);
        $this->assertSame($usage->id, (int) $log->target_id);
    }

    public function test_一般トレーナーが利用を削除すると会員詳細に戻る(): void
    {
        $usage = ClientUsage::query()->create([
            'client_id' => $this->client->id, 'used_on' => '2026-10-12',
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
        ]);

        $response->assertSessionHasErrors([
            'used_on' => '利用日を入力してください。',
        ]);
        // 利用は回数を入力しない（回数のエラーは出ない）
        $response->assertSessionDoesntHaveErrors(['count']);
        $this->assertSame('usage', session('_old_input._form_type'));
        $this->assertFalse(ClientUsage::query()->exists());
    }

    // ---- 利用の契約の割り当て（S-0305-M05） ----

    private function contractOf(Client $client, int $count, string $contractedOn = '2026-10-01'): ClientContract
    {
        return ClientContract::query()->create([
            'client_id' => $client->id, 'contracted_on' => $contractedOn, 'count' => $count,
        ]);
    }

    /** 利用を 1 件（＝1 回分）作る。$contract を渡すとその契約に割り当てる（null は「契約なし」） */
    private function usage(?ClientContract $contract, string $usedOn): ClientUsage
    {
        return ClientUsage::query()->create([
            'client_id' => $this->client->id,
            'client_contract_id' => $contract?->id,
            'used_on' => $usedOn,
        ]);
    }

    public function test_契約を選んで利用を登録できる(): void
    {
        $contract = $this->contractOf($this->client, 4);

        $this->post(route('client-usages.store', $this->client), [
            '_form_type' => 'usage',
            'client_contract_id' => (string) $contract->id,
            'used_on' => '2026-10-12',
        ])->assertRedirect(route('clients.show', $this->client))->assertSessionHasNoErrors();

        $this->assertSame($contract->id, (int) ClientUsage::query()->sole()->client_contract_id);
    }

    public function test_契約なしで利用を登録できる(): void
    {
        $contract = $this->contractOf($this->client, 1);
        $this->usage($contract, '2026-10-02'); // 契約は使い切り

        // 「契約なし」の option は value が空。契約の残りに関係なく登録できる
        $this->post(route('client-usages.store', $this->client), [
            '_form_type' => 'usage',
            'client_contract_id' => '',
            'used_on' => '2026-10-12',
        ])->assertRedirect(route('clients.show', $this->client))->assertSessionHasNoErrors();

        $this->assertSame(1, ClientUsage::query()->whereNull('client_contract_id')->count());
    }

    public function test_残りのない契約を選ぶとエラー(): void
    {
        $contract = $this->contractOf($this->client, 2);
        $this->usage($contract, '2026-10-02');
        $this->usage($contract, '2026-10-03'); // 残り 0

        $this->from(route('clients.show', $this->client))
            ->post(route('client-usages.store', $this->client), [
                '_form_type' => 'usage',
                'client_contract_id' => (string) $contract->id,
                'used_on' => '2026-10-12',
            ])
            ->assertRedirect(route('clients.show', $this->client))
            ->assertSessionHasErrors(['client_contract_id' => '選んだ契約には残りがありません。']);

        $this->assertSame(2, ClientUsage::query()->count());
        // 選んでいた契約が old() で戻せるようにフラッシュされている
        $this->assertSame((string) $contract->id, (string) session('_old_input.client_contract_id'));
    }

    public function test_残りが1あれば割り当てられる(): void
    {
        $contract = $this->contractOf($this->client, 2);
        $this->usage($contract, '2026-10-02'); // 残り 1

        $this->post(route('client-usages.store', $this->client), [
            '_form_type' => 'usage',
            'client_contract_id' => (string) $contract->id,
            'used_on' => '2026-10-12',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, ClientUsage::query()->where('client_contract_id', $contract->id)->count());
    }

    public function test_編集で同じ契約のままなら使い切りの契約でも通る(): void
    {
        $contract = $this->contractOf($this->client, 2);
        $target = $this->usage($contract, '2026-10-02');
        $this->usage($contract, '2026-10-03'); // 使い切り。自分を除くと 1 件

        $this->put(route('client-usages.update', $target), [
            '_form_type' => 'usage', '_usage_id' => (string) $target->id,
            'client_contract_id' => (string) $contract->id,
            'used_on' => '2026-10-05',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-10-05', $target->fresh()->used_on->format('Y-m-d'));
        $this->assertSame($contract->id, (int) $target->fresh()->client_contract_id);
    }

    public function test_編集で別の契約に移すときは移す先の残りで判定する(): void
    {
        $from = $this->contractOf($this->client, 4, '2026-09-01');
        $to = $this->contractOf($this->client, 1, '2026-10-01');
        $target = $this->usage($from, '2026-10-02');
        $filler = $this->usage($to, '2026-10-03'); // 移す先は使い切り

        $this->put(route('client-usages.update', $target), [
            '_form_type' => 'usage', '_usage_id' => (string) $target->id,
            'client_contract_id' => (string) $to->id,
            'used_on' => '2026-10-02',
        ])->assertSessionHasErrors(['client_contract_id' => '選んだ契約には残りがありません。']);
        $this->assertSame($from->id, (int) $target->fresh()->client_contract_id);

        // 移す先の利用を消して残りを 1 にすれば移せる
        $filler->delete();
        $this->put(route('client-usages.update', $target), [
            '_form_type' => 'usage', '_usage_id' => (string) $target->id,
            'client_contract_id' => (string) $to->id,
            'used_on' => '2026-10-02',
        ])->assertSessionHasNoErrors();
        $this->assertSame($to->id, (int) $target->fresh()->client_contract_id);
    }

    public function test_別の会員の契約を選ぶとエラー(): void
    {
        $otherClient = Client::query()->create([
            'internal_id' => '0002', 'initial_consultation_date' => '2026-01-20', 'last_name' => '鈴木',
        ]);
        $otherContract = $this->contractOf($otherClient, 4);

        $this->post(route('client-usages.store', $this->client), [
            '_form_type' => 'usage',
            'client_contract_id' => (string) $otherContract->id,
            'used_on' => '2026-10-12',
        ])->assertSessionHasErrors(['client_contract_id' => '選んだ契約はこの会員の契約ではありません。']);

        $this->assertFalse(ClientUsage::query()->exists());
    }

    // ---- 契約の回数を減らす編集（S-0305-M04） ----

    public function test_割り当て済みの利用の件数より少ない回数への契約の編集はエラー(): void
    {
        $contract = $this->contractOf($this->client, 4);
        $this->usage($contract, '2026-10-02');
        $this->usage($contract, '2026-10-02');
        $this->usage($contract, '2026-10-03'); // 割り当て済み 3 件

        $this->put(route('client-contracts.update', $contract), [
            '_form_type' => 'contract', '_contract_id' => (string) $contract->id,
            'contracted_on' => '2026-10-01', 'count' => '2',
        ])->assertSessionHasErrors(['count' => '回数は割り当て済みの利用の件数（3件）以上で入力してください。']);
        $this->assertSame(4, $contract->fresh()->count);

        // ちょうど 3 回には減らせる
        $this->put(route('client-contracts.update', $contract), [
            '_form_type' => 'contract', '_contract_id' => (string) $contract->id,
            'contracted_on' => '2026-10-01', 'count' => '3',
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, $contract->fresh()->count);
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

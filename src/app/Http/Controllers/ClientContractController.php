<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientContractRequest;
use App\Models\Client;
use App\Models\ClientContract;
use Illuminate\Http\RedirectResponse;

/**
 * 契約（D-0900）の CRUD コントローラ。
 *
 * TraineeMeasurementController の書き方に合わせる：
 * - store / update で `updated_by` はコントローラで明示代入（既存慣例）
 * - フラッシュキーは `success`
 * - destroy は物理削除。**権限は一般トレーナーも可**（設計書 requirements.md 6-17-3）
 *
 * 送信元は会員詳細（S-0305）だけのため、処理後は常に会員詳細へ戻す
 * （計測値の `return_to` は持たない。api-design.md「契約・利用エンドポイントのリダイレクト先」参照）。
 */
class ClientContractController extends Controller
{
    /**
     * 契約の登録
     */
    public function store(ClientContractRequest $request, Client $client): RedirectResponse
    {
        $validated = $request->validated();
        $validated['client_id'] = $client->id;
        $validated['updated_by'] = auth()->id();

        ClientContract::create($validated);

        return redirect()
            ->route('clients.show', $client)
            ->with('success', '契約を登録しました。');
    }

    /**
     * 契約の更新
     */
    public function update(ClientContractRequest $request, ClientContract $contract): RedirectResponse
    {
        $validated = $request->validated();
        $validated['updated_by'] = auth()->id();
        $contract->update($validated);

        return redirect()
            ->route('clients.show', $contract->client_id)
            ->with('success', '契約を更新しました。');
    }

    /**
     * 契約の削除（物理削除、一般トレーナーも可）
     *
     * この契約に割り当てられていた利用は、DB の外部キー（ON DELETE SET NULL）で
     * 「契約なし」（client_contract_id = NULL）になる。利用そのものは消さない。
     * DB の処理のため、アクセスログには契約の削除の 1 行だけが残る（設計書 D-1000 注記）。
     */
    public function destroy(ClientContract $contract): RedirectResponse
    {
        $clientId = $contract->client_id;
        $contract->delete();

        return redirect()
            ->route('clients.show', $clientId)
            ->with('success', '契約を削除しました。');
    }
}

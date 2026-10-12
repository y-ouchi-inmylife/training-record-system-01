<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientUsageRequest;
use App\Models\Client;
use App\Models\ClientUsage;
use Illuminate\Http\RedirectResponse;

/**
 * 利用（D-1000）の CRUD コントローラ。
 *
 * ClientContractController と同じ書き方（TraineeMeasurementController に合わせる）：
 * - store / update で `updated_by` はコントローラで明示代入
 * - フラッシュキーは `success`
 * - destroy は物理削除。**権限は一般トレーナーも可**（設計書 requirements.md 6-17-6）
 * - 処理後は常に会員詳細（S-0305）へ戻す（`return_to` は持たない）
 *
 * 残り回数がマイナスになる登録・編集も止めない（警告も出さない。設計書 6-17-4）。
 */
class ClientUsageController extends Controller
{
    /**
     * 利用の登録
     */
    public function store(ClientUsageRequest $request, Client $client): RedirectResponse
    {
        $validated = $request->validated();
        $validated['client_id'] = $client->id;
        $validated['updated_by'] = auth()->id();

        ClientUsage::create($validated);

        return redirect()
            ->route('clients.show', $client)
            ->with('success', '利用を登録しました。');
    }

    /**
     * 利用の更新
     */
    public function update(ClientUsageRequest $request, ClientUsage $usage): RedirectResponse
    {
        $validated = $request->validated();
        $validated['updated_by'] = auth()->id();
        $usage->update($validated);

        return redirect()
            ->route('clients.show', $usage->client_id)
            ->with('success', '利用を更新しました。');
    }

    /**
     * 利用の削除（物理削除、一般トレーナーも可）
     */
    public function destroy(ClientUsage $usage): RedirectResponse
    {
        $clientId = $usage->client_id;
        $usage->delete();

        return redirect()
            ->route('clients.show', $clientId)
            ->with('success', '利用を削除しました。');
    }
}

<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\ContractTicketService;
use Illuminate\Support\Arr;
use Illuminate\View\View;

/**
 * 会員のご利用回数画面（S-1412、要件定義書 6-15-16）。
 *
 * ログイン中の会員が、自分の回数の契約と利用の状況を**見るだけ**できる。
 * - 対象は auth('client')->user() の契約・利用だけ（URL に会員の id を取らない）
 * - 残り回数の計算・利用の割り当てはトレーナー側（S-0305 の回数カード）と同じ
 *   ContractTicketService を使う。違うのは言葉づかいとマイナスの見せ方（Blade 側）
 * - 読むだけの表示に使う値だけをビューに渡す。トレーナーの操作のための値
 *   （contractOptions / defaultContractId / usageCount）は渡さない
 * - メモ（契約・利用）はビューで出さない（設計書 S-1412 設計方針）
 */
class ContractController extends Controller
{
    public function index(ContractTicketService $contractTicketService): View
    {
        $client = auth('client')->user();
        $client->load(['contracts', 'usages']);

        $ticket = $contractTicketService->build($client->contracts, $client->usages);

        // 契約ごとの行は、読むだけの表示に使う値だけにする（usageCount はトレーナーの削除の確認用）。
        // 並びは build() のまま（契約日の新しいものが先頭。同じ日は id の降順）。
        $contracts = array_map(
            fn (array $row) => Arr::only($row, ['contract', 'slots', 'remaining', 'usedUp', 'expired']),
            $ticket['contracts']
        );

        return view('client.contracts.index', [
            'remaining' => $ticket['remaining'],
            'isEmpty' => $ticket['isEmpty'],
            'unassigned' => $ticket['unassigned'],
            'contracts' => $contracts,
        ]);
    }
}

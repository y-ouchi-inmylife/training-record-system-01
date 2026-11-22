<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ClientUsage;
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
 * - 予約の印（2026-11 追加。設計書 S-1412「マスの予約の印」）：割り当てた利用のマスごとに、利用日が今日なら「本日」、
 *   今日より後なら「ご予約」の印と見た目の状態を組み立てて渡す（判定は ContractTicketService の usageStates。Blade では判定しない）。
 *   使い切っていて予約を含む契約は「すべてご予約済み」（ContractTicketService の reserved）
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
        // slots は、マス 1 つずつを「日付・状態・印」の配列にする（未利用は null のまま。2026-11 変更：その前は利用そのもの）。
        // statusLabel は使い切った契約のバッジの文（予約を含めば「すべてご予約済み」。2026-11 追加）
        $contracts = array_map(
            fn (array $row) => Arr::only($row, ['contract', 'remaining', 'usedUp', 'expired']) + [
                'slots' => array_map(
                    fn (?ClientUsage $usage) => $usage === null ? null : self::slot($usage, $ticket['usageStates'][$usage->id]),
                    $row['slots']
                ),
                'statusLabel' => $row['reserved'] ? 'すべてご予約済み' : 'すべてご利用済み',
            ],
            $ticket['contracts']
        );

        return view('client.contracts.index', [
            'remaining' => $ticket['remaining'],
            'isEmpty' => $ticket['isEmpty'],
            'unassigned' => $ticket['unassigned'],
            'contracts' => $contracts,
        ]);
    }

    /**
     * 割り当てた利用のマス 1 つ（2026-11 追加。設計書 S-1412「マスの予約の印」）。
     * date は利用日（Y/m/d）、state は ContractTicketService の状態（past / today / upcoming。見た目のクラスに使う）、
     * mark は日付の上に出す印（今日「本日」・今日より後「ご予約」・今日より前は null）。利用のメモは入れない
     *
     * @return array{date: string, state: string, mark: ?string}
     */
    private static function slot(ClientUsage $usage, string $state): array
    {
        return [
            'date' => $usage->used_on->format('Y/m/d'),
            'state' => $state,
            'mark' => match ($state) {
                ContractTicketService::STATE_TODAY => '本日',
                ContractTicketService::STATE_UPCOMING => 'ご予約',
                default => null,
            },
        ];
    }
}

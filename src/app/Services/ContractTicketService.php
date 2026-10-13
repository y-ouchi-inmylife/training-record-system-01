<?php

namespace App\Services;

use App\Models\ClientContract;
use App\Models\ClientUsage;
use Illuminate\Support\Collection;

/**
 * 会員詳細（S-0305）セクション5「回数」の回数券型の表示データと、
 * 利用モーダル（S-0305-M05）の「契約」の選択肢を組み立てるサービス。
 *
 * 利用（D-1000）は、どの契約に割り当てたかを client_contract_id で持つ（NULL は「契約なし」）。
 * 表示では、この割り当てのとおりにマスを並べる（設計書 screen-design.md S-0305
 * セクション5「マスの並べ方」）。表示のときに古い契約から自動で割り当てる処理は持たない。
 *
 *   1. 各契約に、回数ぶんのマスを用意する
 *   2. その契約に割り当てた利用を、利用日の昇順（同じ日は id の昇順）に並べ、左のマスから埋める。
 *      利用 1 件は 1 回分で、1 マスを使う（利用は回数を持たない。D-1000 注記）
 *   3. 埋まらなかったマスは「未利用」
 *   4. 契約に割り当てていない利用は「（契約なし）」の行（未割り当て）に並べる
 *
 * 残りのない契約への割り当てと、割り当て済みの件数より少ない回数への契約の編集は
 * ClientUsageRequest / ClientContractRequest でエラーにするため、
 * 通常はマスからあふれない。万一データ上あふれていても画面が壊れないよう、
 * あふれた分も契約の行のマスとして末尾に並べ（隠さない）、契約の状態は「使い切り」として扱う。
 *
 * ロジックを Blade に書かないため、ビューにそのまま渡せる配列で返す。
 */
class ContractTicketService
{
    /**
     * 回数券型の表示データと、利用モーダルの選択肢を組み立てる。
     *
     * 返す配列の形：
     * [
     *   'remaining'  => ?int,  // 会員の残り回数（契約回数の合計 − 利用の件数。未割り当ても数える）。契約も利用も 0 件なら null
     *   'isEmpty'    => bool,  // 契約も利用も 1 件もないか（カード本文の空の状態の判定用）
     *   'unassigned' => [      // 「（契約なし）」の行
     *     'slots' => ClientUsage[],  // 1 要素 = 1 マス。利用日の昇順（同じ日は id の昇順）
     *     'count' => int,            // 未割り当ての利用の件数（＝マスの数。バッジ「未割り当て n」の n）
     *   ],
     *   'contracts'  => [      // 表示順（契約日の新しいものが先頭）
     *     [
     *       'contract'   => ClientContract,
     *       'slots'      => (ClientUsage|null)[],  // 1 要素 = 1 マス。null は未利用。利用日の昇順
     *       'remaining'  => int,                   // この契約の残り（回数 − 割り当てた利用の件数。0 未満にはしない）
     *       'usedUp'     => bool,                  // 残りが 0 か
     *       'usageCount' => int,                   // 割り当てた利用の件数（削除の確認ダイアログ用。＝使ったマスの数）
     *     ],
     *     ...
     *   ],
     *   'contractOptions'   => [  // 利用モーダルの「契約」の選択肢（契約日の古い順。「契約なし」は含めない）
     *     ['id' => int, 'label' => string, 'usedUp' => bool],
     *     ...
     *   ],
     *   'defaultContractId' => ?int,  // 登録時の初期値（残りがある契約のうち一番古いもの。なければ null＝「契約なし」）
     * ]
     *
     * @param  Collection<int, ClientContract>  $contracts
     * @param  Collection<int, ClientUsage>  $usages
     */
    public function build(Collection $contracts, Collection $usages): array
    {
        // 契約日の昇順・同じ日は id の昇順（選択肢の並び・初期値の「一番古い契約」の基準）。
        // 呼び出し側の並びに頼らずここで並べ直す。
        $sortedContracts = $contracts
            ->sortBy([
                fn (ClientContract $a, ClientContract $b) => $a->contracted_on->format('Y-m-d') <=> $b->contracted_on->format('Y-m-d'),
                fn (ClientContract $a, ClientContract $b) => $a->id <=> $b->id,
            ])
            ->values();

        // 利用日の昇順・同じ日は id の昇順（マスの並び）
        $sortedUsages = $usages
            ->sortBy([
                fn (ClientUsage $a, ClientUsage $b) => $a->used_on->format('Y-m-d') <=> $b->used_on->format('Y-m-d'),
                fn (ClientUsage $a, ClientUsage $b) => $a->id <=> $b->id,
            ])
            ->values();

        // 契約ごとに割り当てた利用を振り分ける。渡された契約に見つからない契約 id を持つ利用
        // （通常は起きない。別会員の契約を指すなどデータの不整合）は、表示から消えないよう未割り当てに入れる。
        $contractIds = $sortedContracts->pluck('id')->all();
        $usagesByContract = [];
        $unassignedSlots = [];
        foreach ($sortedUsages as $usage) {
            $contractId = $usage->client_contract_id;
            if ($contractId !== null && in_array((int) $contractId, $contractIds, true)) {
                $usagesByContract[(int) $contractId][] = $usage;
            } else {
                $unassignedSlots[] = $usage; // 1 件＝1 マス
            }
        }

        $contractRows = [];
        $contractOptions = [];
        $defaultContractId = null;
        foreach ($sortedContracts as $contract) {
            $assigned = $usagesByContract[$contract->id] ?? [];

            // 割り当てた利用を、1 件につき 1 マスに入れる
            $slots = $assigned;
            $assignedCount = count($slots);

            // 残りのマスは未利用。あふれている場合（通常は起きない）は足さない
            for ($i = $assignedCount; $i < (int) $contract->count; $i++) {
                $slots[] = null;
            }

            $remaining = max(0, (int) $contract->count - $assignedCount);
            $usedUp = $remaining === 0;

            $contractRows[] = [
                'contract' => $contract,
                'slots' => $slots,
                'remaining' => $remaining,
                'usedUp' => $usedUp,
                'usageCount' => count($assigned),
            ];

            $contractOptions[] = [
                'id' => $contract->id,
                'label' => self::optionLabel($contract, $remaining),
                'usedUp' => $usedUp,
            ];

            // 登録時の初期値：残りがある契約のうち一番古いもの（契約日の昇順で最初に見つかったもの）
            if ($defaultContractId === null && ! $usedUp) {
                $defaultContractId = $contract->id;
            }
        }

        return [
            // 利用の数は件数（利用 1 件は 1 回分）
            'remaining' => self::remaining((int) $sortedContracts->sum('count'), $sortedUsages->count()),
            'isEmpty' => $sortedContracts->isEmpty() && $sortedUsages->isEmpty(),
            'unassigned' => [
                'slots' => $unassignedSlots,
                'count' => count($unassignedSlots),
            ],
            // 表示用：契約日の新しいものが上
            'contracts' => array_reverse($contractRows),
            'contractOptions' => $contractOptions,
            'defaultContractId' => $defaultContractId,
        ];
    }

    /**
     * 利用モーダルの「契約」の選択肢の表示。
     *
     * 「Y/m/d 契約（N回） 残り n」（設計書 S-0305-M05）。メモがあれば、契約どうしを
     * 見分けやすいよう後ろに括弧で付ける（「メモを後ろに付けるかは実装時に決める」の判断）。
     */
    private static function optionLabel(ClientContract $contract, int $remaining): string
    {
        $label = $contract->contracted_on->format('Y/m/d')
            ." 契約（{$contract->count}回） 残り {$remaining}";

        if ($contract->note !== null && $contract->note !== '') {
            $label .= "（{$contract->note}）";
        }

        return $label;
    }

    /**
     * 残り回数を求める（契約回数の合計 − 利用の件数。マイナスも許す）。
     *
     * 利用 1 件は 1 回分なので、第 2 引数には利用の件数を渡す。未割り当ての利用も数える。契約も利用も 1 件もない会員は null（表示しない）。
     * 契約・利用が 1 件でも登録されていれば回数管理の対象の会員として計算どおりの値を返す
     * （契約がなく利用だけならマイナス）。回数契約をしていない会員と、使い切った会員（0）を
     * 見分けるため（設計書 screen-design.md §2-4「例外：残り回数」）。
     *
     * 会員一覧（S-0304）のサブクエリで取った合計からも同じ規則で求めるため、静的メソッドとして公開する。
     * 契約回数のサブクエリの SUM は対象の行がないと NULL、利用の件数の COUNT(*) は 0 になるため、
     * null と 0 はどちらも「登録なし」として扱う（契約の回数は 1 以上なので、契約があれば合計は 0 にならない）。
     */
    public static function remaining(?int $contractTotal, ?int $usageCount): ?int
    {
        $contractTotal = (int) $contractTotal;
        $usageCount = (int) $usageCount;

        if ($contractTotal === 0 && $usageCount === 0) {
            return null;
        }

        return $contractTotal - $usageCount;
    }
}

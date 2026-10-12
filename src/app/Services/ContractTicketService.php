<?php

namespace App\Services;

use App\Models\ClientContract;
use App\Models\ClientUsage;
use Illuminate\Support\Collection;

/**
 * 会員詳細（S-0305）セクション5「回数」の回数券型の表示データを組み立てるサービス。
 *
 * 契約（D-0900）と利用（D-1000）はデータとしてはひも付けていないため、
 * どの利用をどの契約のマスに入れるかを表示のたびに計算する（DB には持たない）。
 * 割り当てルールは設計書 screen-design.md S-0305 セクション5「割り当てルール」のとおり：
 *
 *   1. 契約を契約日の昇順（同じ日は id の昇順）に並べ、各契約の回数ぶんのマスを用意する
 *   2. 利用を利用日の昇順（同じ日は id の昇順）に並べ、1 件の回数ぶん、
 *      古い契約の空いているマスから順に埋める（1 件が 2 つの契約にまたがってもよい）
 *   3. どの契約のマスにも入らなかった分は「超過」とする（マス単位。2 回分のうち
 *      1 回分だけが超過になることもある）
 *   4. 利用日と契約日の前後・有効期限は見ない（契約日より前の利用もマスに入る）
 *
 * ロジックを Blade に書かないため、ビューにそのまま渡せる配列で返す。
 */
class ContractTicketService
{
    /**
     * 回数券型の表示データを組み立てる。
     *
     * 返す配列の形：
     * [
     *   'remaining'    => ?int,  // 残り回数（契約回数の合計 − 利用回数の合計）。契約も利用も 0 件なら null
     *   'isEmpty'      => bool,  // 契約も利用も 1 件もないか（カード本文の空の状態の判定用）
     *   'overflow'     => ClientUsage[],  // 超過のマス（1 要素 = 1 マス。古い利用が先頭）
     *   'contracts'    => [               // 表示順（契約日の新しいものが先頭）
     *     [
     *       'contract'  => ClientContract,
     *       'slots'     => (ClientUsage|null)[],  // 1 要素 = 1 マス。null は未利用。古い利用が先頭
     *       'remaining' => int,                   // この契約の未利用のマスの数
     *       'usedUp'    => bool,                  // すべてのマスが埋まっているか
     *     ],
     *     ...
     *   ],
     * ]
     *
     * @param  Collection<int, ClientContract>  $contracts
     * @param  Collection<int, ClientUsage>  $usages
     */
    public function build(Collection $contracts, Collection $usages): array
    {
        // 手順 1：契約日の昇順・同じ日は id の昇順。呼び出し側の並びに頼らずここで並べ直す。
        $sortedContracts = $contracts
            ->sortBy([
                fn (ClientContract $a, ClientContract $b) => $a->contracted_on->format('Y-m-d') <=> $b->contracted_on->format('Y-m-d'),
                fn (ClientContract $a, ClientContract $b) => $a->id <=> $b->id,
            ])
            ->values();

        // 手順 2 の並び：利用日の昇順・同じ日は id の昇順
        $sortedUsages = $usages
            ->sortBy([
                fn (ClientUsage $a, ClientUsage $b) => $a->used_on->format('Y-m-d') <=> $b->used_on->format('Y-m-d'),
                fn (ClientUsage $a, ClientUsage $b) => $a->id <=> $b->id,
            ])
            ->values();

        // 各契約の回数ぶんのマス（null = 未利用）
        $slotsByContract = [];
        foreach ($sortedContracts as $index => $contract) {
            $slotsByContract[$index] = array_fill(0, max(0, (int) $contract->count), null);
        }

        // 手順 2・3：1 回分ずつ、古い契約の空いているマスから埋める。入らなければ超過。
        // 空きを探す位置（契約の添字・マスの添字）は前に進むだけでよい
        // （マスは古い契約の先頭から順に埋まり、途中に空きが残ることはないため）。
        $overflow = [];
        $contractIndex = 0;
        $slotIndex = 0;
        $contractTotal = count($slotsByContract);
        foreach ($sortedUsages as $usage) {
            for ($i = 0; $i < (int) $usage->count; $i++) {
                // 埋まりきった契約（回数 0 の契約を含む）を飛ばす
                while ($contractIndex < $contractTotal && $slotIndex >= count($slotsByContract[$contractIndex])) {
                    $contractIndex++;
                    $slotIndex = 0;
                }
                if ($contractIndex < $contractTotal) {
                    $slotsByContract[$contractIndex][$slotIndex] = $usage;
                    $slotIndex++;
                } else {
                    $overflow[] = $usage;
                }
            }
        }

        // 表示用：契約日の新しいものが上（割り当ての並びの逆順）
        $contractRows = [];
        foreach ($sortedContracts as $index => $contract) {
            $slots = $slotsByContract[$index];
            $remaining = count(array_filter($slots, fn ($slot) => $slot === null));
            $contractRows[] = [
                'contract' => $contract,
                'slots' => $slots,
                'remaining' => $remaining,
                'usedUp' => $remaining === 0,
            ];
        }
        $contractRows = array_reverse($contractRows);

        return [
            'remaining' => self::remaining((int) $sortedContracts->sum('count'), (int) $sortedUsages->sum('count')),
            'isEmpty' => $sortedContracts->isEmpty() && $sortedUsages->isEmpty(),
            'overflow' => $overflow,
            'contracts' => $contractRows,
        ];
    }

    /**
     * 残り回数を求める（契約回数の合計 − 利用回数の合計。マイナスも許す）。
     *
     * 契約も利用も 1 件もない会員は null（表示しない）。契約・利用が 1 件でも登録されていれば
     * 回数管理の対象の会員として計算どおりの値を返す（契約がなく利用だけならマイナス）。
     * 回数契約をしていない会員と、使い切った会員（0）を見分けるため
     * （設計書 screen-design.md §2-4「例外：残り回数」）。
     *
     * 会員一覧（S-0304）のサブクエリで取った合計からも同じ規則で求めるため、静的メソッドとして公開する。
     * サブクエリの SUM は対象の行がないと NULL になるため、null と 0 はどちらも「登録なし」として扱う
     * （回数は 1 以上なので、行があれば合計は 0 にならない）。
     */
    public static function remaining(?int $contractTotal, ?int $usageTotal): ?int
    {
        $contractTotal = (int) $contractTotal;
        $usageTotal = (int) $usageTotal;

        if ($contractTotal === 0 && $usageTotal === 0) {
            return null;
        }

        return $contractTotal - $usageTotal;
    }
}

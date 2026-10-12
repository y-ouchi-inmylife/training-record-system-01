<?php

namespace Tests\Unit\Services;

use App\Models\ClientContract;
use App\Models\ClientUsage;
use App\Services\ContractTicketService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * 回数券型の割り当て（ContractTicketService）のテスト。
 *
 * 割り当てルールは設計書 screen-design.md S-0305 セクション5「割り当てルール」：
 *   1. 契約を契約日の昇順（同じ日は id の昇順）に並べ、回数ぶんのマスを用意する
 *   2. 利用を利用日の昇順（同じ日は id の昇順）に並べ、古い契約の空いているマスから埋める
 *   3. 入らなかった分は超過（マス単位）
 *   4. 利用日と契約日の前後・有効期限は見ない
 *
 * DB は使わず、モデルを new して id と値だけをセットする
 * （日付のキャストに Laravel のアプリケーションが要るため Tests\TestCase を使う）。
 */
class ContractTicketServiceTest extends TestCase
{
    private function contract(int $id, string $contractedOn, int $count, ?string $expiresOn = null): ClientContract
    {
        $contract = new ClientContract([
            'contracted_on' => $contractedOn,
            'count' => $count,
            'expires_on' => $expiresOn,
        ]);
        $contract->id = $id;

        return $contract;
    }

    private function usage(int $id, string $usedOn, int $count = 1): ClientUsage
    {
        $usage = new ClientUsage(['used_on' => $usedOn, 'count' => $count]);
        $usage->id = $id;

        return $usage;
    }

    private function build(array $contracts, array $usages): array
    {
        return (new ContractTicketService)->build(new Collection($contracts), new Collection($usages));
    }

    /** マスの並びを利用の id（未利用は null）の配列にする */
    private function slotIds(array $slots): array
    {
        return array_map(fn ($slot) => $slot?->id, $slots);
    }

    /** 表示順（新しい契約が先頭）の行から、契約 id をキーにした行を取り出す */
    private function rowOf(array $result, int $contractId): array
    {
        foreach ($result['contracts'] as $row) {
            if ($row['contract']->id === $contractId) {
                return $row;
            }
        }
        $this->fail("contract {$contractId} not found");
    }

    public function test_契約1件で利用なしならすべて未利用(): void
    {
        $result = $this->build([$this->contract(1, '2026-10-01', 4)], []);

        $row = $this->rowOf($result, 1);
        $this->assertSame([null, null, null, null], $this->slotIds($row['slots']));
        $this->assertSame(4, $row['remaining']);
        $this->assertFalse($row['usedUp']);
        $this->assertSame([], $result['overflow']);
        $this->assertSame(4, $result['remaining']);
        $this->assertFalse($result['isEmpty']);
    }

    public function test_一部利用と使い切り(): void
    {
        $result = $this->build(
            [
                $this->contract(1, '2026-09-03', 2),
                $this->contract(2, '2026-10-12', 4),
            ],
            [
                $this->usage(11, '2026-09-04'),
                $this->usage(12, '2026-09-06'),
                $this->usage(13, '2026-10-12'),
                $this->usage(14, '2026-10-13'),
            ]
        );

        // 表示順は契約日の新しいものが先頭
        $this->assertSame([2, 1], array_map(fn ($row) => $row['contract']->id, $result['contracts']));

        $old = $this->rowOf($result, 1);
        $this->assertSame([11, 12], $this->slotIds($old['slots']));
        $this->assertTrue($old['usedUp']);
        $this->assertSame(0, $old['remaining']);

        $new = $this->rowOf($result, 2);
        $this->assertSame([13, 14, null, null], $this->slotIds($new['slots']));
        $this->assertFalse($new['usedUp']);
        $this->assertSame(2, $new['remaining']);

        $this->assertSame(2, $result['remaining']);
    }

    public function test_2回分の利用が2つの契約にまたがる(): void
    {
        $result = $this->build(
            [
                $this->contract(1, '2026-09-01', 2),
                $this->contract(2, '2026-10-01', 2),
            ],
            [
                $this->usage(11, '2026-09-02'),
                $this->usage(12, '2026-09-10', 2),
            ]
        );

        // 利用 12 の 2 回分は、古い契約の残り 1 マスと、新しい契約の先頭 1 マスに入る
        $this->assertSame([11, 12], $this->slotIds($this->rowOf($result, 1)['slots']));
        $this->assertSame([12, null], $this->slotIds($this->rowOf($result, 2)['slots']));
        $this->assertSame(1, $result['remaining']);
    }

    public function test_超過はマス単位で一部だけが超過になり残り回数はマイナス(): void
    {
        $result = $this->build(
            [$this->contract(1, '2026-10-01', 2)],
            [
                $this->usage(11, '2026-10-02'),
                // 2 回分のうち 1 回分だけが契約のマスに入り、残り 1 回分が超過
                $this->usage(12, '2026-10-03', 2),
                $this->usage(13, '2026-10-04'),
            ]
        );

        $row = $this->rowOf($result, 1);
        $this->assertSame([11, 12], $this->slotIds($row['slots']));
        $this->assertTrue($row['usedUp']);

        // 超過のマスは古い利用が先頭
        $this->assertSame([12, 13], array_map(fn ($usage) => $usage->id, $result['overflow']));
        // 超過があるとき、残り回数は −（超過のマスの数）
        $this->assertSame(-2, $result['remaining']);
    }

    public function test_契約日より前の日付の利用もマスに入る(): void
    {
        $result = $this->build(
            [$this->contract(1, '2026-10-12', 2)],
            [$this->usage(11, '2026-10-01')]
        );

        $this->assertSame([11, null], $this->slotIds($this->rowOf($result, 1)['slots']));
        $this->assertSame([], $result['overflow']);
        $this->assertSame(1, $result['remaining']);
    }

    public function test_有効期限は割り当てに使わない(): void
    {
        $result = $this->build(
            [$this->contract(1, '2026-01-01', 2, '2026-03-31')],
            [$this->usage(11, '2026-10-01')]
        );

        $this->assertSame([11, null], $this->slotIds($this->rowOf($result, 1)['slots']));
    }

    public function test_同じ日の契約と利用はidの昇順で並ぶ(): void
    {
        // 引数の並びはばらばらに渡し、サービス側で並べ直すことを確かめる
        $result = $this->build(
            [
                $this->contract(5, '2026-10-01', 1),
                $this->contract(3, '2026-10-01', 1),
            ],
            [
                $this->usage(22, '2026-10-02'),
                $this->usage(21, '2026-10-02'),
            ]
        );

        // 同じ契約日：id の小さい契約 3 が先に埋まる。同じ利用日：id の小さい利用 21 が先
        $this->assertSame([21], $this->slotIds($this->rowOf($result, 3)['slots']));
        $this->assertSame([22], $this->slotIds($this->rowOf($result, 5)['slots']));
        // 表示順は割り当ての並びの逆（新しいもの＝後に並ぶものが先頭）
        $this->assertSame([5, 3], array_map(fn ($row) => $row['contract']->id, $result['contracts']));
    }

    public function test_契約がなく利用2回なら残り回数はマイナス2で利用はすべて超過(): void
    {
        $result = $this->build([], [$this->usage(11, '2026-10-01', 2)]);

        // 契約・利用が 1 件でもあれば回数管理の対象として計算どおりの値を出す（設計書 §2-4）
        $this->assertSame(-2, $result['remaining']);
        $this->assertFalse($result['isEmpty']);
        $this->assertSame([], $result['contracts']);
        $this->assertSame([11, 11], array_map(fn ($usage) => $usage->id, $result['overflow']));
    }

    public function test_契約も利用もないと空の状態(): void
    {
        $result = $this->build([], []);

        $this->assertNull($result['remaining']);
        $this->assertTrue($result['isEmpty']);
    }

    public function test_残り回数は契約も利用も0件のときだけnullで0とは区別する(): void
    {
        // 会員一覧のサブクエリの SUM は、行がないと NULL になる
        $this->assertNull(ContractTicketService::remaining(null, null));
        $this->assertNull(ContractTicketService::remaining(0, 0));
        $this->assertNull(ContractTicketService::remaining(null, 0));
        // 契約がなく利用だけ：計算どおりマイナス
        $this->assertSame(-3, ContractTicketService::remaining(null, 3));
        $this->assertSame(-3, ContractTicketService::remaining(0, 3));
        $this->assertSame(0, ContractTicketService::remaining(4, 4));
        $this->assertSame(4, ContractTicketService::remaining(4, null));
        $this->assertSame(-1, ContractTicketService::remaining(4, 5));
    }
}

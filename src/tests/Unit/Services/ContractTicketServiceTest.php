<?php

namespace Tests\Unit\Services;

use App\Models\ClientContract;
use App\Models\ClientUsage;
use App\Services\ContractTicketService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * 回数券型の表示データと利用モーダルの選択肢（ContractTicketService）のテスト。
 *
 * 利用は client_contract_id で割り当てた契約を持ち（NULL は「契約なし」）、利用 1 件は 1 回分（1 マス）。
 * 表示はその割り当てのとおりに
 * 並べる（設計書 screen-design.md S-0305 セクション5「マスの並べ方」）。表示のときに古い契約から
 * 自動で割り当てる処理は持たない。
 *
 * DB は使わず、モデルを new して id と値だけをセットする
 * （日付のキャストに Laravel のアプリケーションが要るため Tests\TestCase を使う）。
 */
class ContractTicketServiceTest extends TestCase
{
    private function contract(int $id, string $contractedOn, int $count, ?string $note = null, ?string $expiresOn = null): ClientContract
    {
        $contract = new ClientContract([
            'contracted_on' => $contractedOn,
            'count' => $count,
            'note' => $note,
            'expires_on' => $expiresOn,
        ]);
        $contract->id = $id;

        return $contract;
    }

    private function usage(int $id, ?int $contractId, string $usedOn): ClientUsage
    {
        $usage = new ClientUsage(['client_contract_id' => $contractId, 'used_on' => $usedOn]);
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

    /** 表示順（新しい契約が先頭）の行から、契約 id の行を取り出す */
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
        $this->assertSame(0, $row['usageCount']);
        $this->assertSame(['slots' => [], 'count' => 0], $result['unassigned']);
        $this->assertSame(4, $result['remaining']);
        $this->assertFalse($result['isEmpty']);
    }

    public function test_割り当てた契約のマスに利用日の順で入る(): void
    {
        // 引数の並びはばらばらに渡し、サービス側で利用日の昇順（同じ日は id の昇順）に並べ直すことを確かめる
        $result = $this->build(
            [
                $this->contract(1, '2026-09-03', 2),
                $this->contract(2, '2026-10-12', 4),
            ],
            [
                $this->usage(14, 2, '2026-10-13'),
                $this->usage(13, 2, '2026-10-12'),
                $this->usage(12, 1, '2026-09-06'),
                $this->usage(11, 1, '2026-09-04'),
                $this->usage(15, 2, '2026-10-12'),
            ]
        );

        // 表示順は契約日の新しいものが先頭
        $this->assertSame([2, 1], array_map(fn ($row) => $row['contract']->id, $result['contracts']));

        $old = $this->rowOf($result, 1);
        $this->assertSame([11, 12], $this->slotIds($old['slots']));
        $this->assertTrue($old['usedUp']);
        $this->assertSame(0, $old['remaining']);
        $this->assertSame(2, $old['usageCount']);

        // 同じ利用日（10/12）は id の昇順（13 → 15）
        $new = $this->rowOf($result, 2);
        $this->assertSame([13, 15, 14, null], $this->slotIds($new['slots']));
        $this->assertFalse($new['usedUp']);
        $this->assertSame(1, $new['remaining']);
        $this->assertSame(3, $new['usageCount']);

        $this->assertSame(1, $result['remaining']);
    }

    public function test_自動割り当てはしない_古い契約に空きがあっても割り当てたとおりに並ぶ(): void
    {
        // 古い契約（1）に空きがあっても、新しい契約（2）に割り当てた利用は 2 に入る
        $result = $this->build(
            [
                $this->contract(1, '2026-09-01', 2),
                $this->contract(2, '2026-10-01', 2),
            ],
            [$this->usage(11, 2, '2026-09-02')]
        );

        $this->assertSame([null, null], $this->slotIds($this->rowOf($result, 1)['slots']));
        $this->assertSame([11, null], $this->slotIds($this->rowOf($result, 2)['slots']));
    }

    public function test_利用1件は1マスで契約ごとの残りは件数で数える(): void
    {
        // 2 回分使ったときは利用を 2 件登録する（同じ日でもよい）
        $result = $this->build(
            [$this->contract(1, '2026-10-01', 4)],
            [
                $this->usage(11, 1, '2026-10-02'),
                $this->usage(12, 1, '2026-10-02'),
                $this->usage(13, 1, '2026-10-03'),
            ]
        );

        $row = $this->rowOf($result, 1);
        $this->assertSame([11, 12, 13, null], $this->slotIds($row['slots']));
        // 契約ごとの残り ＝ 回数 − 割り当てた利用の件数
        $this->assertSame(1, $row['remaining']);
        $this->assertSame(3, $row['usageCount']);
    }

    public function test_未割り当ての利用は利用日の順に並び件数を持つ(): void
    {
        $result = $this->build(
            [$this->contract(1, '2026-10-01', 2)],
            [
                $this->usage(14, null, '2026-10-20'),
                $this->usage(13, null, '2026-10-05'),
                $this->usage(12, null, '2026-10-05'),
                $this->usage(11, 1, '2026-10-02'),
            ]
        );

        // 未割り当てのマスは利用日の昇順（同じ日は id の昇順）。1 件＝1 マス
        $this->assertSame([12, 13, 14], $this->slotIds($result['unassigned']['slots']));
        $this->assertSame(3, $result['unassigned']['count']);
        // 契約のマスには割り当てた利用だけが入る
        $this->assertSame([11, null], $this->slotIds($this->rowOf($result, 1)['slots']));
    }

    public function test_全体の残り回数は未割り当ての利用も引く(): void
    {
        // 契約 4 回、割り当て 1 件、未割り当て 3 件 → 4 − 4 = 0（契約の残りは 3 のまま）
        $result = $this->build(
            [$this->contract(1, '2026-10-01', 4)],
            [
                $this->usage(11, 1, '2026-10-02'),
                $this->usage(12, null, '2026-10-03'),
                $this->usage(13, null, '2026-10-04'),
                $this->usage(14, null, '2026-10-05'),
            ]
        );

        $this->assertSame(3, $this->rowOf($result, 1)['remaining']);
        $this->assertSame(0, $result['remaining']);
    }

    public function test_契約がなく利用だけなら残り回数はマイナスですべて未割り当て(): void
    {
        $result = $this->build([], [$this->usage(11, null, '2026-10-01'), $this->usage(12, null, '2026-10-02')]);

        $this->assertSame(-2, $result['remaining']);
        $this->assertFalse($result['isEmpty']);
        $this->assertSame([], $result['contracts']);
        $this->assertSame([11, 12], $this->slotIds($result['unassigned']['slots']));
        $this->assertSame(2, $result['unassigned']['count']);
        $this->assertSame([], $result['contractOptions']);
        $this->assertNull($result['defaultContractId']);
    }

    public function test_契約も利用もないと空の状態(): void
    {
        $result = $this->build([], []);

        $this->assertNull($result['remaining']);
        $this->assertTrue($result['isEmpty']);
    }

    public function test_選択肢は契約日の古い順で表示と使い切りを持つ(): void
    {
        $result = $this->build(
            [
                $this->contract(5, '2026-10-12', 4, '4回コース'),
                $this->contract(3, '2026-09-03', 2),
                $this->contract(2, '2026-09-03', 1),
            ],
            [
                $this->usage(11, 3, '2026-09-04'),
                $this->usage(13, 3, '2026-09-05'),
                $this->usage(12, 5, '2026-10-13'),
            ]
        );

        // 契約日の古い順・同じ日は id の昇順（2 → 3 → 5）。「契約なし」は含めない（Blade で最後に足す）
        $this->assertSame([
            ['id' => 2, 'label' => '2026/09/03 契約（1回） 残り 1', 'usedUp' => false],
            ['id' => 3, 'label' => '2026/09/03 契約（2回） 残り 0', 'usedUp' => true],
            ['id' => 5, 'label' => '2026/10/12 契約（4回） 残り 3（4回コース）', 'usedUp' => false],
        ], $result['contractOptions']);
    }

    public function test_登録時の初期値は残りがある一番古い契約(): void
    {
        $result = $this->build(
            [
                $this->contract(1, '2026-09-01', 1),
                $this->contract(2, '2026-10-01', 2),
                $this->contract(3, '2026-11-01', 2),
            ],
            // 一番古い契約（1）は使い切り → 残りがある一番古い契約は 2
            [$this->usage(11, 1, '2026-09-02')]
        );

        $this->assertSame(2, $result['defaultContractId']);
    }

    public function test_残りのある契約がなければ登録時の初期値は契約なし(): void
    {
        $result = $this->build(
            [$this->contract(1, '2026-09-01', 1)],
            [$this->usage(11, 1, '2026-09-02')]
        );

        $this->assertNull($result['defaultContractId']);
    }

    public function test_データ上マスからあふれていても壊れずにすべてのマスを出し使い切りにする(): void
    {
        // 通常はバリデーションで起きない（契約の残りを超える割り当て）。直接 DB を書き換えた場合などの保険
        $result = $this->build(
            [$this->contract(1, '2026-10-01', 2)],
            [
                $this->usage(11, 1, '2026-10-02'),
                $this->usage(12, 1, '2026-10-03'),
                $this->usage(13, 1, '2026-10-04'),
            ]
        );

        $row = $this->rowOf($result, 1);
        $this->assertSame([11, 12, 13], $this->slotIds($row['slots']), 'あふれた分も隠さず並べる');
        $this->assertSame(0, $row['remaining']);
        $this->assertTrue($row['usedUp']);
        // 全体の残り回数は合計どおり（2 − 3 = −1）
        $this->assertSame(-1, $result['remaining']);
    }

    public function test_渡された契約にない契約idを持つ利用は未割り当てとして扱う(): void
    {
        // 通常は起きない（別会員の契約を指すなどのデータの不整合）。表示から利用が消えないようにする
        $result = $this->build(
            [$this->contract(1, '2026-10-01', 2)],
            [$this->usage(11, 99, '2026-10-02')]
        );

        $this->assertSame([11], $this->slotIds($result['unassigned']['slots']));
        $this->assertSame([null, null], $this->slotIds($this->rowOf($result, 1)['slots']));
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
    // ---- 有効期限を過ぎていて残りがあるか（expired） ----

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 今日を 2026-10-13（アプリのタイムゾーン）に固定して、契約 1 件（回数 2）の expired を返す */
    private function expiredOf(?string $expiresOn, int $usages = 0): bool
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', config('app.timezone')));

        $usageModels = [];
        for ($i = 0; $i < $usages; $i++) {
            $usageModels[] = $this->usage(100 + $i, 1, '2026-10-01');
        }
        $result = $this->build([$this->contract(1, '2026-09-01', 2, null, $expiresOn)], $usageModels);

        return $this->rowOf($result, 1)['expired'];
    }

    public function test_有効期限が昨日で残りがあれば期限切れ(): void
    {
        $this->assertTrue($this->expiredOf('2026-10-12'));
        // 残り 1（2 回のうち 1 件使用）でも期限切れ
        $this->assertTrue($this->expiredOf('2026-10-12', 1));
    }

    public function test_有効期限が今日ならまだ期限切れではない(): void
    {
        $this->assertFalse($this->expiredOf('2026-10-13'));
    }

    public function test_有効期限が明日なら期限切れではない(): void
    {
        $this->assertFalse($this->expiredOf('2026-10-14'));
    }

    public function test_有効期限がなければ期限切れではない(): void
    {
        $this->assertFalse($this->expiredOf(null));
    }

    public function test_有効期限が昨日でも使い切りなら期限切れにしない(): void
    {
        $this->assertFalse($this->expiredOf('2026-10-12', 2));
    }

    public function test_今日の日付はアプリのタイムゾーンで判定する(): void
    {
        // UTC では 10/12 15:30 だが、アプリのタイムゾーン（Asia/Tokyo）では 10/13 00:30。
        // 有効期限 10/12 は「今日（10/13）より前」なので期限切れ
        $this->assertSame('Asia/Tokyo', config('app.timezone'));
        Carbon::setTestNow(Carbon::parse('2026-10-12 15:30:00', 'UTC'));
        $result = $this->build([$this->contract(1, '2026-09-01', 2, null, '2026-10-12')], []);

        $this->assertTrue($this->rowOf($result, 1)['expired']);
    }

    public function test_期限切れの契約も選択肢では今までどおり選べる(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', config('app.timezone')));
        $result = $this->build([$this->contract(1, '2026-09-01', 2, null, '2026-10-01')], []);

        // 期限を過ぎていても、残りがあれば使い切り扱いにしない（選べる）。初期値にもなる
        $this->assertTrue($this->rowOf($result, 1)['expired']);
        $this->assertSame([['id' => 1, 'label' => '2026/09/01 契約（2回） 残り 2', 'usedUp' => false]], $result['contractOptions']);
        $this->assertSame(1, $result['defaultContractId']);
        // 残り回数の計算にも使わない
        $this->assertSame(2, $result['remaining']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainer;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員の検索の内部 API（GET /api/clients/search）の並び順（2026-11 追加。設計書 api-design.md の order）。
 *
 * - order を付けなければ、数字だけの内部ID を数の小さい順に先に、それ以外の形は後ろに文字列の小さい順（音声系の画面はこちら。
 *   2026-11 変更：以前は internal_id の文字列の順で、10 が 9 より前に来ていた）
 * - order=desc（メディア一覧 S-1302 の会員の絞り込み）は、数字だけの内部ID を数の大きい順に先に、
 *   それ以外の形（先頭ゼロ・英字など）は後ろに文字列の大きい順
 * - 件数の上限（20 件）・部分一致・表示（「内部ID 姓 名」）・id の指定は変えない
 *
 * 「-1」「1.5」のような符号・小数の扱いは MySQL と SQLite で CAST の結果が違うため、ここでは確かめない
 * （MySQL での並びは開発 DB で確かめた。降順：10, 9, 2, abc, 1.5, 007, -1／昇順：2, 9, 10, -1, 007, 1.5, abc）。
 */
class ClientSearchApiOrderTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createAccessLogsTable();
        $this->createTrainersTableForTraineeShow();
        DB::table('trainers')->insert(['id' => 200, 'name' => 'テスト', 'role' => 'staff', 'display_order' => 1]);
        $trainer = new Trainer(['name' => 'テスト', 'role' => 'staff']);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    private function makeClients(array $internalIds): void
    {
        foreach ($internalIds as $i => $internalId) {
            Client::query()->create(['internal_id' => $internalId, 'last_name' => '会員', 'first_name' => (string) $i]);
        }
    }

    /** 返った候補の内部ID（「内部ID 姓 名」の先頭） */
    private function internalIds(array $json): array
    {
        return array_map(fn ($row) => explode(' ', $row['text'])[0], $json['results']);
    }

    public function test_orderを付けなければ内部idの数の小さい順(): void
    {
        $this->makeClients(['9', '10', '2', '100']);

        $json = $this->getJson('/api/clients/search?q=')->assertOk()->json();

        // 数の順（以前の文字列の順では 10, 100, 2, 9 だった）
        $this->assertSame(['2', '9', '10', '100'], $this->internalIds($json));
    }

    public function test_orderを付けなければ数字だけでない形のものは後ろに文字列の小さい順(): void
    {
        $this->makeClients(['9', '007', '10', 'abc', 'A1', '2']);

        $json = $this->getJson('/api/clients/search?q=')->assertOk()->json();

        $this->assertSame(['2', '9', '10', '007', 'A1', 'abc'], $this->internalIds($json));
    }

    public function test_order_descなら内部idの数の大きい順(): void
    {
        $this->makeClients(['9', '10', '2', '100']);

        $json = $this->getJson('/api/clients/search?q=&order=desc')->assertOk()->json();

        $this->assertSame(['100', '10', '9', '2'], $this->internalIds($json));
    }

    public function test_order_descで数字だけでない形のものは後ろに文字列の大きい順(): void
    {
        $this->makeClients(['9', '007', '10', 'abc', 'A1']);

        $json = $this->getJson('/api/clients/search?q=&order=desc')->assertOk()->json();

        $this->assertSame(['10', '9', 'abc', 'A1', '007'], $this->internalIds($json));
    }

    public function test_desc以外の値は指定なしと同じ並び(): void
    {
        $this->makeClients(['9', '10', '2', 'A1']);

        foreach (['asc', 'DESC', 'x'] as $order) {
            $json = $this->getJson('/api/clients/search?q=&order='.$order)->assertOk()->json();
            $this->assertSame(['2', '9', '10', 'A1'], $this->internalIds($json), "order={$order}");
        }
    }

    public function test_order_descでも上限20件と部分一致と表示は変わらない(): void
    {
        $this->makeClients(array_map('strval', range(1, 25)));
        Client::query()->create(['internal_id' => '99', 'last_name' => '大内', 'first_name' => '佳和']);

        $all = $this->getJson('/api/clients/search?q=&order=desc')->assertOk()->json();
        $this->assertCount(20, $all['results']);
        $this->assertSame('99 大内 佳和', $all['results'][0]['text']);
        $this->assertSame(['99', '25', '24'], array_slice($this->internalIds($all), 0, 3));

        // 部分一致（姓）
        $found = $this->getJson('/api/clients/search?q=大内&order=desc')->assertOk()->json();
        $this->assertSame(['99 大内 佳和'], array_column($found['results'], 'text'));

        // id を指定したときは 1 件（order は関係しない）
        $client = Client::query()->where('internal_id', '99')->first();
        $one = $this->getJson('/api/clients/search?id='.$client->id.'&order=desc')->assertOk()->json();
        $this->assertSame([['id' => $client->id, 'text' => '99 大内 佳和']], $one['results']);
    }
}

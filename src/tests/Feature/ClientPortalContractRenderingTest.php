<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientContract;
use App\Models\ClientUsage;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員側のご利用回数画面（設計書 screen-design.md S-1412、要件定義書 6-15-16）の描画確認。
 *
 * - 全体の「あと n回」（マイナスは「ご契約の回数を n回超えてご利用中です」の文。赤字にしない）
 * - 契約ごとのカード（新しい契約が上）・マス（利用日の昇順、まだ使っていないマスは「ご利用なし」。2026-11 変更：その前は「未利用」）
 * - 使い切りは「すべてご利用済み」、期限切れで残りがあるときは有効期限の日付が赤字
 * - 契約に含まれないご利用は一番下（黄色のバッジは使わない）
 * - 読むだけ（button・onclick・data-* を出さない）、メモは一切出さない
 * - 未ログインはログイン画面へ、ログイン中の会員自身の分だけを出す
 *
 * 実際の HTTP で開く。CLIENT_HOST は phpunit.xml で空にしているため、ルートはドメインの
 * 制約なしで登録され、テストの既定ホスト（localhost）のまま /client-portal/contracts に届く。
 */
class ClientPortalContractRenderingTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createClientCountTables();

        // 期限切れの判定（today() との比較）を固定する
        Carbon::setTestNow('2026-10-13 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeClient(string $lastName = '山田'): Client
    {
        return Client::query()->create([
            'internal_id' => 'C-'.$lastName,
            'last_name' => $lastName,
            'first_name' => '太郎',
            'email' => $lastName.'@example.com',
        ]);
    }

    private function contract(Client $client, string $contractedOn, int $count, ?string $expiresOn = null, ?string $note = null): ClientContract
    {
        return ClientContract::query()->create([
            'client_id' => $client->id, 'contracted_on' => $contractedOn, 'count' => $count,
            'expires_on' => $expiresOn, 'note' => $note,
        ]);
    }

    /** 利用を 1 件（1 回分）作る。$contract を省略すると「契約なし」 */
    private function usage(Client $client, string $usedOn, ?ClientContract $contract = null, ?string $note = null): ClientUsage
    {
        return ClientUsage::query()->create([
            'client_id' => $client->id, 'client_contract_id' => $contract?->id,
            'used_on' => $usedOn, 'start_time' => '08:00', 'end_time' => '19:00', 'note' => $note,
        ]);
    }

    private function open(Client $client): string
    {
        $response = $this->actingAs($client, 'client')->get('/client-portal/contracts');
        $response->assertOk();

        return $response->getContent();
    }

    /** 空白をまとめた本文（タグを除いたテキスト） */
    private function text(string $html): string
    {
        return preg_replace('/\s+/u', ' ', strip_tags($html));
    }

    public function test_未ログインは会員のログイン画面へ戻される(): void
    {
        $this->get('/client-portal/contracts')->assertRedirect('/client-portal/login');
    }

    public function test_契約と残りがあるとき全体のあとn回と契約のカードとマスが出る(): void
    {
        $client = $this->makeClient();
        $c = $this->contract($client, '2026-09-01', 4, '2027-03-31');
        $this->usage($client, '2026-09-20', $c);
        $this->usage($client, '2026-09-10', $c);

        $html = $this->open($client);
        $text = $this->text($html);

        $this->assertStringContainsString('ご利用回数', $text);
        $this->assertStringContainsString('あと 2回', $text);
        $this->assertStringContainsString('2026/09/01 ご契約（4回）', $text);
        $this->assertStringContainsString('有効期限 2027/03/31', $text);
        // マスは利用日の昇順、残りは「ご利用なし」（2026-11 変更：その前は「未利用」）
        $this->assertMatchesRegularExpression('#2026/09/10.*2026/09/20.*ご利用なし.*ご利用なし#su', $text);
        $this->assertSame(2, substr_count($html, 'c-ticket-slot c-ticket-slot--empty'));
        // 期限内なので赤字にしない
        $this->assertStringNotContainsString('text-danger', $html);
        // HOME への戻る導線（S-1404 と同じ）
        $this->assertStringContainsString('class="c-detail-back"', $html);
        $this->assertStringContainsString(route('client-portal.dashboard'), $html);
    }

    public function test_見出しと全体の数を1行のまとまりに並べ見出しはご利用回数だけにする(): void
    {
        $client = $this->makeClient();
        $c = $this->contract($client, '2026-09-01', 4);
        $this->usage($client, '2026-09-10', $c);
        $this->usage($client, '2026-09-20', $c);

        $html = $this->open($client);

        // 見出しと数のまとまり（.c-ticket-heading）の中に、h1「ご利用回数」と、その後に「あと 2回」が続く
        $start = strpos($html, '<div class="c-ticket-heading">');
        $this->assertNotFalse($start, '見出しと数のまとまりが見出しの行に見つからない');
        $end = strpos($html, '</div>', $start);
        $heading = substr($html, $start, $end - $start);
        $this->assertMatchesRegularExpression('#<h1 class="c-detail-title">ご利用回数</h1>\s*<p class="c-ticket-total">#u', $heading);
        $this->assertStringContainsString('あと 2回', $this->text($heading));
        // 数は h1 の外（読み上げの見出しは「ご利用回数」だけ）。h1 は 1 つだけ
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_新しい契約が上に並ぶ(): void
    {
        $client = $this->makeClient();
        $this->contract($client, '2026-01-10', 2);
        $this->contract($client, '2026-08-01', 2);

        $text = $this->text($this->open($client));

        $this->assertMatchesRegularExpression('#2026/08/01 ご契約.*2026/01/10 ご契約#su', $text);
    }

    public function test_使い切った契約はすべてご利用済みと出る(): void
    {
        $client = $this->makeClient();
        $c = $this->contract($client, '2026-09-01', 1, '2026-09-30');
        $this->usage($client, '2026-09-05', $c);

        $html = $this->open($client);

        $this->assertStringContainsString('すべてご利用済み', $html);
        $this->assertStringContainsString('あと <span class="num-tabular c-remaining-num">0</span>回', $html);
        // 使い切りは期限が過ぎていても赤字にしない
        $this->assertStringNotContainsString('text-danger', $html);
    }

    public function test_期限切れで残りがあるとき有効期限の日付が赤字になる(): void
    {
        $client = $this->makeClient();
        $c = $this->contract($client, '2026-01-01', 3, '2026-06-30');
        $this->usage($client, '2026-02-01', $c);

        $html = $this->open($client);

        $this->assertMatchesRegularExpression('#有効期限 <span class="text-danger num-tabular">2026/06/30</span>#u', $html);
        $this->assertStringNotContainsString('すべてご利用済み', $html);
    }

    public function test_契約に含まれないご利用は一番下に黄色のバッジなしで出る(): void
    {
        $client = $this->makeClient();
        $c = $this->contract($client, '2026-09-01', 2);
        $this->usage($client, '2026-09-03', $c);
        $this->usage($client, '2026-08-20');
        $this->usage($client, '2026-08-25');

        $html = $this->open($client);
        $text = $this->text($html);

        $this->assertMatchesRegularExpression('#2026/09/01 ご契約.*契約に含まれないご利用 2回 2026/08/20 2026/08/25#su', $text);
        $this->assertStringNotContainsString('bg-warning', $html);
        $this->assertStringNotContainsString('（契約なし）', $text);
    }

    public function test_全体がマイナスのとき文で伝え赤字にしない(): void
    {
        $client = $this->makeClient();
        $c = $this->contract($client, '2026-09-01', 1);
        $this->usage($client, '2026-09-03', $c);
        $this->usage($client, '2026-09-10');
        $this->usage($client, '2026-09-17');

        $html = $this->open($client);
        $text = $this->text($html);

        $this->assertStringContainsString('ご契約の回数を 2回超えてご利用中です', $text);
        $this->assertStringNotContainsString('-2', $text);
        $this->assertStringNotContainsString('text-danger', $html);
    }

    public function test_契約も利用もないとき空の状態を出す(): void
    {
        $html = $this->open($this->makeClient());

        $this->assertStringContainsString('回数のご契約はありません。', $html);
        // 空の状態は見出しだけ（数のまとまりは出さない）
        $this->assertStringContainsString('<h1 class="c-detail-title">ご利用回数</h1>', $html);
        $this->assertStringNotContainsString('c-ticket-heading', $html);
        $this->assertStringNotContainsString('c-ticket-total', $html);
        $this->assertStringNotContainsString('class="c-ticket"', $html);
    }

    public function test_メモとトレーナー側の操作要素は出さない(): void
    {
        $client = $this->makeClient();
        $c = $this->contract($client, '2026-09-01', 3, null, 'CONTRACT_NOTE_SECRET');
        $this->usage($client, '2026-09-03', $c, 'USAGE_NOTE_SECRET');
        $this->usage($client, '2026-09-04', null, 'UNASSIGNED_NOTE_SECRET');

        $html = $this->open($client);
        // 本文（レイアウトのナビ等を除く）：c-detail の中
        $main = substr($html, strpos($html, 'container py-4 c-detail'));

        // メモの内容・メモの印は出さない
        $this->assertStringNotContainsString('NOTE_SECRET', $html);
        $this->assertStringNotContainsString('bi-chat-left-text', $html);
        $this->assertStringNotContainsString('メモあり', $html);
        // 読むだけ：button・onclick・data-*・トレーナー側のクラス／モーダルを出さない
        $this->assertStringNotContainsString('<button', $main);
        $this->assertStringNotContainsString('onclick', $main);
        $this->assertDoesNotMatchRegularExpression('/\sdata-[a-z-]+=/', $main);
        $this->assertStringNotContainsString('ticket-slot"', $main);
        $this->assertStringNotContainsString('usageModal', $html);
    }

    public function test_ログイン中の会員自身の分だけを出す(): void
    {
        $me = $this->makeClient('山田');
        $other = $this->makeClient('佐藤');
        $mine = $this->contract($me, '2026-09-01', 2);
        $this->usage($me, '2026-09-05', $mine);
        $others = $this->contract($other, '2025-12-24', 9);
        $this->usage($other, '2025-12-25', $others);
        $this->usage($other, '2025-12-26');

        $text = $this->text($this->open($me));

        $this->assertStringContainsString('2026/09/01 ご契約（2回）', $text);
        $this->assertStringContainsString('2026/09/05', $text);
        $this->assertStringNotContainsString('2025/12/24', $text);
        $this->assertStringNotContainsString('2025/12/25', $text);
        $this->assertStringNotContainsString('契約に含まれないご利用', $text);
        $this->assertStringContainsString('あと 1回', $text);
    }

    // ---- 予約の印・すべてご予約済み（2026-11 追加。設計書 S-1412「マスの予約の印」。今日は 2026-10-13） ----

    /** マス（.c-ticket-slot）ごとの「クラス」と「中の文字」 */
    private function slots(string $html): array
    {
        preg_match_all('/<span class="(c-ticket-slot[^"]*)">(.*?)<\/span>\s*(?=<span class="c-ticket-slot|<\/div>)/su', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => [$x[1], trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace('</span><span', '</span> <span', $x[2]))))], $m);
    }

    public function test_今日のマスは本日_今日より後はご予約の印で_過去は印なし(): void
    {
        $client = $this->makeClient();
        $c = $this->contract($client, '2026-10-01', 4);
        $this->usage($client, '2026-10-12', $c);   // 過去
        $this->usage($client, '2026-10-13', $c);   // 今日
        $this->usage($client, '2026-10-20', $c);   // 今日より後

        $this->assertSame([
            ['c-ticket-slot num-tabular', '2026/10/12'],
            ['c-ticket-slot num-tabular c-ticket-slot--today', '本日 2026/10/13'],
            ['c-ticket-slot num-tabular c-ticket-slot--upcoming', 'ご予約 2026/10/20'],
            ['c-ticket-slot c-ticket-slot--empty', 'ご利用なし'],
        ], $this->slots($this->open($client)));
    }

    public function test_使い切った契約は_予約を含めばすべてご予約済み_今日以前だけならすべてご利用済み(): void
    {
        $client = $this->makeClient();
        $reserved = $this->contract($client, '2026-10-01', 2);
        $this->usage($client, '2026-10-12', $reserved);
        $this->usage($client, '2026-10-14', $reserved);   // 今日より後を含む
        $usedToday = $this->contract($client, '2026-09-01', 2);
        $this->usage($client, '2026-09-05', $usedToday);
        $this->usage($client, '2026-10-13', $usedToday);  // 今日は予約に数えない（トレーナー側と同じ）

        $html = $this->open($client);
        $this->assertSame(1, substr_count($html, '<span class="badge bg-secondary">すべてご予約済み</span>'));
        $this->assertSame(1, substr_count($html, '<span class="badge bg-secondary">すべてご利用済み</span>'));
        // 新しい契約（予約を含む）が上
        $this->assertLessThan(strpos($html, 'すべてご利用済み'), strpos($html, 'すべてご予約済み'));
    }

    public function test_予約の印のあるページでもメモは出さない(): void
    {
        $client = $this->makeClient();
        $c = $this->contract($client, '2026-10-01', 2, null, 'CONTRACT_NOTE_SECRET');
        ClientUsage::query()->create([
            'client_id' => $client->id, 'client_contract_id' => $c->id,
            'used_on' => '2026-10-20', 'start_time' => '08:00', 'end_time' => '19:00', 'note' => 'RESERVATION_NOTE_SECRET',
        ]);

        $html = $this->open($client);
        $this->assertStringContainsString('ご予約', $html);
        $this->assertStringNotContainsString('NOTE_SECRET', $html);
    }

    public function test_契約に含まれないご利用のマスにも_本日とご予約の印を付け_過去は印なし(): void
    {
        $client = $this->makeClient();
        $this->usage($client, '2026-10-12');   // 過去
        $this->usage($client, '2026-10-13');   // 今日
        $this->usage($client, '2026-10-20');   // 今日より後

        $html = $this->open($client);
        $section = substr($html, strpos($html, 'aria-label="契約に含まれないご利用"'));
        $this->assertStringContainsString('契約に含まれないご利用 <span class="num-tabular">3</span>回', $section);
        $this->assertSame([
            ['c-ticket-slot num-tabular', '2026/10/12'],
            ['c-ticket-slot num-tabular c-ticket-slot--today', '本日 2026/10/13'],
            ['c-ticket-slot num-tabular c-ticket-slot--upcoming', 'ご予約 2026/10/20'],
        ], $this->slots($section));
    }

    // ---- 言葉（2026-11 変更。用語集 No.44） ----

    public function test_まだ使っていないマスはご利用なしと出し_未利用の言葉は出さない(): void
    {
        $client = $this->makeClient();
        $c = $this->contract($client, '2026-10-01', 3);
        $this->usage($client, '2026-10-05', $c);
        $this->usage($client, '2026-10-20');   // 契約に含まれないご利用（予約）

        $html = $this->open($client);
        $this->assertSame(2, substr_count($html, '<span class="c-ticket-slot c-ticket-slot--empty">ご利用なし</span>'));
        $this->assertStringNotContainsString('未利用', $html);
        // 「ご」の付かない「利用なし」だけの表記も出さない（2026-11 変更：「ご予約」「すべてご利用済み」とそろえる）
        $this->assertDoesNotMatchRegularExpression('/(?<!ご)利用なし/u', $html);
    }
}


<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientContract;
use App\Models\ClientUsage;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 残り回数・回数カードの描画確認（設計書 screen-design.md S-0304「残り回数」列、
 * S-0305 セクション5「回数」（見出しの残り回数）、§2-4「例外：残り回数」）。
 *
 * - 会員一覧：値は「回」を付けず数字だけ。契約も利用も 0 件は空欄、契約なし・利用ありは赤字の「-n」、
 *   マイナスは赤字（text-danger）、0 は「0」
 * - 会員詳細：回数カードの見出しは「残り回数 n」（「回数」の語は置かない。数字は fs-5 で少し大きく、
 *   マイナスは数字の部分が赤字。契約も利用も 0 件はラベル「残り回数」だけで数字は空欄）、
 *   契約の見出し・マス・未割り当て・使い切り・空の状態。ヘッダーサマリーには残り回数を出さない
 *
 * 実際の HTTP で一覧・詳細を開くため、画面が読むテーブルを列を絞って作る。
 */
class ClientCountRenderingTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createClientCountTables();
        $this->createAccessLogsTable();

        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });
        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->date('training_date');
            $table->time('training_time')->nullable();
            $table->unsignedBigInteger('trainer1_id')->nullable();
            $table->unsignedBigInteger('trainer2_id')->nullable();
        });
        Schema::create('media_records', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('media_record_training_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('media_record_id');
            $table->unsignedBigInteger('training_record_id');
            $table->integer('sort_order')->default(0);
        });
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name')->nullable();
        });
        Schema::create('trainee_measurements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id');
            $table->date('measured_date');
            $table->time('measured_time');
        });
        Schema::create('audio_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
        });
        Schema::create('client_email_registration_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->boolean('is_used')->default(false);
            $table->timestamp('expires_at')->nullable();
        });

        $staff = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $staff->id = 200;
        $staff->exists = true;
        $this->actingAs($staff, 'web');
    }

    private function makeClient(string $internalId, string $lastName): Client
    {
        return Client::query()->create([
            'internal_id' => $internalId,
            'initial_consultation_date' => '2026-01-20',
            'last_name' => $lastName,
            'first_name' => '太郎',
        ]);
    }

    private function contract(Client $client, string $contractedOn, int $count, ?string $note = null, ?string $expiresOn = null): ClientContract
    {
        return ClientContract::query()->create([
            'client_id' => $client->id, 'contracted_on' => $contractedOn, 'count' => $count,
            'note' => $note, 'expires_on' => $expiresOn,
        ]);
    }

    /** 利用を作る。$contract を渡すとその契約に割り当てる（省略・null は「契約なし」） */
    /**
     * 利用を $times 件作る（利用 1 件は 1 回分。2 回分使ったときは 2 件）。最後に作った利用を返す。
     * $contract を渡すとその契約に割り当てる（省略・null は「契約なし」）。
     */
    private function usage(Client $client, string $usedOn, int $times = 1, ?string $note = null, ?ClientContract $contract = null): ClientUsage
    {
        $usage = null;
        for ($i = 0; $i < $times; $i++) {
            $usage = ClientUsage::query()->create([
                'client_id' => $client->id, 'client_contract_id' => $contract?->id,
                'used_on' => $usedOn, 'note' => $note,
            ]);
        }

        return $usage;
    }

    /** 会員一覧の、指定した会員の行（<tr>…</tr>）の HTML を取り出す */
    private function listRow(string $html, string $lastName): string
    {
        $pos = strpos($html, $lastName);
        $this->assertNotFalse($pos, "{$lastName} の行が見つからない");
        $start = strrpos(substr($html, 0, $pos), '<tr');
        $end = strpos($html, '</tr>', $pos);

        return substr($html, $start, $end - $start);
    }

    /** 回数カードの見出し（<h6 class="mb-0">回数…</h6>）の HTML。空白は 1 つにまとめる */
    private function countHeading(string $html): string
    {
        $start = strpos($html, '<h6 class="mb-0">残り回数');
        $this->assertNotFalse($start, '回数カードの見出しが見つからない');
        $end = strpos($html, '</h6>', $start);

        return preg_replace('/\s+/u', ' ', substr($html, $start, $end + 5 - $start));
    }

    /** 旧見出し（「回数」単独の見出し）が出ていないこと */
    private function assertNoOldHeading(string $html): void
    {
        $this->assertStringNotContainsString('<h6 class="mb-0">回数', $html);
    }

    /** ヘッダーサマリーに残り回数の項目（「残り回数」のラベル）がないこと */
    private function assertHeaderHasNoRemaining(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/<div class="text-muted small">残り回数<\/div>/u', $html);
    }

    /** 行の最後のセル（8 列目＝残り回数）の中身 */
    private function lastCell(string $row): string
    {
        $start = strrpos($row, '<td>');

        return trim(strip_tags(substr($row, $start)));
    }

    // ---- 会員一覧（S-0304） ----

    public function test_会員一覧の残り回数列(): void
    {
        $this->makeClient('0005', '登録なし');         // 契約も利用もない → 空欄（COUNT(*) は 0、SUM は NULL → remaining() が null）

        $usageOnly = $this->makeClient('0001', '契約なし');
        $this->usage($usageOnly, '2026-10-01', 2);    // 契約がなく利用だけ 2 件 → 0 − 2 = −2（赤字）

        $plus = $this->makeClient('0002', '残りあり');
        $this->contract($plus, '2026-10-01', 4);
        $this->contract($plus, '2026-10-05', 2);
        $this->usage($plus, '2026-10-02', 3);         // 利用 3 件 → 6 − 3 = 3

        $zero = $this->makeClient('0003', '使い切り');
        $this->contract($zero, '2026-10-01', 2);
        $this->usage($zero, '2026-10-02', 2);         // 利用 2 件 → 0

        $minus = $this->makeClient('0004', '残りマイナス');
        $this->contract($minus, '2026-10-01', 1);
        $this->usage($minus, '2026-10-02', 3);        // 利用 3 件 → 1 − 3 = −2

        $html = $this->get(route('clients.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<th>残り回数</th>', $html);
        $this->assertSame('', $this->lastCell($this->listRow($html, '登録なし')));
        $usageOnlyRow = $this->listRow($html, '契約なし');
        $this->assertSame('-2', $this->lastCell($usageOnlyRow));
        $this->assertStringContainsString('<span class="text-danger">-2</span>', $usageOnlyRow);
        $this->assertSame('3', $this->lastCell($this->listRow($html, '残りあり')));
        $this->assertSame('0', $this->lastCell($this->listRow($html, '使い切り')));

        $minusRow = $this->listRow($html, '残りマイナス');
        // 値は「回」を付けず数字だけ。マイナスは数字の部分が赤字
        $this->assertSame('-2', $this->lastCell($minusRow));
        $this->assertStringContainsString('<span class="text-danger">-2</span>', $minusRow);
        $this->assertStringNotContainsString('text-danger', $this->listRow($html, '残りあり'));

        // 会員一覧の出力は見出し用の大きさに影響されない（fs-5 が付かない）
        $this->assertStringContainsString('<td><span>3</span></td>', preg_replace('/\s+/u', '', $this->listRow($html, '残りあり')));
        foreach (['登録なし', '契約なし', '残りあり', '使い切り', '残りマイナス'] as $name) {
            $this->assertStringNotContainsString('fs-5', $this->listRow($html, $name), $name);
        }
    }

    public function test_会員一覧の空の行は8列にまたがる(): void
    {
        $html = $this->get(route('clients.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<td colspan="8"', $html);
    }

    // ---- 会員詳細（S-0305） ----

    public function test_契約も利用もない会員は見出しがラベルだけで空の状態の文言が出る(): void
    {
        $client = $this->makeClient('0001', '山田');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringContainsString('契約・利用は登録されていません', $html);
        // 契約も利用も 0 件は、ラベル「残り回数」だけを出して数字（の要素）は出さない（§2-4 の例外）
        $this->assertSame('<h6 class="mb-0">残り回数</h6>', $this->countHeading($html));
        $this->assertNoOldHeading($html);
        $this->assertHeaderHasNoRemaining($html);
    }

    public function test_残りが0なら見出しに残り回数0が出る(): void
    {
        $client = $this->makeClient('0001', '山田');
        $this->contract($client, '2026-10-01', 2);
        $this->usage($client, '2026-10-02', 2);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $heading = $this->countHeading($html);
        $this->assertStringContainsString('<h6 class="mb-0">残り回数<span class="fs-5 ms-2">0</span>', $heading);
        $this->assertStringNotContainsString('text-danger', $heading);
        $this->assertHeaderHasNoRemaining($html);
    }

    public function test_回数カードに契約の見出し_マス_使い切り_残りが出る(): void
    {
        $client = $this->makeClient('0001', '山田');
        $old = $this->contract($client, '2026-09-03', 2, null, '2026-12-03');
        $new = $this->contract($client, '2026-10-12', 4, '4回コース');
        $this->usage($client, '2026-09-04', 1, null, $old);
        $this->usage($client, '2026-09-06', 1, null, $old);
        $this->usage($client, '2026-10-12', 1, 'メモあり利用', $new);
        $this->usage($client, '2026-10-13', 1, null, $new);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 回数カードの見出しの残り回数（6 − 4 = 2）。値は「回」を付けず数字だけ。ヘッダーサマリーには出さない
        // 数字だけに fs-5（少し大きく）が付く。見出しに旧見出しの「回数」は出ない
        $this->assertStringContainsString('<h6 class="mb-0">残り回数<span class="fs-5 ms-2">2</span>', $this->countHeading($html));
        $this->assertNoOldHeading($html);
        $this->assertHeaderHasNoRemaining($html);

        // 契約の見出し（メモ・有効期限は、あるときだけ出す）。新しい契約が上
        $newPos = strpos($html, '2026/10/12 契約（4回） 4回コース');
        $oldPos = strpos($html, '2026/09/03 契約（2回）  有効期限 2026/12/03');
        $this->assertNotFalse($newPos);
        $this->assertNotFalse($oldPos);
        $this->assertLessThan($oldPos, $newPos, '契約は新しいものが上');

        // 状態：新しい契約は残り 2、古い契約は使い切り（bg-secondary）
        $this->assertStringContainsString('残り 2</span>', $html);
        $this->assertStringContainsString('<span class="badge bg-secondary flex-shrink-0">使い切り</span>', $html);

        // マス：未利用が 2 つ、利用済みは利用日。メモのある利用には印が付く
        $this->assertSame(2, substr_count($html, '>未利用</span>'));
        $this->assertStringContainsString('data-used-on="2026-09-04"', $html);
        $this->assertSame(1, substr_count($html, 'bi-chat-left-text'));

        // 見出し・マスは編集モーダルを開く button
        $this->assertStringContainsString('data-id="'.$new->id.'"', $html);
        // 契約の見出しに、割り当てた利用の件数（削除の確認ダイアログ用）
        $this->assertMatchesRegularExpression('/data-id="'.$new->id.'"[^>]*data-usage-count="2"/u', $html);
        // 利用のマスに、今割り当てられている契約の id（編集モーダルの初期値用）
        $this->assertStringContainsString('data-client-contract-id="'.$old->id.'"', $html);
        $this->assertStringContainsString('onclick="window.contractModal.openForEdit(this.dataset)"', $html);
        $this->assertStringContainsString('onclick="window.usageModal.openForEdit(this.dataset)"', $html);

        // 未割り当ての利用はないので「（契約なし）」の行は出ない
        $this->assertStringNotContainsString('（契約なし）', $html);
    }

    public function test_未割り当ての利用があると一番上に契約なしの行が出て未割り当ての回数のバッジが出る(): void
    {
        $client = $this->makeClient('0001', '山田');
        $contract = $this->contract($client, '2026-10-01', 1);
        $this->usage($client, '2026-10-02', 1, null, $contract);
        $this->usage($client, '2026-10-05', 2); // 契約なし（2 件）

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 見出しの残り回数は未割り当ても引く（1 − 3 = −2。数字に text-danger と fs-5 の両方が付く）
        $this->assertStringContainsString('<h6 class="mb-0">残り回数<span class="text-danger fs-5 ms-2">-2</span>', $this->countHeading($html));
        // §2-5「要対応」の bg-warning text-dark で「未割り当て n」（n は未割り当ての利用の件数）。超過の bg-danger は使わない
        $this->assertStringContainsString('<span class="badge bg-warning text-dark">未割り当て 2</span>', $html);
        $this->assertStringNotContainsString('超過', $html);
        $this->assertLessThan(
            strpos($html, '2026/10/01 契約（1回）'),
            strpos($html, '（契約なし）'),
            '（契約なし）の行は契約の行より上'
        );
        // 契約の行は割り当てたとおり：使い切り
        $this->assertStringContainsString('<span class="badge bg-secondary flex-shrink-0">使い切り</span>', $html);
    }

    public function test_契約がなく利用だけある会員は見出しに赤字の残りマイナスと未割り当ての行が出る(): void
    {
        $client = $this->makeClient('0001', '山田');
        $this->usage($client, '2026-10-02');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 利用が 1 件あるので回数管理の対象の会員として、計算どおり「残り回数 -1」（数字の部分が赤字）を出す
        $this->assertStringContainsString('<h6 class="mb-0">残り回数<span class="text-danger fs-5 ms-2">-1</span>', $this->countHeading($html));
        $this->assertStringContainsString('<span class="badge bg-warning text-dark">未割り当て 1</span>', $html);
    }

    public function test_利用モーダルの入力エラーで戻ると利用モーダルだけが開き直す(): void
    {
        $client = $this->makeClient('0001', '山田');

        $html = $this->from(route('clients.show', $client))
            ->followingRedirects()
            ->post(route('client-usages.store', $client), [
                '_form_type' => 'usage',
                'used_on' => 'x',
            ])
            ->assertOk()
            ->getContent();

        // ClientController::show が old('_form_type') から $openModal='usage' を決め、
        // 利用モーダルの側にだけ開き直しの JS と欄の下の文言が入る
        $this->assertStringContainsString('const oldUsageId', $html);
        $this->assertStringNotContainsString('const oldContractId', $html);
        $this->assertStringContainsString('利用日の形式が正しくありません。', $html);
        $this->assertSame(1, substr_count($html, '利用日の形式が正しくありません。'), '契約モーダルには出ない');
    }

    public function test_利用の入力エラーで戻ると選んでいた契約が戻り_契約なしも戻る(): void
    {
        $client = $this->makeClient('0001', '山田');
        $contract = $this->contract($client, '2026-10-01', 4);

        // 契約を選んで、利用日のエラーで戻る
        $html = $this->from(route('clients.show', $client))->followingRedirects()
            ->post(route('client-usages.store', $client), [
                '_form_type' => 'usage', 'client_contract_id' => (string) $contract->id,
                'used_on' => 'x',
            ])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="'.$contract->id.'"[^>]*selected/u', $html);

        // 「契約なし」（value は空）を選んで、利用日のエラーで戻る
        $html = $this->from(route('clients.show', $client))->followingRedirects()
            ->post(route('client-usages.store', $client), [
                '_form_type' => 'usage', 'client_contract_id' => '',
                'used_on' => 'x',
            ])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="" data-used-up="0"\s*selected/u', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="'.$contract->id.'"[^>]*selected/u', $html);
    }

    public function test_有効期限を過ぎて残りがある契約は有効期限の日付だけが赤字になる(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 09:00:00', config('app.timezone')));
        try {
            $client = $this->makeClient('0001', '山田');
            $expired = $this->contract($client, '2026-08-01', 2, null, '2026-10-12');     // 昨日・残りあり → 赤字
            $today = $this->contract($client, '2026-08-02', 2, null, '2026-10-13');       // 今日 → 赤字にしない
            $usedUp = $this->contract($client, '2026-08-03', 1, null, '2026-10-01');      // 期限切れ・使い切り → 赤字にしない
            $this->usage($client, '2026-09-01', 1, null, $usedUp);

            $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

            // 日付の部分だけが text-danger。「有効期限」の文字は赤字の span の外
            $this->assertStringContainsString('有効期限 <span class="text-danger">2026/10/12</span>', $html);
            $this->assertStringNotContainsString('<span class="text-danger">有効期限', $html);
            // 当日・使い切りの契約は赤字にしない
            $this->assertStringContainsString('有効期限 2026/10/13', $html);
            $this->assertStringNotContainsString('<span class="text-danger">2026/10/13</span>', $html);
            $this->assertStringContainsString('有効期限 2026/10/01', $html);
            $this->assertStringNotContainsString('<span class="text-danger">2026/10/01</span>', $html);

            // 期限を過ぎた契約も、利用モーダルの選択肢では今までどおり選べる（disabled にならない）
            $this->assertMatchesRegularExpression('/<option value="'.$expired->id.'"\s+data-used-up="0"\s*>/u', $html);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_回数カードの本文は40vhでスクロールし見出しはスクロールの外にある(): void
    {
        $client = $this->makeClient('0001', '山田');
        $this->contract($client, '2026-10-01', 4);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // カード本文にスクロールのクラス。最大の高さは 40vh（トレーニング記録の 60vh より低い）
        $this->assertStringContainsString('<div class="card-body contract-ticket-scroll">', $html);
        $this->assertMatchesRegularExpression('/\.contract-ticket-scroll\s*\{\s*max-height:\s*40vh;\s*overflow-y:\s*auto;/u', $html);
        $this->assertMatchesRegularExpression('/\.training-records-scroll\s*\{\s*max-height:\s*60vh;/u', $html);
        // 見出し（残り回数・追加ボタン）はスクロール領域より前（外）にある
        $this->assertLessThan(
            strpos($html, 'contract-ticket-scroll">'),
            strpos($html, '<h6 class="mb-0">残り回数')
        );
    }

    public function test_回数カードはトレーニーカードの上にある(): void
    {
        $client = $this->makeClient('0001', '山田');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, '<h6 class="mb-0">トレーニー（'),
            strpos($html, '<h6 class="mb-0">残り回数')
        );
    }
}

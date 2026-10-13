<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientContract;
use App\Models\ClientUsage;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 残り回数・回数カードの描画確認（設計書 screen-design.md S-0304「ご利用回数」列、
 * S-0305 セクション5「回数」（見出しの残り回数）、§2-4「例外：残り回数」）。
 * 画面の言葉は会員側（S-1412）にそろえる：「ご利用回数　あと n回」「契約 n回分不足」（赤字）「すべてご利用済み」。
 *
 * - 会員一覧：列の見出しは「ご利用回数」、値は「あと n回」。契約も利用も 0 件は空欄、契約なし・利用ありは
 *   赤字の「契約 n回分不足」、マイナスは赤字（text-danger）の「契約 n回分不足」、0 は「あと 0回」。fs-5 は付けない
 * - 会員詳細：回数カードの見出しは「ご利用回数　あと n回」（数字の部分だけ fs-5 で少し大きく、
 *   マイナスは「契約 n回分不足」の全体が赤字。契約も利用も 0 件はラベル「ご利用回数」だけで数字は空欄）、
 *   契約の見出し・マス・未割り当て（「未割り当て n」は変えない）・すべてご利用済み・空の状態。
 *   ヘッダーサマリーには残り回数を出さない
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
            $table->string('photo_path', 500)->nullable();
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

    /** 回数カードの見出し（<h6 class="mb-0">ご利用回数…</h6>）の HTML。タグの間の空白は取り、ほかの空白は 1 つにまとめる */
    private function countHeading(string $html): string
    {
        $start = strpos($html, '<h6 class="mb-0">ご利用回数');
        $this->assertNotFalse($start, '回数カードの見出しが見つからない');
        $end = strpos($html, '</h6>', $start);

        return preg_replace('/\s+/u', ' ', preg_replace('/>\s+</u', '><', substr($html, $start, $end + 5 - $start)));
    }

    /** 旧見出し（「回数」単独の見出し）が出ていないこと */
    private function assertNoOldHeading(string $html): void
    {
        $this->assertStringNotContainsString('<h6 class="mb-0">回数', $html);
    }

    /** ヘッダーサマリーに残り回数の項目（「ご利用回数」「残り回数」のラベル）がないこと */
    private function assertHeaderHasNoRemaining(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/<div class="text-muted small">(ご利用回数|残り回数)<\/div>/u', $html);
    }

    /** 行の最後のセル（8 列目＝ご利用回数）の中身 */
    private function lastCell(string $row): string
    {
        $start = strrpos($row, '<td>');

        return trim(strip_tags(substr($row, $start)));
    }

    // ---- 会員一覧（S-0304） ----

    public function test_会員一覧のご利用回数列(): void
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

        $this->assertStringContainsString('<th>ご利用回数</th>', $html);
        $this->assertStringNotContainsString('<th>残り回数</th>', $html);
        $this->assertSame('', $this->lastCell($this->listRow($html, '登録なし')));
        $usageOnlyRow = $this->listRow($html, '契約なし');
        $this->assertSame('契約 2回分不足', $this->lastCell($usageOnlyRow));
        $this->assertStringContainsString('<span class="text-danger">契約 2回分不足</span>', $usageOnlyRow);
        $this->assertSame('あと 3回', $this->lastCell($this->listRow($html, '残りあり')));
        $this->assertSame('あと 0回', $this->lastCell($this->listRow($html, '使い切り')));

        $minusRow = $this->listRow($html, '残りマイナス');
        // 値は「あと n回」。マイナスは赤字の「契約 n回分不足」（n は絶対値。「-2」は出さない）
        $this->assertSame('契約 2回分不足', $this->lastCell($minusRow));
        $this->assertStringContainsString('<span class="text-danger">契約 2回分不足</span>', $minusRow);
        $this->assertStringNotContainsString('-2', $this->lastCell($minusRow));
        // 以前の言い方「n回超過」は出さない
        $this->assertStringNotContainsString('回超過', $html);
        $this->assertStringNotContainsString('text-danger', $this->listRow($html, '残りあり'));

        // 会員一覧の出力は見出し用の大きさに影響されない（fs-5 が付かず、数字を要素で囲まない）
        $this->assertStringContainsString('<td><span>あと3回</span></td>', preg_replace('/\s+/u', '', $this->listRow($html, '残りあり')));
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
        // 契約も利用も 0 件は、ラベル「ご利用回数」だけを出して数字（の要素）は出さない（§2-4 の例外）
        $this->assertSame('<h6 class="mb-0">ご利用回数</h6>', $this->countHeading($html));
        $this->assertNoOldHeading($html);
        $this->assertHeaderHasNoRemaining($html);
    }

    public function test_残りが0なら見出しにあと0回が出る(): void
    {
        $client = $this->makeClient('0001', '山田');
        $this->contract($client, '2026-10-01', 2);
        $this->usage($client, '2026-10-02', 2);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $heading = $this->countHeading($html);
        $this->assertSame('<h6 class="mb-0">ご利用回数<span class="ms-2"><span>あと <span class="fs-5">0</span>回</span></span></h6>', $heading);
        $this->assertStringNotContainsString('text-danger', $heading);
        $this->assertHeaderHasNoRemaining($html);
    }

    public function test_回数カードに契約の見出し_マス_すべてご利用済み_あとn回が出る(): void
    {
        $client = $this->makeClient('0001', '山田');
        $old = $this->contract($client, '2026-09-03', 2, null, '2026-12-03');
        $new = $this->contract($client, '2026-10-12', 4, '4回コース');
        $this->usage($client, '2026-09-04', 1, null, $old);
        $this->usage($client, '2026-09-06', 1, null, $old);
        $this->usage($client, '2026-10-12', 1, 'メモあり利用', $new);
        $this->usage($client, '2026-10-13', 1, null, $new);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 回数カードの見出しは「ご利用回数　あと 2回」（6 − 4 = 2）。ヘッダーサマリーには出さない
        // 数字の部分だけに fs-5（少し大きく）が付き、「あと」「回」は普通の大きさ。旧見出しの「回数」「残り回数」は出ない
        $this->assertSame('<h6 class="mb-0">ご利用回数<span class="ms-2"><span>あと <span class="fs-5">2</span>回</span></span></h6>', $this->countHeading($html));
        $this->assertStringNotContainsString('<h6 class="mb-0">残り回数', $html);
        $this->assertNoOldHeading($html);
        $this->assertHeaderHasNoRemaining($html);

        // 契約の見出し（メモ・有効期限は、あるときだけ出す）。新しい契約が上
        $newPos = strpos($html, '2026/10/12 契約（4回） 4回コース');
        $oldPos = strpos($html, '2026/09/03 契約（2回）  有効期限 2026/12/03');
        $this->assertNotFalse($newPos);
        $this->assertNotFalse($oldPos);
        $this->assertLessThan($oldPos, $newPos, '契約は新しいものが上');

        // 状態：新しい契約は「あと 2回」、古い契約は「すべてご利用済み」（bg-secondary）
        $this->assertStringContainsString('<span class="flex-shrink-0">あと 2回</span>', $html);
        $this->assertStringContainsString('<span class="badge bg-secondary flex-shrink-0">すべてご利用済み</span>', $html);
        $this->assertStringNotContainsString('残り 2</span>', $html);
        $this->assertStringNotContainsString('>使い切り</span>', $html);

        // マス：未利用が 2 つ、利用済みは利用日。メモのある利用には印が付く
        $this->assertSame(2, substr_count($html, '>未利用</button>'));
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

        // 見出しの残り回数は未割り当ても引く（1 − 3 = −2）。赤字の「契約 2回分不足」（赤字は「契約 n回分不足」の全体、数字の部分に fs-5）
        $this->assertSame('<h6 class="mb-0">ご利用回数<span class="ms-2"><span class="text-danger">契約 <span class="fs-5">2</span>回分不足</span></span></h6>', $this->countHeading($html));
        // §2-5「要対応」の bg-warning text-dark で「未割り当て n」（n は未割り当ての利用の件数。変えない）。bg-danger のバッジは使わない
        $this->assertStringContainsString('<span class="badge bg-warning text-dark">未割り当て 2</span>', $html);
        $this->assertStringNotContainsString('回超過', $html);
        $this->assertStringNotContainsString('badge bg-danger', $html);
        $this->assertLessThan(
            strpos($html, '2026/10/01 契約（1回）'),
            strpos($html, '（契約なし）'),
            '（契約なし）の行は契約の行より上'
        );
        // 契約の行は割り当てたとおり：すべてご利用済み
        $this->assertStringContainsString('<span class="badge bg-secondary flex-shrink-0">すべてご利用済み</span>', $html);
    }

    public function test_契約がなく利用だけある会員は見出しに赤字の残りマイナスと未割り当ての行が出る(): void
    {
        $client = $this->makeClient('0001', '山田');
        $this->usage($client, '2026-10-02');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 利用が 1 件あるので回数管理の対象の会員として、計算どおり赤字の「ご利用回数　契約 1回分不足」を出す
        $this->assertSame('<h6 class="mb-0">ご利用回数<span class="ms-2"><span class="text-danger">契約 <span class="fs-5">1</span>回分不足</span></span></h6>', $this->countHeading($html));
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

    public function test_回数カードの本文は35vhでスクロールし見出しはスクロールの外にある(): void
    {
        $client = $this->makeClient('0001', '山田');
        $this->contract($client, '2026-10-01', 4);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // カード本文にスクロールのクラス。最大の高さは 35vh（トレーニング記録の 60vh より低い）
        $this->assertStringContainsString('<div class="card-body contract-ticket-scroll">', $html);
        $this->assertMatchesRegularExpression('/\.contract-ticket-scroll\s*\{\s*max-height:\s*35vh;\s*overflow-y:\s*auto;/u', $html);
        $this->assertMatchesRegularExpression('/\.training-records-scroll\s*\{\s*max-height:\s*60vh;/u', $html);
        // 見出し（ご利用回数・追加ボタン）はスクロール領域より前（外）にある
        $this->assertLessThan(
            strpos($html, 'contract-ticket-scroll">'),
            strpos($html, '<h6 class="mb-0">ご利用回数')
        );
    }

    public function test_契約の状態は見出しのボタンの外ですぐ後ろに並ぶ(): void
    {
        $client = $this->makeClient('0001', '山田');
        $open = $this->contract($client, '2026-10-12', 4, '4回コース');
        $full = $this->contract($client, '2026-09-03', 1);
        $this->usage($client, '2026-09-04', 1, null, $full);
        $this->usage($client, '2026-10-20'); // 契約なし

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 見出しのボタン（</button>）の直後に状態が続く＝ボタンの外で、見出しのすぐ後ろ
        $this->assertMatchesRegularExpression('/2026\/10\/12 契約（4回） 4回コース\s*<\/button>\s*<span class="flex-shrink-0">あと 4回<\/span>/u', $html);
        $this->assertMatchesRegularExpression('/2026\/09\/03 契約（1回）\s*<\/button>\s*<span class="badge bg-secondary flex-shrink-0">すべてご利用済み<\/span>/u', $html);
        // 「未割り当て n」も「（契約なし）」のすぐ後ろ
        $this->assertMatchesRegularExpression('/<span>（契約なし）<\/span>\s*<span class="badge bg-warning text-dark">未割り当て 1<\/span>/u', $html);

        // 行は左から並べる（右端に寄せる justify-content-between を使わない）。狭い幅では折り返す
        $card = substr($html, strpos($html, 'contract-ticket-scroll">'), 4000);
        $this->assertStringNotContainsString('justify-content-between', $card);
        $this->assertStringContainsString('d-flex flex-wrap align-items-center column-gap-2 mb-1', $card);
    }

    public function test_未利用のマスはその契約を選んで利用の登録を開くボタン(): void
    {
        $client = $this->makeClient('0001', '山田');
        $open = $this->contract($client, '2026-10-12', 3);
        $full = $this->contract($client, '2026-09-03', 1);
        $this->usage($client, '2026-09-04', 1, null, $full);
        $this->usage($client, '2026-10-13', 1, null, $open);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 未利用のマスは button。契約の id の data-* と、読み上げ用の名前を持つ。押すとその契約で登録モーダルを開く
        $pattern = '/<button type="button" class="btn btn-sm ticket-slot ticket-slot-empty"\s+'
            .'data-client-contract-id="'.$open->id.'"\s+'
            .'aria-label="未利用。2026\/10\/12 の契約で利用を追加"\s+'
            .'onclick="window\.usageModal\.openForCreate\(this\.dataset\.clientContractId\)">未利用<\/button>/u';
        $this->assertSame(2, preg_match_all($pattern, $html), 'あと 2回 の契約に未利用のマスが 2 つ');

        // 使い切りの契約には未利用のマスが出ない
        $this->assertDoesNotMatchRegularExpression('/ticket-slot-empty"\s+data-client-contract-id="'.$full->id.'"/u', $html);
        $this->assertSame(2, substr_count($html, '>未利用</button>'));

        // 「利用を追加」ボタンからの開き方は今のまま（契約の指定なし）
        $this->assertStringContainsString('onclick="window.usageModal.openForCreate()">利用を追加</button>', $html);
        // モーダルの登録の入口は、契約の指定があればその契約を、なければ初期値を選ぶ
        $this->assertStringContainsString('function openForCreate(contractId) {', $html);
        $this->assertStringContainsString('contractSelect.value = String(contractId);', $html);
        $this->assertStringContainsString("contractSelect.value = DEFAULT_CONTRACT_ID === null ? '' : String(DEFAULT_CONTRACT_ID);", $html);

        // 押せる見た目：.btn の上で、点線の枠とマウスを乗せたときの色の変化を指定している
        $this->assertMatchesRegularExpression('/\.ticket-slot-empty\s*\{[^}]*--bs-btn-hover-bg:[^}]*border-style:\s*dashed;/su', $html);
        // 普段は文字と枠を薄く（文字は本文の色の 50%）、マウスを乗せたとき・押したときは文字を本文の色に戻す
        // （フォーカスしたときは Bootstrap の .btn:focus-visible が hover の変数を使う）
        $this->assertMatchesRegularExpression('/\.ticket-slot-empty\s*\{[^}]*--bs-btn-color:\s*rgba\(var\(--bs-body-color-rgb\),\s*0\.5\);/su', $html);
        $this->assertMatchesRegularExpression('/\.ticket-slot-empty\s*\{[^}]*--bs-btn-border-color:\s*rgba\(var\(--bs-body-color-rgb\),\s*0\.12\);/su', $html);
        $this->assertMatchesRegularExpression('/\.ticket-slot-empty\s*\{[^}]*--bs-btn-hover-color:\s*var\(--bs-body-color\);/su', $html);
        $this->assertMatchesRegularExpression('/\.ticket-slot-empty\s*\{[^}]*--bs-btn-active-color:\s*var\(--bs-body-color\);/su', $html);
    }

    // ---- トレーニーカードの写真（S-0305 セクション3「写真」） ----

    /** 写真の URL を作る media ディスクを偽物にし、一時 URL を決まった形で返すようにする */
    private function fakeMediaDisk(): void
    {
        Storage::fake('media');
        Storage::disk('media')->buildTemporaryUrlsUsing(
            fn (string $path) => 'https://media.example.test/'.$path.'?signed=1'
        );
    }

    private function trainee(Client $client, string $name, ?string $photoPath = null): int
    {
        return DB::table('trainees')->insertGetId([
            'client_id' => $client->id, 'name' => $name, 'photo_path' => $photoPath,
        ]);
    }

    public function test_写真があるトレーニーにはトレーニー詳細へのリンクの中に写真が出る(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ', 'trainees/202610/abc.jpg');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 写真があるときは img の代替テキストで伝わるため、リンクに読み上げ用の名前は付けない
        $pattern = '/<a href="'.preg_quote(route('trainees.show', $id), '/').'" class="flex-shrink-0 text-decoration-none"\s*>\s*'
            .'<div class="d-flex align-items-center justify-content-center"\s*'
            .'style="width: 96px; height: 96px; background-color: #ffffff; border: 1px solid rgba\(15, 26, 46, 0\.08\); border-radius: 0\.5rem; overflow: hidden;">\s*'
            .'<img src="https:\/\/media\.example\.test\/trainees\/202610\/abc\.jpg\?signed=1"\s*'
            .'alt="ポチの写真"\s*'
            .'style="max-width: 100%; max-height: 100%; object-fit: contain; display: block;">/u';
        $this->assertMatchesRegularExpression($pattern, $html);
        $this->assertStringNotContainsString('写真なし', $html);
    }

    public function test_写真がないトレーニーには同じ枠に写真なしを出しトレーニー詳細へのリンクにする(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'たま');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 写真があるときと同じ 96px の枠に、S-0309 と同じ見た目の「写真なし」。読み上げ用の名前を付ける
        $pattern = '/<a href="'.preg_quote(route('trainees.show', $id), '/').'" class="flex-shrink-0 text-decoration-none"\s*'
            .'aria-label="たまの写真なし。トレーニー詳細へ"\s*>\s*'
            .'<div class="d-flex align-items-center justify-content-center"\s*'
            .'style="width: 96px; height: 96px; background-color: #ffffff; border: 1px solid rgba\(15, 26, 46, 0\.08\); border-radius: 0\.5rem; overflow: hidden;">\s*'
            .'<span class="text-muted" style="font-size: 0\.875rem;">写真なし<\/span>\s*<\/div>\s*<\/a>/u';
        $this->assertMatchesRegularExpression($pattern, $html);
        // 写真の img は出さない
        $this->assertStringNotContainsString('alt="たまの写真"', $html);
        $this->assertStringNotContainsString('media.example.test', $html);
    }

    public function test_トレーニーが2人で片方だけ写真があるとき両方とも左に枠右に名前と項目の同じ組み方(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $withPhoto = $this->trainee($client, 'ポチ', 'trainees/202610/pochi.jpg');
        $noPhoto = $this->trainee($client, 'たま');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 枠は 2 人とも出る。写真はポチだけ、「写真なし」はたまだけ
        $this->assertSame(2, substr_count($html, 'width: 96px; height: 96px;'));
        $this->assertSame(1, substr_count($html, 'alt="ポチの写真"'));
        $this->assertSame(1, substr_count($html, '>写真なし</span>'));
        $this->assertStringNotContainsString('alt="たまの写真"', $html);

        // どちらのまとまりも「左に枠のリンク、右に名前・項目の列」の同じ組み方
        foreach ([[$withPhoto, 'ポチ'], [$noPhoto, 'たま']] as [$id, $name]) {
            $url = preg_quote(route('trainees.show', $id), '/');
            $this->assertMatchesRegularExpression(
                '/<div class="d-flex align-items-start gap-3">\s*<a href="'.$url.'" class="flex-shrink-0 text-decoration-none"[^>]*>.*?<\/a>\s*'
                .'<div class="flex-grow-1" style="min-width: 0;">\s*<h6 class="mb-2"><a href="'.$url.'">'.$name.'<\/a><\/h6>/su',
                $html,
                $name
            );
        }
        // 「写真なし」はたまのまとまりの中（ポチの名前より後、たまの名前より前）
        $this->assertGreaterThan(strpos($html, '>ポチ</a>'), strpos($html, '>写真なし</span>'));
        $this->assertLessThan(strpos($html, '>たま</a>'), strpos($html, '>写真なし</span>'));
    }

    public function test_写真は名前と項目の左にあり計測を追加は写真の有無にかかわらず同じ並べ方(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $withPhoto = $this->trainee($client, 'ポチ', 'trainees/202610/pochi.jpg');
        $this->trainee($client, 'たま');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 写真のまとまり：横並びの中で、写真のリンクが先（左）、名前・項目の列が後（右）
        $pattern = '/<div class="d-flex align-items-start gap-3">\s*'
            .'<a href="'.preg_quote(route('trainees.show', $withPhoto), '/').'" class="flex-shrink-0 text-decoration-none"\s*>.*?<\/a>\s*'
            .'<div class="flex-grow-1" style="min-width: 0;">\s*'
            .'<h6 class="mb-2"><a href="'.preg_quote(route('trainees.show', $withPhoto), '/').'">ポチ<\/a><\/h6>/su';
        $this->assertMatchesRegularExpression($pattern, $html);

        // 写真がないまとまりも、左に「写真なし」の枠のリンク、右に名前・項目の列（位置がそろう）
        $this->assertMatchesRegularExpression(
            '/<div class="d-flex align-items-start gap-3">\s*<a href="[^"]+" class="flex-shrink-0 text-decoration-none"\s*aria-label="たまの写真なし。トレーニー詳細へ"\s*>.*?写真なし<\/span>\s*<\/div>\s*<\/a>\s*'
            .'<div class="flex-grow-1" style="min-width: 0;">\s*<h6 class="mb-2"><a href="[^"]+">たま<\/a><\/h6>/su',
            $html
        );

        // 「計測を追加」ボタンは写真の有無にかかわらず同じ並べ方（写真を置く前と同じクラス）
        $ddPattern = '/<dd class="col-8 mb-0 d-flex flex-wrap align-items-center justify-content-between gap-2">/u';
        $this->assertSame(2, preg_match_all($ddPattern, $html));
        $this->assertSame(2, substr_count($html, '<button type="button" class="btn btn-sm btn-outline-primary flex-shrink-0"'));
        // 項目の dl は写真の有無で変わらない（flex-grow-1 などを持たない）
        $this->assertSame(2, substr_count($html, '<dl class="row mb-0 small">'));
    }

    public function test_pc幅では左のトレーニング記録カードを右の列の高さに合わせる(): void
    {
        $client = $this->makeClient('0001', '山田');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 左の列・伸びる箱・カードに会員詳細専用のクラス
        $this->assertMatchesRegularExpression(
            '/<div class="col-lg-7 client-records-col">\s*<div class="client-records-fill">\s*<div class="card mb-3 client-records-card">/u',
            $html
        );

        // PC 幅（lg 以上）だけに効くスタイル
        $start = strpos($html, '@media (min-width: 992px) {');
        $this->assertNotFalse($start, 'PC 幅用の @media がある');
        $media = substr($html, $start, 900);
        $this->assertMatchesRegularExpression('/\.client-records-col\s*\{\s*display: flex;\s*flex-direction: column;\s*\}/u', $media);
        // 最低の高さ：カード 40vh ＋ 下の 1rem
        $this->assertMatchesRegularExpression('/\.client-records-fill\s*\{\s*position: relative;\s*flex: 1 1 auto;\s*min-height: calc\(40vh \+ 1rem\);\s*\}/u', $media);
        // カードは absolute で行の高さの計算から外す（行を押し広げない）
        $this->assertMatchesRegularExpression('/\.client-records-card\s*\{\s*position: absolute;\s*top: 0;\s*right: 0;\s*bottom: 1rem;\s*left: 0;/u', $media);
        // 本文は残りの高さでスクロール（PC では max-height を外す）
        $this->assertMatchesRegularExpression('/\.client-records-card \.training-records-scroll\s*\{\s*max-height: none;\s*flex: 1 1 auto;\s*min-height: 0;\s*\}/u', $media);

        // スマホ幅用の max-height: 60vh は @media の外に残る
        $this->assertMatchesRegularExpression('/\.training-records-scroll\s*\{\s*max-height:\s*60vh;\s*overflow-y:\s*auto;\s*\}/u', substr($html, 0, $start));
        // 回数カードの 35vh は変わらない
        $this->assertMatchesRegularExpression('/\.contract-ticket-scroll\s*\{\s*max-height:\s*35vh;\s*overflow-y:\s*auto;/u', $html);
    }

    public function test_回数カードはトレーニーカードの上にある(): void
    {
        $client = $this->makeClient('0001', '山田');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, '<h6 class="mb-0">トレーニー（'),
            strpos($html, '<h6 class="mb-0">ご利用回数')
        );
    }
}

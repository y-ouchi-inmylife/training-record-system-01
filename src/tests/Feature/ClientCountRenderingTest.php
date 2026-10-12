<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientContract;
use App\Models\ClientUsage;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 残り回数・回数カードの描画確認（設計書 screen-design.md S-0304「残り回数」列、
 * S-0305 セクション1「残り回数」・セクション5「回数」、§2-4「例外：残り回数」）。
 *
 * - 会員一覧：契約 0 件は空欄、マイナスは赤字（text-danger）で「-n回」、0 は「0回」
 * - 会員詳細：ヘッダーの残り回数、回数カードの契約の見出し・マス・超過・使い切り・空の状態
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

    private function usage(Client $client, string $usedOn, int $count = 1, ?string $note = null): ClientUsage
    {
        return ClientUsage::query()->create([
            'client_id' => $client->id, 'used_on' => $usedOn, 'count' => $count, 'note' => $note,
        ]);
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

    /** 行の最後のセル（8 列目＝残り回数）の中身 */
    private function lastCell(string $row): string
    {
        $start = strrpos($row, '<td>');

        return trim(strip_tags(substr($row, $start)));
    }

    // ---- 会員一覧（S-0304） ----

    public function test_会員一覧の残り回数列(): void
    {
        $none = $this->makeClient('0001', '契約なし');
        $this->usage($none, '2026-10-01');            // 契約なしの利用だけ → 空欄

        $plus = $this->makeClient('0002', '残りあり');
        $this->contract($plus, '2026-10-01', 4);
        $this->contract($plus, '2026-10-05', 2);
        $this->usage($plus, '2026-10-02', 3);         // 6 − 3 = 3

        $zero = $this->makeClient('0003', '使い切り');
        $this->contract($zero, '2026-10-01', 2);
        $this->usage($zero, '2026-10-02', 2);         // 0

        $minus = $this->makeClient('0004', '超過あり');
        $this->contract($minus, '2026-10-01', 1);
        $this->usage($minus, '2026-10-02', 3);        // 1 − 3 = −2

        $html = $this->get(route('clients.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<th>残り回数</th>', $html);
        $this->assertSame('', $this->lastCell($this->listRow($html, '契約なし')));
        $this->assertSame('3回', $this->lastCell($this->listRow($html, '残りあり')));
        $this->assertSame('0回', $this->lastCell($this->listRow($html, '使い切り')));

        $minusRow = $this->listRow($html, '超過あり');
        $this->assertSame('-2回', $this->lastCell($minusRow));
        $this->assertStringContainsString('text-danger', $minusRow);
        $this->assertStringNotContainsString('text-danger', $this->listRow($html, '残りあり'));
    }

    public function test_会員一覧の空の行は8列にまたがる(): void
    {
        $html = $this->get(route('clients.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<td colspan="8"', $html);
    }

    // ---- 会員詳細（S-0305） ----

    public function test_契約も利用もない会員は残り回数が空欄で空の状態の文言が出る(): void
    {
        $client = $this->makeClient('0001', '山田');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringContainsString('契約・利用は登録されていません', $html);
        $this->assertStringNotContainsString('回</span>', $html);
    }

    public function test_回数カードに契約の見出し_マス_使い切り_残りが出る(): void
    {
        $client = $this->makeClient('0001', '山田');
        $old = $this->contract($client, '2026-09-03', 2, null, '2026-12-03');
        $new = $this->contract($client, '2026-10-12', 4, '4回コース');
        $this->usage($client, '2026-09-04');
        $this->usage($client, '2026-09-06');
        $this->usage($client, '2026-10-12', 1, 'メモあり利用');
        $this->usage($client, '2026-10-13');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // ヘッダーの残り回数（6 − 4 = 2）
        $this->assertMatchesRegularExpression('/残り回数<\/div>\s*<div[^>]*><span>2回<\/span>/u', $html);

        // 契約の見出し（メモ・期限は、あるときだけ出す）。新しい契約が上
        $newPos = strpos($html, '2026/10/12 契約（4回） 4回コース');
        $oldPos = strpos($html, '2026/09/03 契約（2回）  期限 2026/12/03');
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
        $this->assertStringContainsString('onclick="window.contractModal.openForEdit(this.dataset)"', $html);
        $this->assertStringContainsString('onclick="window.usageModal.openForEdit(this.dataset)"', $html);

        // 超過はないので出ない
        $this->assertStringNotContainsString('（契約なし）', $html);
    }

    public function test_超過があると一番上に超過の行が出て残り回数は赤字(): void
    {
        $client = $this->makeClient('0001', '山田');
        $this->contract($client, '2026-10-01', 1);
        $this->usage($client, '2026-10-02', 2);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringContainsString('<span class="text-danger">-1回</span>', $html);
        $this->assertStringContainsString('<span class="badge bg-danger">超過 1</span>', $html);
        $this->assertLessThan(
            strpos($html, '2026/10/01 契約（1回）'),
            strpos($html, '（契約なし）'),
            '超過の行は契約の行より上'
        );
    }

    public function test_契約がなく利用だけある会員は残り回数が空欄で超過の行が出る(): void
    {
        $client = $this->makeClient('0001', '山田');
        $this->usage($client, '2026-10-02');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/残り回数<\/div>\s*<div[^>]*><\/div>/u', $html);
        $this->assertStringContainsString('<span class="badge bg-danger">超過 1</span>', $html);
    }

    public function test_利用モーダルの入力エラーで戻ると利用モーダルだけが開き直す(): void
    {
        $client = $this->makeClient('0001', '山田');

        $html = $this->from(route('clients.show', $client))
            ->followingRedirects()
            ->post(route('client-usages.store', $client), [
                '_form_type' => 'usage',
                'used_on' => '2026-10-12',
                'count' => '0',
            ])
            ->assertOk()
            ->getContent();

        // ClientController::show が old('_form_type') から $openModal='usage' を決め、
        // 利用モーダルの側にだけ開き直しの JS と欄の下の文言が入る
        $this->assertStringContainsString('const oldUsageId', $html);
        $this->assertStringNotContainsString('const oldContractId', $html);
        $this->assertStringContainsString('回数は1以上で入力してください。', $html);
        $this->assertSame(1, substr_count($html, '回数は1以上で入力してください。'), '契約モーダルには出ない');
    }

    public function test_回数カードはトレーニーカードの上にある(): void
    {
        $client = $this->makeClient('0001', '山田');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, '<h6 class="mb-0">トレーニー（'),
            strpos($html, '<h6 class="mb-0">回数</h6>')
        );
    }
}

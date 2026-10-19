<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientContract;
use App\Models\ClientUsage;
use App\Models\Trainee;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsIntakeSurveyName;
use Tests\Concerns\AssertsPersonalityDiagnosisName;
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
    use AssertsIntakeSurveyName;
    use AssertsPersonalityDiagnosisName;
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
            // 空のトレーニーかの判定（会員の削除の確認。2026-10-18 追加）に使う登録内容の列
            $table->string('breed', 100)->nullable();
            $table->string('sex', 20)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->unsignedTinyInteger('birth_month')->nullable();
            $table->unsignedTinyInteger('birth_day')->nullable();
            $table->string('neuter_status', 20)->nullable();
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
        // 愛犬アンケート用 URL のトークン（トレーニーカードの愛犬アンケートの行の状態。2026-10 追加）
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        // 性格診断の案内（2026-10 追加）。会員詳細の「性格診断」の行のボタンに使う
        $this->createTraineePersonalityDiagnosisTokensTable();
        // 性格診断の結果（性格診断の行の最新の診断の日付。2026-10 追加）
        $this->createTraineePersonalityDiagnosisResultsTable();

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

    // ---- 契約の登録のモーダルの有効期限の初期値（今日の月の最終日。2026-10-18 追加） ----

    public static function contractDefaultExpiresOnCases(): array
    {
        return [
            '月の途中' => ['2026-10-18 10:00:00', '2026-10-31'],
            'うるう年の 2 月' => ['2028-02-10 10:00:00', '2028-02-29'],
            'うるう年でない 2 月' => ['2026-02-10 10:00:00', '2026-02-28'],
            '12 月の最終日' => ['2026-12-31 23:30:00', '2026-12-31'],
            '30 日の月の 1 日' => ['2026-11-01 00:10:00', '2026-11-30'],
        ];
    }

    #[DataProvider('contractDefaultExpiresOnCases')]
    public function test_契約の登録の有効期限の初期値は今日の月の最終日(string $now, string $expected): void
    {
        Carbon::setTestNow($now);
        $client = $this->makeClient('0001', '山田');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="expires_on" name="expires_on"\s*value="'.$expected.'"/u', $html);
        $this->assertStringContainsString('const DEFAULT_EXPIRES_ON = "'.$expected.'";', $html);
        Carbon::setTestNow();
    }

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

    private function trainee(Client $client, ?string $name, ?string $photoPath = null): int
    {
        return DB::table('trainees')->insertGetId([
            'client_id' => $client->id, 'name' => $name, 'photo_path' => $photoPath,
        ]);
    }

    // ---- 会員の削除の確認（2026-10-18 変更：空のトレーニーだけなら一緒に削除できる） ----

    /** 管理者で会員詳細を開き、削除の確認の JS（confirmDelete）を返す */
    private function confirmDeleteScript(Client $client): string
    {
        $admin = new Trainer(['name' => '管理者太郎', 'role' => 'admin']);
        $admin->id = 100;
        $admin->exists = true;
        $html = $this->actingAs($admin, 'web')->get(route('clients.show', $client))->assertOk()->getContent();
        $start = strpos($html, 'function confirmDelete()');
        $this->assertNotFalse($start);

        return preg_replace('/\s+/u', ' ', substr($html, $start, strpos($html, '</script>', $start) - $start));
    }

    public function test_削除の確認_空のトレーニーだけならトレーニーがいないときと同じ文(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $this->trainee($client, null);

        $script = $this->confirmDeleteScript($client);

        // 2026-10-18 変更：ユーザーレビューで、一度添えていた「登録内容のないトレーニーも一緒に削除します。」を外した
        $this->assertStringContainsString("return confirm('この会員を削除しますか？');", $script);
        $this->assertStringNotContainsString('登録内容のないトレーニー', $script);
        $this->assertStringNotContainsString('トレーニーが登録されているため削除できません', $script);
    }

    public function test_削除の確認_空でないトレーニーがいれば今までどおり止める(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $this->trainee($client, null);
        $this->trainee($client, 'ポチ');

        $script = $this->confirmDeleteScript($client);

        $this->assertStringContainsString("alert('この会員にはトレーニーが登録されているため削除できません。'); return false;", $script);
        $this->assertStringNotContainsString('confirm(', $script);
    }

    public function test_削除の確認_トレーニーがいなければ今までの文(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');

        $script = $this->confirmDeleteScript($client);

        $this->assertStringContainsString("return confirm('この会員を削除しますか？');", $script);
        $this->assertStringNotContainsString('登録内容のないトレーニー', $script);
    }

    public function test_トレーニーの欄に備考の行はなく去勢避妊の次が暮らしの様子(): void
    {
        $client = $this->makeClient('0001', '山田');
        $this->trainee($client, 'ポチ');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // ラベルの並び（2026-10 変更：トレーニーの備考をなくした。2026-10-19 変更：去勢・避妊の次は暮らしの様子、最終計測はいちばん下。
        // 愛犬性格診断は dl の外の行なので、ここには入らない）
        // ラベルの文字（折り返しの印 <wbr> を除いたもの。2026-10-18 から「去勢・避妊」「最終計測」に印が入る）
        preg_match_all('/<dt class="col-4 fw-normal text-muted"[^>]*>(.+?)<\/dt>/u', $html, $m);
        $m[1] = array_map(fn ($label) => str_replace('<wbr>', '', $label), $m[1]);
        $this->assertSame(['犬種', '性別', '誕生日', '去勢・避妊', '暮らしの様子', '最終計測'], array_slice($m[1], 0, 6));
        $this->assertNotContains('備考', $m[1]);
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
                .'<div class="flex-grow-1" style="min-width: 0;">\s*<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">\s*<h6 class="mb-0 text-break trainee-survey-line"><a href="'.$url.'">'.$name.'<\/a><\/h6>/su',
                $html,
                $name
            );
        }
        // 「写真なし」はたまのまとまりの中（ポチの名前より後、たまの名前より前）
        $this->assertGreaterThan(strpos($html, '>ポチ</a>'), strpos($html, '>写真なし</span>'));
        $this->assertLessThan(strpos($html, '>たま</a>'), strpos($html, '>写真なし</span>'));
    }

    public function test_写真は名前と項目の左にあり写真の有無にかかわらず同じ並べ方(): void
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
            .'<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">\s*<h6 class="mb-0 text-break trainee-survey-line"><a href="'.preg_quote(route('trainees.show', $withPhoto), '/').'">ポチ<\/a><\/h6>/su';
        $this->assertMatchesRegularExpression($pattern, $html);

        // 写真がないまとまりも、左に「写真なし」の枠のリンク、右に名前・項目の列（位置がそろう）
        $this->assertMatchesRegularExpression(
            '/<div class="d-flex align-items-start gap-3">\s*<a href="[^"]+" class="flex-shrink-0 text-decoration-none"\s*aria-label="たまの写真なし。トレーニー詳細へ"\s*>.*?写真なし<\/span>\s*<\/div>\s*<\/a>\s*'
            .'<div class="flex-grow-1" style="min-width: 0;">\s*<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">\s*<h6 class="mb-0 text-break trainee-survey-line"><a href="[^"]+">たま<\/a><\/h6>/su',
            $html
        );

        // 最終計測の行は写真の有無にかかわらず同じ並べ方（ほかの属性と同じ dd。計測がないので「計測なし」。2026-10-19 変更：その前は空欄）
        $this->assertSame(2, preg_match_all('/<dt class="col-4 fw-normal text-muted" style="word-break: keep-all; overflow-wrap: normal;">最終<wbr>計測<\/dt>\s*<dd class="col-8 mb-0 text-muted" data-latest-measurement>計測なし<\/dd>/u', $html));
        // 項目の dl は写真の有無で変わらない（flex-grow-1 などを持たない）。最終計測だけの dl も同じ
        $this->assertSame(2, substr_count($html, '<dl class="row mb-0 small">'));
        $this->assertSame(2, substr_count($html, '<dl class="row mb-0 small mt-1">'));
    }

    public function test_トレーニーの欄に計測を追加のボタンと計測値のモーダルを出さない(): void
    {
        // 計測値の登録はトレーニー詳細（S-0309）の計測値の欄から行う（2026-10 変更）
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        // このテストの表には体重の列がないため、最終計測の値を確かめるときだけ足す
        Schema::table('trainee_measurements', fn (Blueprint $table) => $table->decimal('weight_kg', 5, 2)->nullable());
        DB::table('trainee_measurements')->insert([
            'trainee_id' => $id, 'measured_date' => '2026-10-13', 'measured_time' => '08:00:00', 'weight_kg' => 3.2,
        ]);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringNotContainsString('計測を追加', $html);
        $this->assertStringNotContainsString('measurementModal', $html);
        $this->assertStringNotContainsString('name="return_to"', $html);
        $this->assertStringNotContainsString(route('trainee-measurements.store', $id), $html);
        // 最終計測の値は今までどおり（ほかの属性と同じ dd の中に値だけ）
        $this->assertMatchesRegularExpression('/<dt class="col-4 fw-normal text-muted" style="word-break: keep-all; overflow-wrap: normal;">最終<wbr>計測<\/dt>\s*<dd class="col-8 mb-0" data-latest-measurement>2026\/10\/13（3\.20kg）<\/dd>/u', $html);
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

    /**
     * 名前が未登録のトレーニー（2026-10 追加。§2-4 の例外）：カードの名前のリンクは「名前未登録」を
     * 薄い文字で出し、リンクは張ったまま（文字が空にならない）。写真なしの枠の読み上げ用の名前も同じ
     */
    public function test_名前が未登録のトレーニーはカードに名前未登録を薄い文字のリンクで出す(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, null);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $url = preg_quote(route('trainees.show', $id), '/');
        $this->assertMatchesRegularExpression('/<h6 class="mb-0 text-break trainee-survey-line"><a href="'.$url.'" class="text-muted">名前未登録<\/a><\/h6>/u', $html);
        $this->assertStringContainsString('aria-label="名前未登録の写真なし。トレーニー詳細へ"', $html);
        // リンクの文字が空にならない
        $this->assertDoesNotMatchRegularExpression('/<a href="'.$url.'"[^>]*><\/a>/u', $html);
    }

    public function test_名前があるトレーニーのリンクには薄い文字のクラスを付けない(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringContainsString('<h6 class="mb-0 text-break trainee-survey-line"><a href="'.route('trainees.show', $id).'">ポチ</a></h6>', $html);
    }

    // ---- 愛犬アンケートの行（2026-10 追加。設計書 S-0305 セクション3「愛犬アンケート」） ----

    private function intakeToken(int $traineeId, ?string $answeredAt = null, string $createdAt = '2026-10-15 09:00:00'): int
    {
        return DB::table('trainee_intake_survey_tokens')->insertGetId([
            'token' => bin2hex(random_bytes(16)), 'trainee_id' => $traineeId,
            'answered_at' => $answeredAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    /**
     * 愛犬アンケートの回答。$answers は暮らしの様子などの列の値（2026-10-19 変更：「入力あり」は暮らしの様子に文字が入っているかで
     * 決めるので、既定は「こうなったらいいな」に文字が入った回答にする）
     */
    private function intakeResponse(int $traineeId, ?int $tokenId, string $answeredAt = '2026-10-16 14:32:00', array $answers = ['wish' => 'お留守番が上手になってほしい']): void
    {
        DB::table('trainee_intake_survey_responses')->insert([
            'trainee_id' => $traineeId, 'trainee_intake_survey_token_id' => $tokenId,
            'answered_at' => $answeredAt, 'created_at' => $answeredAt, 'updated_at' => $answeredAt,
            ...$answers,
        ]);
    }

    /** 「暮らしの様子」のリンク（回答済みのとき、属性の並び〔dl〕のすぐ後ろ）の正規表現 */
    private function lifeLinkPattern(int $traineeId, string $name): string
    {
        $url = preg_quote(route('trainees.show', $traineeId).'#intake-survey', '/');

        // 去勢・避妊の行のすぐ後ろに、ラベルがリンクの「暮らしの様子」と「入力あり」のバッジ（2026-10-19 変更：その前は性格診断の行の
        // すぐ後ろに、リンクだけの行〔div.small.mt-1〕を出していた）
        return '/<dd class="col-8 mb-1">[^<]*<\/dd>\s*'
            .'<dt class="col-4 fw-normal" style="word-break: keep-all; overflow-wrap: normal;"><a href="'.$url.'"\s*aria-label="'.preg_quote($name, '/').'の暮らしの様子">暮らしの<wbr>様子<\/a><\/dt>\s*'
            .'<dd class="col-8 mb-0" data-intake-survey-status><span class="badge bg-success">入力あり<\/span><\/dd>\s*<\/dl>/us';
    }

    public function test_回答済みの暮らしの様子のリンクの読み上げ用の名前は名前が未登録なら名前未登録(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, null);
        $this->intakeResponse($id, $this->intakeToken($id, '2026-10-16 14:32:00'));

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertMatchesRegularExpression($this->lifeLinkPattern($id, '名前未登録'), $html);
    }

    public function test_未発行と回答待ちでは暮らしの様子のリンクを出さない(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $notIssued = $this->trainee($client, 'ポチ');
        $awaiting = $this->trainee($client, 'たま');
        $this->intakeToken($awaiting);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringNotContainsString('暮らしの様子', $html);
        $this->assertStringNotContainsString('#intake-survey', $html);
        // 未発行は［案内を発行］、回答待ちは「案内中」の行（2026-10-19 変更）
        $this->assertMatchesRegularExpression($this->issueButtonPattern($notIssued, 'ポチ'), $html);
        $this->assertStringNotContainsString('aria-label="たまの案内を発行"', $html);
        $this->assertNotNull($this->inProgressRow($html, 'たま'));
        $this->assertSame(1, substr_count($html, '>取消</button>'));
    }

    public function test_取消モーダルはトレーニーが何頭いても1つで送信先のひな形を持つ(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $a = $this->trainee($client, 'ポチ');
        $b = $this->trainee($client, 'たま');
        $this->intakeToken($a);
        $this->intakeToken($b);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="intakeSurveyCancelModal"'));
        $this->assertStringContainsString('愛犬アンケートの案内の取消', $html);
        $this->assertStringContainsString('発行済みの愛犬アンケートの案内が使えなくなります。<br>', $html);
        // 取消のあとは常に会員詳細に戻るため、戻り先（return_to）は送らない（2026-10 変更）
        $this->assertMatchesRegularExpression('/id="intakeSurveyCancelForm">\s*<input type="hidden" name="_token"[^>]*>\s*<input type="hidden" name="_method" value="DELETE">\s*<button type="submit" class="btn btn-danger">取り消す<\/button>/u', $html);
        // 送信先のひな形（トレーニーの id を JS で入れる）
        $this->assertStringContainsString(json_encode(route('trainee-intake-survey-tokens.destroy', ['trainee' => '__ID__'])), $html);
        $this->assertStringContainsString('data-intake-survey-trainee-id="'.$a.'"', $html);
        $this->assertStringContainsString('data-intake-survey-trainee-id="'.$b.'"', $html);
    }

    // ---- マイページ登録案内のボタン（3 段目のメールアドレス列。2026-10 変更） ----

    /**
     * 3 段目のメールアドレス列の状態別ボタンのラッパー（バッジの右）を取り出す。
     * 愛犬アンケートの「取消」と取り違えないよう、ラッパーの中だけで確かめる
     */
    private function emailButtons(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="d-flex flex-wrap align-items-center justify-content-end gap-2 ms-auto"[^>]*>(.*?)<\/div>\s*<\/div>\s*<\/div>/su', $html, $m));

        return $m[0];
    }

    private function emailRegToken(Client $client, string $expiresAt): void
    {
        DB::table('client_email_registration_tokens')->insert([
            'client_id' => $client->id, 'is_used' => false, 'expires_at' => $expiresAt,
        ]);
    }

    public function test_未登録ならマイページ登録の案内を発行のボタンを出す(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');

        $buttons = $this->emailButtons($this->get(route('clients.show', $client))->assertOk()->getContent());

        $this->assertStringContainsString('role="group" aria-label="マイページ登録の案内"', $buttons);
        $this->assertStringContainsString('<button type="submit" class="btn btn-sm btn-primary text-nowrap">マイページ登録の案内を発行</button>', $buttons);
        $this->assertSame(0, preg_match('/>\s*登録案内を(発行|表示|取消)\s*</u', $buttons));
        $this->assertStringNotContainsString('マイページ登録の案内を表示', $buttons);
        $this->assertSame(0, preg_match('/>\s*取消\s*</u', $buttons));
    }

    public function test_登録待ちならマイページ登録の案内を表示と取消のボタンを出す(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $this->emailRegToken($client, now()->addDays(2)->format('Y-m-d H:i:s'));

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();
        $buttons = $this->emailButtons($html);

        $this->assertStringContainsString('role="group" aria-label="マイページ登録の案内"', $buttons);
        $printUrl = preg_quote(route('client-email-registration-tokens.print', $client), '/');
        $this->assertMatchesRegularExpression('/<a href="'.$printUrl.'"\s*class="btn btn-sm btn-outline-primary text-nowrap">マイページ登録の案内を表示<\/a>/u', $buttons);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-sm btn-outline-danger text-nowrap"\s*data-bs-toggle="modal" data-bs-target="#tokenCancellationModal">\s*取消\s*<\/button>/u', $buttons);
        // 古い文字のボタンは出さない
        $this->assertSame(0, preg_match('/>\s*登録案内を(発行|表示|取消)\s*</u', $buttons));
        $this->assertStringNotContainsString('マイページ登録の案内を発行', $buttons);
        // 取消の確認（M03）の文言は今までどおり
        $this->assertMatchesRegularExpression('/<h5 class="modal-title" id="tokenCancellationModalLabel">\s*マイページ登録案内の取消\s*<\/h5>/u', $html);
        $this->assertStringContainsString('発行済みのマイページ登録案内が使えなくなります。<br>', $html);
        $this->assertStringContainsString('すでにお渡しした案内からは登録できなくなります。', $html);
    }

    public function test_登録待ちで期限切れならマイページ登録の案内を発行のボタンを出す(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $this->emailRegToken($client, now()->subDay()->format('Y-m-d H:i:s'));

        $buttons = $this->emailButtons($this->get(route('clients.show', $client))->assertOk()->getContent());

        $this->assertStringContainsString('>マイページ登録の案内を発行</button>', $buttons);
        $this->assertSame(0, preg_match('/>\s*取消\s*</u', $buttons));
    }

    /** 1 段目の上部ボタン列（右寄せ）の HTML */
    private function topButtons(string $html): string
    {
        // 次の 2 段目（氏名の行）の手前まで
        $start = strpos($html, '<div class="d-flex flex-wrap justify-content-end gap-2 mb-2">');
        $this->assertNotFalse($start);
        $end = strpos($html, '<div class="d-flex align-items-center gap-2 flex-wrap mb-2">', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    public function test_利用中なら上部の並びにマイページ登録を削除を出しメールアドレスの欄には出さない(): void
    {
        $this->fakeMediaDisk();
        // 管理者（会員の「削除」も出る）で並びを確かめる
        $admin = new Trainer(['name' => '管理太郎', 'role' => 'admin']);
        $admin->id = 100;
        $admin->exists = true;
        $this->actingAs($admin, 'web');
        $client = $this->makeClient('0001', '山田');
        $client->forceFill(['email' => 'yamada@example.com', 'password' => 'hashed'])->save();

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();
        $top = $this->topButtons($html);

        // 並びは「会員一覧に戻る → マイページ登録を削除 → 編集 → 削除」（2026-10 変更）
        $this->assertMatchesRegularExpression(
            '/会員一覧に戻る<\/a>\s*<button type="button" class="btn btn-outline-danger text-nowrap"\s*data-bs-toggle="modal" data-bs-target="#emailDeletionModal">\s*マイページ登録を削除\s*<\/button>\s*'
            .'<a href="[^"]+" class="btn btn-primary">編集<\/a>.*?<button type="submit" class="btn btn-danger">削除<\/button>/su',
            $top
        );
        // メールアドレス（マイページ）の欄には削除のボタンを出さない。案内のまとまりの名前も付けない
        $buttons = $this->emailButtons($html);
        $this->assertStringNotContainsString('削除', $buttons);
        $this->assertStringNotContainsString('<button', $buttons);
        $this->assertStringNotContainsString('role="group"', $buttons);
        // 「登録を削除」だけの古い名前のボタンはどこにもない
        $this->assertSame(0, preg_match('/>\s*登録を削除\s*</u', $html));
        // 確認のモーダル（M02）の文言は今までどおり
        // 見出しはボタン名にそろえた「マイページ登録の削除」（2026-10 変更）。本文・確定ボタンは今までどおり
        $this->assertMatchesRegularExpression('/aria-labelledby="emailDeletionModalLabel".*?<h5 class="modal-title" id="emailDeletionModalLabel">\s*マイページ登録の削除\s*<\/h5>/su', $html);
        $this->assertStringNotContainsString('メールアドレスの削除', $html);
        $this->assertStringContainsString('登録されたメールアドレスが削除され、マイページを使えなくなります。<br>', $html);
        $this->assertStringContainsString('会員情報とトレーニング記録は残ります。', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-danger">削除する</button>', $html);
    }

    public function test_初回設定待ちでも上部の並びにマイページ登録を削除を出す(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $client->forceFill(['email' => 'yamada@example.com'])->save();

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringContainsString('マイページ登録を削除', $this->topButtons($html));
        $this->assertStringNotContainsString('削除', $this->emailButtons($html));
    }

    public function test_未登録と登録待ちでは上部にマイページ登録を削除を出さない(): void
    {
        $this->fakeMediaDisk();
        $none = $this->makeClient('0001', '山田');
        $awaiting = $this->makeClient('0002', '佐藤');
        $this->emailRegToken($awaiting, now()->addDays(2)->format('Y-m-d H:i:s'));

        foreach ([$none, $awaiting] as $client) {
            $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

            $this->assertStringNotContainsString('マイページ登録を削除', $html);
            $this->assertStringContainsString('role="group" aria-label="マイページ登録の案内"', $this->emailButtons($html));
        }
    }

    public function test_上部のメールアドレスのラベルはメールアドレス_マイページ(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 初回日・主担当と並ぶ 3 段目の列（2026-10 変更）
        $this->assertMatchesRegularExpression('/<div class="text-muted small">主担当<\/div>.*?<div class="text-muted small">メールアドレス（マイページ）<\/div>/su', $html);
        $this->assertStringNotContainsString('<div class="text-muted small">メールアドレス</div>', $html);
    }

    public function test_会員一覧の列見出しはマイページでバッジは今までどおり(): void
    {
        $this->fakeMediaDisk();
        $this->makeClient('0001', '山田');

        $html = $this->get(route('clients.index'))->assertOk()->getContent();

        // 列見出しは「マイページ」（2026-10 変更）。主担当と最終トレーニング日の間
        $this->assertMatchesRegularExpression('/<th>主担当<\/th>\s*(?:\{\{--.*?--\}\}\s*)?<th>マイページ<\/th>\s*<th>最終トレーニング日<\/th>/su', $html);
        $this->assertStringNotContainsString('<th>メールアドレス</th>', $html);
        // 列の中身（マイページの状態のバッジ）は今までどおり
        $this->assertStringContainsString('<td><span class="badge bg-secondary">未登録</span></td>', $this->listRow($html, '山田'));
    }

    public function test_マイページの状態のバッジは一覧と詳細で同じ文言_初回設定待ちは登録待ち初回設定(): void
    {
        // 2026-10-18 変更：初回設定待ち（awaiting_setup）のバッジは「登録待ち（初回設定）」。期限切れは「登録待ち（期限切れ）」
        // （その前は「初回設定待ち」「初回設定待ち（期限切れ）」）。ほかのバッジ・色は変えない
        $this->fakeMediaDisk();
        $none = $this->makeClient('0001', '山田');
        $awaitingEmail = $this->makeClient('0002', '佐藤');
        $this->emailRegToken($awaitingEmail, now()->addDays(2)->format('Y-m-d H:i:s'));
        $awaitingEmailExpired = $this->makeClient('0003', '鈴木');
        $this->emailRegToken($awaitingEmailExpired, now()->subDay()->format('Y-m-d H:i:s'));
        $awaitingSetup = $this->makeClient('0004', '田中');
        $awaitingSetup->forceFill(['email' => 'tanaka@example.com'])->save();
        $this->emailRegToken($awaitingSetup, now()->addDays(2)->format('Y-m-d H:i:s'));
        $awaitingSetupExpired = $this->makeClient('0005', '高橋');
        $awaitingSetupExpired->forceFill(['email' => 'takahashi@example.com'])->save();
        $this->emailRegToken($awaitingSetupExpired, now()->subDay()->format('Y-m-d H:i:s'));
        $inUse = $this->makeClient('0006', '伊藤');
        $inUse->forceFill(['email' => 'ito@example.com', 'password' => 'hashed'])->save();

        $expected = [
            '山田' => [$none, '<span class="badge bg-secondary">未登録</span>'],
            '佐藤' => [$awaitingEmail, '<span class="badge bg-warning text-dark">登録待ち</span>'],
            '鈴木' => [$awaitingEmailExpired, '<span class="badge bg-danger">登録待ち（期限切れ）</span>'],
            '田中' => [$awaitingSetup, '<span class="badge bg-warning text-dark">登録待ち（初回設定）</span>'],
            '高橋' => [$awaitingSetupExpired, '<span class="badge bg-danger">登録待ち（期限切れ）</span>'],
            '伊藤' => [$inUse, '<span class="badge bg-success">利用中</span>'],
        ];

        $list = $this->get(route('clients.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('初回設定待ち', $list);
        foreach ($expected as $lastName => [$client, $badge]) {
            $this->assertStringContainsString("<td>{$badge}</td>", $this->listRow($list, $lastName), $lastName);

            $show = $this->get(route('clients.show', $client))->assertOk()->getContent();
            $this->assertStringContainsString($badge, $show, $lastName);
            $this->assertStringNotContainsString('初回設定待ち', $show, $lastName);
        }
    }

    // ---- ボタンの色（2026-10 変更：青いボタンはすべて塗り） ----

    /** モーダルより前（画面の本文）の HTML */
    private function bodyBeforeModals(string $html): string
    {
        $end = strpos($html, '<div class="modal fade"');
        $this->assertNotFalse($end);

        return substr($html, 0, $end);
    }

    public function test_表示のボタンが出ないときは白抜きの青を残さず発行やほかの青いボタンは塗り(): void
    {
        $this->fakeMediaDisk();
        $admin = new Trainer(['name' => '管理太郎', 'role' => 'admin']);
        $admin->id = 100;
        $admin->exists = true;
        $this->actingAs($admin, 'web');
        $client = $this->makeClient('0001', '山田');
        $this->trainee($client, 'ポチ');

        $body = $this->bodyBeforeModals($this->get(route('clients.show', $client))->assertOk()->getContent());

        $this->assertStringNotContainsString('btn-outline-primary', $body);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-sm btn-primary"\s*onclick="window\.contractModal\.openForCreate\(\)">契約を追加<\/button>/u', $body);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-sm btn-primary"\s*onclick="window\.usageModal\.openForCreate\(\)">利用を追加<\/button>/u', $body);
        // トレーニーの欄の［案内を発行］（2026-10-19 変更：その前は［愛犬アンケートの案内を発行］）
        $this->assertStringContainsString('class="btn btn-sm btn-primary text-nowrap ms-auto"', $body);
        $this->assertStringContainsString('>案内を発行</button>', $body);
        $this->assertMatchesRegularExpression('/class="btn btn-sm btn-primary">トレーニーを追加<\/a>/u', $body);
        $this->assertStringContainsString('<button type="submit" class="btn btn-sm btn-primary text-nowrap">マイページ登録の案内を発行</button>', $body);
        $this->assertStringContainsString('class="btn btn-primary">編集</a>', $body);
        $this->assertMatchesRegularExpression('/class="btn btn-primary ms-auto">トレーニング記録を登録<\/a>/u', $body);
        // 赤・グレーは今のまま
        $this->assertStringContainsString('<button type="submit" class="btn btn-danger">削除</button>', $body);
        $this->assertStringContainsString('class="btn btn-outline-secondary">&laquo; 会員一覧に戻る</a>', $body);
    }

    public function test_表示のボタンは青の白抜きにし取消とマイページ登録を削除は赤の白抜きのまま(): void
    {
        $this->fakeMediaDisk();
        // 愛犬アンケートは回答待ち、マイページは登録待ち（期限内）
        $waiting = $this->makeClient('0001', '山田');
        $this->intakeToken($this->trainee($waiting, 'ポチ'), null, '2026-10-15 09:00:00');
        $this->emailRegToken($waiting, now()->addDays(2)->format('Y-m-d H:i:s'));

        $body = $this->bodyBeforeModals($this->get(route('clients.show', $waiting))->assertOk()->getContent());

        // 白抜きの青は［マイページ登録の案内を表示］と「案内中」の行の［表示］の 2 つだけ（オプションの操作。2026-10 変更。
        // 2026-10-19 変更：その前は［愛犬アンケートの案内を表示］）
        $this->assertSame(2, substr_count($body, 'btn-outline-primary'));
        $this->assertMatchesRegularExpression('/class="btn btn-sm btn-outline-primary text-nowrap"\s*aria-label="愛犬アンケートの案内を表示">表示<\/a>/u', $body);
        $this->assertMatchesRegularExpression('/class="btn btn-sm btn-outline-primary text-nowrap">マイページ登録の案内を表示<\/a>/u', $body);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-sm btn-outline-danger text-nowrap"\s*data-bs-toggle="modal" data-bs-target="#intakeSurveyCancelModal"/u', $body);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-sm btn-outline-danger text-nowrap"\s*data-bs-toggle="modal" data-bs-target="#tokenCancellationModal"/u', $body);

        // 利用中なら［マイページ登録を削除］は赤の白抜き
        $active = $this->makeClient('0002', '佐藤');
        $active->forceFill(['email' => 'sato@example.com', 'password' => 'hashed'])->save();
        $body = $this->bodyBeforeModals($this->get(route('clients.show', $active))->assertOk()->getContent());

        $this->assertStringNotContainsString('btn-outline-primary', $body);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-outline-danger text-nowrap"\s*data-bs-toggle="modal" data-bs-target="#emailDeletionModal">\s*マイページ登録を削除/u', $body);
    }

    // ---- 案内の発行と表示は同じタブで開く（2026-10-17 変更：当初は発行のフォームを別タブで送っていた） ----

    public function test_三つの発行のフォームは同じタブで送り別タブの目印もスクリプトも読み込まない(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $notIssued = $this->trainee($client, 'ポチ');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // マイページ登録の発行のフォームと、案内の発行のモーダル（M08）のフォームは、target も目印も付けない
        // （2026-10-19 変更：愛犬アンケート・愛犬性格診断の発行はモーダルから送る。送り先は JS でひな形から作る）
        $this->assertMatchesRegularExpression('/<form method="POST" action="'.preg_quote(route('client-email-registration-tokens.store', $client), '/').'"\s*class="d-inline m-0">/u', $html);
        $this->assertStringContainsString('<form method="POST" action="" class="m-0" id="surveyIssueForm">', $html);
        $this->assertStringContainsString('data-survey-issue-trainee-id="'.$notIssued.'"', $html);
        $this->assertStringNotContainsString('target="_blank"', $html);
        $this->assertStringNotContainsString('data-issue-in-new-tab', $html);
        $this->assertStringNotContainsString('issue-in-new-tab', $html);
    }

    public function test_トレーニーの案内の発行のあとは会員詳細の上に完了メッセージが出てボタンが表示に変わる(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $traineeId = $this->trainee($client, 'ポチ');
        // 2026-10-17 変更：発行のあとは案内の画面を開かず、会員詳細（ページの上）に戻る。
        // マイページ登録案内の発行のリダイレクトと完了メッセージは ClientEmailRegistrationTokenStoreTest で確かめる
        // （このテストの表はトークンの列を持たないため）。出し方はトレーニーの案内と同じ session('success')
        // 1 頭に出しておける案内は 1 つまで（2026-10-19 変更）なので、愛犬性格診断は別のトレーニーで確かめる
        $other = $this->trainee($client, 'たま');
        $cases = [
            [route('trainee-intake-survey-tokens.store', $traineeId), 'ポチの愛犬アンケートの案内を発行しました。', 'href="'.route('trainee-intake-survey-tokens.print', $traineeId).'"'],
            [route('trainee-personality-diagnosis-tokens.store', $other), 'たまの愛犬性格診断の案内を発行しました。', 'href="'.route('trainee-personality-diagnosis-tokens.print', $other).'"'],
        ];
        foreach ($cases as [$url, $message, $printLink]) {
            $response = $this->post($url);
            $response->assertRedirect(route('clients.show', $client));

            $html = $this->get(route('clients.show', $client))->assertOk()->getContent();
            $this->assertMatchesRegularExpression(
                '/<div class="alert alert-success alert-dismissible fade show" role="alert" data-auto-dismiss>\s*'.preg_quote($message, '/').'/u',
                $html
            );
            $this->assertStringContainsString($printLink, $html);
        }
    }

    public function test_三つの案内を表示のリンクは同じタブで開く(): void
    {
        $this->fakeMediaDisk();
        // マイページは登録待ち（期限内）、愛犬アンケートと性格診断は回答待ち：［…を表示］が 3 つ出る
        $client = $this->makeClient('0001', '山田');
        $this->emailRegToken($client, now()->addDays(2)->format('Y-m-d H:i:s'));
        $traineeId = $this->trainee($client, 'ポチ');
        $this->intakeToken($traineeId, null, '2026-10-15 09:00:00');
        $this->personalityToken($traineeId);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // トレーニーの 2 つは「案内中」の行の［表示］（2026-10-19 変更。前の決まりで 2 つ出ていると 2 行）
        $printUrls = [
            route('client-email-registration-tokens.print', $client) => 'マイページ登録の案内を表示',
            route('trainee-intake-survey-tokens.print', $traineeId) => '表示',
            route('trainee-personality-diagnosis-tokens.print', $traineeId) => '表示',
        ];
        foreach ($printUrls as $url => $label) {
            $this->assertMatchesRegularExpression(
                '/<a href="'.preg_quote($url, '/').'"\s*class="btn btn-sm btn-outline-primary text-nowrap"(?:\s*aria-label="[^"]*")?>'.$label.'<\/a>/u',
                $html
            );
        }
        $this->assertStringNotContainsString('target="_blank"', $html);
        // 発行のフォームは出ない（マイページ登録案内の取消のフォームはある）
        $this->assertStringNotContainsString('<button type="submit" class="btn btn-sm btn-primary text-nowrap">', $html);
        $this->assertStringContainsString('action="'.route('client-email-registration-tokens.destroy', $client).'" class="d-inline m-0">', $html);
    }

    // ---- 性格診断の行（2026-10 追加。設計書 S-0305 セクション3「性格診断の行」） ----

    private function personalityToken(int $traineeId, ?string $answeredAt = null): int
    {
        return DB::table('trainee_personality_diagnosis_tokens')->insertGetId([
            'token' => bin2hex(random_bytes(16)), 'trainee_id' => $traineeId, 'answered_at' => $answeredAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_性格診断の行はトレーニーごとに最終計測のすぐ後ろにいつも出て暮らしの様子はその後ろ(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $answered = $this->trainee($client, 'ポチ');
        $tokenId = $this->intakeToken($answered, '2026-10-16 14:32:00');
        $this->intakeResponse($answered, $tokenId);
        $notIssued = $this->trainee($client, null);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 並びは 去勢・避妊 → 暮らしの様子（いつも。バッジ）→ 性格診断の行 → 最終計測（2026-10-19 変更：その前は
        // 最終計測 → 性格診断の行 → 暮らしの様子〔回答済みのときだけのリンク〕）
        // 回答済みのトレーニー：属性の並び（dl。最後が暮らしの様子）のすぐ後ろに性格診断の行
        $this->assertMatchesRegularExpression(
            '/<\/dl>\s*<div class="row small mt-1 align-items-center" data-personality-diagnosis-row>\s*<div class="col-4">\s*'
            .'<a href="'.preg_quote(route('trainees.show', $answered), '/').'#personality-diagnosis" style="word-break: keep-all; overflow-wrap: normal;"\s*aria-label="ポチの愛犬性格診断">愛犬<wbr>性格診断<\/a>/u',
            $html
        );
        $this->assertMatchesRegularExpression($this->lifeLinkPattern($answered, 'ポチ'), $html);
        // 名前未登録・結果も案内もない（愛犬アンケートも未回答の）トレーニーにも、属性の並びのすぐ後ろに出る
        // （読み上げは「名前未登録の性格診断」）。暮らしの様子のリンクは出ず、性格診断の行で欄が終わる
        $this->assertMatchesRegularExpression(
            '/<\/dl>\s*<div class="row small mt-1 align-items-center" data-personality-diagnosis-row>\s*<div class="col-4">\s*'
            .'<a href="'.preg_quote(route('trainees.show', $notIssued), '/').'#personality-diagnosis" style="word-break: keep-all; overflow-wrap: normal;"\s*aria-label="名前未登録の愛犬性格診断">愛犬<wbr>性格診断<\/a>/u',
            $html
        );
        $afterNotIssued = substr($html, strpos($html, 'aria-label="名前未登録の愛犬性格診断">愛犬<wbr>性格診断</a>'));
        // 回答のないトレーニーは、暮らしの様子のラベルをリンクにせず「入力なし」（2026-10-19 変更：その前は行ごと出さなかった）
        $this->assertSame(1, substr_count($html, '<span class="badge bg-secondary">入力なし</span>'));
        // 行のボタンのまとまりはない（2026-10-19 変更：トレーニーの欄の［案内を発行］と「案内中」の行にまとめた）。
        // 性格診断の行（リンク・診断なし）のあと、最終計測の行（2026-10-19 変更：いちばん下）で、トレーニーの欄が閉じる
        $this->assertStringNotContainsString('role="group" aria-label="名前未登録の愛犬性格診断"', $html);
        $this->assertMatchesRegularExpression(
            '/data-personality-diagnosis-latest>診断なし<\/span>\s*<\/div>\s*<\/div>\s*<dl class="row mb-0 small mt-1">.*?計測なし<\/dd>\s*<\/dl>\s*<\/div>\s*<\/div>\s*<\/div>/us',
            $afterNotIssued,
            '愛犬性格診断の行のあと、最終計測の行でトレーニーの欄が閉じる'
        );
        $this->assertSame(1, substr_count($html, '>暮らしの<wbr>様子</a>'));
        $this->assertSame(2, substr_count($html, '>愛犬<wbr>性格診断</a>'));
    }

    public function test_性格診断の取消の確認M07の見出しと本文と確定ボタン(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $this->personalityToken($this->trainee($client, 'ポチ'));

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringContainsString('<div class="modal fade" id="personalityDiagnosisCancelModal" tabindex="-1"', $html);
        $this->assertMatchesRegularExpression('/<h5 class="modal-title" id="personalityDiagnosisCancelModalLabel">\s*愛犬性格診断の案内の取消\s*<\/h5>/u', $html);
        $this->assertStringContainsString('発行済みの愛犬性格診断の案内が使えなくなります。<br>', $html);
        $this->assertStringContainsString('すでにお渡しした案内からは回答できなくなります。', $html);
        $this->assertMatchesRegularExpression('/<form method="POST" action="" class="d-inline m-0" id="personalityDiagnosisCancelForm">[\s\S]*?<button type="submit" class="btn btn-danger">取り消す<\/button>/u', $html);
        // 送信先のひな形（開いたボタンのトレーニーの id で置き換える）
        $this->assertStringContainsString(json_encode(route('trainee-personality-diagnosis-tokens.destroy', ['trainee' => '__ID__'])), $html);
        // 愛犬アンケートの取消の確認（M06）も今までどおりある
        $this->assertStringContainsString('id="intakeSurveyCancelModal"', $html);
    }

    public function test_性格診断の案内の読み込みはトレーニーの数で増えない(): void
    {
        $this->fakeMediaDisk();
        $one = $this->makeClient('0001', '山田');
        $this->personalityToken($this->trainee($one, 'ポチ'));
        $three = $this->makeClient('0002', '佐藤');
        foreach (['ハル', 'ナツ', 'アキ'] as $name) {
            $this->personalityToken($this->trainee($three, $name));
        }

        $count = function (Client $client): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('clients.show', $client))->assertOk();
            $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'trainee_personality_diagnosis_tokens'));
            DB::disableQueryLog();

            return $queries->count();
        };

        // 有効な案内と、いちばん新しい案内（「期限切れ」の行。2026-10-19 追加）の 2 本。トレーニーの数では増えない
        $this->assertSame(2, $count($one));
        $this->assertSame(2, $count($three));
    }

    // ---- 性格診断の行の最新の診断の日付（2026-10 追加。設計書 S-0305「性格診断の行」の「最新の診断の日付」） ----

    private function personalityResult(int $traineeId, string $answeredAt): void
    {
        $row = [
            'trainee_id' => $traineeId, 'trainee_personality_diagnosis_token_id' => null, 'answered_at' => $answeredAt,
            'question_version' => 'v1', 'answers' => json_encode(['Q01' => 0]), 'personality_type' => 'calm',
            'created_at' => now(), 'updated_at' => now(),
        ];
        foreach (['people', 'dogs', 'possessiveness', 'separation', 'fear', 'trainability', 'excitability'] as $category) {
            $row['score_'.$category] = '0.00';
        }
        DB::table('trainee_personality_diagnosis_results')->insert($row);
    }

    /** トレーニー 1 頭分の「性格診断」の行（行の始まりから、ボタンのまとまりの手前まで）の HTML */
    private function personalityLatest(string $html, int $traineeId): string
    {
        $link = route('trainees.show', $traineeId).'#personality-diagnosis';
        $start = strpos($html, '<a href="'.$link.'"');
        $this->assertNotFalse($start);
        // 日付の span の終わりまで（2026-10-19 変更：行のボタンのまとまりをなくした）
        $end = strpos($html, '</span>', strpos($html, 'data-personality-diagnosis-latest', $start)) + strlen('</span>');

        return substr($html, $start, $end - $start);
    }

    public function test_性格診断の結果があれば行に最新の回答日を出す(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->personalityResult($id, '2026-06-01 09:00:00');
        $this->personalityResult($id, '2026-10-15 10:30:00');
        $this->personalityResult($id, '2026-03-10 11:00:00');

        $row = $this->personalityLatest($this->get(route('clients.show', $client))->assertOk()->getContent(), $id);

        // いちばん新しい回答日だけを出す（トレーニー詳細のグラフの「今回」と同じ回）。時刻は出さない
        $this->assertMatchesRegularExpression('/<span data-personality-diagnosis-latest>2026\/10\/15<\/span>/u', $row);
        $this->assertStringNotContainsString('2026/06/01', $row);
        $this->assertStringNotContainsString('2026/03/10', $row);
        $this->assertStringNotContainsString('診断なし', $row);
    }

    public function test_性格診断の結果がなければ診断なしを控えめな色で出す(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');

        $row = $this->personalityLatest($this->get(route('clients.show', $client))->assertOk()->getContent(), $id);

        $this->assertMatchesRegularExpression('/<span class="text-muted" data-personality-diagnosis-latest>診断なし<\/span>/u', $row);
    }

    public function test_回答待ちの案内があっても出すのは結果の日付(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        // 結果あり＋回答待ち：結果の日付（案内の発行日は出さない）
        $withResult = $this->trainee($client, 'ポチ');
        $this->personalityResult($withResult, '2026-06-01 09:00:00');
        DB::table('trainee_personality_diagnosis_tokens')->insert([
            'token' => bin2hex(random_bytes(16)), 'trainee_id' => $withResult, 'answered_at' => null,
            'created_at' => '2026-10-20 09:00:00', 'updated_at' => '2026-10-20 09:00:00',
        ]);
        // 結果なし＋回答待ち：診断なし
        $noResult = $this->trainee($client, 'たま');
        $this->personalityToken($noResult);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $row = $this->personalityLatest($html, $withResult);
        $this->assertStringContainsString('<span data-personality-diagnosis-latest>2026/06/01</span>', $row);
        $this->assertStringNotContainsString('2026/10/20', $html);
        $this->assertStringContainsString('<span class="text-muted" data-personality-diagnosis-latest>診断なし</span>', $this->personalityLatest($html, $noResult));
        // どちらも回答待ち：「案内中」の行（2026-10-19 変更：その前は行の［案内を表示］）
        $this->assertSame(2, substr_count($html, 'data-survey-in-progress-item'));
    }

    public function test_日付は属性の値の列にそろえリンクと同じ行に置く(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->personalityResult($id, '2026-10-15 10:30:00');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 属性の並び（dl.row の dt col-4・dd col-8）と同じ row・col-4／col-8。ラベルの列にリンク、値の列の左に日付
        // （2026-10-19 変更：値の列の右のボタンのまとまりはなくした）
        $this->assertMatchesRegularExpression('/<dl class="row mb-0 small">[\s\S]*?<dt class="col-4 fw-normal text-muted">/u', $html);
        $this->assertMatchesRegularExpression(
            '/<div class="row small mt-1 align-items-center" data-personality-diagnosis-row>\s*'
            .'<div class="col-4">\s*<a href="'.preg_quote(route('trainees.show', $id), '/').'#personality-diagnosis" style="word-break: keep-all; overflow-wrap: normal;"\s*aria-label="ポチの愛犬性格診断">愛犬<wbr>性格診断<\/a>\s*<\/div>\s*'
            .'<div class="col-8 d-flex flex-wrap align-items-center gap-2">\s*'
            .'<span data-personality-diagnosis-latest>2026\/10\/15<\/span>\s*<\/div>\s*<\/div>/u',
            $html
        );
        // 日付には読み上げ用の特別な指定を付けない（普通の文字として読まれる）
        $this->assertDoesNotMatchRegularExpression('/data-personality-diagnosis-latest[^>]*aria-/u', $html);
    }

    public function test_性格診断の結果の読み込みはトレーニーの数で増えない(): void
    {
        $this->fakeMediaDisk();
        $one = $this->makeClient('0001', '山田');
        $this->personalityResult($this->trainee($one, 'ポチ'), '2026-10-15 10:30:00');
        $three = $this->makeClient('0002', '佐藤');
        foreach (['ハル', 'ナツ', 'アキ'] as $name) {
            $id = $this->trainee($three, $name);
            $this->personalityResult($id, '2026-06-01 09:00:00');
            $this->personalityResult($id, '2026-10-15 10:30:00');
        }

        $count = function (Client $client): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('clients.show', $client))->assertOk();
            $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'trainee_personality_diagnosis_results'));
            DB::disableQueryLog();

            return $queries->count();
        };

        // トレーニーの結果（1 本）と、記録の一覧に混ぜる公開済みの評価文（1 本。2026-10-18 追加）。トレーニーが何頭いても増えない
        $this->assertSame(2, $count($one));
        $this->assertSame(2, $count($three));
    }

    // ---- 連絡先の緊急連絡先（ご家族等）（2026-10-17 変更：その前は「電話番号（予備）」。
    //      2026-10-18 変更：続柄を別の項目にせず「電話番号（続柄）」の 1 つの値で出す） ----

    public function test_会員詳細の緊急連絡先は電話番号と続柄を1つの値で出し続柄の項目は出さない(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');

        foreach ([
            '両方' => ['090-1111-2222', '母', '090-1111-2222（母）'],
            '電話番号だけ' => ['090-1111-2222', null, '090-1111-2222'],
            '続柄だけ' => [null, '母', '（続柄：母）'],
            'どちらもない' => [null, null, ''],
        ] as $case => [$phone, $relationship, $expected]) {
            $client->forceFill(['phone1' => '03-0000-0000', 'phone2' => $phone, 'emergency_contact_relationship' => $relationship])->save();

            $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/緊急連絡先（ご家族等）<\/div>\s*<div style="min-height: 1.5rem;">\s*'.preg_quote($expected, '/').'\s*<\/div>/u',
                $html,
                $case
            );
            $this->assertStringNotContainsString('>続柄</div>', $html, $case);
            $this->assertStringNotContainsString('電話番号（予備）', $html, $case);
        }
    }

    public function test_会員詳細の連絡先は2行目の3列目を1行目と同じく空ける(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();
        $start = strpos($html, '<h6 class="mb-0">連絡先</h6>');
        $card = substr($html, $start, strpos($html, '最終更新', $start) - $start);

        // 郵便番号・住所・（空き）／電話番号・緊急連絡先（続柄の列はない）
        $this->assertSame(5, substr_count($card, '<div class="col-6 col-md-4">'));
        $this->assertSame(4, substr_count($card, '<div class="text-muted small mb-1">'));
        $this->assertTrue(strpos($card, '>電話番号</div>') < strpos($card, '>緊急連絡先（ご家族等）</div>'));
    }

    // ---- 愛犬性格診断の名前（2026-10-18 変更：正式名称に決まった。その前は「性格診断」） ----

    public function test_会員詳細は愛犬性格診断の名前で出し性格診断は単独で出さない(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->personalityToken($id);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="ポチの愛犬性格診断">愛犬<wbr>性格診断</a>', $html);
        // 「案内中」の行（2026-10-19 変更：その前は行のボタンのまとまり）
        $this->assertStringContainsString('<span class="trainee-survey-line">愛犬性格診断</span>', $this->inProgressRow($html, 'ポチ'));
        // 取消の確認（M07）
        $this->assertStringContainsString('愛犬性格診断の案内の取消', $html);
        $this->assertOnlyFullPersonalityDiagnosisName($html);
    }

    public function test_会員詳細の愛犬性格診断の名前は文のファイルの1か所から読む(): void
    {
        $this->fakeMediaDisk();
        $this->overridePersonalityDiagnosisName('テスト診断');
        $client = $this->makeClient('0001', '山田');
        $this->trainee($client, 'ポチ');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="ポチのテスト診断">テスト診断</a>', $html);
        $this->assertStringNotContainsString('性格診断', $html);
    }

    // ---- 愛犬性格診断のラベルの折り返し（2026-10-18 追加：「愛犬」と「性格診断」の間でだけ折り返す） ----

    public function test_会員詳細の愛犬性格診断のラベルは愛犬と性格診断の間でだけ折り返す(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $this->trainee($client, 'ポチ');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 言葉の途中では折り返さない指定（keep-all）と、「愛犬」と「性格診断」の間の折り返しの印（<wbr>）。読み上げの名前は変えない
        $this->assertMatchesRegularExpression(
            '/<a href="[^"]*#personality-diagnosis" style="word-break: keep-all; overflow-wrap: normal;"\s*aria-label="ポチの愛犬性格診断">愛犬<wbr>性格診断<\/a>/u',
            $html
        );
        $this->assertSame(1, substr_count($html, '<wbr>性格診断'));
    }

    public function test_名前と分けた部分を差し替えるとラベルもその位置で折り返す(): void
    {
        $this->fakeMediaDisk();
        // 先に文のファイル（messages）を読み込んでおく（読み込む前に addLines すると、ファイルのほかの文が読まれなくなる。
        // 2026-10-19 追加：組み合わせの名前のひな形〔配列〕が読めずに止まるため）
        app('translator')->load('*', 'messages', 'ja');
        app('translator')->addLines([
            'messages.personality_diagnosis_name' => 'テスト診断',
            'messages.personality_diagnosis_name_parts' => ['テスト', '診断'],
        ], 'ja');
        $client = $this->makeClient('0001', '山田');
        $this->trainee($client, 'ポチ');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="ポチのテスト診断">テスト<wbr>診断</a>', $html);
        $this->assertStringNotContainsString('性格診断', $html);
    }

    public function test_ラベルの各部分は逃がしてから折り返しの印でつなぐ(): void
    {
        // 文のファイルの決まった文字だけを出す作りだが、もし HTML の記号が入っても、そのまま出さずに逃がす
        $this->fakeMediaDisk();
        // 先に文のファイル（messages）を読み込んでおく（読み込む前に addLines すると、ファイルのほかの文が読まれなくなる。
        // 2026-10-19 追加：組み合わせの名前のひな形〔配列〕が読めずに止まるため）
        app('translator')->load('*', 'messages', 'ja');
        app('translator')->addLines([
            'messages.personality_diagnosis_name' => '<b>愛犬</b>性格診断',
            'messages.personality_diagnosis_name_parts' => ['<b>愛犬</b>', '性格診断'],
        ], 'ja');
        $client = $this->makeClient('0001', '山田');
        $this->trainee($client, 'ポチ');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringContainsString('>&lt;b&gt;愛犬&lt;/b&gt;<wbr>性格診断</a>', $html);
        $this->assertStringNotContainsString('<b>愛犬</b>', $html);
    }

    public function test_トレーニーの欄の長いラベルは言葉の間でだけ折り返す(): void
    {
        // 「最終計測」「去勢・避妊」は狭い幅（992px・375px）でラベルの列に 1 行で収まらないので、「最終／計測」「去勢・／避妊」の
        // 位置でだけ折り返す（2026-10-18 追加。「愛犬性格診断」のリンクと同じやり方）。2〜3 文字のラベルには付けない
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $this->trainee($client, 'ポチ');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $keepAll = '<dt class="col-4 fw-normal text-muted" style="word-break: keep-all; overflow-wrap: normal;">';
        $this->assertStringContainsString($keepAll.'去勢・<wbr>避妊</dt>', $html);
        $this->assertStringContainsString($keepAll.'最終<wbr>計測</dt>', $html);
        foreach (['犬種', '性別', '誕生日'] as $label) {
            $this->assertStringContainsString('<dt class="col-4 fw-normal text-muted">'.$label.'</dt>', $html);
        }
        // 長いラベルはこの 2 つだけ（印のないまま 4 文字以上のラベルが残っていない）
        preg_match_all('/<dt class="col-4 fw-normal text-muted">([^<]+)<\/dt>/u', $html, $m);
        foreach ($m[1] as $label) {
            $this->assertLessThanOrEqual(3, mb_strlen($label), $label);
        }
    }

    // ---- 愛犬アンケートの名前（2026-10-19 変更：その前は「受付アンケート」） ----

    // ---- 答え直し（2026-10-19 追加：回答したあとも、いつでも案内を発行し直して答え直してもらえる） ----

    // ---- 有効期限（2026-10-19 追加：案内に 1 週間の有効期限を付けた。「今日」を固定する） ----

    // ---- 案内の操作（2026-10-19 変更：トレーニーの欄の［案内を発行］1 つとモーダル M08・「案内中」の行。設計書 S-0305 セクション3「案内の操作」） ----

    /** トレーニーの欄の名前の行の［案内を発行］（モーダル M08 を開く）の正規表現 */
    private function issueButtonPattern(int $traineeId, string $name): string
    {
        return '/<button type="button" class="btn btn-sm btn-primary text-nowrap ms-auto"\s*data-bs-toggle="modal" data-bs-target="#surveyIssueModal"\s*'
            .'data-survey-issue-trainee-id="'.$traineeId.'"\s*data-survey-issue-trainee-name="'.preg_quote($name, '/').'"\s*'
            .'aria-label="'.preg_quote($name, '/').'の案内を発行">案内を発行<\/button>/u';
    }

    /** 名前の行の右のまとまり（案内中でないとき：期限切れの表示と［案内を発行］の段）の始まりの正規表現の一部 */
    private function rightOfNamePrefix(): string
    {
        return '<div class="d-flex flex-column align-items-end gap-1 ms-auto small">\s*<div class="d-flex flex-wrap align-items-center justify-content-end gap-2">\s*';
    }

    /** トレーニー 1 頭分の「案内中」の表示（名前の行の右のまとまり）の HTML（なければ null） */
    private function inProgressRow(string $html, string $name): ?string
    {
        // まとまりの中の最後の段の［取消］とボタンのまとまりの閉じから、段・まとまり・右の入れ物・名前の行の閉じまで
        $pattern = '/aria-label="'.preg_quote($name, '/').'の案内中の案内" data-survey-in-progress>(.*?<\/button>\s*<\/div>)\s*<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/su';

        return preg_match($pattern, $html, $m) === 1 ? $m[1] : null;
    }

    public function test_回答待ちがなければ名前の行に案内を発行のボタン1つを出し種類ごとのボタンは出さない(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $unnamed = $this->trainee($client, null);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 名前の行の右に［案内を発行］（塗り。読み上げ用に「{表示名}の案内を発行」）
        $this->assertMatchesRegularExpression(
            '/<h6 class="mb-0 text-break trainee-survey-line"><a href="'.preg_quote(route('trainees.show', $id), '/').'">ポチ<\/a><\/h6>\s*'
            .$this->rightOfNamePrefix().trim($this->issueButtonPattern($id, 'ポチ'), '/u').'\s*<\/div>\s*<\/div>\s*<\/div>/u',
            $html
        );
        $this->assertMatchesRegularExpression($this->issueButtonPattern($unnamed, '名前未登録'), $html);
        // 前の種類ごとのボタン・まとまり・「案内中」の行は出さない
        foreach (['愛犬アンケートの案内を発行', '愛犬アンケートの案内を表示', '>案内を表示</a>', 'の愛犬アンケート"', 'role="group" aria-label="ポチの愛犬性格診断"', 'data-survey-in-progress', '>取消</button>'] as $text) {
            $this->assertStringNotContainsString($text, $html, $text);
        }
        $this->assertSame(0, substr_count($html, 'action="'.route('trainee-intake-survey-tokens.store', $id).'"'));
        $this->assertSame(0, substr_count($html, 'action="'.route('trainee-personality-diagnosis-tokens.store', $id).'"'));
    }

    public function test_回答済みや回答のない回答済みの案内だけなら案内を発行を出す(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        // 愛犬アンケートに回答済み・愛犬性格診断も回答済みの案内だけ
        $answered = $this->trainee($client, 'ポチ');
        $this->intakeResponse($answered, $this->intakeToken($answered, '2026-10-16 14:32:00'));
        $this->personalityToken($answered, '2026-10-16 15:00:00');
        // 回答のない回答済みの案内しかない（回答済みのトークンは有効な案内として数えない）
        $tokenOnly = $this->trainee($client, 'たま');
        $this->intakeToken($tokenOnly, '2026-10-16 10:00:00');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 回答済みでも塗り（2026-10-19 変更：その前は回答済みの［愛犬アンケートの案内を発行］は白抜き）
        $this->assertMatchesRegularExpression($this->issueButtonPattern($answered, 'ポチ'), $html);
        $this->assertMatchesRegularExpression($this->issueButtonPattern($tokenOnly, 'たま'), $html);
        $this->assertStringNotContainsString('data-survey-in-progress', $html);
        // 暮らしの様子のラベルのリンク（と「入力あり」）は回答があるときだけ
        $this->assertMatchesRegularExpression($this->lifeLinkPattern($answered, 'ポチ'), $html);
        $this->assertSame(1, substr_count($html, '>暮らしの<wbr>様子</a>'));
    }

    public function test_回答待ちがあれば案内を発行を出さず案内中の行に種類の名前と表示と取消を出し期限の日は出さない(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->intakeToken($id);
        DB::table('trainee_intake_survey_tokens')->update(['expires_at' => '2026-10-26 23:59:59']);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-survey-issue-trainee-id="'.$id.'"', $html);
        $row = $this->inProgressRow($html, 'ポチ');
        $this->assertNotNull($row);
        // 名前の行の右（2026-10-19 変更：その前は名前の行のすぐ下の別の行。さらにその前は欄のいちばん下に区切り線つき）に、
        // 「案内中」（太字）・種類の名前・［表示］［取消］。名前の下の別の行はない。
        // 期限つきの案内でも期限の日は出さない（2026-10-19 変更：その前は「愛犬アンケート（10/26まで）」）
        $this->assertMatchesRegularExpression(
            '/>ポチ<\/a><\/h6>\s*<div class="d-flex flex-column align-items-end gap-1 ms-auto small">\s*'
            .'<div class="d-flex flex-column align-items-end gap-1" role="group"\s*aria-label="ポチの案内中の案内" data-survey-in-progress>\s*'
            .'<div class="d-flex flex-wrap align-items-center justify-content-end gap-2" data-survey-in-progress-item>\s*'
            .'<div class="d-flex align-items-center gap-2">\s*<span class="trainee-survey-line fw-bold text-nowrap">案内中<\/span>\s*<span class="trainee-survey-line">愛犬アンケート<\/span>/u',
            $html
        );
        $this->assertStringNotContainsString('class="row small mb-2"', $html);
        $this->assertStringNotContainsString('<div class="col-4 fw-bold">', $html);
        $this->assertStringNotContainsString('border-top', substr($html, strpos($html, 'data-survey-in-progress'), 2000));
        $this->assertStringContainsString('<span class="trainee-survey-line">愛犬アンケート</span>', $row);
        $this->assertStringNotContainsString('まで）', $row);
        $this->assertStringNotContainsString('10/26', $row);
        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote(route('trainee-intake-survey-tokens.print', $id), '/').'" class="btn btn-sm btn-outline-primary text-nowrap"\s*aria-label="愛犬アンケートの案内を表示">表示<\/a>\s*'
            .'<button type="button" class="btn btn-sm btn-outline-danger text-nowrap"\s*data-bs-toggle="modal" data-bs-target="#intakeSurveyCancelModal"\s*'
            .'data-intake-survey-trainee-id="'.$id.'"\s*aria-label="愛犬アンケートの案内を取消">取消<\/button>/u',
            $row
        );
        $this->assertStringNotContainsString('愛犬性格診断', $row);
        // 「案内中」の行は名前より後ろ、犬種（属性の並び）・性格診断の行より前
        $this->assertGreaterThan(strpos($html, '>ポチ</a></h6>'), strpos($html, 'data-survey-in-progress'));
        $this->assertLessThan(strpos($html, '<dt class="col-4 fw-normal text-muted">犬種</dt>'), strpos($html, 'data-survey-in-progress'));
        $this->assertLessThan(strpos($html, 'data-personality-diagnosis-row'), strpos($html, 'data-survey-in-progress'));
    }

    public function test_期限が空の案内も案内中の行は期限つきと同じ見た目(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->personalityToken($id);

        $row = $this->inProgressRow($this->get(route('clients.show', $client))->assertOk()->getContent(), 'ポチ');

        $this->assertNotNull($row);
        $this->assertStringContainsString('<span class="trainee-survey-line">愛犬性格診断</span>', $row);
        $this->assertStringNotContainsString('まで）', $row);
        $this->assertStringContainsString('data-bs-target="#personalityDiagnosisCancelModal"', $row);
        $this->assertStringContainsString('data-personality-diagnosis-trainee-id="'.$id.'"', $row);
        $this->assertStringContainsString('href="'.route('trainee-personality-diagnosis-tokens.print', $id).'"', $row);
    }

    public function test_前の決まりで回答待ちが2つあれば案内中の行を2つ出し案内を発行を出さない(): void
    {
        $this->travelTo(Carbon::parse('2026-10-20 10:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->personalityToken($id);
        $this->intakeToken($id);
        DB::table('trainee_personality_diagnosis_tokens')->update(['expires_at' => '2026-10-25 23:59:59']);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $row = $this->inProgressRow($html, 'ポチ');
        $this->assertNotNull($row);
        $this->assertSame(2, substr_count($row, 'data-survey-in-progress-item'));
        // 愛犬アンケート → 愛犬性格診断の順
        $this->assertLessThan(strpos($row, '<span class="trainee-survey-line">愛犬性格診断</span>'), strpos($row, '<span class="trainee-survey-line">愛犬アンケート</span>'));
        $this->assertStringNotContainsString('まで）', $row);
        $this->assertSame(2, substr_count($row, '>表示</a>'));
        $this->assertSame(2, substr_count($row, '>取消</button>'));
        // 2 行とも名前の行のすぐ下、犬種の行の上（2026-10-19 変更）
        $posRow = strpos($html, 'data-survey-in-progress>');
        $this->assertGreaterThan(strpos($html, '>ポチ</a></h6>'), $posRow);
        $this->assertLessThan(strpos($html, '<dt class="col-4 fw-normal text-muted">犬種</dt>'), strrpos($html, 'data-survey-in-progress-item'));
        $this->assertStringNotContainsString('data-survey-issue-trainee-id="'.$id.'"', $html);
    }

    public function test_期限が切れた案内は回答待ちにせず案内を発行を出し期限の日のうちは案内中(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->intakeToken($id);
        $this->personalityToken($id);
        DB::table('trainee_intake_survey_tokens')->update(['expires_at' => '2026-10-26 23:59:59']);
        DB::table('trainee_personality_diagnosis_tokens')->update(['expires_at' => '2026-10-26 23:59:59']);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertMatchesRegularExpression($this->issueButtonPattern($id, 'ポチ'), $html);
        $this->assertNull($this->inProgressRow($html, 'ポチ'));
        $this->assertSame(Trainee::INTAKE_NOT_ISSUED, Trainee::query()->with(['latestIntakeSurveyResponse', 'activeIntakeSurveyToken'])->find($id)->intake_survey_status);

        // 期限の日のうちは回答待ち（「案内中」の行が 2 つ、［案内を発行］は出さない）
        $this->travelTo(Carbon::parse('2026-10-26 23:59:59'));
        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();
        $this->assertSame(2, substr_count((string) $this->inProgressRow($html, 'ポチ'), 'data-survey-in-progress-item'));
        $this->assertStringNotContainsString('data-survey-issue-trainee-id="'.$id.'"', $html);
    }

    public function test_回答済みで答え直しの案内があれば案内中の行と暮らしの様子のリンクを出す(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->intakeResponse($id, $this->intakeToken($id, '2026-10-16 14:32:00'));
        $this->intakeToken($id);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringContainsString('<span class="trainee-survey-line">愛犬アンケート</span>', (string) $this->inProgressRow($html, 'ポチ'));
        $this->assertStringNotContainsString('data-survey-issue-trainee-id="'.$id.'"', $html);
        // 答え直しの案内を出している間も、前回の答え（暮らしの様子）を見られる
        $this->assertMatchesRegularExpression($this->lifeLinkPattern($id, 'ポチ'), $html);
    }

    public function test_案内の発行のモーダルは1つで2つの種類を選び選ぶまで発行するを押せず送り先はひな形から作る(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $a = $this->trainee($client, 'ポチ');
        $b = $this->trainee($client, 'たま');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="surveyIssueModal"'));
        $this->assertMatchesRegularExpression('/<h5 class="modal-title" id="surveyIssueModalLabel"><span data-survey-issue-name><\/span>の案内を発行<\/h5>/u', $html);
        // 種類は 2 つのラジオボタン。開いたときはどちらも選ばない
        $this->assertMatchesRegularExpression('/<input class="form-check-input" type="radio" name="survey_kind" id="surveyIssueKindIntake" value="intake">\s*<label class="form-check-label" for="surveyIssueKindIntake">愛犬アンケート<\/label>/u', $html);
        $this->assertMatchesRegularExpression('/<input class="form-check-input" type="radio" name="survey_kind" id="surveyIssueKindPersonality" value="personality">\s*<label class="form-check-label" for="surveyIssueKindPersonality">愛犬性格診断<\/label>/u', $html);
        $this->assertStringNotContainsString('name="survey_kind" id="surveyIssueKindIntake" value="intake" checked', $html);
        // ［キャンセル］（グレーの白抜き）と［発行する］（塗り。選ぶまで押せない）
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">キャンセル<\/button>\s*<button type="submit" class="btn btn-primary" id="surveyIssueSubmit" disabled>発行する<\/button>/u', $html);
        // 送り先は種類ごとの今の発行のルート（トレーニーの id を JS で入れる）
        $this->assertStringContainsString(json_encode(route('trainee-intake-survey-tokens.store', ['trainee' => '__ID__'])), $html);
        $this->assertStringContainsString(json_encode(route('trainee-personality-diagnosis-tokens.store', ['trainee' => '__ID__'])), $html);
        $this->assertStringContainsString('data-survey-issue-trainee-id="'.$a.'"', $html);
        $this->assertStringContainsString('data-survey-issue-trainee-id="'.$b.'"', $html);
        // 二重送信を防ぐ
        $this->assertStringContainsString('submitEl.disabled = true;', $html);
    }

    public function test_案内の種類の名前は文のファイルの1か所から読む(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $this->trainee($client, 'ポチ');
        $waiting = $this->trainee($client, 'タマ');
        $this->intakeToken($waiting);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();
        $this->assertIntakeSurveyNameOnly($html);

        $this->overrideIntakeSurveyName('テストアンケート');
        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();
        $this->assertStringContainsString('for="surveyIssueKindIntake">テストアンケート</label>', $html);
        $this->assertStringContainsString('<span class="trainee-survey-line">テストアンケート</span>', (string) $this->inProgressRow($html, 'タマ'));
        $this->assertStringContainsString('発行済みのテストアンケートの案内が使えなくなります。', $html);
        $this->assertStringNotContainsString('愛犬アンケート', $html);
    }

    // ---- 「期限切れ」の行と上下のそろえ（2026-10-19 追加。設計書 S-0305 セクション3「案内の操作」） ----

    /** トレーニー 1 頭分の「期限切れ」の表示（名前の行の右）の HTML（なければ null） */
    private function expiredRow(string $html, string $name): ?string
    {
        // ラベル「期限切れ」から、値（種類の名前）の span とまとまりの閉じまで（［案内を発行］はまとまりの外）
        $pattern = '/aria-label="'.preg_quote($name, '/').'の期限切れの案内" data-survey-expired>(.*?<\/span>\s*<\/div>)/su';

        return preg_match($pattern, $html, $m) === 1 ? $m[1] : null;
    }

    public function test_いちばん新しい案内が未回答で期限切れなら期限切れの行を出しボタンはなく案内を発行は出す(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->personalityToken($id);
        DB::table('trainee_personality_diagnosis_tokens')->update(['expires_at' => '2026-10-26 23:59:59', 'created_at' => '2026-10-19 10:00:00']);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $row = $this->expiredRow($html, 'ポチ');
        $this->assertNotNull($row);
        // 名前の行の右（2026-10-19 変更：その前は名前の行のすぐ下の別の行）に、「期限切れ」（太字の赤）・種類の名前（日付なし）、
        // その右に［案内を発行］。ラベル・値とも上下をそろえる箱の中。名前の下の別の行はない
        $this->assertMatchesRegularExpression(
            '/>ポチ<\/a><\/h6>\s*'.$this->rightOfNamePrefix()
            .'<div class="d-flex align-items-center gap-2" role="group"\s*aria-label="ポチの期限切れの案内" data-survey-expired>\s*'
            .'<span class="trainee-survey-line fw-bold text-danger text-nowrap">期限切れ<\/span>\s*'
            .'<span class="trainee-survey-line">愛犬性格診断<\/span>\s*<\/div>\s*'
            .trim($this->issueButtonPattern($id, 'ポチ'), '/u').'\s*<\/div>\s*<\/div>\s*<\/div>/u',
            $html
        );
        $this->assertStringNotContainsString('class="row small mb-2"', $html);
        $this->assertLessThan(strpos($html, '<dt class="col-4 fw-normal text-muted">犬種</dt>'), strpos($html, 'data-survey-expired'));
        $this->assertStringNotContainsString('<a', $row);
        $this->assertStringNotContainsString('<button', $row);
        $this->assertStringNotContainsString('10/26', $row);
        $this->assertNull($this->inProgressRow($html, 'ポチ'));
    }

    public function test_新しく発行すると期限切れの行は消え案内中の行になる(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 10:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->intakeToken($id, null, '2026-10-19 10:00:00');
        DB::table('trainee_intake_survey_tokens')->update(['expires_at' => '2026-10-26 23:59:59']);
        $this->assertNotNull($this->expiredRow($this->get(route('clients.show', $client))->assertOk()->getContent(), 'ポチ'));

        $this->post(route('trainee-personality-diagnosis-tokens.store', $id))->assertSessionHas('success');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();
        $this->assertNull($this->expiredRow($html, 'ポチ'));
        $this->assertStringContainsString('<span class="trainee-survey-line">愛犬性格診断</span>', (string) $this->inProgressRow($html, 'ポチ'));
    }

    public function test_いちばん新しい案内が回答済みや期限が空なら期限切れの行を出さない(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        // 前に期限切れの愛犬アンケートがあっても、いちばん新しい愛犬性格診断が回答済みなら出さない
        $answered = $this->trainee($client, 'ポチ');
        $this->intakeToken($answered, null, '2026-10-10 10:00:00');
        DB::table('trainee_intake_survey_tokens')->where('trainee_id', $answered)->update(['expires_at' => '2026-10-17 23:59:59']);
        $pdId = $this->personalityToken($answered, '2026-10-20 10:00:00');
        DB::table('trainee_personality_diagnosis_tokens')->where('id', $pdId)->update(['created_at' => '2026-10-19 10:00:00', 'expires_at' => '2026-10-26 23:59:59']);
        // 期限が空の案内（前に発行した期限なしのもの）は期限切れにならない：回答待ち（案内中）
        $noExpiry = $this->trainee($client, 'たま');
        $this->intakeToken($noExpiry, null, '2026-09-01 10:00:00');
        // 案内がない
        $this->trainee($client, 'ハル');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-survey-expired', $html);
        $this->assertNotNull($this->inProgressRow($html, 'たま'));
    }

    public function test_2つの種類のうち新しく発行した方で期限切れの種類が決まる(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->intakeToken($id, '2026-10-12 10:00:00', '2026-10-10 10:00:00');
        $pdId = $this->personalityToken($id);
        DB::table('trainee_personality_diagnosis_tokens')->where('id', $pdId)->update(['created_at' => '2026-10-19 10:00:00', 'expires_at' => '2026-10-26 23:59:59']);

        $row = $this->expiredRow($this->get(route('clients.show', $client))->assertOk()->getContent(), 'ポチ');

        $this->assertStringContainsString('>愛犬性格診断</span>', (string) $row);
        $this->assertStringNotContainsString('愛犬アンケート', (string) $row);
    }

    public function test_いちばん新しい案内の読み込みはトレーニーの数で増えない(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));
        $this->fakeMediaDisk();
        $one = $this->makeClient('0001', '山田');
        $this->intakeToken($this->trainee($one, 'ポチ'), null, '2026-10-19 10:00:00');
        $three = $this->makeClient('0002', '佐藤');
        foreach (['ハル', 'ナツ', 'アキ'] as $name) {
            $this->intakeToken($this->trainee($three, $name), null, '2026-10-19 10:00:00');
        }
        DB::table('trainee_intake_survey_tokens')->update(['expires_at' => '2026-10-26 23:59:59']);

        $count = function (Client $client): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $html = $this->get(route('clients.show', $client))->assertOk()->getContent();
            $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'trainee_intake_survey_tokens'));
            DB::disableQueryLog();
            $this->assertStringContainsString('data-survey-expired', $html);

            return $queries->count();
        };

        // 有効な案内といちばん新しい案内の 2 本。トレーニーの数では増えない
        $this->assertSame(2, $count($one));
        $this->assertSame(2, $count($three));
    }

    public function test_案内中の行はラベルと値を上下をそろえる箱に入れる(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->intakeToken($id);
        $this->personalityToken($id);

        $row = (string) $this->inProgressRow($this->get(route('clients.show', $client))->assertOk()->getContent(), 'ポチ');

        // 段ごとのラベル「案内中」（2 つ）と 2 つの値がどれも .trainee-survey-line（小さいボタンと同じ高さで縦の中央に置く。
        // 名前の h6 も同じ箱なので、名前と 1 段目の中央がそろう）
        $this->assertSame(2, substr_count($row, '<span class="trainee-survey-line fw-bold text-nowrap">案内中</span>'));
        $this->assertSame(2, substr_count($row, '<span class="trainee-survey-line">'));
    }

    // ---- 案内中・期限切れの表示を名前の行の右に（2026-10-19 変更。設計書 S-0305 セクション3「案内の操作」） ----

    /** トレーニー 1 頭分の、名前の h6 から属性の並び（犬種の dl）の手前までの HTML */
    private function nameRowToBreed(string $html, string $name): string
    {
        $start = strpos($html, '>'.$name.'</a></h6>');
        $this->assertNotFalse($start);
        $end = strpos($html, '<dl class="row mb-0 small">', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    public function test_案内中と期限切れの表示は名前の行の中にあり名前の下の別の行はない(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $active = $this->trainee($client, 'ポチ');
        $expired = $this->trainee($client, 'タマ');
        $this->intakeToken($active);
        $this->personalityToken($expired);
        DB::table('trainee_personality_diagnosis_tokens')->where('trainee_id', $expired)
            ->update(['expires_at' => '2026-10-26 23:59:59', 'created_at' => '2026-10-19 10:00:00']);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        foreach (['ポチ' => 'data-survey-in-progress', 'タマ' => 'data-survey-expired'] as $name => $marker) {
            $segment = $this->nameRowToBreed($html, $name);
            // 名前より後、犬種より前にあり、名前の行の閉じ（</div>）より前で終わる：名前の行の閉じの後は属性の並びまで空白だけ
            $this->assertStringContainsString($marker, $segment);
            $this->assertMatchesRegularExpression('/<\/div>\s*<\/div>\s*<\/div>\s*(\{\{--.*?--\}\})?\s*$/su', $segment);
            $this->assertStringNotContainsString('class="row', $segment);
        }
    }

    public function test_期限切れは名前の行に期限切れの表示と案内を発行を出し案内中は案内を発行を出さない(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $active = $this->trainee($client, 'ポチ');
        $expired = $this->trainee($client, 'タマ');
        $this->intakeToken($active);
        $this->personalityToken($expired);
        DB::table('trainee_personality_diagnosis_tokens')->where('trainee_id', $expired)
            ->update(['expires_at' => '2026-10-26 23:59:59', 'created_at' => '2026-10-19 10:00:00']);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $expiredSegment = $this->nameRowToBreed($html, 'タマ');
        $this->assertStringContainsString('>期限切れ</span>', $expiredSegment);
        $this->assertMatchesRegularExpression($this->issueButtonPattern($expired, 'タマ'), $expiredSegment);
        // 期限切れの表示は［案内を発行］の左
        $this->assertLessThan(strpos($expiredSegment, 'data-survey-issue-trainee-id'), strpos($expiredSegment, 'data-survey-expired'));

        $activeSegment = $this->nameRowToBreed($html, 'ポチ');
        $this->assertStringContainsString('>案内中</span>', $activeSegment);
        $this->assertStringNotContainsString('data-survey-issue-trainee-id', $activeSegment);
        $this->assertStringNotContainsString('data-survey-expired', $activeSegment);
    }

    public function test_案内中が2つのときは2つとも名前の行の中に縦に並べる(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->intakeToken($id);
        $this->personalityToken($id);

        $segment = $this->nameRowToBreed($this->get(route('clients.show', $client))->assertOk()->getContent(), 'ポチ');

        // 縦に並べるまとまり（flex-column）の中に 2 段
        $this->assertStringContainsString('<div class="d-flex flex-column align-items-end gap-1" role="group"', $segment);
        $this->assertSame(2, substr_count($segment, 'data-survey-in-progress-item'));
        $this->assertSame(2, substr_count($segment, '>案内中</span>'));
        // ［表示］［取消］は段ごとに 1 つのまとまり（狭い幅でも 2 つ一緒に折り返す）
        $this->assertSame(2, substr_count($segment, '<div class="d-flex gap-2">'));
        $this->assertStringContainsString('>愛犬アンケート</span>', $segment);
        $this->assertStringContainsString('>愛犬性格診断</span>', $segment);
    }

    // ---- 名前の下の項目の並び・暮らしの様子のバッジ・最終計測の「計測なし」（2026-10-19 変更。設計書 S-0305 セクション3「名前の下の項目の並びと出し方」） ----

    public function test_項目の並びは犬種性別誕生日去勢避妊暮らしの様子愛犬性格診断最終計測(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->intakeResponse($id, $this->intakeToken($id, '2026-10-16 14:32:00'));

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $markers = [
            '<dt class="col-4 fw-normal text-muted">犬種</dt>',
            '<dt class="col-4 fw-normal text-muted">性別</dt>',
            '<dt class="col-4 fw-normal text-muted">誕生日</dt>',
            '去勢・<wbr>避妊</dt>',
            '>暮らしの<wbr>様子</a></dt>',
            'data-personality-diagnosis-row',
            '最終<wbr>計測</dt>',
        ];
        $positions = array_map(fn ($marker) => strpos($html, $marker), $markers);
        foreach ($positions as $i => $position) {
            $this->assertNotFalse($position, $markers[$i]);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    public function test_暮らしの様子は回答があれば入力ありのバッジとラベルのリンクでなければ入力なしのバッジでリンクなし(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $answered = $this->trainee($client, 'ポチ');
        $this->intakeResponse($answered, $this->intakeToken($answered, '2026-10-16 14:32:00'));
        $none = $this->trainee($client, 'たま');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 回答あり：ラベルはトレーニー詳細の暮らしの様子の欄へのリンク、値は「入力あり」（bg-success）
        $this->assertMatchesRegularExpression($this->lifeLinkPattern($answered, 'ポチ'), $html);
        // 回答なし：ラベルはほかのラベルと同じ灰色の文字（リンクなし）、値は「入力なし」（bg-secondary）。行はいつも出す
        $this->assertSame(1, preg_match_all(
            '/<dt class="col-4 fw-normal text-muted" style="word-break: keep-all; overflow-wrap: normal;">暮らしの<wbr>様子<\/dt>\s*'
            .'<dd class="col-8 mb-0" data-intake-survey-status><span class="badge bg-secondary">入力なし<\/span><\/dd>/u',
            $html
        ));
        $this->assertStringNotContainsString(route('trainees.show', $none).'#intake-survey', $html);
        $this->assertSame(2, substr_count($html, 'data-intake-survey-status'));
    }

    public function test_最終計測は計測がなければ計測なしをあれば日付と体重を出す(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $measured = $this->trainee($client, 'ポチ');
        $this->trainee($client, 'たま');
        Schema::table('trainee_measurements', fn (Blueprint $table) => $table->decimal('weight_kg', 5, 2)->nullable());
        DB::table('trainee_measurements')->insert([
            'trainee_id' => $measured, 'measured_date' => '2026-10-14', 'measured_time' => '08:00:00', 'weight_kg' => 5,
        ]);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 計測あり：今までどおりの出し方（文字の色はほかの値と同じ）
        $this->assertStringContainsString('<dd class="col-8 mb-0" data-latest-measurement>2026/10/14（5.00kg）</dd>', $html);
        // 計測なし：「計測なし」（愛犬性格診断の「診断なし」と同じ text-muted）
        $this->assertSame(1, substr_count($html, '<dd class="col-8 mb-0 text-muted" data-latest-measurement>計測なし</dd>'));
        $this->assertStringContainsString('<span class="text-muted" data-personality-diagnosis-latest>診断なし</span>', $html);
    }

    // ---- 愛犬アンケート＆愛犬性格診断の案内（2026-10-19 追加。設計書 S-0305 セクション3「案内の操作」） ----

    /**
     * 組み合わせの名前の 2 行の出し方（2026-10-19 追加）：見た目は「愛犬アンケート＆」「愛犬性格診断」の 2 行（別の要素。読み上げない）、
     * 読み上げは隠した文字の 1 つの名前
     */
    private const COMBINED_NAME_LINES = '<span class="trainee-survey-line" data-survey-name-lines><span class="visually-hidden">愛犬アンケート＆愛犬性格診断</span><span class="d-flex flex-column text-nowrap" aria-hidden="true"><span>愛犬アンケート＆</span><span>愛犬性格診断</span></span></span>';

    /** 組み合わせの案内（2 つの案内を作って結び付ける）。期限は 2 つとも同じ。[愛犬アンケートの案内の id, 愛犬性格診断の案内の id] */
    private function combinedTokens(int $traineeId, string $expiresAt = '2026-10-26 23:59:59', string $createdAt = '2026-10-19 10:00:00'): array
    {
        $row = ['trainee_id' => $traineeId, 'expires_at' => $expiresAt, 'created_at' => $createdAt, 'updated_at' => $createdAt];
        $intakeId = DB::table('trainee_intake_survey_tokens')->insertGetId(['token' => bin2hex(random_bytes(16)), ...$row]);
        $personalityId = DB::table('trainee_personality_diagnosis_tokens')->insertGetId(['token' => bin2hex(random_bytes(16)), ...$row]);
        DB::table('trainee_intake_survey_tokens')->where('id', $intakeId)->update(['trainee_personality_diagnosis_token_id' => $personalityId]);

        return [$intakeId, $personalityId];
    }

    public function test_組み合わせの案内中は1つにまとめ愛犬アンケートを答えたあとは愛犬性格診断だけにする(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        [$intakeId] = $this->combinedTokens($id);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        // 2 つとも回答待ち：「案内中　愛犬アンケート＆愛犬性格診断　［表示］［取消］」の 1 段。種類の名前は「＆」のあとで改行した 2 行
        // （2026-10-19 変更：その前は 1 行）
        $row = (string) $this->inProgressRow($html, 'ポチ');
        $this->assertSame(1, substr_count($row, 'data-survey-in-progress-item'));
        $this->assertStringContainsString(self::COMBINED_NAME_LINES, $row);
        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote(route('trainee-combined-survey-tokens.print', $id), '/').'" class="btn btn-sm btn-outline-primary text-nowrap"\s*aria-label="愛犬アンケート＆愛犬性格診断の案内を表示">表示<\/a>\s*'
            .'<button type="button" class="btn btn-sm btn-outline-danger text-nowrap"\s*data-bs-toggle="modal" data-bs-target="#combinedSurveyCancelModal"\s*'
            .'data-combined-survey-trainee-id="'.$id.'"\s*aria-label="愛犬アンケート＆愛犬性格診断の案内を取消">取消<\/button>/u',
            $row
        );
        $this->assertStringNotContainsString('data-survey-issue-trainee-id="'.$id.'"', $html);
        // 取消の確認（M09）の文は、まだ答えていない分をまとめて取り消すこと
        $this->assertStringContainsString('id="combinedSurveyCancelModal"', $html);
        $this->assertStringContainsString('発行済みの愛犬アンケート＆愛犬性格診断の案内のうち、まだお答えいただいていない分がまとめて使えなくなります。', $html);
        $this->assertStringContainsString(json_encode(route('trainee-combined-survey-tokens.destroy', ['trainee' => '__ID__'])), $html);

        // 愛犬アンケートだけ答えたあと：残っている愛犬性格診断だけ（［表示］は愛犬性格診断のご案内、［取消］は愛犬性格診断の取消）
        DB::table('trainee_intake_survey_tokens')->where('id', $intakeId)->update(['answered_at' => '2026-10-19 11:00:00']);
        $row = (string) $this->inProgressRow($this->get(route('clients.show', $client))->assertOk()->getContent(), 'ポチ');
        $this->assertSame(1, substr_count($row, 'data-survey-in-progress-item'));
        $this->assertStringContainsString('<span class="trainee-survey-line">愛犬性格診断</span>', $row);
        $this->assertStringNotContainsString('＆', $row);
        $this->assertStringContainsString(route('trainee-personality-diagnosis-tokens.print', $id), $row);
        $this->assertStringContainsString('data-bs-target="#personalityDiagnosisCancelModal"', $row);
    }

    public function test_組み合わせの取消で未回答の分がまとめて消え案内を発行に戻る(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->combinedTokens($id);

        $this->delete(route('trainee-combined-survey-tokens.destroy', $id))->assertRedirect(route('clients.show', $client));

        $this->assertSame(0, DB::table('trainee_intake_survey_tokens')->count());
        $this->assertSame(0, DB::table('trainee_personality_diagnosis_tokens')->count());
        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();
        $this->assertNull($this->inProgressRow($html, 'ポチ'));
        $this->assertMatchesRegularExpression($this->issueButtonPattern($id, 'ポチ'), $html);
    }

    public function test_組み合わせの期限切れは両方なら組み合わせの名前で片方なら愛犬性格診断(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $both = $this->trainee($client, 'ポチ');
        $this->combinedTokens($both);
        $one = $this->trainee($client, 'タマ');
        [$oneIntake] = $this->combinedTokens($one);
        DB::table('trainee_intake_survey_tokens')->where('id', $oneIntake)->update(['answered_at' => '2026-10-20 09:00:00']);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringContainsString(self::COMBINED_NAME_LINES, (string) $this->expiredRow($html, 'ポチ'));
        $this->assertStringContainsString('<span class="trainee-survey-line">愛犬性格診断</span>', (string) $this->expiredRow($html, 'タマ'));
        $this->assertStringNotContainsString('＆', (string) $this->expiredRow($html, 'タマ'));
        // どちらも［案内を発行］を出す
        $this->assertMatchesRegularExpression($this->issueButtonPattern($both, 'ポチ'), $html);
        $this->assertMatchesRegularExpression($this->issueButtonPattern($one, 'タマ'), $html);
    }

    public function test_案内の発行のモーダルは3つの種類から選び組み合わせの送信先を持つ(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $this->trainee($client, 'ポチ');

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'type="radio" name="survey_kind"'));
        $this->assertMatchesRegularExpression(
            '/<input class="form-check-input" type="radio" name="survey_kind" id="surveyIssueKindCombined" value="combined">\s*'
            .'<label class="form-check-label" for="surveyIssueKindCombined">愛犬アンケート＆愛犬性格診断<\/label>/u',
            $html
        );
        $this->assertStringContainsString('combined: '.json_encode(route('trainee-combined-survey-tokens.store', ['trainee' => '__ID__'])), $html);
    }

    // ---- 組み合わせの名前を 2 行にして名前の行に収める（2026-10-19 変更。設計書 S-0305「愛犬アンケート＆愛犬性格診断の案内」） ----

    public function test_組み合わせの案内中と期限切れは2つの名前を別の行にして名前の行の中に出す(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $active = $this->trainee($client, 'ポチ');
        $this->combinedTokens($active, '2026-11-02 23:59:59', '2026-10-26 10:00:00');
        $expired = $this->trainee($client, 'タマ');
        $this->combinedTokens($expired);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        foreach (['ポチ' => 'data-survey-in-progress', 'タマ' => 'data-survey-expired'] as $name => $marker) {
            // 名前の行の中（名前より後、犬種より前）に、2 つの名前を別の要素で
            $segment = $this->nameRowToBreed($html, $name);
            $this->assertStringContainsString($marker, $segment);
            $this->assertSame(1, substr_count($segment, self::COMBINED_NAME_LINES), $name);
            $this->assertMatchesRegularExpression('/aria-hidden="true"><span>愛犬アンケート＆<\/span><span>愛犬性格診断<\/span><\/span>/u', $segment);
            // 1 行の名前の要素は出さない（見た目の文字はいつも 2 行）
            $this->assertStringNotContainsString('<span class="trainee-survey-line">愛犬アンケート＆愛犬性格診断</span>', $segment);
        }
    }

    public function test_組み合わせの読み上げの名前は1つの名前のまま(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->combinedTokens($id);

        $segment = $this->nameRowToBreed($this->get(route('clients.show', $client))->assertOk()->getContent(), 'ポチ');

        // 隠した文字で 1 つの名前を読み、行に分けた見た目の文字は読まない。ボタンの読み上げの名前も 1 つの名前
        $this->assertStringContainsString('<span class="visually-hidden">愛犬アンケート＆愛犬性格診断</span>', $segment);
        $this->assertStringContainsString('aria-label="愛犬アンケート＆愛犬性格診断の案内を表示"', $segment);
        $this->assertStringContainsString('aria-label="愛犬アンケート＆愛犬性格診断の案内を取消"', $segment);
    }

    public function test_単独の案内中は今のまま1行で名前の行は縦の真ん中そろえ(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->intakeToken($id);
        $two = $this->trainee($client, 'タマ');
        $this->intakeToken($two);
        $this->personalityToken($two);

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-survey-name-lines', $html);
        $this->assertStringContainsString('<span class="trainee-survey-line">愛犬アンケート</span>', (string) $this->inProgressRow($html, 'ポチ'));
        // 名前の行：1 段なら縦の真ん中、2 段（前の決まりの案内が 2 つ）なら上詰め（名前は 1 段目の高さ）
        $this->assertMatchesRegularExpression('/<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">\s*<h6[^>]*><a[^>]*>ポチ<\/a>/u', $html);
        $this->assertMatchesRegularExpression('/<div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">\s*<h6[^>]*><a[^>]*>タマ<\/a>/u', $html);
    }

    // ---- 案内の発行のモーダルの「マイページ登録の案内も発行する」（2026-10-19 追加。要件定義書 6-16-18） ----

    public function test_マイページが未登録か登録待ち期限切れならチェックボックスを出し登録待ちと登録待ち初回設定と利用中なら出さない(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $this->trainee($client, 'ポチ');
        $html = fn () => $this->get(route('clients.show', $client))->assertOk()->getContent();
        $checkbox = '<input class="form-check-input" type="checkbox" name="with_mypage_registration" value="1" id="surveyIssueWithMypage"';

        // 未登録（メールアドレスなし・案内なし）：最初はオフで、説明の一言を添える
        $page = $html();
        $this->assertStringContainsString($checkbox, $page);
        $this->assertDoesNotMatchRegularExpression('/id="surveyIssueWithMypage"[^>]*checked/u', $page);
        $this->assertStringContainsString('<label class="form-check-label" for="surveyIssueWithMypage">マイページ登録の案内も発行する</label>', $page);
        $this->assertStringContainsString('回答のあと、マイページの登録をご案内します。', $page);

        // 登録待ち（期限内の案内がある）：出さない（2026-10-19 変更：その前は出していた。もう 1 件発行されるように見えて分かりにくいため）
        $tokenId = DB::table('client_email_registration_tokens')->insertGetId(['client_id' => $client->id, 'is_used' => false, 'expires_at' => '2026-10-26 23:59:59']);
        $this->assertStringNotContainsString('with_mypage_registration" value', $html());
        // 登録待ち（期限切れ）：有効な案内がないので出す
        DB::table('client_email_registration_tokens')->where('id', $tokenId)->update(['expires_at' => '2026-10-18 23:59:59']);
        $this->assertStringContainsString($checkbox, $html());

        // 登録待ち（初回設定）：メールアドレスを入れて登録の途中
        DB::table('clients')->where('id', $client->id)->update(['email' => 'a@example.com']);
        $this->assertStringNotContainsString('with_mypage_registration" value', $html());

        // 利用中
        DB::table('clients')->where('id', $client->id)->update(['password' => 'x']);
        $this->assertStringNotContainsString('with_mypage_registration" value', $html());
    }

    // ---- 暮らしの様子の「入力あり」は文字が入っているかで決める（2026-10-19 変更。設計書 S-0305「名前の下の項目の並びと出し方」） ----

    /** トレーニー 1 頭分の暮らしの様子の行（ラベルとバッジ）。入力あり：['入力あり', リンクあり]、入力なし：['入力なし', リンクなし] */
    private function lifeStatus(string $html, int $traineeId): array
    {
        $linked = str_contains($html, 'href="'.route('trainees.show', $traineeId).'#intake-survey"');
        $segment = substr($html, strpos($html, 'href="'.route('trainees.show', $traineeId).'"'));
        $this->assertSame(1, preg_match('/data-intake-survey-status><span class="badge (bg-success|bg-secondary)">(入力あり|入力なし)<\/span>/u', $segment, $m));

        return [$m[2], $m[1], $linked];
    }

    public function test_暮らしの様子は自由記述のどれかに文字があれば入力ありで空や空白だけなら入力なし(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $cases = [
            // [名前, 回答（null なら回答なし）, 期待]
            ['ナシ', null, '入力なし'],
            ['カラ', ['wish' => null, 'recent_events' => '', 'ideal_days' => null, 'health_concerns' => ''], '入力なし'],
            ['クウハク', ['wish' => "  \n\t", 'recent_events' => '　　', 'ideal_days' => "\r\n　\n", 'health_concerns' => ' '], '入力なし'],
            ['ヒトツ', ['wish' => null, 'recent_events' => '', 'ideal_days' => null, 'health_concerns' => '　膝が弱い　'], '入力あり'],
            // 「愛犬のこと」（基本情報）の項目だけ入っていて、暮らしの様子は空
            ['キホン', ['name' => 'キホン', 'breed' => '柴犬', 'sex' => 'male', 'birth_year' => 2020, 'neuter_status' => 'yes'], '入力なし'],
        ];
        $ids = [];
        foreach ($cases as [$name, $answers]) {
            $ids[$name] = $this->trainee($client, $name);
            if ($answers !== null) {
                $this->intakeResponse($ids[$name], null, '2026-10-16 14:32:00', $answers);
            }
        }

        $html = $this->get(route('clients.show', $client))->assertOk()->getContent();

        foreach ($cases as [$name, , $expected]) {
            [$label, $class, $linked] = $this->lifeStatus($html, $ids[$name]);
            $this->assertSame($expected, $label, $name);
            $this->assertSame($expected === '入力あり' ? 'bg-success' : 'bg-secondary', $class, $name);
            // ラベルのリンクは入力ありのときだけ
            $this->assertSame($expected === '入力あり', $linked, $name);
        }
    }

    public function test_前の回答に文字があっても最新の回答がすべて空なら入力なし(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $id = $this->trainee($client, 'ポチ');
        $this->intakeResponse($id, null, '2026-10-10 10:00:00', ['wish' => 'お留守番が上手になってほしい']);
        $this->intakeResponse($id, null, '2026-10-16 14:32:00', ['wish' => '', 'recent_events' => null]);

        [$label, , $linked] = $this->lifeStatus($this->get(route('clients.show', $client))->assertOk()->getContent(), $id);

        $this->assertSame('入力なし', $label);
        $this->assertFalse($linked);
    }

    public function test_暮らしの様子の判定でトレーニーごとに問い合わせは増えない(): void
    {
        $this->fakeMediaDisk();
        $client = $this->makeClient('0001', '山田');
        $count = function () use ($client) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('clients.show', $client))->assertOk();
            $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'trainee_intake_survey_responses'))->count();
            DB::disableQueryLog();

            return $n;
        };
        $this->intakeResponse($this->trainee($client, 'ポチ'), null);
        $one = $count();
        foreach (['タマ', 'ハナ'] as $name) {
            $this->intakeResponse($this->trainee($client, $name), null);
        }

        $this->assertSame($one, $count());
    }
}

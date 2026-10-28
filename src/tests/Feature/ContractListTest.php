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
 * 契約一覧（S-0315。2026-10-28 追加。要件定義書 6-17-8）。
 *
 * - 一般トレーナー・管理者が見られる。システム管理者は 403、ログインしていなければログインへ
 * - 1 行＝契約 1 件。列は内部ID / 会員 / 契約日 / 回数 / 利用（割り当てた件数）/ 有効期限 / メモ。未割り当ての利用は出さない・数えない
 * - 既定の並びは契約日の新しい順（同じ並びの中は契約の id の大きい順）。契約日・内部ID・会員の列見出しで並べ替える
 * - 検索は内部ID・名前（姓名・かな）・契約日の範囲。範囲が逆なら入力エラー
 * - 20 件ずつのページ送り。期限切れで残りがある契約は有効期限を赤字（会員詳細と同じ判定）
 * - ナビに「契約」。/contracts で強調し、会員詳細では強調しない
 *
 * 「今日」は 2026-10-28 12:00 に固定する。会員詳細を開くため、会員詳細が読むテーブルも列を絞って作る（ClientCountRenderingTest と同じ）。
 */
class ContractListTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 12:00:00');

        $this->createClientsTable();
        $this->createClientCountTables();
        $this->createAccessLogsTable();
        $this->createClientEmailRegistrationTokensTable();
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });
        // 以下は会員詳細（ナビの強調の確認）が読むテーブル（ClientCountRenderingTest と同じ）
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
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $this->actingAsRole('staff');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => 'テスト', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    private function makeClient(string $internalId, string $lastName, string $firstName = '太郎', array $attributes = []): Client
    {
        return Client::query()->create(['internal_id' => $internalId, 'last_name' => $lastName, 'first_name' => $firstName, ...$attributes]);
    }

    private function contract(Client $client, string $contractedOn, int $count = 4, array $attributes = []): ClientContract
    {
        return ClientContract::query()->create(['client_id' => $client->id, 'contracted_on' => $contractedOn, 'count' => $count, ...$attributes]);
    }

    /** 利用を $times 件入れる（$contract が null なら「契約なし」） */
    private function usages(Client $client, ?ClientContract $contract, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            ClientUsage::query()->create(['client_id' => $client->id, 'client_contract_id' => $contract?->id, 'used_on' => '2026-10-01']);
        }
    }

    private function page(string $query = ''): string
    {
        return $this->get(route('contracts.index').$query)->assertOk()->getContent();
    }

    /** 表の行（tbody の tr）ごとのセルの中身（タグを除いて前後の空白を落とす） */
    private function rows(string $html): array
    {
        $this->assertSame(1, preg_match('/<table class="table table-hover" data-contracts>.*?<tbody>(.*?)<\/tbody>/s', $html, $m));
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $m[1], $trs);

        return array_map(function (string $tr) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $tr, $cells);

            return array_map(fn ($cell) => trim(strip_tags($cell)), $cells[1]);
        }, $trs[1]);
    }

    /** 各行の契約日（3 列目）だけ */
    private function contractedOns(string $html): array
    {
        return array_column($this->rows($html), 2);
    }

    // ---- 権限 ----

    public function test_一般トレーナーと管理者は見られる(): void
    {
        $this->page();
        $this->actingAsRole('admin');
        $this->page();
    }

    public function test_システム管理者は403(): void
    {
        $this->actingAsRole('system_admin');

        $this->get(route('contracts.index'))->assertForbidden();
    }

    public function test_ログインしていなければログインへ(): void
    {
        auth('web')->logout();

        $this->get(route('contracts.index'))->assertRedirect(route('login'));
    }

    // ---- 列と値 ----

    public function test_列は内部idと会員と契約日と回数と利用と有効期限とメモの順(): void
    {
        $html = $this->page();

        $this->assertSame(1, preg_match('/<table class="table table-hover" data-contracts>\s*<thead class="table-light">(.*?)<\/thead>/s', $html, $m));
        preg_match_all('/<th[^>]*>(.*?)<\/th>/s', $m[1], $ths);
        $headings = array_map(fn ($th) => trim(preg_replace('/[▲▼\s]+/u', ' ', strip_tags($th))), $ths[1]);
        $this->assertSame(['内部ID', '会員', '契約日', '回数', '利用', '有効期限', 'メモ'], $headings);
        // 新規登録のボタンは置かない（見るだけ）
        $this->assertStringNotContainsString('新規登録', $html);
    }

    public function test_1行は契約1件で利用は割り当てた件数だけを数え未割り当ての利用は出さない(): void
    {
        $client = $this->makeClient('12', '山田');
        $contract = $this->contract($client, '2026-10-01', 4, ['expires_on' => '2026-12-31', 'note' => '4回コース']);
        $this->usages($client, $contract, 3);
        $this->usages($client, null, 2); // 未割り当て

        $html = $this->page();
        $this->assertSame([['12', '山田 太郎', '2026/10/01', '4', '3', '2026/12/31', '4回コース']], $this->rows($html));
        $this->assertStringContainsString('1件', $html);
    }

    public function test_有効期限とメモが空なら空欄で利用が0件なら0(): void
    {
        $this->contract($this->makeClient('1', '山田'), '2026-10-01');

        $this->assertSame([['1', '山田 太郎', '2026/10/01', '4', '0', '', '']], $this->rows($this->page()));
    }

    public function test_メモ以外の列は折り返さずメモは全文を出す(): void
    {
        $note = str_repeat('長いメモ', 40);
        $this->contract($this->makeClient('1', '山田'), '2026-10-01', 4, ['note' => $note]);

        $html = $this->page();
        $this->assertSame(1, preg_match('/<tbody>\s*<tr[^>]*>(.*?)<\/tr>/s', $html, $m));
        preg_match_all('/<td( class="[^"]*")?>/', $m[1], $classes);
        $this->assertSame(array_fill(0, 6, ' class="text-nowrap"'), array_slice($classes[1], 0, 6));
        $this->assertSame('', $classes[1][6]);
        $this->assertStringContainsString('<td>'.$note.'</td>', $m[1]);
    }

    public function test_行全体のクリックで会員詳細へ(): void
    {
        $client = $this->makeClient('1', '山田');
        $this->contract($client, '2026-10-01');

        $this->assertStringContainsString(
            '<tr style="cursor: pointer;" onclick="location.href=\''.route('clients.show', $client).'\'">',
            $this->page()
        );
    }

    public function test_0件なら該当する契約がありませんを出す(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('<td colspan="7" class="text-center text-muted py-4">該当する契約がありません。</td>', $html);
        $this->assertStringContainsString('0件', $html);
    }

    // ---- 有効期限の赤字 ----

    public function test_期限切れで残りがある契約だけ有効期限を赤字にする(): void
    {
        $client = $this->makeClient('1', '山田');
        // 期限切れ・残りあり → 赤字
        $this->contract($client, '2026-09-04', 2, ['expires_on' => '2026-10-27']);
        // 期限切れ・使い切り → 赤字にしない
        $usedUp = $this->contract($client, '2026-09-03', 1, ['expires_on' => '2026-10-27']);
        $this->usages($client, $usedUp, 1);
        // 今日が期限 → まだ有効
        $this->contract($client, '2026-09-02', 2, ['expires_on' => '2026-10-28']);
        // 期限なし
        $this->contract($client, '2026-09-01', 2);

        $html = $this->page();
        $this->assertSame(1, substr_count($html, '<span class="text-danger">'));
        $this->assertStringContainsString('<span class="text-danger">2026/10/27</span>', $html);
        $this->assertSame(['2026/10/27', '2026/10/27', '2026/10/28', ''], array_column($this->rows($html), 5));
    }

    public function test_有効期限の判定は会員詳細と同じメソッド(): void
    {
        $client = $this->makeClient('1', '山田');
        $contract = $this->contract($client, '2026-09-01', 2, ['expires_on' => '2026-10-27']);

        $this->assertTrue($contract->isExpiredWithRemaining(false));
        $this->assertFalse($contract->isExpiredWithRemaining(true));
        $this->assertFalse($contract->isExpiredWithRemaining(false, '2026-10-27'));
        $this->assertTrue($contract->expired_with_remaining);
        $this->usages($client, $contract, 2);
        $this->assertFalse($contract->fresh()->expired_with_remaining);
    }

    // ---- 並べ替え ----

    public function test_既定は契約日の新しい順で同じ日は契約のidの大きい順(): void
    {
        $client = $this->makeClient('1', '山田');
        $first = $this->contract($client, '2026-10-10', 1);
        $this->contract($client, '2026-10-20', 2);
        $this->contract($client, '2026-10-10', 3);

        $rows = $this->rows($this->page());
        $this->assertSame(['2026/10/20', '2026/10/10', '2026/10/10'], array_column($rows, 2));
        // 同じ日は id の大きい順（後に登録した回数 3 の契約が先）
        $this->assertSame(['2', '3', '1'], array_column($rows, 3));
        $this->assertSame(1, $first->id);
        // 初期表示でも「契約日」の見出しに ▼
        $this->assertMatchesRegularExpression('/契約日\s*▼/u', $this->page());
    }

    public function test_列見出しで契約日と内部idで並べ替える(): void
    {
        $this->contract($this->makeClient('10', '山田'), '2026-10-01');
        $this->contract($this->makeClient('9', '鈴木'), '2026-10-03');
        $this->contract($this->makeClient('100', '田中'), '2026-10-02');

        $this->assertSame(['2026/10/01', '2026/10/02', '2026/10/03'], $this->contractedOns($this->page('?sort=contracted_on&direction=asc')));
        // 内部ID は数値順（文字列順なら 10・100・9）
        $this->assertSame(['9', '10', '100'], array_column($this->rows($this->page('?sort=internal_id&direction=asc')), 0));
        $this->assertSame(['100', '10', '9'], array_column($this->rows($this->page('?sort=internal_id&direction=desc')), 0));
        // 不正な列は既定（契約日の新しい順）に戻す
        $this->assertSame(['2026/10/03', '2026/10/02', '2026/10/01'], $this->contractedOns($this->page('?sort=count&direction=asc')));
    }

    public function test_列見出しのリンクは検索条件を引き継ぎページを外し検索フォームは並び順を持つ(): void
    {
        $html = $this->page('?keyword=%E5%B1%B1&page=2&sort=internal_id&direction=asc');

        // 会員（姓）の並べ替えのリンク。会員一覧と同じく日本語照合順序で並べる（MySQL の照合順序のため、並べた結果はここでは確かめない）
        $this->assertMatchesRegularExpression('/href="[^"]*\/contracts\?keyword=[^"]*sort=last_name&amp;direction=asc"/', $html);
        $this->assertMatchesRegularExpression('/href="[^"]*\/contracts\?keyword=[^"]*sort=internal_id&amp;direction=desc"/', $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*\/contracts\?[^"]*page=2[^"]*sort=/', $html);
        $this->assertStringContainsString('<input type="hidden" name="sort" value="internal_id">', $html);
        $this->assertStringContainsString('<input type="hidden" name="direction" value="asc">', $html);
    }

    // ---- 検索 ----

    public function test_内部idと名前と契約日の範囲で検索する(): void
    {
        $yamada = $this->makeClient('12', '山田', '太郎', ['last_name_kana' => 'やまだ']);
        $suzuki = $this->makeClient('34', '鈴木', '花子', ['first_name_kana' => 'はなこ']);
        $this->contract($yamada, '2026-09-30');
        $this->contract($yamada, '2026-10-15');
        $this->contract($suzuki, '2026-10-01');

        $this->assertSame(['12', '12'], array_column($this->rows($this->page('?internal_id=2')), 0));
        $this->assertSame(['34'], array_column($this->rows($this->page('?keyword='.urlencode('はな'))), 0));
        $this->assertSame(['12', '12'], array_column($this->rows($this->page('?keyword='.urlencode('やま'))), 0));
        $this->assertSame(['2026/10/15', '2026/10/01'], $this->contractedOns($this->page('?date_from=2026-10-01')));
        $this->assertSame(['2026/10/01', '2026/09/30'], $this->contractedOns($this->page('?date_to=2026-10-01')));
        $this->assertSame(['2026/10/01'], $this->contractedOns($this->page('?date_from=2026-10-01&date_to=2026-10-14')));
    }

    public function test_検索の初期値はすべて空(): void
    {
        $html = $this->page();

        foreach (['internal_id', 'keyword', 'date_from', 'date_to'] as $name) {
            $this->assertMatchesRegularExpression('/name="'.$name.'"\s[^>]*value=""/', $html, $name);
        }
    }

    public function test_契約日の範囲が逆なら入力エラー(): void
    {
        $this->from(route('contracts.index'))
            ->get(route('contracts.index').'?date_from=2026-10-10&date_to=2026-10-01')
            ->assertRedirect(route('contracts.index'))
            ->assertSessionHasErrors(['date_to' => '終了日は開始日以降の日付で入力してください。']);
    }

    // ---- ページ送り ----

    public function test_20件を超えるとページ送り(): void
    {
        $client = $this->makeClient('1', '山田');
        for ($day = 1; $day <= 21; $day++) {
            $this->contract($client, sprintf('2026-10-%02d', $day));
        }

        $html = $this->page();
        $this->assertCount(20, $this->rows($html));
        $this->assertStringContainsString('全21件中 1〜20件', $html);
        $this->assertStringContainsString('21件', $html);

        $this->assertSame(['2026/10/01'], $this->contractedOns($this->page('?page=2')));
    }

    public function test_20件以下ならページ送りを出さない(): void
    {
        $client = $this->makeClient('1', '山田');
        for ($day = 1; $day <= 20; $day++) {
            $this->contract($client, sprintf('2026-10-%02d', $day));
        }

        $this->assertStringNotContainsString('aria-label="ページ送り"', $this->page());
    }

    // ---- ナビ ----

    public function test_ナビの契約は会員とトレーニング記録のあいだで契約一覧のときだけ強調する(): void
    {
        $html = $this->page();
        $link = '/<a class="nav-link active" href="'.preg_quote(route('contracts.index'), '/').'">\s*契約\s*<\/a>/u';
        $this->assertMatchesRegularExpression($link, $html);
        // 並びは 会員 → 契約 → トレーニング記録
        $members = strpos($html, '会員一覧</a>');
        $contracts = strpos($html, 'href="'.route('contracts.index').'"');
        $records = strpos($html, 'href="'.route('training-records.index').'"');
        $this->assertTrue($members < $contracts && $contracts < $records);

        // 会員詳細では「契約」を強調しない（「会員」が強調）
        $client = $this->makeClient('1', '山田');
        $this->contract($client, '2026-10-01');
        $detail = $this->get(route('clients.show', $client))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<a class="nav-link " href="'.preg_quote(route('contracts.index'), '/').'">\s*契約\s*<\/a>/u', $detail);
        $this->assertMatchesRegularExpression('/<a class="nav-link dropdown-toggle active" href="#"[^>]*>\s*会員\s*<\/a>/u', $detail);
    }

    public function test_システム管理者のナビには契約を出さない(): void
    {
        $this->actingAsRole('system_admin');

        $html = $this->get(route('profile.edit'))->getContent();
        $this->assertStringNotContainsString('href="'.route('contracts.index').'"', $html);
    }
}

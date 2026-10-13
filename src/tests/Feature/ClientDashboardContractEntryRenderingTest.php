<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientContract;
use App\Models\ClientUsage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員ダッシュボード S-1402 の「ご利用回数」の入口（S-1412 への導線）の描画確認。
 *
 * - 残り回数があるときは「あと n回」と「詳しく見る」（S-1412 へのリンク）を出す
 * - マイナスのときは「ご契約の回数を n回超えてご利用中です」の文（赤字にしない）
 * - 契約も利用も 0 件（ContractTicketService::remaining() が null）のときは入口ごと出さない
 *
 * 表示の確認は、コントローラーで集計済みの $contractRemaining をビューに渡して行う（DB は使わない）。
 * 集計（契約の回数の合計・利用の件数）は、最後のテストだけ実際の HTTP でダッシュボードを開いて確かめる。
 */
class ClientDashboardContractEntryRenderingTest extends TestCase
{
    use CreatesClientCountTables;

    private function renderDashboard(?int $contractRemaining): string
    {
        $client = new Client(['last_name' => '山田', 'first_name' => '太郎']);
        $client->id = 1;
        $client->exists = true;
        $this->actingAs($client, 'client');

        return $this->view('client.dashboard', [
            'sessions' => new Collection([]),
            'weightCharts' => [],
            'contractRemaining' => $contractRemaining,
        ])->__toString();
    }

    private function entry(string $html): string
    {
        $start = strpos($html, '<p class="c-ticket-entry">');
        $this->assertNotFalse($start, 'ご利用回数の入口が見つからない');
        $end = strpos($html, '</p>', $start);

        return substr($html, $start, $end - $start);
    }

    public function test_残りがあるときあとn回と詳しく見るのリンクが出る(): void
    {
        $entry = $this->entry($this->renderDashboard(3));
        $text = preg_replace('/\s+/u', ' ', strip_tags($entry));

        $this->assertStringContainsString('ご利用回数', $text);
        $this->assertStringContainsString('あと 3回', $text);
        $this->assertStringContainsString('詳しく見る', $text);
        $this->assertStringContainsString('href="'.route('client-portal.contracts').'"', $entry);
    }

    public function test_0回のときはあと0回と出る(): void
    {
        $text = preg_replace('/\s+/u', ' ', strip_tags($this->entry($this->renderDashboard(0))));

        $this->assertStringContainsString('あと 0回', $text);
    }

    public function test_マイナスのときは超えてご利用中の文で赤字にしない(): void
    {
        $entry = $this->entry($this->renderDashboard(-2));
        $text = preg_replace('/\s+/u', ' ', strip_tags($entry));

        $this->assertStringContainsString('ご契約の回数を 2回超えてご利用中です', $text);
        $this->assertStringNotContainsString('-2', $text);
        $this->assertStringNotContainsString('text-danger', $entry);
    }

    public function test_契約も利用もないときは入口を出さない(): void
    {
        $html = $this->renderDashboard(null);

        $this->assertStringNotContainsString('c-ticket-entry', $html);
        $this->assertStringNotContainsString(route('client-portal.contracts'), $html);
    }

    public function test_ダッシュボードはログイン中の会員の契約回数の合計と利用件数で集計する(): void
    {
        $this->createClientsTable();
        $this->createClientCountTables();
        // ダッシュボードが読む残りのテーブル（列を絞る。中身は空）
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->date('training_date');
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

        $me = Client::query()->create(['internal_id' => 'C-1', 'last_name' => '山田', 'first_name' => '太郎']);
        $other = Client::query()->create(['internal_id' => 'C-2', 'last_name' => '佐藤', 'first_name' => '花子']);
        // 自分：契約 4 回＋3 回＝7 回、利用 2 件（うち 1 件は契約なし）→ あと 5回
        $c = ClientContract::query()->create(['client_id' => $me->id, 'contracted_on' => '2026-09-01', 'count' => 4]);
        ClientContract::query()->create(['client_id' => $me->id, 'contracted_on' => '2026-10-01', 'count' => 3]);
        ClientUsage::query()->create(['client_id' => $me->id, 'client_contract_id' => $c->id, 'used_on' => '2026-09-05']);
        ClientUsage::query()->create(['client_id' => $me->id, 'client_contract_id' => null, 'used_on' => '2026-09-06']);
        // 他の会員の分は数えない
        ClientContract::query()->create(['client_id' => $other->id, 'contracted_on' => '2026-09-01', 'count' => 20]);
        ClientUsage::query()->create(['client_id' => $other->id, 'client_contract_id' => null, 'used_on' => '2026-09-07']);

        $response = $this->actingAs($me, 'client')->get('/client-portal/dashboard');
        $response->assertOk();
        $text = preg_replace('/\s+/u', ' ', strip_tags($this->entry($response->getContent())));
        $this->assertStringContainsString('あと 5回', $text);

        // 契約も利用もない会員には入口を出さない
        $none = Client::query()->create(['internal_id' => 'C-3', 'last_name' => '鈴木', 'first_name' => '一郎']);
        $this->actingAs($none, 'client')->get('/client-portal/dashboard')
            ->assertOk()
            ->assertDontSee('c-ticket-entry', false);
    }
}

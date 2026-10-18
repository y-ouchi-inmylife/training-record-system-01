<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientContract;
use App\Models\ClientUsage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員側の「ご利用回数」（S-1412）の入口の描画確認（設計書 screen-design.md §2-1-2・S-1402・S-1412）。
 *
 * - ダッシュボード（S-1402）にはご利用回数を出さない（「ご利用回数」「あと n回」「詳しく見る」を
 *   本文に出さない）。会員は犬の写真を見せるためにトップを人に見せることがあるため
 * - 入口はナビゲーションの「ご利用回数」（S-1412 へのリンク）。「登録情報」より前に置く
 * - ナビの項目は名前だけで、回数の数字は出さない
 * - 契約・利用の有無にかかわらず、ナビの項目は常に出す
 *
 * 実際の HTTP でダッシュボードと S-1412 を開くため、画面が読むテーブルを列を絞って作る。
 */
class ClientContractNavRenderingTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();

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
        // 記録の一覧に混ぜる公開済みの評価文を読む（2026-10-18 追加。中身は空）
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();
    }

    /** 契約 4 回＋3 回、利用 2 件（うち 1 件は契約なし）の会員（残りは 5 回） */
    private function clientWithContracts(): Client
    {
        $client = Client::query()->create(['internal_id' => 'C-1', 'last_name' => '山田', 'first_name' => '太郎']);
        $c = ClientContract::query()->create(['client_id' => $client->id, 'contracted_on' => '2026-09-01', 'count' => 4]);
        ClientContract::query()->create(['client_id' => $client->id, 'contracted_on' => '2026-10-01', 'count' => 3]);
        ClientUsage::query()->create(['client_id' => $client->id, 'client_contract_id' => $c->id, 'used_on' => '2026-09-05']);
        ClientUsage::query()->create(['client_id' => $client->id, 'client_contract_id' => null, 'used_on' => '2026-09-06']);

        return $client;
    }

    private function clientWithoutContracts(): Client
    {
        return Client::query()->create(['internal_id' => 'C-2', 'last_name' => '鈴木', 'first_name' => '一郎']);
    }

    private function open(Client $client, string $url): string
    {
        $response = $this->actingAs($client, 'client')->get($url);
        $response->assertOk();

        return $response->getContent();
    }

    /** ナビゲーション（<nav>…</nav>）の HTML */
    private function nav(string $html): string
    {
        $start = strpos($html, '<nav');
        $this->assertNotFalse($start, 'ナビゲーションが見つからない');

        return substr($html, $start, strpos($html, '</nav>', $start) - $start);
    }

    /** ナビゲーションより後ろ（本文）の HTML */
    private function body(string $html): string
    {
        $end = strpos($html, '</nav>');
        $this->assertNotFalse($end, 'ナビゲーションが見つからない');

        return substr($html, $end);
    }

    public function test_ダッシュボードの本文にはご利用回数を出さない(): void
    {
        $html = $this->open($this->clientWithContracts(), '/client-portal/dashboard');
        $body = $this->body($html);

        $this->assertStringNotContainsString('ご利用回数', $body);
        $this->assertStringNotContainsString('詳しく見る', $body);
        $this->assertStringNotContainsString(route('client-portal.contracts'), $body);
        // 回数の数（あと n回・超えてご利用中の文）は画面のどこにも出ない
        $this->assertStringNotContainsString('あと', strip_tags($html));
        $this->assertStringNotContainsString('超えてご利用中', $html);
        $this->assertStringNotContainsString('c-remaining', $html);
    }

    public function test_ナビにご利用回数がご利用回数画面へのリンクとして登録情報より前に出る(): void
    {
        $nav = $this->nav($this->open($this->clientWithContracts(), '/client-portal/dashboard'));

        $link = '<a class="btn btn-link btn-sm" href="'.route('client-portal.contracts').'">ご利用回数</a>';
        $this->assertStringContainsString($link, $nav);
        $this->assertLessThan(strpos($nav, '登録情報'), strpos($nav, $link));
        $this->assertLessThan(strpos($nav, 'ログアウト'), strpos($nav, '登録情報'));
    }

    public function test_ナビのご利用回数には数字を出さない(): void
    {
        $nav = $this->nav($this->open($this->clientWithContracts(), '/client-portal/dashboard'));

        // ナビの文字に数字が含まれない（項目名だけ）
        $this->assertDoesNotMatchRegularExpression('/\d/u', strip_tags($nav));
        $this->assertStringNotContainsString('あと', $nav);
    }

    public function test_契約も利用もない会員にもナビのご利用回数を出す(): void
    {
        $client = $this->clientWithoutContracts();

        $nav = $this->nav($this->open($client, '/client-portal/dashboard'));
        $this->assertStringContainsString('href="'.route('client-portal.contracts').'">ご利用回数</a>', $nav);

        // ナビから開くと、S-1412 の空の状態が出る
        $this->assertStringContainsString('回数のご契約はありません。', $this->open($client, '/client-portal/contracts'));
    }
}

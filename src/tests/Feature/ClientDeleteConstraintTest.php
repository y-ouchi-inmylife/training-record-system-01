<?php

namespace Tests\Feature;

use App\Http\Controllers\ClientController;
use App\Models\Client;
use App\Models\Trainer;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員削除の削除拒否と最後の守りの確認（2026-10 追加。
 * 本番で音声記録のある会員の削除が外部キーに断られて 500 になった事例の再発防止）。
 *
 * 要件定義書 6-3-5・画面設計書 S-0305・API 設計書 DELETE /clients/{id} が
 * 定める「削除できない条件（トレーニング記録・トレーニー・契約・利用・音声記録）」と、
 * 画面の確かめをすり抜けて DB の外部キーに断られた場合の最後の守りを確かめる。
 *
 * テスト用 SQLite ではマイグレーション（MySQL 専用の CHECK 制約）が通らないため、
 * 既存の CreatesRecordingTables と同じく検証に必要な列だけでテーブルを作る。
 *
 * 2026-11 変更：トレーニー（空のトレーニーも含む）・契約・利用が 1 件でもあれば止め、文言にはあるものを挙げる。
 * 会員の削除で、ほかのものを一緒に消すことはない。その前（2026-10-18〜）は、空のトレーニーだけなら会員と一緒に消し、
 * 契約・利用は外部キーの CASCADE で一緒に消えていた。
 */
class ClientDeleteConstraintTest extends TestCase
{
    use CreatesClientCountTables;

    private Trainer $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('internal_id')->default('');
            $table->date('initial_consultation_date')->nullable();
            $table->string('last_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name_kana')->nullable();
            $table->string('first_name_kana')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('primary_trainer_id')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
        });
        // トレーニーと、トレーニーに結び付く表（空のトレーニーかの判定に使う。2026-10-18 追加）
        $this->createTraineesWithRelatedTables();
        // 契約・利用（2026-11 追加。会員の削除を断る条件に入れたため）
        $this->createClientCountTables();
        Schema::create('audio_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
        });

        $this->admin = new Trainer(['name' => '管理者太郎', 'role' => 'admin']);
        $this->admin->id = 100;
        $this->admin->exists = true;
        $this->actingAs($this->admin, 'web');
    }

    private function makeClient(): Client
    {
        $client = Client::query()->create([
            'internal_id' => '0001',
            'initial_consultation_date' => '2026-01-20',
            'last_name' => '山田',
            'first_name' => '太郎',
        ]);
        return $client->fresh();
    }

    /** 契約を 1 件入れて id を返す */
    private function insertContract(Client $client): int
    {
        return DB::table('client_contracts')->insertGetId([
            'client_id' => $client->id,
            'contracted_on' => '2026-11-01',
            'count' => 4,
        ]);
    }

    /** 利用を 1 件入れる（契約は省略で「契約なし」） */
    private function insertUsage(Client $client, ?int $contractId = null): void
    {
        DB::table('client_usages')->insert([
            'client_id' => $client->id,
            'client_contract_id' => $contractId,
            'used_on' => '2026-11-10',
            'start_time' => '08:00:00',
            'end_time' => '19:00:00',
        ]);
    }

    // ---- 削除拒否 ----

    public function test_トレーニング記録がある会員は削除できず_トレーニング記録のメッセージが出る(): void
    {
        $client = $this->makeClient();
        DB::table('training_records')->insert(['client_id' => $client->id]);

        $response = app(ClientController::class)->destroy($client);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(route('clients.show', $client), $response->getTargetUrl());
        $this->assertSame(
            'この会員にはトレーニング記録が登録されているため削除できません。',
            $response->getSession()->get('error')
        );
        $this->assertTrue(Client::where('id', $client->id)->exists(), '会員は消えていない');
    }

    public function test_空でないトレーニーがある会員は削除できず_トレーニーのメッセージが出る(): void
    {
        $client = $this->makeClient();
        DB::table('trainees')->insert(['client_id' => $client->id, 'name' => 'ポチ']);

        $response = app(ClientController::class)->destroy($client);

        $this->assertSame(route('clients.show', $client), $response->getTargetUrl());
        $this->assertSame(
            'この会員にはトレーニーが登録されているため削除できません。',
            $response->getSession()->get('error')
        );
        $this->assertTrue(Client::where('id', $client->id)->exists());
    }

    public function test_音声記録がある会員は削除できず_音声記録のメッセージが出る(): void
    {
        $client = $this->makeClient();
        DB::table('audio_records')->insert(['client_id' => $client->id]);

        $response = app(ClientController::class)->destroy($client);

        $this->assertSame(route('clients.show', $client), $response->getTargetUrl());
        $this->assertSame(
            'この会員には音声記録が登録されているため削除できません。',
            $response->getSession()->get('error')
        );
        $this->assertTrue(Client::where('id', $client->id)->exists());
    }

    public function test_空のトレーニーだけの会員は削除できず_トレーニーは消えない(): void
    {
        // 2026-11 変更：その前は、空のトレーニーだけなら会員を削除でき、トレーニーも一緒に消えた
        $client = $this->makeClient();
        // 会員の登録で自動で作ったトレーニー（中身は空）と、空文字だけのトレーニー
        DB::table('trainees')->insert(['client_id' => $client->id]);
        DB::table('trainees')->insert(['client_id' => $client->id, 'name' => '', 'breed' => '']);

        $response = app(ClientController::class)->destroy($client);

        $this->assertSame(route('clients.show', $client), $response->getTargetUrl());
        $this->assertSame(
            'この会員にはトレーニーが登録されているため削除できません。',
            $response->getSession()->get('error')
        );
        $this->assertTrue(Client::where('id', $client->id)->exists(), '会員は消えていない');
        $this->assertSame(2, DB::table('trainees')->where('client_id', $client->id)->count(), 'トレーニーは消えていない');
    }

    public function test_契約だけある会員は削除できず_契約のメッセージが出る(): void
    {
        $client = $this->makeClient();
        $this->insertContract($client);

        $response = app(ClientController::class)->destroy($client);

        $this->assertSame(route('clients.show', $client), $response->getTargetUrl());
        $this->assertSame(
            'この会員には契約が登録されているため削除できません。',
            $response->getSession()->get('error')
        );
        $this->assertTrue(Client::where('id', $client->id)->exists());
        $this->assertSame(1, DB::table('client_contracts')->where('client_id', $client->id)->count(), '契約は消えていない');
    }

    public function test_利用だけある会員は削除できず_利用のメッセージが出る(): void
    {
        // 契約なし（未割り当て）・トレーニーなしの利用
        $client = $this->makeClient();
        $this->insertUsage($client);

        $response = app(ClientController::class)->destroy($client);

        $this->assertSame(route('clients.show', $client), $response->getTargetUrl());
        $this->assertSame(
            'この会員には利用が登録されているため削除できません。',
            $response->getSession()->get('error')
        );
        $this->assertTrue(Client::where('id', $client->id)->exists());
        $this->assertSame(1, DB::table('client_usages')->where('client_id', $client->id)->count(), '利用は消えていない');
    }

    public function test_トレーニー契約利用が全部あれば3つをこの順に挙げる(): void
    {
        $client = $this->makeClient();
        $this->insertUsage($client, $this->insertContract($client));
        DB::table('trainees')->insert(['client_id' => $client->id]);

        $response = app(ClientController::class)->destroy($client);

        $this->assertSame(
            'この会員にはトレーニー・契約・利用が登録されているため削除できません。',
            $response->getSession()->get('error')
        );
    }

    public function test_あるものだけを挙げる_トレーニーと利用(): void
    {
        $client = $this->makeClient();
        DB::table('trainees')->insert(['client_id' => $client->id]);
        $this->insertUsage($client);

        $response = app(ClientController::class)->destroy($client);

        $this->assertSame(
            'この会員にはトレーニー・利用が登録されているため削除できません。',
            $response->getSession()->get('error')
        );
    }

    public function test_ほかの会員のトレーニー契約利用は判定に入れない(): void
    {
        $client = $this->makeClient();
        $other = Client::query()->create(['internal_id' => '0002', 'last_name' => '佐藤']);
        DB::table('trainees')->insert(['client_id' => $other->id]);
        $this->insertUsage($other, $this->insertContract($other));

        $response = app(ClientController::class)->destroy($client);

        $this->assertSame(route('clients.index'), $response->getTargetUrl());
        $this->assertFalse(Client::where('id', $client->id)->exists());
        $this->assertSame(1, DB::table('trainees')->where('client_id', $other->id)->count());
        $this->assertSame(1, DB::table('client_contracts')->where('client_id', $other->id)->count());
        $this->assertSame(1, DB::table('client_usages')->where('client_id', $other->id)->count());
    }

    public function test_トレーニング記録があればトレーニング記録の文言が先(): void
    {
        $client = $this->makeClient();
        DB::table('trainees')->insert(['client_id' => $client->id]);
        $this->insertContract($client);
        DB::table('training_records')->insert(['client_id' => $client->id]);

        $response = app(ClientController::class)->destroy($client);

        $this->assertSame('この会員にはトレーニング記録が登録されているため削除できません。', $response->getSession()->get('error'));
        $this->assertSame(1, DB::table('trainees')->where('client_id', $client->id)->count());
    }

    public function test_トレーニー契約利用があれば音声記録より先に挙げる(): void
    {
        $client = $this->makeClient();
        $this->insertContract($client);
        DB::table('audio_records')->insert(['client_id' => $client->id]);

        $response = app(ClientController::class)->destroy($client);

        $this->assertSame('この会員には契約が登録されているため削除できません。', $response->getSession()->get('error'));
    }

    public function test_どれも登録されていない会員は削除できて会員一覧に戻る(): void
    {
        $client = $this->makeClient();

        $response = app(ClientController::class)->destroy($client);

        $this->assertSame(route('clients.index'), $response->getTargetUrl());
        $this->assertSame('会員を削除しました。', $response->getSession()->get('success'));
        $this->assertFalse(Client::where('id', $client->id)->exists(), '会員は消えている');
    }

    public function test_管理者でないトレーナーは削除できない(): void
    {
        $staff = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $staff->id = 200;
        $staff->exists = true;
        $this->actingAs($staff, 'web');

        $client = $this->makeClient();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(ClientController::class)->destroy($client);
    }

    // ---- 最後の守り ----

    /**
     * delete() を外部キー違反で差し替えた Client のサブクラスを返す。
     * 匿名クラスだと親の HasMany の外部キー列名の推測（classBasenameから client_id）が
     * 崩れるため、通常の名前付きサブクラスを使う（protected $table で "clients" を固定）。
     */
    private function makeFaultyClient(string $sqlstate, int $errorNo): Client
    {
        $base = $this->makeClient();
        $faulty = new ClientDeleteConstraintTest_FaultyClient();
        $faulty->setRawAttributes($base->getAttributes(), true);
        $faulty->exists = true;
        $faulty->setFaultyDelete($sqlstate, $errorNo);
        return $faulty;
    }

    public function test_外部キー違反1451で500にならず会員詳細に戻って関連データのメッセージが出る(): void
    {
        $faulty = $this->makeFaultyClient('23000', 1451);

        $response = app(ClientController::class)->destroy($faulty);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(route('clients.show', $faulty), $response->getTargetUrl());
        $this->assertSame(
            'この会員には関連するデータが登録されているため削除できません。',
            $response->getSession()->get('error')
        );
    }

    public function test_外部キー違反以外のDB例外は再throwされる(): void
    {
        // デッドロック（40001/1213）は再 throw されるべき
        $faulty = $this->makeFaultyClient('40001', 1213);

        $this->expectException(QueryException::class);
        app(ClientController::class)->destroy($faulty);
    }

    // ---- 画面側の確かめ ----

    public function test_会員詳細画面のJSに音声記録の削除拒否が入っている(): void
    {
        $blade = file_get_contents(resource_path('views/clients/show.blade.php'));
        $this->assertStringContainsString('audioRecords->count() > 0', $blade);
        $this->assertStringContainsString(
            '音声記録が登録されているため削除できません',
            $blade
        );
        // 既存の条件もそのまま残っていること（トレーニー・契約・利用は、サーバーと同じメソッドの文言で止める。2026-11 変更）
        $this->assertStringContainsString('trainingRecords->count() > 0', $blade);
        $this->assertStringContainsString('$traineeContractUsageDeletionBlockedMessage !== null', $blade);
        $this->assertStringNotContainsString('hasNonBlankTrainees', $blade);
    }
}

/**
 * 最後の守りのテスト用 Client サブクラス。
 * delete() を外部キー違反などで差し替えるためだけに作る。
 * 匿名クラス（class@anonymous）だと HasMany の既定外部キー推測（classBasename 由来の client_id）が
 * 崩れるため、通常の名前付きクラスにして $table を "clients" に固定する。
 */
class ClientDeleteConstraintTest_FaultyClient extends Client
{
    protected $table = 'clients';

    private string $sqlstate = '23000';
    private int $errorNo = 1451;

    public function setFaultyDelete(string $sqlstate, int $errorNo): void
    {
        $this->sqlstate = $sqlstate;
        $this->errorNo = $errorNo;
    }

    // HasMany の既定外部キー推測はクラス名由来（class_basename + '_id'）のため、
    // サブクラスのままだと "client_delete_constraint_test__faulty_client_id" を探しに行く。
    // 本番の列名 "client_id" を使うため、第 2 引数で明示する。
    public function trainingRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\TrainingRecord::class, 'client_id');
    }
    public function trainees(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Trainee::class, 'client_id')->orderBy('id');
    }
    public function audioRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\AudioRecord::class, 'client_id');
    }
    public function contracts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\ClientContract::class, 'client_id');
    }
    public function usages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\ClientUsage::class, 'client_id');
    }

    public function delete(): bool
    {
        $previous = new \PDOException(
            sprintf('SQLSTATE[%s]: %d error', $this->sqlstate, $this->errorNo),
            (int) $this->sqlstate
        );
        $previous->errorInfo = [$this->sqlstate, $this->errorNo, 'simulated'];
        throw new QueryException(
            'mysql',
            'delete from `clients` where `id` = ?',
            [$this->id],
            $previous
        );
    }
}

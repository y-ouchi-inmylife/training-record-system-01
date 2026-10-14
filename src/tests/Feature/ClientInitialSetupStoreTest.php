<?php

namespace Tests\Feature;

use App\Mail\ClientSetupCompletedMail;
use App\Models\Client;
use App\Models\ClientEmailRegistrationToken;
use App\Models\ClientLoginLinkToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 初回設定の送信（POST /client-portal/setup/{token}、S-1403、要件定義書 6-15-5）。
 *
 * 描画のテスト（ClientSetupFormErrorRenderingTest）だけでは、送信した値が黙って捨てられても
 * 気づけないため、実際に送信して保存されることを確かめる。
 *
 * 2026-10 変更：初回設定から愛犬（トレーニー）の情報を外した（受付アンケートに分離）。
 * 初回設定はトレーニーを読み込まず、書き込みもしない。古い trainee_* の項目が送られても、
 * トレーニーには何も起きない。
 *
 * 本プロジェクトのマイグレーションは MySQL 専用の CHECK 制約を生 SQL で追加するため、
 * テスト用 SQLite では RefreshDatabase が使えない。テストが読み書きする列だけのテーブルを
 * テストの中で作る（CreatesClientCountTables と同じ事情）。
 */
class ClientInitialSetupStoreTest extends TestCase
{
    use CreatesClientCountTables;

    private const TOKEN = 'login-link-token-for-test-0000000';

    private const PASSWORD = 'Abcdef1!x';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createSetupTokenTables();
        $this->createTraineesTable();

        Mail::fake();

        // トレーナーが登録した会員。メールアドレスは S-1405 で登録済み、パスワードは未設定（初回設定待ち）
        $this->client = Client::query()->create([
            'internal_id' => '0001',
            'last_name' => '仮',
            'email' => 'owner@example.com',
        ]);

        ClientEmailRegistrationToken::query()->create([
            'token' => 'email-registration-token-0000000',
            'client_id' => $this->client->id,
            'expires_at' => now()->addDays(3),
            'is_used' => false,
        ]);
        ClientLoginLinkToken::query()->create([
            'token' => self::TOKEN,
            'client_id' => $this->client->id,
            'expires_at' => now()->addDays(3),
            'is_used' => false,
        ]);
    }

    private function createSetupTokenTables(): void
    {
        Schema::create('client_email_registration_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('client_id');
            $table->timestamp('expires_at');
            $table->boolean('is_used')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('client_login_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('client_id');
            $table->timestamp('expires_at');
            $table->boolean('is_used')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    private function createTraineesTable(): void
    {
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->string('breed', 100)->nullable();
            $table->string('sex', 20)->nullable();
            // 誕生日は年・月・日の 3 列（D-0700。2026-10 に birth_date から分けた）
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->unsignedTinyInteger('birth_month')->nullable();
            $table->unsignedTinyInteger('birth_day')->nullable();
            $table->text('note')->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
    }

    /** 正しい入力 */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'last_name' => '山田',
            'first_name' => '太郎',
            'last_name_kana' => 'やまだ',
            'first_name_kana' => 'たろう',
            'phone1' => '03-1234-5678',
            'phone2' => '090-0000-0000',
            'postal_code' => '100-0014',
            'address1' => '東京都',
            'address2' => '千代田区',
            'address3' => '永田町1-1',
            'address4' => 'ビル101',
        ], $overrides);
    }

    private function postSetup(array $input)
    {
        return $this->post(route('client-portal.setup.store', ['token' => self::TOKEN]), $input);
    }

    public function test_正しい入力で送信すると完了してダッシュボードへ移る(): void
    {
        $response = $this->postSetup($this->validInput());

        $response->assertRedirect(route('client-portal.dashboard'));
        $response->assertSessionHas('success', '初回設定が完了しました。');
        $response->assertSessionHasNoErrors();
    }

    public function test_会員の氏名_連絡先_住所_パスワードが保存される(): void
    {
        $this->postSetup($this->validInput());

        $client = $this->client->fresh();
        $this->assertSame('山田', $client->last_name);
        $this->assertSame('太郎', $client->first_name);
        $this->assertSame('やまだ', $client->last_name_kana);
        $this->assertSame('たろう', $client->first_name_kana);
        $this->assertSame('03-1234-5678', $client->phone1);
        $this->assertSame('090-0000-0000', $client->phone2);
        $this->assertSame('100-0014', $client->postal_code);
        $this->assertSame('東京都', $client->address1);
        $this->assertSame('千代田区', $client->address2);
        $this->assertSame('永田町1-1', $client->address3);
        $this->assertSame('ビル101', $client->address4);
        $this->assertTrue(Hash::check(self::PASSWORD, $client->password));
        // メールアドレスは初回設定では変えない
        $this->assertSame('owner@example.com', $client->email);
    }

    public function test_ログイン用リンクとメールアドレス登録用URLが使い切りになる(): void
    {
        $this->postSetup($this->validInput());

        $this->assertTrue(ClientLoginLinkToken::query()->where('token', self::TOKEN)->sole()->is_used);
        $this->assertTrue(ClientEmailRegistrationToken::query()->where('client_id', $this->client->id)->sole()->is_used);
    }

    public function test_登録完了メールが登録アドレスに送られる(): void
    {
        $this->postSetup($this->validInput());

        Mail::assertSent(ClientSetupCompletedMail::class, function (ClientSetupCompletedMail $mail) {
            return $mail->hasTo('owner@example.com');
        });
    }

    public function test_トレーニーが1頭いる会員_送信の前後でトレーニーの値が変わらない(): void
    {
        $traineeId = DB::table('trainees')->insertGetId([
            'client_id' => $this->client->id,
            'name' => 'モモ',
            'breed' => 'トイ・プードル',
            'sex' => 'female',
            'birth_year' => 2022,
            'birth_month' => 3,
            'birth_day' => 15,
            'note' => 'トレーナーのメモ',
            'photo_path' => null,
            'created_at' => '2026-10-01 10:00:00',
            'updated_at' => '2026-10-01 10:00:00',
            'updated_by' => 7,
        ]);
        $before = (array) DB::table('trainees')->where('id', $traineeId)->first();

        $this->postSetup($this->validInput())->assertRedirect(route('client-portal.dashboard'));

        $after = (array) DB::table('trainees')->where('id', $traineeId)->first();
        $this->assertSame($before, $after);
        $this->assertSame(1, DB::table('trainees')->count());
    }

    public function test_トレーニーが0頭の会員_送信後もトレーニーは0頭のまま(): void
    {
        $this->postSetup($this->validInput())->assertRedirect(route('client-portal.dashboard'));

        $this->assertSame(0, DB::table('trainees')->count());
    }

    public function test_古いtrainee項目が送られてもトレーニーに何も起きない(): void
    {
        $traineeId = DB::table('trainees')->insertGetId([
            'client_id' => $this->client->id,
            'name' => 'モモ',
            'breed' => null,
            'sex' => null,
            'birth_year' => null,
            'birth_month' => null,
            'birth_day' => null,
            'note' => null,
            'photo_path' => null,
            'created_at' => '2026-10-01 10:00:00',
            'updated_at' => '2026-10-01 10:00:00',
            'updated_by' => 7,
        ]);
        $before = (array) DB::table('trainees')->where('id', $traineeId)->first();

        $response = $this->postSetup($this->validInput([
            'trainee_name' => '別の名前',
            'trainee_breed' => '柴',
            'trainee_sex' => 'male',
            'trainee_birth_date' => '2020-01-01',
            'trainee_note' => '古い画面からの送信',
        ]));

        // 古い項目は検証の対象外で、送信そのものは成功する
        $response->assertRedirect(route('client-portal.dashboard'));
        $response->assertSessionHasNoErrors();

        $after = (array) DB::table('trainees')->where('id', $traineeId)->first();
        $this->assertSame($before, $after);
        $this->assertSame(1, DB::table('trainees')->count());
    }

    public function test_古いtrainee項目が送られてもトレーニーが0頭の会員には作られない(): void
    {
        $this->postSetup($this->validInput([
            'trainee_name' => 'モモ',
        ]))->assertRedirect(route('client-portal.dashboard'));

        $this->assertSame(0, DB::table('trainees')->count());
    }
}

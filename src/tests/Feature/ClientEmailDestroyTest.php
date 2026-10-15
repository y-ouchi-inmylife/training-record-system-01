<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * マイページ登録の削除（DELETE /clients/{client}/email。要件 6-3-8、2026-10 追加）。
 *
 * 会員詳細の［マイページ登録を削除］から確認（S-0305-M02）を経て実行する。完了メッセージはボタン名に
 * そろえて「マイページ登録を削除しました。」（2026-10 変更）。メールアドレスとパスワードを NULL に戻し、
 * 未使用の登録用 URL・ログイン用リンクを消す。
 */
class ClientEmailDestroyTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createAccessLogsTable();
        foreach (['client_email_registration_tokens', 'client_login_link_tokens'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('token')->nullable();
                $table->unsignedBigInteger('client_id');
                $table->dateTime('expires_at')->nullable();
                $table->boolean('is_used')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        $staff = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $staff->id = 200;
        $staff->exists = true;
        $this->actingAs($staff, 'web');
    }

    private function makeClient(array $account): Client
    {
        $client = Client::query()->create(['internal_id' => '1', 'last_name' => '山田', 'initial_consultation_date' => '2026-10-01']);
        $client->forceFill($account)->save();

        return $client;
    }

    public function test_利用中の会員のマイページ登録を削除すると完了メッセージを出す(): void
    {
        $client = $this->makeClient(['email' => 'yamada@example.com', 'password' => 'hashed']);
        DB::table('client_login_link_tokens')->insert(['client_id' => $client->id, 'token' => 'unused', 'is_used' => false]);
        DB::table('client_login_link_tokens')->insert(['client_id' => $client->id, 'token' => 'used', 'is_used' => true]);

        $response = $this->delete(route('client-email.destroy', $client));

        $response->assertRedirect(route('clients.show', $client));
        $response->assertSessionHas('success', 'マイページ登録を削除しました。');
        $fresh = $client->fresh();
        $this->assertNull($fresh->email);
        $this->assertNull($fresh->password);
        // 未使用のログイン用リンクだけを消す
        $this->assertSame(['used'], DB::table('client_login_link_tokens')->pluck('token')->all());
    }

    public function test_初回設定待ちの会員でも同じ完了メッセージを出す(): void
    {
        $client = $this->makeClient(['email' => 'yamada@example.com']);
        DB::table('client_email_registration_tokens')->insert(['client_id' => $client->id, 'token' => 'unused', 'is_used' => false]);

        $response = $this->delete(route('client-email.destroy', $client));

        $response->assertRedirect(route('clients.show', $client));
        $response->assertSessionHas('success', 'マイページ登録を削除しました。');
        $this->assertNull($client->fresh()->email);
        $this->assertSame(0, DB::table('client_email_registration_tokens')->count());
    }

    public function test_メールアドレスがなければ削除しない(): void
    {
        $client = $this->makeClient([]);

        $this->delete(route('client-email.destroy', $client))->assertStatus(409);
    }
}

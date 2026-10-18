<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientEmailRegistrationToken;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * マイページ登録案内の発行（POST /clients/{client}/email-registration-tokens。2026-10 変更）。
 *
 * - 発行のあとは同じタブで S-0307 を開く（完了メッセージは載せない）
 * - 有効な（未使用で期限内の）案内がすでにあれば、新しく発行せず、今ある案内の S-0307 を開く
 * - 期限切れの未使用の案内しかなければ、消して作り直す
 *
 * ミドルウェア（auth・practitioners など）は外さず、実際の HTTP で確かめる。
 */
class ClientEmailRegistrationTokenStoreTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');

        $this->createClientsTable();
        $this->createAccessLogsTable();
        foreach (['client_email_registration_tokens', 'client_login_link_tokens'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('token', 64)->unique();
                $table->unsignedBigInteger('client_id');
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_used')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        $trainer = new Trainer(['name' => 'テスト', 'role' => 'staff']);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function insertToken(string $token, string $expiresAt, bool $isUsed = false): void
    {
        DB::table('client_email_registration_tokens')->insert([
            'token' => $token, 'client_id' => $this->client->id,
            'expires_at' => $expiresAt, 'is_used' => $isUsed,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function registrationUrl(string $token): string
    {
        return route('client-portal.email-registration.show', ['token' => $token]);
    }

    public function test_発行すると会員詳細に戻り完了メッセージを出す_案内はS0307で見られる(): void
    {
        $response = $this->post(route('client-email-registration-tokens.store', $this->client));

        // 発行のあとは会員詳細（ページの上）に戻る。S-0307 には移らない（2026-10-17 変更）
        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', 'マイページ登録の案内を発行しました。');

        $token = ClientEmailRegistrationToken::query()->sole();
        $this->assertFalse($token->is_used);
        // 有効期限は発行から 7 日後（2026-10-18 変更：その前は 3 日後の 2026-10-17 10:00:00）
        $this->assertSame('2026-10-21 10:00:00', $token->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(200, (int) $token->created_by);

        // 案内は［マイページ登録の案内を表示］から S-0307 で見る
        $html = $this->get(route('client-email-registration-tokens.print', $this->client))->assertOk()->getContent();
        $this->assertStringContainsString(e($this->registrationUrl($token->token)), $html);
    }

    public function test_有効な案内があれば新しく作らず完了メッセージも出さずに会員詳細に戻る(): void
    {
        $this->insertToken('active-token-0000', '2026-10-16 10:00:00');
        DB::table('client_login_link_tokens')->insert([
            'token' => 'login-link-0000', 'client_id' => $this->client->id,
            'expires_at' => '2026-10-16 10:00:00', 'is_used' => false,
        ]);

        $response = $this->post(route('client-email-registration-tokens.store', $this->client));

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionMissing('success');
        // 何も作らず・消さない
        $this->assertSame(['active-token-0000'], ClientEmailRegistrationToken::query()->pluck('token')->all());
        $this->assertSame(1, DB::table('client_login_link_tokens')->count());

        $html = $this->get(route('client-email-registration-tokens.print', $this->client))->assertOk()->getContent();
        $this->assertStringContainsString(e($this->registrationUrl('active-token-0000')), $html);
    }

    public function test_二重に送っても有効な案内は1つのまま(): void
    {
        $this->post(route('client-email-registration-tokens.store', $this->client))
            ->assertSessionHas('success', 'マイページ登録の案内を発行しました。');
        $first = ClientEmailRegistrationToken::query()->sole()->token;
        $this->flushSession();

        $this->post(route('client-email-registration-tokens.store', $this->client))
            ->assertRedirect(route('clients.show', $this->client))
            ->assertSessionMissing('success');

        $this->assertSame([$first], ClientEmailRegistrationToken::query()->pluck('token')->all());
    }

    public function test_期限切れの案内しかなければ消して作り直す(): void
    {
        $this->insertToken('expired-token-0000', '2026-10-13 10:00:00');
        $this->insertToken('used-token-0000', '2026-10-13 10:00:00', true);

        $response = $this->post(route('client-email-registration-tokens.store', $this->client));

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', 'マイページ登録の案内を発行しました。');
        $tokens = ClientEmailRegistrationToken::query()->orderBy('id')->pluck('token')->all();
        $this->assertCount(2, $tokens);
        $this->assertSame('used-token-0000', $tokens[0]);
        $this->assertNotSame('expired-token-0000', $tokens[1]);
    }

    public function test_S0307の戻るは会員詳細へのふつうのリンクで閉じるのスクリプトを読み込まない(): void
    {
        $this->post(route('client-email-registration-tokens.store', $this->client));

        $html = $this->get(route('client-email-registration-tokens.print', $this->client))->assertOk()->getContent();

        $this->assertStringContainsString('<a href="'.route('clients.show', $this->client).'" class="btn btn-outline-secondary btn-sm">戻る</a>', $html);
        $this->assertStringNotContainsString('history.back', $html);
        $this->assertStringNotContainsString('&laquo; 戻る', $html);
        $this->assertStringNotContainsString('close-tab', $html);
        $this->assertStringNotContainsString('閉じる', $html);
    }

    // ---- 発行の URL を GET で開いたとき（2026-10-17 追加。405 にしない） ----

    public function test_発行のURLをGETで開くと発行せず会員詳細に移る(): void
    {
        $this->get('/clients/'.$this->client->id.'/email-registration-tokens')
            ->assertRedirect(route('clients.show', $this->client));
        $this->assertSame(0, ClientEmailRegistrationToken::query()->count());
    }

    public function test_発行のURLをGETで開いても有効な案内には触らない(): void
    {
        $this->insertToken('active-token', '2026-10-16 10:00:00');

        $this->get('/clients/'.$this->client->id.'/email-registration-tokens')
            ->assertRedirect(route('clients.show', $this->client));
        $this->assertSame(['active-token'], ClientEmailRegistrationToken::query()->pluck('token')->all());
    }

    public function test_発行のURLをGETで開いたとき_ない会員は404_ログインしていなければログインへ_システム管理者は403(): void
    {
        $this->get('/clients/999/email-registration-tokens')->assertNotFound();

        $admin = new Trainer(['name' => 'システム', 'role' => 'system_admin']);
        $admin->id = 201;
        $admin->exists = true;
        $this->actingAs($admin, 'web');
        $this->get('/clients/'.$this->client->id.'/email-registration-tokens')->assertForbidden();

        auth('web')->logout();
        $this->get('/clients/'.$this->client->id.'/email-registration-tokens')->assertRedirect(route('login'));
        $this->assertSame(0, ClientEmailRegistrationToken::query()->count());
    }
}

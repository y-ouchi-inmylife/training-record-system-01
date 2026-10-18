<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientEmailRegistrationToken;
use App\Models\ClientLoginLinkToken;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * マイページ登録のご案内（メールアドレス登録用 URL。S-0307）の有効期限（2026-10-18 変更）。
 *
 * - 2026-10-18 のユーザーレビューで、有効期限を発行から 3 日から 7 日にした（config/client_tokens.php の
 *   email_registration_expires_days）。期限は発行した時刻から 7 日後の同じ時刻で、画面・文面には日付だけを出す
 * - すでに発行した案内の期限は変えない（発行したときの expires_at のまま）
 * - ログイン用リンク（初回設定へのリンク）は、今までどおり案内の期限をそのまま引き継ぐ
 * - メールアドレス確認リンク・パスワード再設定リンクの有効期限（3 日）は変えない
 *
 * 「今日」は 2026-10-18（日）10:00 に固定する。
 */
class ClientEmailRegistrationExpiryTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 10:00:00');

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

    private function issue(): ClientEmailRegistrationToken
    {
        $this->post(route('client-email-registration-tokens.store', $this->client))
            ->assertRedirect(route('clients.show', $this->client));

        return ClientEmailRegistrationToken::query()->sole();
    }

    /** お客様が案内のリンクを開いたときの画面（HTML） */
    private function openRegistration(string $token): string
    {
        return $this->get(route('client-portal.email-registration.show', ['token' => $token]))->assertOk()->getContent();
    }

    private function assertUsable(string $html): void
    {
        $this->assertStringContainsString('マイページのご登録', $html);
        $this->assertStringNotContainsString('このURLは期限切れです', $html);
    }

    private function assertExpired(string $html): void
    {
        $this->assertStringContainsString('このURLは期限切れです', $html);
    }

    public function test_発行すると有効期限は7日後の同じ時刻になる(): void
    {
        $this->assertSame(7, config('client_tokens.email_registration_expires_days'));

        $token = $this->issue();

        $this->assertSame('2026-10-25 10:00:00', $token->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_画面と文面の有効期限は7日後の日付(): void
    {
        $this->issue();

        $html = $this->get(route('client-email-registration-tokens.print', $this->client))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/data-copy-text="([^"]*)"/', $html, $m));
        $copyText = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        $this->assertSame(1, preg_match('/<div class="invitation-message" data-invitation-message>(.*?)<\/div>/su', $html, $m));
        $shownText = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString("リンクの有効期限：2026年10月25日（日）まで\n", $copyText);
        $this->assertSame($copyText, $shownText);
        $this->assertStringNotContainsString('2026年10月21日', $html);
    }

    public function test_7日目の発行した時刻まではまだ使え_それを過ぎると使えない(): void
    {
        $token = $this->issue()->token;

        // 7 日目（10/25）の発行した時刻の前は使える
        Carbon::setTestNow('2026-10-25 09:59:59');
        $this->assertUsable($this->openRegistration($token));

        // 発行した時刻を過ぎると期限切れ（今の数え方のまま：expires_at が今より前なら期限切れ）
        Carbon::setTestNow('2026-10-25 10:00:01');
        $this->assertExpired($this->openRegistration($token));

        // 8 日目（10/26）も使えない
        Carbon::setTestNow('2026-10-26 09:00:00');
        $this->assertExpired($this->openRegistration($token));
    }

    public function test_すでに発行した3日の期限の案内は期限を変えない(): void
    {
        // 7 日に変える前（3 日）に 10/18 10:00 に発行した案内
        DB::table('client_email_registration_tokens')->insert([
            'token' => 'issued-before-change-0', 'client_id' => $this->client->id,
            'expires_at' => '2026-10-21 10:00:00', 'is_used' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 期限内に［マイページ登録の案内を発行］を押しても、新しく作らず期限も延ばさない
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->post(route('client-email-registration-tokens.store', $this->client))
            ->assertRedirect(route('clients.show', $this->client))
            ->assertSessionMissing('success');
        $token = ClientEmailRegistrationToken::query()->sole();
        $this->assertSame('issued-before-change-0', $token->token);
        $this->assertSame('2026-10-21 10:00:00', $token->expires_at->format('Y-m-d H:i:s'));
        $this->assertUsable($this->openRegistration('issued-before-change-0'));

        // 発行したときの期限（10/21 10:00）を過ぎたら使えない（7 日に延びない）
        Carbon::setTestNow('2026-10-21 10:00:01');
        $this->assertExpired($this->openRegistration('issued-before-change-0'));
    }

    public function test_初回設定へのリンクは今までどおり案内の期限を引き継ぐ(): void
    {
        Mail::fake();
        $token = $this->issue();

        // お客様がメールアドレスを入れたのが 10/20 でも、初回設定へのリンクの期限は案内の期限（10/25 10:00）のまま
        Carbon::setTestNow('2026-10-20 15:00:00');
        $this->post(route('client-portal.email-registration.store', ['token' => $token->token]), ['email' => 'taro@example.com'])
            ->assertOk();

        $loginLink = ClientLoginLinkToken::query()->sole();
        $this->assertSame('2026-10-25 10:00:00', $loginLink->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_メールアドレス確認リンクとパスワード再設定リンクの有効期限は3日のまま(): void
    {
        $this->assertSame(3, config('client_tokens.email_change_confirm_expires_days'));
        $this->assertSame(3, config('client_tokens.password_reset_expires_days'));
    }
}

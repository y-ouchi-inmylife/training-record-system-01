<?php

namespace Tests\Feature;

use App\Mail\ClientLoginLinkMail;
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
 *   email_registration_expires_days）。さらに、画面・文面には日付だけを出すので、期限を発行した日から 7 日後の
 *   日の終わり（23:59:59。日本時間）にした（その前は発行した時刻の 7 日後の同じ時刻で切れていた）
 * - すでに発行した案内の期限は変えない（発行したときの expires_at のまま）
 * - ログイン用リンク（初回設定へのリンク）は、今までどおり案内の期限をそのまま引き継ぐ。メールの期限は日付だけ（曜日つき）
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
        // ログインしてからの長さ（トレーナー 12 時間。EnforceAbsoluteSessionLifetime）は数えない（2026-10-21 追加）。このテストは「今日」を
        // 何日も進めて同じログインのまま開くが、見たいのは日付の決まりのため。ログインしてからの長さは EnforceAbsoluteSessionLifetimeTest で確かめる
        config(['session_roles.trainer.absolute_lifetime' => 0]);
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

    public function test_発行すると有効期限は7日後の日の終わりになる(): void
    {
        $this->assertSame(7, config('client_tokens.email_registration_expires_days'));
        $this->assertSame('Asia/Tokyo', config('app.timezone'));

        $token = $this->issue();

        $this->assertSame('2026-10-25 23:59:59', $token->expires_at->format('Y-m-d H:i:s'));
        // DB に入っている値も同じ（秒より下を持たない）
        $this->assertSame('2026-10-25 23:59:59', DB::table('client_email_registration_tokens')->value('expires_at'));
    }

    public function test_同じ日の遅い時刻に発行しても7日後の日の終わり(): void
    {
        Carbon::setTestNow('2026-10-18 23:30:00');

        $this->assertSame('2026-10-25 23:59:59', $this->issue()->expires_at->format('Y-m-d H:i:s'));
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

    public function test_7日目は日の終わりまで使え_翌日0時から使えない(): void
    {
        $token = $this->issue()->token;

        // 7 日目（10/25）は、発行した時刻（10:00）を過ぎても日の終わりまで使える
        Carbon::setTestNow('2026-10-25 15:00:00');
        $this->assertUsable($this->openRegistration($token));
        Carbon::setTestNow('2026-10-25 23:59:59');
        $this->assertUsable($this->openRegistration($token));
        // 秒より下があっても 23:59:59 のうちは使える
        Carbon::setTestNow(Carbon::parse('2026-10-25 23:59:59.900000'));
        $this->assertUsable($this->openRegistration($token));
        $this->assertSame(1, (int) Client::query()->withStatusData()->sole()->has_active_email_reg_token);
        $this->assertSame(1, (int) Client::query()->sole()->loadStatusData()->has_active_email_reg_token);
        $this->get(route('client-email-registration-tokens.print', $this->client))->assertOk()
            ->assertSee('リンクの有効期限：2026年10月25日（日）まで');

        // 翌日（10/26）の 0:00:00 から期限切れ
        Carbon::setTestNow('2026-10-26 00:00:00');
        $this->assertExpired($this->openRegistration($token));
        $this->assertNull(Client::query()->withStatusData()->sole()->has_active_email_reg_token);
        $this->assertNull(Client::query()->sole()->loadStatusData()->has_active_email_reg_token);
        $this->get(route('client-email-registration-tokens.print', $this->client))->assertOk()
            ->assertSee('発行済みのマイページ登録案内がありません');
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

        // 発行したときの期限（10/21 10:00）を過ぎたら使えない（7 日後や日の終わりに延びない）
        Carbon::setTestNow('2026-10-21 10:00:01');
        $this->assertExpired($this->openRegistration('issued-before-change-0'));
        $this->assertSame('2026-10-21 10:00:00', DB::table('client_email_registration_tokens')->value('expires_at'));
    }

    public function test_初回設定へのリンクは案内の期限を引き継ぎ_メールの期限は日付だけ(): void
    {
        Mail::fake();
        $token = $this->issue();

        // お客様がメールアドレスを入れたのが 10/20 でも、初回設定へのリンクの期限は案内の期限（10/25 23:59:59）のまま
        Carbon::setTestNow('2026-10-20 15:00:00');
        $this->post(route('client-portal.email-registration.store', ['token' => $token->token]), ['email' => 'taro@example.com'])
            ->assertOk();

        $loginLink = ClientLoginLinkToken::query()->sole();
        $this->assertSame('2026-10-25 23:59:59', $loginLink->expires_at->format('Y-m-d H:i:s'));

        // 初回設定へのリンクも、23:59:59 のうちは期限内、翌日 0:00:00 から期限切れ
        Carbon::setTestNow(Carbon::parse('2026-10-25 23:59:59.900000'));
        $this->assertFalse($loginLink->isExpired());
        Carbon::setTestNow('2026-10-26 00:00:00');
        $this->assertTrue($loginLink->isExpired());

        // メールの期限は日付だけ（曜日つき。ご案内の文面と同じ書き方。2026-10-18 変更：その前は「2026年10月25日 23:59まで」の形で時刻まで出していた）
        Mail::assertSent(ClientLoginLinkMail::class, function (ClientLoginLinkMail $mail) {
            $body = $mail->render();
            $this->assertStringContainsString("有効期限：2026年10月25日（日）まで\n", $body);
            $this->assertDoesNotMatchRegularExpression('/有効期限：[^\n]*\d{1,2}:\d{2}/u', $body);

            return true;
        });
    }

    public function test_メールアドレス確認リンクとパスワード再設定リンクの有効期限は3日のまま(): void
    {
        $this->assertSame(3, config('client_tokens.email_change_confirm_expires_days'));
        $this->assertSame(3, config('client_tokens.password_reset_expires_days'));
    }
}

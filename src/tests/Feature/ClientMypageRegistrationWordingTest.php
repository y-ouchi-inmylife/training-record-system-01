<?php

namespace Tests\Feature;

use App\Mail\ClientLoginLinkMail;
use App\Mail\ClientSetupCompletedMail;
use App\Models\Client;
use App\Models\ClientEmailRegistrationToken;
use App\Models\ClientLoginLinkToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員側のマイページ登録の画面（S-1403。URL は client-portal/setup/{token} のまま）の言い方（2026-10-18 変更）。
 *
 * 2026-10-18 のユーザーレビューで、目的がはっきり分かるよう、お客様に見せる名前を「初回設定」から「マイページ登録」にした。
 * - 見出し・タブの題：「マイページ登録」
 * - 送信のボタン：［登録する］（その前は［設定して進む］）
 * - 完了：「マイページの登録が完了しました。」／失敗：「マイページの登録を完了できませんでした。…」
 * - 使用済みのリンク：「このURLでは既にマイページの登録が完了しています。…」（S-1403・S-1405）
 * - メール（ログイン用リンク・登録完了）は、もとから「初回設定」を使っていない
 */
class ClientMypageRegistrationWordingTest extends TestCase
{
    use CreatesClientCountTables;

    private const LOGIN_TOKEN = 'login-link-token-for-wording-00000';

    private const REGISTRATION_TOKEN = 'email-registration-token-wording-0';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-18 10:00:00');
        config(['app.client_portal_name' => 'Mirai Dog Wellness']);

        $this->createClientsTable();
        foreach (['client_email_registration_tokens', 'client_login_link_tokens'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('token', 64)->unique();
                $table->unsignedBigInteger('client_id');
                $table->timestamp('expires_at');
                $table->boolean('is_used')->default(false);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        $this->client = Client::query()->create([
            'internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎', 'email' => 'owner@example.com',
        ]);
        ClientEmailRegistrationToken::query()->create([
            'token' => self::REGISTRATION_TOKEN, 'client_id' => $this->client->id,
            'expires_at' => '2026-10-25 23:59:59', 'is_used' => false,
        ]);
        ClientLoginLinkToken::query()->create([
            'token' => self::LOGIN_TOKEN, 'client_id' => $this->client->id,
            'expires_at' => '2026-10-25 23:59:59', 'is_used' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setupPage(): string
    {
        return $this->get(route('client-portal.setup.show', ['token' => self::LOGIN_TOKEN]))->assertOk()->getContent();
    }

    public function test_見出しとタブの題とボタンがマイページ登録の言い方で_初回設定が出ない(): void
    {
        $html = $this->setupPage();

        $this->assertStringContainsString('<title>マイページ登録 - Mirai Dog Wellness</title>', $html);
        $this->assertStringContainsString('<h1 class="mb-4">マイページ登録</h1>', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-primary">登録する</button>', $html);
        $this->assertStringNotContainsString('初回設定', $html);
        $this->assertStringNotContainsString('設定して進む', $html);
    }

    public function test_期限切れのリンクの画面に初回設定が出ない(): void
    {
        Carbon::setTestNow('2026-10-26 00:00:00');

        $html = $this->get(route('client-portal.setup.show', ['token' => self::LOGIN_TOKEN]))->assertOk()->getContent();

        $this->assertStringContainsString('このURLは期限切れです', $html);
        $this->assertStringNotContainsString('初回設定', $html);
    }

    public function test_使用済みのリンクの画面はマイページの登録の言い方(): void
    {
        ClientLoginLinkToken::query()->update(['is_used' => true]);
        ClientEmailRegistrationToken::query()->update(['is_used' => true]);

        foreach ([
            route('client-portal.setup.show', ['token' => self::LOGIN_TOKEN]),
            route('client-portal.email-registration.show', ['token' => self::REGISTRATION_TOKEN]),
        ] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('このURLは既に使用されています', $html, $url);
            $this->assertStringContainsString('このURLでは既にマイページの登録が完了しています。ログイン画面からログインしてください。', $html, $url);
            $this->assertStringNotContainsString('初回設定', $html, $url);
        }
    }

    public function test_メールの件名と本文に初回設定が出ない(): void
    {
        $loginLink = new ClientLoginLinkMail(ClientLoginLinkToken::query()->sole());
        $this->assertSame('【Mirai Dog Wellness】マイページのご登録手続き', $loginLink->envelope()->subject);
        $this->assertStringContainsString('マイページのご登録、ありがとうございます。', $loginLink->render());
        $this->assertStringNotContainsString('初回設定', $loginLink->render());

        $completed = new ClientSetupCompletedMail($this->client, 'https://example.test/');
        $this->assertSame('【Mirai Dog Wellness】マイページのご登録が完了しました', $completed->envelope()->subject);
        $this->assertStringContainsString('マイページのご登録が完了しました。', $completed->render());
        $this->assertStringNotContainsString('初回設定', $completed->render());
    }
}

<?php

namespace Tests\Feature;

use App\Mail\ClientLoginLinkMail;
use App\Models\Client;
use App\Models\ClientEmailRegistrationToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員メールアドレス登録画面（S-1405）の完了の状態の、届かないときの案内（2026-11-10 追加）。
 *
 * 迷惑メールフォルダに入って届かない事例が出たため、「迷惑メールフォルダもご確認ください。」の段落を足し、
 * 前からの「メールアドレスに誤りがある可能性があります。…」を「それでも届かない場合は、…」にした（設計書 S-1405 状態一覧「完了」）。
 */
class ClientEmailRegistrationDoneMessageTest extends TestCase
{
    use CreatesClientCountTables;

    private const REGISTRATION_TOKEN = 'email-registration-token-done-msg-0';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');

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

        $client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        ClientEmailRegistrationToken::query()->create([
            'token' => self::REGISTRATION_TOKEN, 'client_id' => $client->id,
            'expires_at' => '2026-11-17 23:59:59', 'is_used' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_完了の状態に迷惑メールフォルダの案内と入れ直しの案内をこの順に出す(): void
    {
        Mail::fake();

        $html = $this->post(route('client-portal.email-registration.store', ['token' => self::REGISTRATION_TOKEN]), [
            'email' => 'owner@example.com',
        ])->assertOk()->getContent();

        Mail::assertSent(ClientLoginLinkMail::class);
        $this->assertStringContainsString('<strong class="c-auth-email">owner@example.com</strong>', $html);
        $spam = strpos($html, 'メールが届かない場合は、迷惑メールフォルダもご確認ください。');
        $retry = strpos($html, 'それでも届かない場合は、メールアドレスに誤りがある可能性があります。お渡しした URL から、もう一度ご登録ください。');
        $this->assertNotFalse($spam);
        $this->assertNotFalse($retry);
        $this->assertLessThan($retry, $spam);
        // 前の 1 段落の文（「メールが届かない場合は、メールアドレスに誤りが…」）は残さない
        $this->assertStringNotContainsString('メールが届かない場合は、メールアドレスに誤りがある可能性があります。', $html);
    }
}

<?php

namespace Tests\Feature;

use App\Mail\ClientLoginLinkMail;
use App\Models\Client;
use App\Models\ClientEmailRegistrationToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 会員メールアドレス登録画面（S-1405）の送信のあとと、完了の状態。
 *
 * - 届かないときの案内（2026-11-10 追加）：迷惑メールフォルダに入って届かない事例が出たため、「迷惑メールフォルダもご確認ください。」の
 *   段落を足し、前からの「メールアドレスに誤りがある可能性があります。…」を「それでも届かない場合は、…」にした（設計書 S-1405 状態一覧「完了」）
 * - 送信のあとはリダイレクトする（2026-11-10 変更。POST → リダイレクト → GET）：送信に成功したら同じ URL の GET にリダイレクトし、
 *   入力したメールアドレスをフラッシュ submittedEmail で渡して完了の状態を出す。完了の状態で再読み込みすると入力の状態に戻り、
 *   メールは送り直さない（その前は POST の応答が完了の状態の view で、再読み込みで POST が再送信されていた）
 */
class ClientEmailRegistrationDoneMessageTest extends TestCase
{
    use CreatesClientCountTables;

    private const REGISTRATION_TOKEN = 'email-registration-token-done-msg-0';

    private Client $client;

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

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        ClientEmailRegistrationToken::query()->create([
            'token' => self::REGISTRATION_TOKEN, 'client_id' => $this->client->id,
            'expires_at' => '2026-11-17 23:59:59', 'is_used' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function showUrl(): string
    {
        return route('client-portal.email-registration.show', ['token' => self::REGISTRATION_TOKEN]);
    }

    private function storeUrl(): string
    {
        return route('client-portal.email-registration.store', ['token' => self::REGISTRATION_TOKEN]);
    }

    /** 入力の状態（見出し「マイページのご登録」とフォーム） */
    private function assertInputState(string $html): void
    {
        $this->assertStringContainsString('<h2 class="c-auth-heading">マイページのご登録</h2>', $html);
        $this->assertStringContainsString('action="'.$this->storeUrl().'"', $html);
        $this->assertStringNotContainsString('メールをお送りしました', $html);
    }

    public function test_送信に成功すると同じトークンのgetへリダイレクトする(): void
    {
        Mail::fake();

        $this->post($this->storeUrl(), ['email' => 'owner@example.com'])
            ->assertStatus(302)
            ->assertRedirect($this->showUrl())
            ->assertSessionHas('submittedEmail', 'owner@example.com');

        Mail::assertSent(ClientLoginLinkMail::class, 1);
    }

    public function test_リダイレクト先で完了の状態に迷惑メールフォルダの案内と入れ直しの案内をこの順に出す(): void
    {
        Mail::fake();

        $html = $this->followingRedirects()
            ->post($this->storeUrl(), ['email' => 'owner@example.com'])
            ->assertOk()->getContent();

        Mail::assertSent(ClientLoginLinkMail::class, 1);
        $this->assertStringContainsString('<h2 class="c-auth-heading">メールをお送りしました</h2>', $html);
        $this->assertStringContainsString('<strong class="c-auth-email">owner@example.com</strong>', $html);
        $spam = strpos($html, 'メールが届かない場合は、迷惑メールフォルダもご確認ください。');
        $retry = strpos($html, 'それでも届かない場合は、メールアドレスに誤りがある可能性があります。お渡しした URL から、もう一度ご登録ください。');
        $this->assertNotFalse($spam);
        $this->assertNotFalse($retry);
        $this->assertLessThan($retry, $spam);
        // 前の 1 段落の文（「メールが届かない場合は、メールアドレスに誤りが…」）は残さない
        $this->assertStringNotContainsString('メールが届かない場合は、メールアドレスに誤りがある可能性があります。', $html);
        // 「メールアドレス入力に戻る」は同じトークンの GET
        $this->assertStringContainsString('<a href="'.$this->showUrl().'"', $html);
    }

    public function test_完了の状態で再読み込みすると入力の状態に戻り_メールは送り直さずログイン用リンクも変わらない(): void
    {
        Mail::fake();

        $done = $this->followingRedirects()
            ->post($this->storeUrl(), ['email' => 'owner@example.com'])
            ->assertOk()->getContent();
        $this->assertStringContainsString('メールをお送りしました', $done);
        $loginLinks = DB::table('client_login_link_tokens')->orderBy('id')->get()->toArray();
        $this->assertCount(1, $loginLinks);

        // 再読み込み（同じ GET をもう一度開く）。フラッシュはもうないので入力の状態
        $reloaded = $this->get($this->showUrl())->assertOk()->getContent();

        $this->assertInputState($reloaded);
        $this->assertStringNotContainsString('owner@example.com', $reloaded);
        Mail::assertSent(ClientLoginLinkMail::class, 1);
        $this->assertEquals($loginLinks, DB::table('client_login_link_tokens')->orderBy('id')->get()->toArray());
    }

    public function test_フラッシュなしで開くと入力の状態(): void
    {
        $this->assertInputState($this->get($this->showUrl())->assertOk()->getContent());
    }

    public function test_入力エラーのときは今までどおり入力の状態に戻してエラーを出し_メールは送らない(): void
    {
        Mail::fake();
        Client::query()->create(['internal_id' => '0002', 'last_name' => '佐藤', 'email' => 'used@example.com']);

        foreach (['not-an-email', 'used@example.com'] as $email) {
            $response = $this->from($this->showUrl())->post($this->storeUrl(), ['email' => $email]);

            $response->assertRedirect($this->showUrl())->assertSessionHasErrors('email')->assertSessionMissing('submittedEmail');
            $html = $this->get($this->showUrl())->assertOk()->getContent();
            $this->assertInputState($html);
            $this->assertStringContainsString('value="'.$email.'"', $html);
        }

        Mail::assertNothingSent();
        $this->assertNull($this->client->fresh()->email);
        $this->assertSame(0, DB::table('client_login_link_tokens')->count());
    }

    public function test_メールの送信に失敗したら今までどおり取り消して入力の状態にformのエラーを出す(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('送信に失敗'));

        $this->from($this->showUrl())->post($this->storeUrl(), ['email' => 'owner@example.com'])
            ->assertRedirect($this->showUrl())
            ->assertSessionHasErrors('form')
            ->assertSessionMissing('submittedEmail');

        $html = $this->get($this->showUrl())->assertOk()->getContent();
        $this->assertInputState($html);
        $this->assertStringContainsString('メールの送信に失敗しました。時間を置いて再度お試しください。', $html);
        // トランザクションは取り消される（メールアドレスもログイン用リンクも残らない）
        $this->assertNull($this->client->fresh()->email);
        $this->assertSame(0, DB::table('client_login_link_tokens')->count());
    }
}

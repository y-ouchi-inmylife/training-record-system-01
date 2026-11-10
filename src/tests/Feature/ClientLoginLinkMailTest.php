<?php

namespace Tests\Feature;

use App\Mail\ClientLoginLinkMail;
use App\Models\AccessLog;
use App\Models\Client;
use App\Models\ClientLoginLinkToken;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 初回設定メールの表示（S-0316、GET /clients/{client}/login-link-mail。要件定義書 6-3-9。2026-11-10 追加）と、
 * 会員詳細（S-0305）の［初回設定メールを表示］の出し分け。
 *
 * - 「登録待ち（初回設定）」（初回設定待ちで期限内）のときだけ、宛先・件名・本文を出す。本文のリンクは送ったメールのリンクそのもの
 * - 件名・本文は送るメール（ClientLoginLinkMail）と同じ
 * - 読むだけ（ログイン用リンクを作り直さない・消さない）。アクセスログも残さない
 * - 条件に合わないときは「ない」表示（404 にしない）。システム管理者は 403
 *
 * ミドルウェア（auth・practitioners など）は外さず、実際の HTTP で確かめる。
 */
class ClientLoginLinkMailTest extends TestCase
{
    use CreatesClientCountTables;

    private const REGISTRATION_TOKEN = 'email-registration-token-login-mail';

    private const LOGIN_TOKEN = 'login-link-token-for-login-mail-00';

    private const EMPTY_MESSAGE = '表示できる初回設定メールはありません。';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');
        config(['app.client_portal_company' => 'Mirai Dog Wellness']);

        $this->createClientsTable();
        $this->createAccessLogsTable();
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

        $this->actingAsRole('staff');
        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => 'テスト', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    /** マイページ登録の案内（メールアドレス登録用 URL）を発行した状態にする */
    private function issueRegistrationToken(string $expiresAt = '2026-11-17 23:59:59', bool $isUsed = false): void
    {
        DB::table('client_email_registration_tokens')->insert([
            'token' => self::REGISTRATION_TOKEN, 'client_id' => $this->client->id,
            'expires_at' => $expiresAt, 'is_used' => $isUsed,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** お客様がメールアドレスを入れた状態（初回設定待ち）にする。ログイン用リンクも 1 つ作る */
    private function registerEmail(string $loginToken = self::LOGIN_TOKEN, string $expiresAt = '2026-11-17 23:59:59'): void
    {
        $this->client->update(['email' => 'owner@example.com']);
        DB::table('client_login_link_tokens')->insert([
            'token' => $loginToken, 'client_id' => $this->client->id,
            'expires_at' => $expiresAt, 'is_used' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mailPage(): string
    {
        return $this->get(route('client-login-link-mail.show', $this->client))->assertOk()->getContent();
    }

    private function setupUrl(string $token): string
    {
        return route('client-portal.setup.show', ['token' => $token]);
    }

    /** 欄（宛先・件名・本文）の中身（.invitation-message の中） */
    private function fieldText(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('/data-login-link-mail-'.$key.'>.*?<div class="invitation-message">(.*?)<\/div>/s', $html, $m), $key);

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    /** 欄の［コピー］でコピーされる文（data-copy-text） */
    private function copyText(string $html, string $key): string
    {
        $this->assertSame(1, preg_match('/data-login-link-mail-'.$key.'>.*?data-copy-text="(.*?)"/s', $html, $m), $key);

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    public function test_初回設定待ちで期限内なら_宛先_件名_本文を送るメールと同じ内容で出す(): void
    {
        $this->issueRegistrationToken();
        $this->registerEmail();

        $html = $this->mailPage();

        $mail = new ClientLoginLinkMail(ClientLoginLinkToken::query()->sole());
        $this->assertSame('owner@example.com', $this->fieldText($html, 'to'));
        $this->assertSame('【Mirai Dog Wellness】マイページのご登録手続き', $this->fieldText($html, 'subject'));
        $this->assertSame($mail->envelope()->subject, $this->fieldText($html, 'subject'));
        $this->assertSame($mail->render(), $this->fieldText($html, 'body'));
        // 本文のリンクは送ったメールのリンクそのもの（作り直さない）。改行も保つ
        $this->assertStringContainsString("\n\n".$this->setupUrl(self::LOGIN_TOKEN)."\n\n有効期限：2026年11月17日（火）まで", $this->fieldText($html, 'body'));
        // 画面に出す文と［コピー］でコピーする文は同じ
        foreach (['to', 'subject', 'body'] as $key) {
            $this->assertSame($this->fieldText($html, $key), $this->copyText($html, $key), $key);
        }
    }

    public function test_見出し_案内文_注意書き_戻る_3つのコピーを出し_qrコードは出さない(): void
    {
        $this->issueRegistrationToken();
        $this->registerEmail();

        $html = $this->mailPage();

        $this->assertStringContainsString('<h1 class="print-band print-band--mypage"><span class="print-band-label"><i class="bi bi-envelope" aria-hidden="true"></i>初回設定メール</span></h1>', $html);
        $this->assertStringContainsString('会員さんにメールが届かないときは、この内容をご自身のメールから送ってください。', $html);
        $this->assertStringContainsString('宛先は必ず下のメールアドレスにしてください。アドレスに誤りがある場合は送らずに、会員さんにお渡しした URL からメールアドレスを入れ直してもらってください。', $html);
        $this->assertMatchesRegularExpression('/<div class="alert alert-warning[^"]*" data-login-link-warning>.*本文のリンクは開かないでください。開くと、この会員さんとしてログインした状態になります。/s', $html);
        $this->assertStringContainsString('href="'.route('clients.show', $this->client).'" class="btn btn-outline-secondary btn-sm">戻る</a>', $html);
        foreach (['宛先', '件名', '本文'] as $heading) {
            $this->assertStringContainsString('aria-label="'.$heading.'をコピー" data-copy-message', $html);
        }
        $this->assertSame(3, substr_count($html, 'data-copy-area'));
        $this->assertSame(3, substr_count($html, 'data-copy-status'));
        $this->assertStringNotContainsString('data-qr-url', $html);
        $this->assertStringNotContainsString(self::EMPTY_MESSAGE, $html);
    }

    public function test_実際に送ったメールの本文と同じ_入れ直したあとは新しいメールの内容になる(): void
    {
        $this->issueRegistrationToken();
        Mail::fake();

        // お客様が S-1405 でメールアドレスを入れる（ログイン用リンクが作られ、メールが送られる）
        $this->post(route('client-portal.email-registration.store', ['token' => self::REGISTRATION_TOKEN]), ['email' => 'first@example.com'])->assertOk();
        $sentBodies = [];
        Mail::assertSent(ClientLoginLinkMail::class, function (ClientLoginLinkMail $mail) use (&$sentBodies) {
            $sentBodies[] = [$mail->envelope()->subject, $mail->render()];

            return $mail->hasTo('first@example.com');
        });

        $html = $this->mailPage();
        $this->assertSame('first@example.com', $this->fieldText($html, 'to'));
        $this->assertSame($sentBodies[0][0], $this->fieldText($html, 'subject'));
        $this->assertSame($sentBodies[0][1], $this->fieldText($html, 'body'));
        $firstToken = ClientLoginLinkToken::query()->sole()->token;
        $this->assertStringContainsString($this->setupUrl($firstToken), $this->fieldText($html, 'body'));

        // 入れ直すと前のリンクは消え、新しいメールが送られる。画面は新しいメールの内容になる
        $this->post(route('client-portal.email-registration.store', ['token' => self::REGISTRATION_TOKEN]), ['email' => 'second@example.com'])->assertOk();
        $secondToken = ClientLoginLinkToken::query()->sole()->token;
        $this->assertNotSame($firstToken, $secondToken);

        $html = $this->mailPage();
        $this->assertSame('second@example.com', $this->fieldText($html, 'to'));
        $this->assertStringContainsString($this->setupUrl($secondToken), $this->fieldText($html, 'body'));
        $this->assertStringNotContainsString($firstToken, $html);
    }

    public function test_何度開いてもログイン用リンクを作り直さず_消さず_アクセスログも残さない(): void
    {
        $this->issueRegistrationToken();
        $this->registerEmail();
        $before = DB::table('client_login_link_tokens')->orderBy('id')->get()->toArray();
        $beforeRegistration = DB::table('client_email_registration_tokens')->orderBy('id')->get()->toArray();

        Carbon::setTestNow('2026-11-10 11:00:00');
        $this->mailPage();
        $this->mailPage();
        $this->mailPage();

        $this->assertEquals($before, DB::table('client_login_link_tokens')->orderBy('id')->get()->toArray());
        $this->assertEquals($beforeRegistration, DB::table('client_email_registration_tokens')->orderBy('id')->get()->toArray());
        $this->assertSame(0, AccessLog::query()->count());
    }

    public function test_未使用のログイン用リンクが複数あれば_いちばん新しいものを出す(): void
    {
        $this->issueRegistrationToken();
        $this->registerEmail('login-link-token-old-000000000000');
        $this->registerEmail('login-link-token-new-000000000000');

        $body = $this->fieldText($this->mailPage(), 'body');

        $this->assertStringContainsString($this->setupUrl('login-link-token-new-000000000000'), $body);
        $this->assertStringNotContainsString('login-link-token-old-000000000000', $body);
    }

    public function test_使用済みのログイン用リンクしかなければ_ない表示(): void
    {
        $this->issueRegistrationToken();
        $this->registerEmail();
        DB::table('client_login_link_tokens')->update(['is_used' => true]);

        $html = $this->mailPage();

        $this->assertStringContainsString('<strong>'.self::EMPTY_MESSAGE.'</strong>', $html);
        $this->assertStringNotContainsString(self::LOGIN_TOKEN, $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notAwaitingSetupWithinExpiry(): array
    {
        return [
            '初回設定待ちで期限切れ' => ['setup_expired'],
            '利用中' => ['in_use'],
            'メールアドレス登録待ち' => ['awaiting_email'],
            'メールアドレスなし' => ['no_email'],
        ];
    }

    #[DataProvider('notAwaitingSetupWithinExpiry')]
    public function test_登録待ち初回設定でないときは_urlを直接開いてもない表示で_404にしない(string $state): void
    {
        match ($state) {
            'setup_expired' => [$this->issueRegistrationToken('2026-11-09 23:59:59'), $this->registerEmail(expiresAt: '2026-11-09 23:59:59')],
            'in_use' => [$this->issueRegistrationToken(isUsed: true), $this->registerEmail(), $this->client->update(['password' => 'password-123'])],
            'awaiting_email' => [$this->issueRegistrationToken()],
            'no_email' => [],
        };

        $html = $this->mailPage();

        $this->assertStringContainsString('<strong>'.self::EMPTY_MESSAGE.'</strong>', $html);
        $this->assertStringContainsString('初回設定メール</span></h1>', $html);
        $this->assertStringNotContainsString('data-copy-message', $html);
        $this->assertStringNotContainsString('本文のリンクは開かないでください。', $html);
        $this->assertStringNotContainsString(self::LOGIN_TOKEN, $html);
    }

    public function test_システム管理者は403(): void
    {
        $this->issueRegistrationToken();
        $this->registerEmail();
        $this->actingAsRole('system_admin');

        $this->get(route('client-login-link-mail.show', $this->client))->assertForbidden();
    }

    public function test_管理者も開ける(): void
    {
        $this->issueRegistrationToken();
        $this->registerEmail();
        $this->actingAsRole('admin');

        $this->assertStringContainsString('owner@example.com', $this->mailPage());
    }
}

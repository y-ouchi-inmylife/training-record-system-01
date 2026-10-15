<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * マイページ登録案内の印刷画面（S-0307、GET /clients/{client}/email-registration-tokens/print）。
 *
 * 2026-10 追加：「文面をコピー」の JS を共用にする前に、S-0307 が今の動きのままであることを
 * 確かめるために足した（それまで S-0307 の HTTP のテストはなかった）。
 *
 * ミドルウェア（auth・practitioners など）は外さず、実際の HTTP で確かめる。
 */
class ClientEmailRegistrationTokenPrintTest extends TestCase
{
    use CreatesClientCountTables;

    private const TOKEN = 'email-registration-token-for-print-0';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');

        $this->createClientsTable();
        $this->createAccessLogsTable();
        Schema::create('client_email_registration_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('client_id');
            $table->timestamp('expires_at');
            $table->boolean('is_used')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

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

    private function issueToken(string $expiresAt = '2026-10-17 10:00:00', bool $isUsed = false): void
    {
        DB::table('client_email_registration_tokens')->insert([
            'token' => self::TOKEN, 'client_id' => $this->client->id,
            'expires_at' => $expiresAt, 'is_used' => $isUsed,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function printPage(): string
    {
        return $this->get(route('client-email-registration-tokens.print', $this->client))->assertOk()->getContent();
    }

    /** 「文面をコピー」のボタンに入っている文面（HTML の属性の値を戻したもの） */
    private function copyText(string $html): string
    {
        $this->assertSame(1, preg_match('/data-copy-text="([^"]*)"/', $html, $m));

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    public function test_有効な案内があるとき_見出し_QR_URL_手順_有効期限_戻る_文面をコピーを出す(): void
    {
        $this->issueToken();
        $url = route('client-portal.email-registration.show', ['token' => self::TOKEN]);

        $html = $this->printPage();

        // 見出しは帯（2026-10 変更）。帯のすぐ後ろに QR コード
        $this->assertMatchesRegularExpression('/>マイページ登録のご案内<\/span><\/h1>\s*<div class="print-qr">/u', $html);
        $this->assertStringContainsString('<canvas data-qr-url="'.$url.'" data-qr-size="240"', $html);
        $this->assertStringContainsString('<div class="print-url">'.$url.'</div>', $html);
        $this->assertStringContainsString('<li>上の QR コードを読み取ります</li>', $html);
        $this->assertStringContainsString('<li>開いたページでメールアドレスを入力します</li>', $html);
        $this->assertStringContainsString('<li>届いたメールのリンクを開いて、パスワードを設定します</li>', $html);
        $this->assertStringContainsString('<p class="print-expiry-date">2026年10月17日まで</p>', $html);
        $this->assertStringContainsString('<a href="'.route('clients.show', $this->client).'" class="btn btn-outline-secondary btn-sm">&laquo; 戻る</a>', $html);
        // 操作エリアは印刷では出さない
        $this->assertStringContainsString('<div class="print-actions d-print-none d-flex flex-wrap gap-2 mb-3">', $html);
        // 文面をコピーのボタン（2026-10 に属性名を data-copy-registration-message から data-copy-message に変えた）と QR の JS
        $this->assertMatchesRegularExpression('/<button type="button"\\s+class="btn btn-outline-primary btn-sm"\\s+data-copy-message\\s+data-copy-text="[^"]*">文面をコピー<\\/button>/u', $html);
        $this->assertStringContainsString('qr-code', $html);
        $this->assertStringContainsString('copy-message', $html);
        $this->assertStringNotContainsString('copy-registration-message', $html);
    }

    public function test_文面_事業者名が設定されているとき(): void
    {
        config(['app.client_portal_company' => 'テスト訓練所']);
        $this->issueToken();
        $url = route('client-portal.email-registration.show', ['token' => self::TOKEN]);

        $html = $this->printPage();

        $this->assertSame(implode("\n", [
            'マイページのご登録は、下の URL からお願いします。',
            '',
            $url,
            '',
            '【ご登録の手順】',
            '1. 上の URL を開きます',
            '2. 開いたページでメールアドレスを入力します',
            '3. 届いたメールのリンクを開いて、パスワードを設定します',
            '',
            '【有効期限】',
            '2026年10月17日まで',
            '',
            'テスト訓練所',
        ]), $this->copyText($html));
        $this->assertStringContainsString('<p class="print-company">テスト訓練所</p>', $html);
    }

    public function test_文面_事業者名が未設定なら行ごと出さない(): void
    {
        config(['app.client_portal_company' => null]);
        $this->issueToken();

        $html = $this->printPage();

        $this->assertStringEndsWith("【有効期限】\n2026年10月17日まで", $this->copyText($html));
        $this->assertStringNotContainsString('<p class="print-company">', $html);
    }

    public function test_お客様の氏名は出さない(): void
    {
        $this->issueToken();

        $html = $this->printPage();

        $this->assertStringNotContainsString('山田', $html);
        $this->assertStringNotContainsString('太郎', $html);
    }

    public function test_有効な案内がないとき_案内を出し_QR_URL_コピーのボタンは出さない(): void
    {
        $html = $this->printPage();

        // 「ない」ことは本文の 1 行目（太字）で伝える。見出しは帯だけ（2026-10 変更）
        $this->assertStringContainsString('<strong>発行済みのマイページ登録案内がありません</strong>', $html);
        $this->assertStringContainsString('会員詳細画面から発行してください。', $html);
        $this->assertStringNotContainsString('data-qr-url', $html);
        $this->assertStringNotContainsString('<div class="print-url">', $html);
        $this->assertStringNotContainsString('文面をコピー', $html);
        // 戻るリンクは出す
        $this->assertStringContainsString('&laquo; 戻る</a>', $html);
    }

    public function test_期限切れや使用済みの案内は有効な案内として扱わない(): void
    {
        $this->issueToken('2026-10-13 10:00:00');
        $this->assertStringContainsString('発行済みのマイページ登録案内がありません', $this->printPage());

        DB::table('client_email_registration_tokens')->delete();
        $this->issueToken('2026-10-17 10:00:00', isUsed: true);
        $this->assertStringContainsString('発行済みのマイページ登録案内がありません', $this->printPage());
    }

    public function test_マイページ登録のご案内の青の帯を見出しにしアイコンは読み上げない(): void
    {
        $this->issueToken();

        $html = $this->printPage();

        // 帯＝見出し（h1。2026-10 変更）：文字は「マイページ登録のご案内」、色は青（print-band--mypage）、アイコンは aria-hidden
        $this->assertMatchesRegularExpression('/<h1 class="print-band print-band--mypage"><span class="print-band-label"><i class="bi bi-person-circle" aria-hidden="true"><\/i>マイページ登録のご案内<\/span><\/h1>/u', $html);
        // 操作エリアの下
        $this->assertMatchesRegularExpression('/print-actions.*<h1 class="print-band print-band--mypage">/su', $html);
        // 見出しは帯の 1 つだけ。「マイページ登録のご案内」は <title> を除くと帯の 1 か所だけ
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertSame(1, substr_count(preg_replace('/<title>.*?<\/title>/su', '', $html), 'マイページ登録のご案内'));
        $this->assertStringNotContainsString('print-title', $html);
        // 受付アンケートの帯は出さない
        $this->assertStringNotContainsString('print-band--intake', $html);
    }

    public function test_有効な案内がないときもマイページ登録の帯を見出しにする(): void
    {
        $html = $this->printPage();

        $this->assertMatchesRegularExpression('/<h1 class="print-band print-band--mypage"><span class="print-band-label"><i class="bi bi-person-circle" aria-hidden="true"><\/i>マイページ登録のご案内<\/span><\/h1>/u', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_システム管理者は403(): void
    {
        $this->actingAsRole('system_admin');
        $this->issueToken();

        $this->get(route('client-email-registration-tokens.print', $this->client))->assertForbidden();
    }
}

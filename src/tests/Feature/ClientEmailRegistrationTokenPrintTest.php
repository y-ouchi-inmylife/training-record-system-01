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
 * マイページ登録のご案内の画面（S-0307、GET /clients/{client}/email-registration-tokens/print）。
 *
 * 2026-10-18 変更：印刷して渡す作りをやめ、「LINE・メールで送る」（文面を画面に出し、［文面をコピー］で同じ文をコピー）と
 * 「その場で読み取ってもらう」（QR コード・有効期限）の 2 つの欄にした（設計書 S-0307）。文面は App\Support\InvitationMessage。
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

    /** QR コードの欄（data-invitation-qr の section）の HTML */
    private function qrPanel(string $html): string
    {
        $start = strpos($html, 'data-invitation-qr>');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</section>', $start) - $start);
    }

    /** 画面に出している文面（data-invitation-message の中身を戻したもの） */
    private function shownText(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="invitation-message" data-invitation-message>(.*?)<\/div>/su', $html, $m));

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    public function test_有効な案内があるとき_2つの欄と戻る_文面をコピー_QRコード_有効期限を出す(): void
    {
        $this->issueToken();
        $url = route('client-portal.email-registration.show', ['token' => self::TOKEN]);

        $html = $this->printPage();

        // 見出しは帯。その下に「LINE・メールで送る」（左・上）と「その場で読み取ってもらう」（右・下）の欄
        $this->assertMatchesRegularExpression('/>マイページ登録のご案内<\/span><\/h1>\s*<div class="row g-3 align-items-start">/u', $html);
        $this->assertLessThan(strpos($html, 'QR コード</h2>'), strpos($html, 'LINE・メールで送る</h2>'));
        // ［コピー］は「LINE・メールで送る」の見出しの行の右（青の塗り・小さいボタン。読み上げは「文面をコピー」）。文面の下にボタンはない
        // （2026-10-18 変更：その前は文面の下の右に［文面をコピー］）。ボタンの左に知らせの場所（aria-live。押すと「コピーしました」を出す）。
        // ボタンの文字は［コピー］のまま変えず、最低の幅も付けない（2026-10-18 変更：その前はボタンの文字を変え、「コピーしました」の幅を最低の幅にしていた）。
        // ボタンは小さいボタン（btn-sm。2026-10-18 変更：その前はふつうの大きさ）
        $this->assertMatchesRegularExpression('/<div class="card-header invitation-panel-header invitation-send-header" data-copy-area>\\s*'
            .'<h2 class="h6 mb-0" id="invitation-message-heading">LINE・メールで送る<\\/h2>\\s*'
            .'<span class="invitation-copy-status small" role="status" aria-live="polite" data-copy-status><\\/span>\\s*'
            .'<button type="button" class="btn btn-primary btn-sm" aria-label="文面をコピー" data-copy-message data-copy-text="[^"]*">コピー<\\/button>\\s*<\\/div>\\s*'
            .'<div class="card-body">\\s*<div class="invitation-message" data-invitation-message>[^<]*<\\/div>\\s*<\\/div>/u', $html);
        $this->assertSame(1, substr_count($html, 'data-copy-message '));
        $this->assertSame(1, substr_count($html, 'aria-live="polite" data-copy-status'));
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*data-copy-message[^>]*style=/u', $html);
        $this->assertStringNotContainsString('min-width', $html);
        $this->assertStringNotContainsString('>文面をコピー<', $html);
        $this->assertMatchesRegularExpression('/<div class="card-header invitation-panel-header d-flex align-items-center">\\s*<h2 class="h6 mb-0" id="invitation-qr-heading">QR コード<\\/h2>/u', $html);
        // QR コード（中身は URL）・お客様向けの一文・有効期限（曜日つき）
        $this->assertStringContainsString('<canvas data-qr-url="'.$url.'" data-qr-size="280" aria-label="マイページ登録案内の QR コード"></canvas>', $html);
        // QR コードの欄は見出し「QR コード」と QR コードだけ（2026-10-18 変更：その前は見出し「その場で読み取ってもらう」と、
        // 下にお客様向けの一文・有効期限〔または 1 回かぎりの文〕。QR コードはメール・LINE に添付することもあり、説明は過剰なため）
        $qr = $this->qrPanel($html);
        $this->assertStringContainsString('<h2 class="h6 mb-0" id="invitation-qr-heading">QR コード</h2>', $qr);
        foreach (['その場で読み取ってもらう', 'スマホのカメラで読み取ってください。', '有効期限', 'ご回答は1回かぎり', 'data-invitation-qr-note'] as $text) {
            $this->assertStringNotContainsString($text, $qr, $text);
        }
        $this->assertStringNotContainsString('その場で読み取ってもらう', $html);
        $this->assertStringNotContainsString('スマホのカメラで読み取ってください。', $html);
        // 有効期限は左の文面の中にある
        $this->assertStringContainsString('リンクの有効期限：2026年10月17日（土）まで', $this->shownText($html));
        $this->assertStringContainsString('<a href="'.route('clients.show', $this->client).'" class="btn btn-outline-secondary btn-sm">戻る</a>', $html);
        $this->assertStringContainsString('qr-code', $html);
        $this->assertStringContainsString('copy-message', $html);
    }

    public function test_印刷のための作りと読み取りの手順は出さない(): void
    {
        $this->issueToken();

        $html = $this->printPage();

        foreach (['@page', 'A4', 'd-print-none', 'print-actions', 'print-qr', 'print-url', 'print-steps', 'print-lead', 'ご登録の手順', '上の QR コードを読み取ります', '上の QR コードを読み取るか'] as $text) {
            $this->assertStringNotContainsString($text, $html, $text);
        }
        // トレーナー側のふつうの画面（ナビゲーションあり）
        $this->assertStringContainsString('<nav', $html);
    }

    public function test_文面_画面に出す文とコピーする文は同じで氏名_訓練所名_URL_有効期限が入る(): void
    {
        config(['app.client_portal_company' => 'テスト訓練所']);
        $this->issueToken();
        $url = route('client-portal.email-registration.show', ['token' => self::TOKEN]);

        $html = $this->printPage();

        $expected = implode("\n", [
            '山田 太郎 様',
            '',
            'テスト訓練所です。',
            'マイページのご登録のご案内をお送りします。',
            '',
            '下のリンクを開いて、メールアドレスをご入力ください。',
            '届いたメールのリンクから、パスワードなどを設定していただくと、ご登録が完了します。',
            '',
            $url,
            '',
            'リンクの有効期限：2026年10月17日（土）まで',
            'ご不明な点がございましたら、お気軽にお問い合わせください。',
        ]);
        $this->assertSame($expected, $this->copyText($html));
        $this->assertSame($expected, $this->shownText($html));
    }

    public function test_文面_訓練所名が未設定なら訓練所名の行を出さない(): void
    {
        config(['app.client_portal_company' => null]);
        $this->issueToken();

        $text = $this->copyText($this->printPage());

        $this->assertStringStartsWith("山田 太郎 様\n\nマイページのご登録のご案内をお送りします。", $text);
        // 3 行目が案内の 1 文（訓練所名の行がない）
        $this->assertSame('マイページのご登録のご案内をお送りします。', explode("\n", $text)[2]);
    }

    public function test_文面_名がなければ姓だけ(): void
    {
        $this->client->update(['first_name' => null]);
        $this->issueToken();

        $this->assertStringStartsWith("山田 様\n", $this->copyText($this->printPage()));
    }

    public function test_有効な案内がないとき_案内を出し_QR_URL_コピーのボタンは出さない(): void
    {
        $html = $this->printPage();

        // 「ない」ことは本文の 1 行目（太字）で伝える。見出しは帯だけ（2026-10 変更）
        $this->assertStringContainsString('<strong>発行済みのマイページ登録案内がありません</strong>', $html);
        $this->assertStringContainsString('会員詳細画面から発行してください。', $html);
        $this->assertStringNotContainsString('data-qr-url', $html);
        $this->assertStringNotContainsString('data-invitation-message', $html);
        $this->assertStringNotContainsString('data-copy-message', $html);
        $this->assertStringNotContainsString('data-copy-status', $html);
        // ［戻る］は出す（会員詳細へのふつうのリンク。2026-10-17 変更：その前は［閉じる］）
        $this->assertStringContainsString('<a href="'.route('clients.show', $this->client).'" class="btn btn-outline-secondary btn-sm">戻る</a>', $html);
        $this->assertStringNotContainsString('閉じる', $html);
        $this->assertStringNotContainsString('data-close-tab', $html);
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
        // ［戻る］の下
        $this->assertMatchesRegularExpression('/>戻る<\/a>\s*<\/div>\s*<h1 class="print-band print-band--mypage">/su', $html);
        // ページの見出し（h1）は帯の 1 つだけ。「マイページ登録のご案内」は <title> を除くと帯の 1 か所だけ
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertSame(1, substr_count(preg_replace('/<title>.*?<\/title>/su', '', $html), 'マイページ登録のご案内'));
        $this->assertStringNotContainsString('print-title', $html);
        // 愛犬アンケートの帯は出さない
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

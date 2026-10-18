<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Trainee;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 受付アンケート案内画面（S-0311、GET /trainees/{trainee}/intake-survey-tokens/print。2026-10 追加）。
 *
 * マイページ登録案内の印刷画面（S-0307）と同じ作り。有効期限は載せず「1回かぎり」の文、
 * 愛犬の名前の行（未登録なら「愛犬」）、戻り先は会員詳細、お客様の氏名は載せない。
 * テストでは会員側のホスト（CLIENT_HOST）が空なので、URL はパスで確かめる。
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 */
class TraineeIntakeSurveyTokenPrintTest extends TestCase
{
    use CreatesClientCountTables;

    private const TOKEN = 'intake-survey-token-for-print-00000';

    private Client $client;

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createAccessLogsTable();
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();

        $this->actingAsRole('staff');
        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        $this->trainee = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'モモ']);
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => 'テスト', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    private function issueToken(?string $answeredAt = null): void
    {
        DB::table('trainee_intake_survey_tokens')->insert([
            'token' => self::TOKEN, 'trainee_id' => $this->trainee->id, 'answered_at' => $answeredAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function printPage(): string
    {
        return $this->get(route('trainee-intake-survey-tokens.print', $this->trainee))->assertOk()->getContent();
    }

    private function copyText(string $html): string
    {
        $this->assertSame(1, preg_match('/data-copy-text="([^"]*)"/', $html, $m));

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    private function surveyUrl(): string
    {
        return route('client-portal.intake-survey.show', ['token' => self::TOKEN]);
    }

    /** 画面に出している文面（data-invitation-message の中身を戻したもの） */
    private function shownText(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="invitation-message" data-invitation-message>(.*?)<\/div>/su', $html, $m));

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    // 2026-10-18 変更：印刷して渡す作りをやめ、「LINE・メールで送る」「その場で読み取ってもらう」の 2 つの欄にした（設計書 S-0311・S-0307）。
    // 文面には会員の氏名と愛犬の呼び名を入れる（その前は、紙をなくしたときに漏れないよう、どちらも載せていなかった）

    public function test_有効な案内があるとき_2つの欄と戻る_文面をコピー_QRコード_1回かぎりを出す(): void
    {
        $this->issueToken();
        $url = $this->surveyUrl();

        $html = $this->printPage();

        $this->assertSame('/client-portal/intake-survey/'.self::TOKEN, parse_url($url, PHP_URL_PATH));
        $this->assertMatchesRegularExpression('/>受付アンケートのご案内<\/span><\/h1>\s*<div class="row g-3 align-items-start">/u', $html);
        $this->assertLessThan(strpos($html, 'その場で読み取ってもらう</h2>'), strpos($html, 'LINE・メールで送る</h2>'));
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-primary" data-copy-message data-copy-text="[^"]*">文面をコピー<\/button>/u', $html);
        $this->assertStringContainsString('<canvas data-qr-url="'.$url.'" data-qr-size="280" aria-label="受付アンケート案内の QR コード"></canvas>', $html);
        $this->assertStringContainsString('<p class="mt-3 mb-1 fw-bold">スマホのカメラで読み取ってください。</p>', $html);
        // 有効期限は持たない（代わりに 1 回かぎりの文）
        $this->assertStringContainsString('<p class="mb-0 text-muted" data-invitation-qr-note>ご回答は1回かぎりです。送信すると、このリンクは使えなくなります。</p>', $html);
        $this->assertStringNotContainsString('有効期限', $html);
        $this->assertStringContainsString('<a href="'.route('clients.show', $this->client).'" class="btn btn-outline-secondary btn-sm">戻る</a>', $html);
        $this->assertStringContainsString('copy-message', $html);
        $this->assertStringContainsString('qr-code', $html);
    }

    public function test_印刷のための作りと読み取りの手順は出さない(): void
    {
        $this->issueToken();

        $html = $this->printPage();

        foreach (['@page', 'A4', 'd-print-none', 'print-actions', 'print-qr', 'print-url', 'print-steps', 'print-lead', 'ご回答の手順', '上の QR コードを読み取ります', '上の QR コードを読み取るか'] as $text) {
            $this->assertStringNotContainsString($text, $html, $text);
        }
    }

    public function test_文面_画面に出す文とコピーする文は同じで氏名_訓練所名_呼び名_URLが入る(): void
    {
        config(['app.client_portal_company' => 'テスト訓練所']);
        $this->issueToken();

        $html = $this->printPage();

        $expected = implode("\n", [
            '山田 太郎 様',
            '',
            'テスト訓練所です。',
            'モモちゃんの受付アンケートのご案内をお送りします。',
            '',
            '下のリンクから、ご回答をお願いいたします。',
            '',
            $this->surveyUrl(),
            '',
            'ご回答は1回かぎりです。送信すると、このリンクは使えなくなります。',
            'ご不明な点がございましたら、お気軽にお問い合わせください。',
        ]);
        $this->assertSame($expected, $this->copyText($html));
        $this->assertSame($expected, $this->shownText($html));
    }

    public function test_文面_名前が未登録なら呼び名は愛犬で訓練所名が未設定なら行を出さない(): void
    {
        config(['app.client_portal_company' => null]);
        $this->trainee->update(['name' => null]);
        $this->issueToken();

        $text = $this->copyText($this->printPage());

        $this->assertStringStartsWith("山田 太郎 様\n\n愛犬の受付アンケートのご案内をお送りします。\n", $text);
        $this->assertStringNotContainsString('愛犬ちゃん', $text);
    }

    public function test_有効な案内がないとき_案内を出し_QR_URL_コピーのボタンは出さない(): void
    {
        $html = $this->printPage();

        // 「ない」ことは本文（太字）で伝える。見出しは帯だけ（2026-10 変更）
        $this->assertStringContainsString('<strong>有効な受付アンケート案内がありません。</strong>', $html);
        $this->assertStringNotContainsString('data-qr-url', $html);
        $this->assertStringNotContainsString('data-invitation-message', $html);
        $this->assertStringNotContainsString('文面をコピー', $html);
        $this->assertStringNotContainsString('発行', $html);
        // ［戻る］は出す（会員詳細へのふつうのリンク。2026-10-17 変更：その前は［閉じる］）
        $this->assertStringContainsString('<a href="'.route('clients.show', $this->client).'" class="btn btn-outline-secondary btn-sm">戻る</a>', $html);
        $this->assertStringNotContainsString('閉じる', $html);
        $this->assertStringNotContainsString('data-close-tab', $html);
    }

    public function test_回答済みの案内しかなければ有効な案内がないとして扱う(): void
    {
        $this->issueToken('2026-10-16 10:00:00');

        $this->assertStringContainsString('有効な受付アンケート案内がありません。', $this->printPage());
    }

    public function test_表示はアクセスログに記録しない(): void
    {
        $this->issueToken();
        $this->printPage();

        $this->assertSame(0, AccessLog::query()->count());
    }

    public function test_受付アンケートのご案内の緑の帯を見出しにしアイコンは読み上げない(): void
    {
        $this->issueToken();

        $html = $this->printPage();

        // 帯＝見出し（h1。2026-10 変更）：文字は「受付アンケートのご案内」、色は緑（print-band--intake）、アイコンは aria-hidden
        $this->assertMatchesRegularExpression('/<h1 class="print-band print-band--intake"><span class="print-band-label"><i class="bi bi-clipboard-check" aria-hidden="true"><\/i>受付アンケートのご案内<\/span><\/h1>/u', $html);
        // ［戻る］の下
        $this->assertMatchesRegularExpression('/>戻る<\/a>\s*<\/div>\s*<h1 class="print-band print-band--intake">/su', $html);
        // 見出しは帯の 1 つだけ（文面の「受付アンケートのご案内をお送りします。」は見出しではない。2026-10-18 変更）
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertSame(1, substr_count($html, '受付アンケートのご案内</span>'));
        $this->assertStringNotContainsString('print-title', $html);
        // マイページ登録の帯は出さない
        $this->assertStringNotContainsString('print-band--mypage', $html);
    }

    public function test_有効な案内がないときも受付アンケートの帯を見出しにする(): void
    {
        $html = $this->printPage();

        $this->assertMatchesRegularExpression('/<h1 class="print-band print-band--intake"><span class="print-band-label"><i class="bi bi-clipboard-check" aria-hidden="true"><\/i>受付アンケートのご案内<\/span><\/h1>/u', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_システム管理者は403(): void
    {
        $this->actingAsRole('system_admin');
        $this->issueToken();

        $this->get(route('trainee-intake-survey-tokens.print', $this->trainee))->assertForbidden();
    }
}

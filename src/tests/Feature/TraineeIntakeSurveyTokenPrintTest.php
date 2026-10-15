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

    public function test_有効な案内があるとき_見出し_名前_QR_URL_手順_1回かぎり_戻る_文面をコピー(): void
    {
        $this->issueToken();
        $url = $this->surveyUrl();

        $html = $this->printPage();

        $this->assertSame('/client-portal/intake-survey/'.self::TOKEN, parse_url($url, PHP_URL_PATH));
        $this->assertStringContainsString('<h1 class="print-title">受付アンケートのご案内</h1>', $html);
        // 見出しのすぐ下に QR コード。「愛犬のことを教えてください」の行は置かない（2026-10 変更。設計書 S-0311）
        $this->assertMatchesRegularExpression('/<h1 class="print-title">受付アンケートのご案内<\/h1>\s*<div class="print-qr">/u', $html);
        $this->assertStringNotContainsString('愛犬のことを教えてください', $html);
        $this->assertStringContainsString('<canvas data-qr-url="'.$url.'" data-qr-size="240"', $html);
        $this->assertStringContainsString('<div class="print-url">'.$url.'</div>', $html);
        $this->assertStringContainsString('<p class="print-section-title">ご回答の手順</p>', $html);
        $this->assertStringContainsString('<li>上の QR コードを読み取ります</li>', $html);
        $this->assertStringContainsString('<li>開いたページで、愛犬のことと暮らしの様子をご記入ください</li>', $html);
        $this->assertStringContainsString('<li>「送信する」を押すと完了です</li>', $html);
        $this->assertStringContainsString('ご回答は1回かぎりです。送信すると、この案内は使えなくなります。', $html);
        // 有効期限は載せない
        $this->assertStringNotContainsString('有効期限', $html);
        $this->assertStringNotContainsString('<p class="print-expiry-date">', $html);
        // 戻り先は会員詳細。操作エリアは印刷では出さない
        $this->assertStringContainsString('<div class="print-actions d-print-none d-flex flex-wrap gap-2 mb-3">', $html);
        $this->assertStringContainsString('<a href="'.route('clients.show', $this->client).'" class="btn btn-outline-secondary btn-sm">&laquo; 戻る</a>', $html);
        // 文面をコピー（S-0307 と共用の copy-message.js）と QR の JS
        $this->assertMatchesRegularExpression('/<button type="button"\\s+class="btn btn-outline-primary btn-sm"\\s+data-copy-message\\s+data-copy-text="[^"]*">文面をコピー<\\/button>/u', $html);
        $this->assertStringContainsString('copy-message', $html);
        $this->assertStringContainsString('qr-code', $html);
    }

    public function test_文面_名前があっても名前を使わず事業者名が設定されているとき(): void
    {
        config(['app.client_portal_company' => 'テスト訓練所']);
        $this->issueToken();

        $html = $this->printPage();

        $this->assertSame(implode("\n", [
            'トレーニングの参考にするため、受付アンケートへのご回答をお願いします。',
            '下のリンクから、愛犬のことと暮らしの様子をお聞かせください。',
            '',
            $this->surveyUrl(),
            '',
            'ご回答は1回かぎりです。送信すると、このリンクは使えなくなります。',
            '',
            'テスト訓練所',
        ]), $this->copyText($html));
        $this->assertStringContainsString('<p class="print-company">テスト訓練所</p>', $html);
        $this->assertStringNotContainsString('モモ', $this->copyText($html));
    }

    public function test_名前が未登録でも名前があるときと同じ文(): void
    {
        config(['app.client_portal_company' => null]);
        $this->trainee->update(['name' => null]);
        $this->issueToken();

        $html = $this->printPage();

        $this->assertStringNotContainsString('愛犬のことを教えてください', $html);
        $this->assertStringNotContainsString('愛犬ちゃん', $html);
        $this->assertSame(implode("\n", [
            'トレーニングの参考にするため、受付アンケートへのご回答をお願いします。',
            '下のリンクから、愛犬のことと暮らしの様子をお聞かせください。',
            '',
            $this->surveyUrl(),
            '',
            'ご回答は1回かぎりです。送信すると、このリンクは使えなくなります。',
        ]), $this->copyText($html));
        $this->assertStringNotContainsString('愛犬のことを教えてください', $this->copyText($html));
        // 事業者名が未設定なら、訓練所名の行も文面の最後の行も出さない
        $this->assertStringNotContainsString('<p class="print-company">', $html);
    }

    public function test_名前があってもページに愛犬の名前を出さない(): void
    {
        $this->issueToken();

        $this->assertStringNotContainsString('モモ', $this->printPage());
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

        $this->assertStringContainsString('<h1 class="print-title">有効な受付アンケート案内がありません。</h1>', $html);
        $this->assertStringNotContainsString('data-qr-url', $html);
        $this->assertStringNotContainsString('<div class="print-url">', $html);
        $this->assertStringNotContainsString('文面をコピー', $html);
        $this->assertStringNotContainsString('発行', $html);
        $this->assertStringContainsString('&laquo; 戻る</a>', $html);
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

    public function test_見出しの上に受付アンケートの緑の帯を出しアイコンは読み上げない(): void
    {
        $this->issueToken();

        $html = $this->printPage();

        // 帯（2026-10 追加）：文字は「受付アンケート」、色は緑（print-band--intake）、アイコンは aria-hidden
        $this->assertMatchesRegularExpression('/<div class="print-band print-band--intake">\s*<p class="print-band-label"><i class="bi bi-clipboard-check" aria-hidden="true"><\/i>受付アンケート<\/p>\s*<\/div>/u', $html);
        // 操作エリアの下、見出しの上
        $this->assertMatchesRegularExpression('/print-actions.*<div class="print-band print-band--intake">.*<h1 class="print-title">受付アンケートのご案内<\/h1>/su', $html);
        // マイページ登録の帯は出さない
        $this->assertStringNotContainsString('print-band--mypage', $html);
    }

    public function test_有効な案内がないときも受付アンケートの帯を出す(): void
    {
        $html = $this->printPage();

        $this->assertStringContainsString('<div class="print-band print-band--intake">', $html);
        $this->assertStringContainsString('<h1 class="print-title">有効な受付アンケート案内がありません。</h1>', $html);
    }

    public function test_システム管理者は403(): void
    {
        $this->actingAsRole('system_admin');
        $this->issueToken();

        $this->get(route('trainee-intake-survey-tokens.print', $this->trainee))->assertForbidden();
    }
}

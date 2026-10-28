<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Client;
use App\Models\Trainer;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * トレーナー側のダッシュボード（S-0201）の「連絡先が未入力の会員」（2026-10-28 追加。要件定義書 6-2-1）。
 *
 * - 対象は会員側の「ご連絡先の登録のお願い」と同じ（契約か利用が 1 件以上かつ、住所〔都道府県・市区町村・町名番地のどれか〕か電話番号が空。
 *   2026-10-28 変更：その前は契約が 1 件以上）
 * - 0 件ならセクションごと出さない
 * - 並びは契約がない（利用だけの）会員が先、次に最新の契約日の新しい順、同じ日なら id の大きい順。契約がない会員の最新の契約日は空欄
 * - マイページの列は会員一覧と同じ列見出し・同じバッジ、未入力は「住所」「電話番号」のバッジ
 * - 行全体は押せないので table-hover は付けない（2026-10-28 変更）
 * - 5 件ずつのページ送り（ページの番号は contacts_page。範囲外なら最後のページ。ほかの欄の絞り込み・ページと状態を受け渡す。2026-10-28 変更）
 */
class DashboardContactMissingClientsTest extends TestCase
{
    use CreatesClientCountTables;

    /** section() で最後に開いたダッシュボードの HTML（見出しの確認用） */
    private string $lastHtml = '';

    private const FILLED = [
        'phone1' => '090-1234-5678', 'address1' => '東京都', 'address2' => '千代田区', 'address3' => '1-1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 12:00:00');

        $this->createClientsTable();
        $this->createClientCountTables();
        $this->createClientEmailRegistrationTokensTable();
        $this->createAccessLogsTable();
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->timestamps();
        });
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();
        $this->createTrainingRecordsTableForPersonalityDiagnosis();
        Schema::create('media_records', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('media_record_training_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('media_record_id');
            $table->unsignedBigInteger('training_record_id');
            $table->integer('sort_order')->default(0);
        });

        $trainer = new Trainer(['name' => '佐藤', 'role' => 'staff']);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 会員を作り、$contractedOn の日付ごとに契約を、$usedOn の日付ごとに「契約なし」の利用を入れる */
    private function makeClient(string $lastName, array $contact, array $contractedOn = [], array $usedOn = []): Client
    {
        $client = Client::query()->create(['internal_id' => $lastName, 'last_name' => $lastName, ...$contact]);
        foreach ($contractedOn as $date) {
            DB::table('client_contracts')->insert(['client_id' => $client->id, 'contracted_on' => $date, 'count' => 4]);
        }
        foreach ($usedOn as $date) {
            DB::table('client_usages')->insert(['client_id' => $client->id, 'client_contract_id' => null, 'used_on' => $date]);
        }

        return $client;
    }

    /** セクションの表の HTML（なければ null）。$query はダッシュボードのクエリ文字列（「?contacts_page=2」など） */
    private function section(string $query = ''): ?string
    {
        $html = $this->get(route('dashboard').$query)->assertOk()->getContent();
        $this->lastHtml = $html;

        return preg_match('/<table class="table" data-contact-missing>(.*?)<\/table>/s', $html, $m) ? $m[1] : null;
    }

    /** 表の行（tbody の tr）ごとの HTML */
    private function rows(string $section): array
    {
        preg_match_all('/<tr>.*?<\/tr>/s', explode('<tbody>', $section)[1], $rows);

        return $rows[0];
    }

    public function test_対象がいなければセクションごと出さない(): void
    {
        // 契約も利用もない会員は未入力でも出さない。契約か利用があっても入力済みなら出さない
        $this->makeClient('契約も利用もなし', []);
        $this->makeClient('利用だけ入力済み', self::FILLED, [], ['2026-10-01']);
        $this->makeClient('入力済み', self::FILLED, ['2026-10-01']);

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('連絡先が未入力の会員', $html);
        $this->assertStringNotContainsString('data-contact-missing', $html);
    }

    public function test_会員側と同じ条件で対象を絞る(): void
    {
        // 郵便番号・建物名・緊急連絡先は見ない
        $this->makeClient('任意だけ空', [...self::FILLED, 'postal_code' => null, 'address4' => null, 'phone2' => null], ['2026-10-01']);
        $noPhone = $this->makeClient('電話空文字', [...self::FILLED, 'phone1' => ''], ['2026-10-02']);
        $noCity = $this->makeClient('市区町村なし', [...self::FILLED, 'address2' => null], ['2026-10-03']);
        $this->makeClient('契約も利用もなし', []);

        $rows = $this->rows($this->section());
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('市区町村なし', $rows[0]);
        $this->assertStringContainsString('電話空文字', $rows[1]);
        foreach ([$noPhone, $noCity] as $client) {
            $this->assertTrue($client->requiresContactDetails());
            $this->assertNotSame([], $client->fresh()->missingContactDetails());
        }
    }

    public function test_最新の契約日の新しい順で同じ日はidの大きい順(): void
    {
        $this->makeClient('古い', [], ['2026-09-01', '2026-10-10']);
        $this->makeClient('新しい', [], ['2026-10-20', '2026-08-01']);
        $this->makeClient('同じ日', [], ['2026-10-10']);

        $rows = $this->rows($this->section());
        $this->assertCount(3, $rows);
        $this->assertStringContainsString('2026/10/20', $rows[0]);
        $this->assertStringContainsString('新しい', $rows[0]);
        $this->assertStringContainsString('同じ日', $rows[1]);
        $this->assertStringContainsString('古い', $rows[2]);
        $this->assertStringContainsString('2026/10/10', $rows[2]);
    }

    public function test_会員のリンクとマイページと未入力のバッジ(): void
    {
        $both = $this->makeClient('両方', [], ['2026-10-01']);
        $this->makeClient('住所だけ', [...self::FILLED, 'address3' => ''], ['2026-10-02']);

        $section = $this->section();
        // 列見出しは会員一覧にそろえて「マイページ」（2026-10-28 変更：その前は「状態」）
        $this->assertStringContainsString('<th class="text-nowrap">マイページ</th>', $section);
        $this->assertStringNotContainsString('<th class="text-nowrap">状態</th>', $section);

        $rows = $this->rows($section);
        $this->assertStringContainsString('<span class="badge bg-warning text-dark">住所</span>', $rows[0]);
        $this->assertStringNotContainsString('電話番号', $rows[0]);

        $this->assertStringContainsString('<a href="'.route('clients.show', $both).'">', $rows[1]);
        $this->assertMatchesRegularExpression('/>住所<\/span>\s*<span class="badge bg-warning text-dark">電話番号<\/span>/', $rows[1]);
        // 会員一覧と同じ状態バッジ（メールアドレスもトークンもない会員は「未登録」）
        $badge = $both->fresh()->statusBadge();
        $this->assertStringContainsString('<span class="badge '.$badge['class'].'">'.$badge['label'].'</span>', $rows[1]);
    }

    public function test_列は内部idと会員とマイページと最新の契約日と未入力の順(): void
    {
        // 2026-10-28 変更：内部IDを足し、最新の契約日を左端からマイページの右に移した
        $client = $this->makeClient('山田', [], ['2026-10-05']);
        DB::table('clients')->where('id', $client->id)->update(['internal_id' => '1234']);

        $section = $this->section();
        preg_match_all('/<th class="text-nowrap">(.*?)<\/th>/', $section, $headings);
        $this->assertSame(['内部ID', '会員', 'マイページ', '最新の契約日', '未入力'], $headings[1]);

        // 内部ID は会員一覧（S-0304）と同じく internal_id を書式なしで出し、リンクにしない。セルの並びも列見出しと同じ
        $row = $this->rows($section)[0];
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $row, $cells);
        $this->assertCount(5, $cells[1]);
        $this->assertSame('<td class="text-nowrap">1234</td>', $cells[0][0]);
        $this->assertStringContainsString('<a href="'.route('clients.show', $client).'">', $cells[1][1]);
        $this->assertStringContainsString('class="badge', $cells[1][2]);
        $this->assertSame('2026/10/05', $cells[1][3]);
        $this->assertStringContainsString('>住所</span>', $cells[1][4]);
    }

    public function test_見出しの先頭に警告マークを付ける(): void
    {
        // 2026-10-28 追加：赤い丸に白い「！」（bi-exclamation-circle-fill・text-danger）。飾りなので aria-hidden。見出しの文言は変えない
        $this->makeClient('山田', [], ['2026-10-05']);
        $this->section();

        $this->assertMatchesRegularExpression(
            '/<h4 class="mb-2" data-contact-missing-heading><i class="bi bi-exclamation-circle-fill text-danger me-1" aria-hidden="true"><\/i>連絡先が未入力の会員<\/h4>/',
            $this->lastHtml,
        );
    }

    public function test_契約がなく利用だけの会員も出し最新の契約日は空欄で一番上(): void
    {
        // 2026-10-28 追加：契約を消して利用だけが「契約なし」で残った会員（すでに犬を預かっているのに契約がなく、いちばん急ぐ）
        $this->makeClient('新しい契約', [], ['2026-10-20']);
        $usageOnly = $this->makeClient('利用だけ', [...self::FILLED, 'phone1' => null], [], ['2026-10-01', '2026-10-08']);
        $this->makeClient('古い契約', [], ['2026-09-01']);

        $rows = $this->rows($this->section());
        $this->assertCount(3, $rows);
        $this->assertStringContainsString('利用だけ', $rows[0]);
        $this->assertStringContainsString('新しい契約', $rows[1]);
        $this->assertStringContainsString('古い契約', $rows[2]);

        // 最新の契約日（4 列目）は空欄。未入力は電話番号だけ
        preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $rows[0], $cells);
        $this->assertSame('', trim($cells[1][3]));
        $this->assertStringContainsString('>電話番号</span>', $cells[1][4]);
        $this->assertStringNotContainsString('>住所</span>', $cells[1][4]);
        $this->assertTrue($usageOnly->requiresContactDetails());
    }

    public function test_契約がない会員どうしはidの大きい順(): void
    {
        $this->makeClient('先に登録', [], [], ['2026-10-01']);
        $this->makeClient('後に登録', [], [], ['2026-10-01']);
        $this->makeClient('契約あり', [], ['2026-10-25']);

        $rows = $this->rows($this->section());
        $this->assertStringContainsString('後に登録', $rows[0]);
        $this->assertStringContainsString('先に登録', $rows[1]);
        $this->assertStringContainsString('契約あり', $rows[2]);
    }

    /** 対象の会員を $count 人作る（契約日は 2026-10-01 から 1 日ずつ新しく。並びは後に作った会員が上） */
    private function makeMany(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->makeClient('会員'.$i, [], [sprintf('2026-10-%02d', $i)]);
        }
    }

    /** 絞り込みのフォームの HTML（$marker はフォームを見分ける文字。診断は data-recent-diagnoses-filter、記録は name="recent"） */
    private function form(string $marker): string
    {
        preg_match_all('/<form method="GET".*?<\/form>/s', $this->lastHtml, $forms);
        $matched = array_values(array_filter($forms[0], fn ($form) => str_contains($form, $marker)));
        $this->assertCount(1, $matched, $marker);

        return $matched[0];
    }

    public function test_6件以上なら5件ずつのページ送りで並びどおりに分ける(): void
    {
        // 2026-10-28 追加：5 件ずつ（CONTACT_MISSING_PER_PAGE）。ページの番号は contacts_page
        $this->assertSame(5, DashboardController::CONTACT_MISSING_PER_PAGE);
        $this->makeMany(6);

        $rows = $this->rows($this->section());
        $this->assertCount(5, $rows);
        foreach ([6, 5, 4, 3, 2] as $index => $number) {
            $this->assertStringContainsString('>会員'.$number.'<', $rows[$index]);
        }
        // ページ送りは診断と同じ共通のビュー（「全N件中 a〜b件」とページの番号）。表の下に出す
        $this->assertMatchesRegularExpression('/<div class="mb-4" data-contact-missing-pagination>\s*<nav [^>]*aria-label="ページ送り">\s*<p class="small text-muted mb-0">\s*全6件中 1〜5件/u', $this->lastHtml);
        $this->assertStringContainsString('contacts_page=2', $this->lastHtml);
        $this->assertStringNotContainsString('?page=2', $this->lastHtml);

        $rows = $this->rows($this->section('?contacts_page=2'));
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('>会員1<', $rows[0]);
        $this->assertStringContainsString('全6件中 6〜6件', $this->lastHtml);
    }

    public function test_5件以下ならページ送りを出さない(): void
    {
        $this->makeMany(5);

        $this->assertCount(5, $this->rows($this->section()));
        $this->assertStringNotContainsString('data-contact-missing-pagination', $this->lastHtml);
    }

    public function test_ページが範囲外なら最後のページを出す(): void
    {
        // 2 ページ目を見ていたが、連絡先が入力されて対象が減った（例：6 件 → 5 件以下）。セクションは消えず、空の表も出さない
        $this->makeMany(6);
        $rows = $this->rows($this->section('?contacts_page=9'));
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('>会員1<', $rows[0]);
        $this->assertStringContainsString('全6件中 6〜6件', $this->lastHtml);
        // 診断・記録のフォームが持つページも、実際に出しているページ（2）にそろう
        $this->assertStringContainsString('<input type="hidden" name="contacts_page" value="2">', $this->form('data-recent-diagnoses-filter'));
    }

    public function test_ページが範囲外で1ページに収まるならそのページを出しページ送りは出さない(): void
    {
        $this->makeMany(3);

        $rows = $this->rows($this->section('?contacts_page=2'));
        $this->assertCount(3, $rows);
        $this->assertStringNotContainsString('data-contact-missing-pagination', $this->lastHtml);
        $this->assertStringNotContainsString('name="contacts_page"', $this->lastHtml);
    }

    public function test_対象が0件ならページが範囲外でもセクションを出さない(): void
    {
        $this->assertNull($this->section('?contacts_page=3'));
        $this->assertStringNotContainsString('連絡先が未入力の会員', $this->lastHtml);
    }

    public function test_2ページ目以降なら診断と記録の絞り込みのフォームがcontacts_pageを持つ(): void
    {
        $this->makeMany(6);

        $this->section();
        $this->assertStringNotContainsString('name="contacts_page"', $this->form('data-recent-diagnoses-filter'));
        $this->assertStringNotContainsString('name="contacts_page"', $this->form('name="recent"'));

        $this->section('?contacts_page=2');
        $this->assertStringContainsString('<input type="hidden" name="contacts_page" value="2">', $this->form('data-recent-diagnoses-filter'));
        $this->assertStringContainsString('<input type="hidden" name="contacts_page" value="2">', $this->form('name="recent"'));
    }

    public function test_ページ送りのリンクはほかの欄の絞り込みとページを残し診断のリンクはcontacts_pageを残す(): void
    {
        $this->makeMany(6);
        // 診断の結果を 6 件（未公開。2 ページになる）
        $traineeId = DB::table('trainees')->insertGetId(['client_id' => Client::query()->value('id'), 'name' => 'アイ']);
        for ($i = 0; $i < 6; $i++) {
            DB::table('trainee_personality_diagnosis_results')->insert([
                'trainee_id' => $traineeId, 'answered_at' => '2026-10-2'.$i.' 09:00:00', 'question_version' => 'v1', 'answers' => '{}',
            ]);
        }

        $this->section('?recent=primary&diagnoses=all&diagnoses_page=2');
        preg_match('/<div class="mb-4" data-contact-missing-pagination>.*?<\/nav>/s', $this->lastHtml, $contacts);
        $this->assertMatchesRegularExpression('/href="[^"]*contacts_page=2[^"]*"/', $contacts[0]);
        foreach (['recent=primary', 'diagnoses=all', 'diagnoses_page=2'] as $param) {
            $this->assertStringContainsString($param, $contacts[0], $param);
        }

        $this->section('?contacts_page=2');
        preg_match('/<div class="mb-4" data-recent-diagnoses-pagination>.*?<\/nav>/s', $this->lastHtml, $diagnoses);
        $this->assertMatchesRegularExpression('/href="[^"]*diagnoses_page=2[^"]*"/', $diagnoses[0]);
        $this->assertStringContainsString('contacts_page=2', $diagnoses[0]);
    }
}

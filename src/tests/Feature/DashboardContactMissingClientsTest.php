<?php

namespace Tests\Feature;

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
 * - 対象は会員側の「ご連絡先の登録のお願い」と同じ（契約が 1 件以上かつ、住所〔都道府県・市区町村・町名番地のどれか〕か電話番号が空）
 * - 0 件ならセクションごと出さない
 * - 並びは最新の契約日の新しい順、同じ日なら id の大きい順
 * - マイページの列は会員一覧と同じ列見出し・同じバッジ、未入力は「住所」「電話番号」のバッジ
 * - 行全体は押せないので table-hover は付けない（2026-10-28 変更）
 */
class DashboardContactMissingClientsTest extends TestCase
{
    use CreatesClientCountTables;

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

    /** 会員を作り、$contractedOn の日付ごとに契約を入れる */
    private function makeClient(string $lastName, array $contact, array $contractedOn = []): Client
    {
        $client = Client::query()->create(['internal_id' => $lastName, 'last_name' => $lastName, ...$contact]);
        foreach ($contractedOn as $date) {
            DB::table('client_contracts')->insert(['client_id' => $client->id, 'contracted_on' => $date, 'count' => 4]);
        }

        return $client;
    }

    /** セクションの表の HTML（なければ null） */
    private function section(): ?string
    {
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

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
        // 契約がない会員は未入力でも出さない。契約があっても入力済みなら出さない
        $this->makeClient('契約なし', []);
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
        $this->makeClient('契約なし', []);

        $rows = $this->rows($this->section());
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('市区町村なし', $rows[0]);
        $this->assertStringContainsString('電話空文字', $rows[1]);
        foreach ([$noPhone, $noCity] as $client) {
            $this->assertTrue($client->hasContracts());
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
}

<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientUsage;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * マイページの HOME（S-1402）の「次回のご予約」の帯（2026-11 追加。要件定義書 6-15-3・6-17-9、設計書 S-1402「次回のご予約」）。
 *
 * - 自分の利用のうち、利用日が今日以降のもの（予約）があるときだけ出す。過去の利用・ほかの会員の利用は数えない
 * - いちばん早い利用日の予約をすべて「開始〜終了　愛犬の呼び方」で並べる（開始時刻の順。名前が未登録なら「愛犬」、トレーニーなしは時刻だけ）
 * - 次回の日が今日なら「本日のご予約」
 * - 次回の日より後の予約があれば「ほかに n件」、なければ「ご利用回数を確認」（どちらもご利用回数 S-1412 へのリンク）
 * - 利用のメモは出さない
 *
 * 実際の HTTP でダッシュボードを開くため、画面が読むテーブルを列を絞って作る。今日は 2026-11-22。
 */
class ClientNextReservationRenderingTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-22 10:00:00');

        $this->createClientsTable();
        $this->createClientCountTables();
        // ダッシュボードが読む残りのテーブル（列を絞る。中身は空）
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
        Schema::create('training_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->date('training_date');
            $table->time('training_time')->nullable();
            $table->unsignedBigInteger('trainer1_id')->nullable();
            $table->unsignedBigInteger('trainer2_id')->nullable();
        });
        Schema::create('media_records', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('media_record_training_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('media_record_id');
            $table->unsignedBigInteger('training_record_id');
            $table->integer('sort_order')->default(0);
        });
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name')->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->string('trainer_photo_path', 500)->nullable();
            $table->string('breed', 100)->nullable();
            $table->string('sex', 20)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->unsignedTinyInteger('birth_month')->nullable();
            $table->unsignedTinyInteger('birth_day')->nullable();
            $table->string('neuter_status', 20)->nullable();
        });
        Schema::create('trainee_measurements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id');
            $table->date('measured_date');
            $table->time('measured_time');
            $table->decimal('weight', 5, 2)->nullable();
        });
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        // 住所・電話番号を入れておき、ご連絡先の登録のお願いは出さない（帯の並びの確認だけ別に入れる）
        $this->client = Client::query()->create([
            'internal_id' => 'C-1', 'last_name' => '大内', 'first_name' => '佳和',
            'address1' => '東京都', 'address2' => '千代田区', 'address3' => '1-1', 'phone1' => '0300000000',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function trainee(?string $name, ?Client $client = null): int
    {
        return DB::table('trainees')->insertGetId(['client_id' => ($client ?? $this->client)->id, 'name' => $name]);
    }

    private function usage(string $date, string $start, string $end, ?int $traineeId = null, ?Client $client = null, ?string $note = null): ClientUsage
    {
        return ClientUsage::query()->create([
            'client_id' => ($client ?? $this->client)->id, 'trainee_id' => $traineeId, 'client_contract_id' => null,
            'used_on' => $date, 'start_time' => $start, 'end_time' => $end, 'note' => $note,
        ]);
    }

    private function dashboard(): string
    {
        return $this->actingAs($this->client, 'client')->get('/client-portal/dashboard')->assertOk()->getContent();
    }

    /** 帯（<section class="c-reservation-notice…">…</section>）の HTML。なければ null */
    private function notice(string $html): ?string
    {
        return preg_match('/<section class="c-reservation-notice[^"]*".*?<\/section>/su', $html, $m) === 1 ? $m[0] : null;
    }

    /** 帯の予約の行の見える文字（読み上げ用の区切りを除き、タグを除き、空白をまとめたもの） */
    private function rows(string $notice): array
    {
        preg_match_all('/<li>(.*?)<\/li>/su', $notice, $m);

        return array_map(fn ($li) => trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace(
            ['<span class="visually-hidden">、</span>', '</span><span'],
            ['', '</span> <span'],
            $li
        )))), $m[1]);
    }

    private function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($html)));
    }

    public function test_予約がなければ帯を出さない_過去の利用も数えない(): void
    {
        $this->assertNull($this->notice($this->dashboard()));

        $this->usage('2026-11-21', '08:00', '19:00', $this->trainee('アイ'));   // 昨日
        $this->assertNull($this->notice($this->dashboard()));
        $this->assertStringNotContainsString('次回のご予約', $this->dashboard());
    }

    public function test_予約が1件なら_次回のご予約と日付と時刻と呼び方_ご利用回数を確認のリンク(): void
    {
        $this->usage('2026-11-26', '08:00', '19:00', $this->trainee('アイ'));

        $notice = $this->notice($this->dashboard());
        $this->assertNotNull($notice);
        $this->assertMatchesRegularExpression('/^<section class="c-reservation-notice"\s+aria-label="次回のご予約" data-client-next-reservation="2026-11-26">/u', $notice);
        $this->assertStringContainsString('<span class="c-reservation-notice-title">次回のご予約</span>', $notice);
        $this->assertStringContainsString('<span class="c-reservation-notice-date num-tabular">11/26(木)</span>', $notice);
        $this->assertSame(['8:00〜19:00 アイちゃん'], $this->rows($notice));
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('client-portal.contracts'), '/').'" class="c-reservation-notice-link">ご利用回数を確認<span aria-hidden="true"> ›<\/span><\/a>/u', $notice);
        $this->assertStringNotContainsString('ほかに', $notice);
        // カレンダーのアイコン（読み上げない）
        $this->assertMatchesRegularExpression('/<svg class="c-reservation-notice-icon"[^>]*aria-hidden="true"[^>]*data-reservation-notice-icon="calendar-event">/u', $notice);
    }

    public function test_同じ日の予約はすべて開始時刻の順に並べる(): void
    {
        $ai = $this->trainee('アイ');
        $amuro = $this->trainee('アムロ');
        $this->usage('2026-11-26', '13:00', '19:00', $amuro);
        $this->usage('2026-11-26', '08:00', '19:00', $ai);
        $this->usage('2026-11-26', '08:00', '12:00', $amuro);   // 同じ開始時刻なら終了時刻の順

        $this->assertSame(
            ['8:00〜12:00 アムロちゃん', '8:00〜19:00 アイちゃん', '13:00〜19:00 アムロちゃん'],
            $this->rows($this->notice($this->dashboard()))
        );
    }

    public function test_次回の日より後の予約があれば_ほかにn件がご利用回数へのリンク(): void
    {
        $ai = $this->trainee('アイ');
        $this->usage('2026-11-28', '08:00', '19:00', $ai);
        $this->usage('2026-11-26', '08:00', '19:00', $ai);
        $this->usage('2026-12-03', '08:00', '19:00', $ai);
        $this->usage('2026-11-21', '08:00', '19:00', $ai);   // 過去は数えない

        $notice = $this->notice($this->dashboard());
        $this->assertStringContainsString('11/26(木)', $notice);
        $this->assertSame(['8:00〜19:00 アイちゃん'], $this->rows($notice));
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('client-portal.contracts'), '/').'" class="c-reservation-notice-link">ほかに <span class="num-tabular">2<\/span>件<span aria-hidden="true"> ›<\/span><\/a>/u', $notice);
        $this->assertStringNotContainsString('ご利用回数を確認', $notice);
    }

    public function test_今日の予約は本日のご予約(): void
    {
        $this->usage('2026-11-22', '13:00', '19:00', $this->trainee('アイ'));
        $this->usage('2026-11-25', '08:00', '19:00', $this->trainee('アムロ'));

        $notice = $this->notice($this->dashboard());
        $this->assertMatchesRegularExpression('/^<section class="c-reservation-notice c-reservation-notice--today"\s+aria-label="本日のご予約" data-client-next-reservation="2026-11-22">/u', $notice);
        $this->assertStringContainsString('<span class="c-reservation-notice-title">本日のご予約</span>', $notice);
        $this->assertStringContainsString('11/22(日)', $notice);
        $this->assertSame(['13:00〜19:00 アイちゃん'], $this->rows($notice));
        $this->assertStringNotContainsString('次回のご予約', $notice);
        $this->assertStringContainsString('ほかに <span class="num-tabular">1</span>件', $notice);
    }

    public function test_名前が未登録なら愛犬_トレーニーなしは時刻だけ(): void
    {
        $this->usage('2026-11-26', '08:00', '12:00', $this->trainee(null));
        $this->usage('2026-11-26', '13:00', '19:00');

        $notice = $this->notice($this->dashboard());
        $this->assertSame(['8:00〜12:00 愛犬', '13:00〜19:00'], $this->rows($notice));
        $this->assertStringContainsString('<li><span class="c-reservation-notice-time num-tabular">13:00〜19:00</span></li>', $notice);
    }

    public function test_ほかの会員の予約は出さない(): void
    {
        $other = Client::query()->create(['internal_id' => 'C-2', 'last_name' => '佐藤', 'first_name' => '一郎']);
        $this->usage('2026-11-24', '08:00', '19:00', $this->trainee('ポチ', $other), $other);
        $this->assertNull($this->notice($this->dashboard()));

        $this->usage('2026-11-26', '08:00', '19:00', $this->trainee('アイ'));
        $notice = $this->notice($this->dashboard());
        $this->assertStringContainsString('11/26(木)', $notice);
        $this->assertStringNotContainsString('ポチ', $notice);
        $this->assertStringNotContainsString('ほかに', $notice);
    }

    public function test_利用のメモは出さない(): void
    {
        $this->usage('2026-11-26', '08:00', '19:00', $this->trainee('アイ'), note: 'RESERVATION_NOTE_SECRET');
        $this->usage('2026-11-28', '08:00', '19:00', note: 'LATER_NOTE_SECRET');

        $html = $this->dashboard();
        $this->assertNotNull($this->notice($html));
        $this->assertStringNotContainsString('NOTE_SECRET', $html);
    }

    public function test_Googleカレンダーに追加のリンクは出さない(): void
    {
        // トレーナー側（会員詳細・カレンダー画面）だけのリンク（2026-11 追加）
        $this->usage('2026-11-26', '08:00', '19:00', $this->trainee('アイ'));

        $html = $this->dashboard();
        $this->assertNotNull($this->notice($html));
        $this->assertStringNotContainsString('calendar.google.com', $html);
        $this->assertStringNotContainsString('Googleカレンダー', $html);
    }

    public function test_帯はあいさつとご連絡先の登録のお願いの下で_愛犬のカードより上(): void
    {
        // 電話番号を空にして、ご連絡先の登録のお願いも出す（利用があるので対象）
        $this->client->forceFill(['phone1' => null])->save();
        $this->usage('2026-11-26', '08:00', '19:00', $this->trainee('アイ'));

        $html = $this->dashboard();
        $greeting = strpos($html, 'さん、こんにちは');
        $contact = strpos($html, 'data-client-contact-notice');
        $reservation = strpos($html, 'class="c-reservation-notice');
        $this->assertNotFalse($contact);
        $this->assertTrue($greeting < $contact && $contact < $reservation);
        // 愛犬のカード（名前の見出し「アイちゃん」）は帯より下
        $this->assertGreaterThan($reservation, strpos($html, 'アイちゃん', $reservation + strlen($this->notice($html))));
        // 帯の文字（回数ではなく予約の日付・時刻だけ）
        $this->assertSame('次回のご予約 11/26(木) 8:00〜19:00、アイちゃん ご利用回数を確認 ›', $this->text($this->notice($html)));
    }

    public function test_時刻と呼び方のあいだに読み上げ用の区切りを入れ_トレーニーなしの行には入れない(): void
    {
        $this->usage('2026-11-26', '08:00', '12:00', $this->trainee('アイ'));
        $this->usage('2026-11-26', '13:00', '19:00');

        $notice = $this->notice($this->dashboard());
        // 見た目には出さない読点（visually-hidden）を、時刻と呼び方のあいだに置く
        $this->assertStringContainsString(
            '<li><span class="c-reservation-notice-time num-tabular">8:00〜12:00</span><span class="visually-hidden">、</span><span class="c-reservation-notice-name">アイちゃん</span></li>',
            $notice
        );
        $this->assertStringContainsString('<li><span class="c-reservation-notice-time num-tabular">13:00〜19:00</span></li>', $notice);
        $this->assertSame(1, substr_count($notice, 'visually-hidden'));
        // 読み上げで続けて読まれない（タグを除いた文字に区切りがある）
        $this->assertStringContainsString('8:00〜12:00、アイちゃん', strip_tags($notice));
    }
}

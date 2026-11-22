<?php

namespace Tests\Feature;

use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * カレンダー画面（S-1602。2026-11 追加。screen-design.md S-1602、api-design.md 4-1-17）。
 *
 * - GET /schedule（schedule.index）。一般トレーナー・管理者は見られる。システム管理者は 403、未ログインはログインへ
 * - 選んでいる日は ?date=YYYY-MM-DD。ないとき・正しくない日付のときは今日
 * - 利用モーダルと同じ部品（<x-usage-schedule>）を、左右に並べる設定（layout="side"）と会員詳細の URL の型で置き、
 *   JS の設定は linkClients・syncUrl（中身は JS が内部 API から読む）
 * - ナビの「カレンダー」は「契約」の右。/schedule… のときだけ強調する。システム管理者のナビには出さない
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 */
class ScheduleScreenTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 10:00:00');

        $this->createAccessLogsTable();
        // システム管理者のナビの確認で開くマイプロフィールが読む
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->string('email')->nullable();
        });

        $this->actingAsRole('staff');
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

    private function page(string $query = ''): string
    {
        return $this->get(route('schedule.index').$query)->assertOk()->getContent();
    }

    /** 画面のスクリプトが部品を開く日（schedule.open の 1 つ目の引数） */
    private function openedDate(string $html): string
    {
        $this->assertSame(1, preg_match('/schedule\.open\("([^"]*)", \{ fallbackDate: "([^"]*)" \}\);/u', $html, $m));
        $this->assertSame('2026-11-21', $m[2], 'fallbackDate は今日');

        return $m[1];
    }

    // ---- 権限 ----

    public function test_一般トレーナーと管理者は見られ_アクセスログには記録しない(): void
    {
        $this->page();
        $this->actingAsRole('admin');
        $this->page();

        $this->assertDatabaseCount('access_logs', 0);
    }

    public function test_システム管理者は403(): void
    {
        $this->actingAsRole('system_admin');

        $this->get(route('schedule.index'))->assertForbidden();
    }

    public function test_ログインしていなければログインへ(): void
    {
        auth('web')->logout();

        $this->get(route('schedule.index'))->assertRedirect(route('login'));
    }

    // ---- 画面 ----

    public function test_URLはscheduleで_タイトルと見出しはカレンダー(): void
    {
        $this->assertSame(url('/schedule'), route('schedule.index'));
        $html = $this->page();

        $this->assertMatchesRegularExpression('/<title>カレンダー - /u', $html);
        $this->assertStringContainsString('<h2 class="mb-4">カレンダー</h2>', $html);
    }

    public function test_dateがあればその日を開く(): void
    {
        $this->assertSame('2026-12-03', $this->openedDate($this->page('?date=2026-12-03')));
        $this->assertSame('2024-02-29', $this->openedDate($this->page('?date=2024-02-29')));   // うるう年の 2/29
    }

    public function test_dateがないときと正しくないときは今日(): void
    {
        $this->assertSame('2026-11-21', $this->openedDate($this->page()));
        foreach (['abc', '2026-13-01', '2026-02-30', '2026-2-3', '20261203', '2026-12-03x', ''] as $value) {
            $this->assertSame('2026-11-21', $this->openedDate($this->page('?date='.urlencode($value))), $value);
        }
        // 配列で渡されても今日（入力エラーにしない）
        $this->assertSame('2026-11-21', $this->openedDate($this->page('?date[]=2026-12-03')));
    }

    public function test_利用モーダルと同じ部品を_予定が上の並びと会員詳細のURLの型で置く(): void
    {
        $html = $this->page();

        // 部品のいちばん上に、その日の予定の枠（2026-11 変更：その前は左右に並べる row gx-4・col-lg-6）
        $this->assertMatchesRegularExpression(
            '/<div id="scheduleCalendar" class="usage-schedule"\s+data-usage-schedule\s+data-month-url="'.preg_quote(route('api.usage-schedule.month'), '/')
            .'"\s+data-day-url="'.preg_quote(route('api.usage-schedule.day'), '/')
            .'"\s+data-client-url="'.preg_quote(url('/clients/__CLIENT_ID__'), '/').'"\s*>\s*<div class="card">\s*<div class="card-header small fw-bold py-1" data-usage-schedule-day-heading><\/div>\s*<div data-usage-schedule-day-body>\s*<ul class="list-group list-group-flush" data-usage-schedule-day-list><\/ul>/u',
            $html
        );
        $this->assertStringNotContainsString('col-lg-6', $html);
        // その下にカレンダー（今までの大きさで左寄せ。usage-schedule-calendar）
        $this->assertMatchesRegularExpression('/<\/ul>\s*<\/div>\s*<\/div>\s*<div class="usage-schedule-calendar mt-3 usage-schedule-calendar--two">\s*<div class="d-flex align-items-center justify-content-center gap-2 mb-1 usage-schedule-nav">/u', $html);
        // JS の設定：会員名をリンクにし、URL の date を書き換え、広い画面でタイムテーブルにし、予定の枠の高さを保つ（2026-11 追加。利用日の欄はない）
        $this->assertStringContainsString("new window.UsageSchedule(document.getElementById('scheduleCalendar'), { linkClients: true, syncUrl: true, timetable: true, keepDayHeight: true, months: 2 });", $html);
        $this->assertStringNotContainsString('onSelect', $html);
        $this->assertMatchesRegularExpression('/<script type="module" src="[^"]*usage-schedule[^"]*\.js"/u', $html);
    }

    public function test_2か月分のカレンダーの入れ物があり_月を送るボタンは1組だけ(): void
    {
        // 2 つ目（翌月）は広い画面（xl 以上）だけ出す（2026-11 追加）
        $html = $this->page();
        foreach (['data-usage-schedule-heading aria-live', 'data-usage-schedule-days>', 'data-usage-schedule-prev', 'data-usage-schedule-next'] as $attribute) {
            $this->assertSame(1, substr_count($html, $attribute), $attribute);
        }
        $this->assertStringContainsString('<div class="fw-bold text-center d-none d-xl-block" data-usage-schedule-heading-2 aria-live="polite"></div>', $html);
        $this->assertMatchesRegularExpression('/<table class="table table-borderless table-sm text-center mb-3 d-none d-xl-table" style="table-layout: fixed;">.*?<tbody data-usage-schedule-days-2><\/tbody>/su', $html);
        // 左（今月）→ 右（翌月）の順
        $this->assertLessThan(strpos($html, 'data-usage-schedule-days-2'), strpos($html, 'data-usage-schedule-days>'));
    }

    public function test_その日の予定の枠はカレンダーより前にある(): void
    {
        // 見た目の順（予定が上・カレンダーが下）と、読み上げ・タブの順をそろえる（2026-11 変更）
        $html = $this->page();
        $day = strpos($html, 'data-usage-schedule-day-heading');
        $calendar = strpos($html, 'data-usage-schedule-days');
        $this->assertNotFalse($day);
        $this->assertNotFalse($calendar);
        $this->assertLessThan($calendar, $day);
        $this->assertSame(1, substr_count($html, 'data-usage-schedule-day-heading'));
    }

    // ---- ナビ ----

    public function test_ナビのカレンダーは契約の右で_カレンダーのときだけ強調する(): void
    {
        $html = $this->page();
        $this->assertMatchesRegularExpression('/<a class="nav-link active" href="'.preg_quote(route('schedule.index'), '/').'">\s*カレンダー\s*<\/a>/u', $html);
        // 並びは 契約 → カレンダー → トレーニング記録
        $contracts = strpos($html, 'href="'.route('contracts.index').'"');
        $calendar = strpos($html, 'href="'.route('schedule.index').'"');
        $records = strpos($html, 'href="'.route('training-records.index').'"');
        $this->assertTrue($contracts < $calendar && $calendar < $records);
        // カレンダーでは「契約」は強調しない
        $this->assertMatchesRegularExpression('/<a class="nav-link " href="'.preg_quote(route('contracts.index'), '/').'">\s*契約\s*<\/a>/u', $html);

        // ほかの画面（マイプロフィール）では「カレンダー」を強調しない
        $profile = $this->get(route('profile.edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<a class="nav-link " href="'.preg_quote(route('schedule.index'), '/').'">\s*カレンダー\s*<\/a>/u', $profile);
    }

    public function test_事業所情報の設定のURLではカレンダーを強調しない(): void
    {
        // request()->is('schedule*') は URL の先頭から比べるので、/settings/schedule（S-1601）は当たらない
        $this->createSystemSettingsTable();
        $this->actingAsRole('admin');
        $html = $this->get(route('settings.schedule.edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<a class="nav-link " href="'.preg_quote(route('schedule.index'), '/').'">\s*カレンダー\s*<\/a>/u', $html);
    }

    public function test_システム管理者のナビにはカレンダーを出さない(): void
    {
        $this->actingAsRole('system_admin');

        $html = $this->get(route('profile.edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('href="'.route('schedule.index').'"', $html);
        $this->assertStringNotContainsString('カレンダー', $html);
    }
}

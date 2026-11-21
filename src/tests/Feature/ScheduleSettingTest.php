<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\SystemSetting;
use App\Models\Trainer;
use App\Support\ScheduleSetting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 予定の設定（S-1601。2026-11 追加。設計書 screen-design.md 3-16・requirements.md 6-17-10・api-design.md 4-1-17）。
 *
 * - 管理者だけが開け、更新できる（admin-only。要約プロンプト S-0601 と同じ）
 * - 営業開始時刻・営業終了時刻は必須・H:i、営業終了時刻は営業開始時刻より後（同じ時刻は不可）
 * - **行がないときは初期値（08:00・19:00）で動く**（本番ではシーダーを流さないため）
 * - 保存は行がなければ作り、あれば更新する
 * - アクセスログは記録しない
 * - 同時に預かれる頭数（2026-11 追加。requirements.md 6-17-11）：必須・整数・1〜99。行がないときは 3
 */
class ScheduleSettingTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAccessLogsTable();
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => '佐藤', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    /** 更新を送る。同時に預かれる頭数（2026-11 追加）を渡さないときは、正しい値（3）を足す */
    private function update(array $input): TestResponse
    {
        return $this->from(route('settings.schedule.edit'))->put(route('settings.schedule.update'), $input + ['schedule_capacity' => '3']);
    }

    public function test_行がないときは初期値で表示される(): void
    {
        $this->actingAsRole('admin');

        $html = $this->get(route('settings.schedule.edit'))->assertOk()->getContent();

        $this->assertSame(0, SystemSetting::query()->count());
        $this->assertMatchesRegularExpression('/<input type="time"[^>]*name="schedule_open_time"\s+value="08:00"/', $html);
        $this->assertMatchesRegularExpression('/<input type="time"[^>]*name="schedule_close_time"\s+value="19:00"/', $html);
        $this->assertSame('08:00', ScheduleSetting::openTime());
        $this->assertSame('19:00', ScheduleSetting::closeTime());
    }

    public function test_保存した値で表示される(): void
    {
        $this->actingAsRole('admin');
        SystemSetting::query()->create(['key' => 'schedule_open_time', 'value' => '09:30']);
        SystemSetting::query()->create(['key' => 'schedule_close_time', 'value' => '18:00']);

        $html = $this->get(route('settings.schedule.edit'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="schedule_open_time"\s+value="09:30"/', $html);
        $this->assertMatchesRegularExpression('/name="schedule_close_time"\s+value="18:00"/', $html);
    }

    public function test_画面の名前は事業所情報の設定で_更新ボタンが出て説明文と上部の案内は置かない(): void
    {
        // 2026-11 変更：画面の名前を「事業所情報の設定」に改め、説明文を消した（設計書 S-1601）
        $this->actingAsRole('admin');

        $html = $this->get(route('settings.schedule.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('<title>事業所情報の設定', $html);
        $this->assertStringContainsString('<h2 class="mb-0">事業所情報の設定</h2>', $html);
        $this->assertStringNotContainsString('予定の設定', $html);
        $this->assertStringContainsString('<button type="submit" form="scheduleSettingForm" class="btn btn-success text-nowrap">更新</button>', $html);
        // 説明文は出さない
        $this->assertStringNotContainsString('data-schedule-setting-description', $html);
        $this->assertStringNotContainsString('初期値になります', $html);
        $this->assertStringNotContainsString('登録済みの利用は変わりません', $html);
        // 離脱前の確認（S-0601 と同じ）
        $this->assertStringContainsString("formSelector: '#scheduleSettingForm'", $html);
    }

    public function test_管理者メニューのプロンプトの設定の後に事業所情報の設定がある(): void
    {
        $this->actingAsRole('admin');

        $html = $this->get(route('settings.schedule.edit'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<li><a class="dropdown-item" href="'.preg_quote(route('settings.summary-prompts.edit'), '/').'">要約プロンプト<\/a><\/li>\s*'
            .'<li><hr class="dropdown-divider"><\/li>\s*'
            .'<li><a class="dropdown-item" href="'.preg_quote(route('settings.schedule.edit'), '/').'">事業所情報の設定<\/a><\/li>/s',
            $html
        );
        // 開いているときは【管理者】を強調する
        $this->assertMatchesRegularExpression('/<a class="nav-link dropdown-toggle active"[^>]*>\s*【管理者】/', $html);
    }

    /** 【管理者】メニュー（ドロップダウン）の中身。メニューが出なければ null */
    private function adminMenu(string $html): ?string
    {
        if (preg_match('/【管理者】\s*<\/a>\s*<ul class="dropdown-menu">(.*?)<\/ul>/su', $html, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    public function test_管理者メニューの区切り線は管理者に2本_システム管理者には出さない(): void
    {
        // 2026-11 追加：事業所情報の設定の上に区切り線。どちらの区切り線も前後の項目と同じく管理者だけに出し、
        // 区切り線だけが残ったり 2 本続いたりしない
        $this->actingAsRole('admin');
        $menu = $this->adminMenu((string) $this->view('layouts.app'));
        $this->assertNotNull($menu);
        $this->assertSame(2, substr_count($menu, '<li><hr class="dropdown-divider"></li>'));
        $this->assertDoesNotMatchRegularExpression('/dropdown-divider"><\/li>\s*<li><hr class="dropdown-divider"/u', $menu);
        $this->assertDoesNotMatchRegularExpression('/dropdown-divider"><\/li>\s*$/u', $menu);

        // システム管理者の【管理者】メニューはトレーナー管理だけで、区切り線は出さない
        $this->actingAsRole('system_admin');
        $menu = $this->adminMenu((string) $this->view('layouts.app'));
        $this->assertNotNull($menu);
        $this->assertStringContainsString('トレーナー管理', $menu);
        $this->assertStringNotContainsString('dropdown-divider', $menu);
        $this->assertStringNotContainsString('事業所情報の設定', $menu);
    }

    public function test_一般トレーナーとシステム管理者は開けず更新もできない(): void
    {
        foreach (['staff', 'system_admin'] as $role) {
            $this->actingAsRole($role);

            $this->get(route('settings.schedule.edit'))->assertForbidden();
            $this->put(route('settings.schedule.update'), ['schedule_open_time' => '09:00', 'schedule_close_time' => '18:00'])->assertForbidden();
        }
        $this->assertSame(0, SystemSetting::query()->count());
    }

    public function test_行がなければ作り_あれば更新する(): void
    {
        $this->actingAsRole('admin');

        $this->update(['schedule_open_time' => '09:00', 'schedule_close_time' => '18:30'])
            ->assertRedirect(route('settings.schedule.edit'))
            ->assertSessionHas('success', '事業所情報の設定を更新しました。');
        $this->assertSame('09:00', ScheduleSetting::openTime());
        $this->assertSame('18:30', ScheduleSetting::closeTime());

        $this->update(['schedule_open_time' => '07:45', 'schedule_close_time' => '20:00'])->assertRedirect(route('settings.schedule.edit'));
        $this->assertSame(3, SystemSetting::query()->count(), '営業開始時刻・営業終了時刻・同時に預かれる頭数の 3 行');
        $this->assertSame('07:45', ScheduleSetting::openTime());
        $this->assertSame('20:00', ScheduleSetting::closeTime());
    }

    public function test_更新はアクセスログに記録しない(): void
    {
        $this->actingAsRole('admin');

        $this->update(['schedule_open_time' => '09:00', 'schedule_close_time' => '18:00'])->assertRedirect();

        $this->assertSame(0, AccessLog::query()->count());
    }

    public function test_必須と形式の文言(): void
    {
        $this->actingAsRole('admin');

        $this->update(['schedule_open_time' => '', 'schedule_close_time' => ''])->assertSessionHasErrors([
            'schedule_open_time' => '営業開始時刻を入力してください。',
            'schedule_close_time' => '営業終了時刻を入力してください。',
        ]);
        $this->update(['schedule_open_time' => '8時', 'schedule_close_time' => '25:00'])->assertSessionHasErrors([
            'schedule_open_time' => '営業開始時刻の形式が正しくありません。',
            'schedule_close_time' => '営業終了時刻の形式が正しくありません。',
        ]);
        $this->assertSame(0, SystemSetting::query()->count());
    }

    public function test_営業終了時刻が営業開始時刻以前ならエラー(): void
    {
        $this->actingAsRole('admin');

        foreach (['19:00' => '08:00', '10:00' => '10:00'] as $open => $close) {
            $this->update(['schedule_open_time' => $open, 'schedule_close_time' => $close])->assertSessionHasErrors([
                'schedule_close_time' => '営業終了時刻は営業開始時刻より後の時刻で入力してください。',
            ]);
        }
        $this->assertSame(0, SystemSetting::query()->count());
    }

    public function test_形式の誤りがあるときは前後の文言を重ねない(): void
    {
        $this->actingAsRole('admin');

        $this->update(['schedule_open_time' => 'xx', 'schedule_close_time' => '08:00'])
            ->assertSessionHasErrors(['schedule_open_time' => '営業開始時刻の形式が正しくありません。'])
            ->assertSessionDoesntHaveErrors(['schedule_close_time']);
    }

    public function test_エラーのときは範囲のまとまりの下に出し入力した値を戻す(): void
    {
        $this->actingAsRole('admin');

        $this->update(['schedule_open_time' => '12:00', 'schedule_close_time' => '11:00']);
        $html = $this->get(route('settings.schedule.edit'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="form-control\s+is-invalid\s*"\s+id="schedule_close_time"\s+name="schedule_close_time"\s+value="11:00"/', $html);
        $this->assertMatchesRegularExpression('/name="schedule_open_time"\s+value="12:00"/', $html);
        $this->assertStringContainsString('<div class="invalid-feedback d-block">営業終了時刻は営業開始時刻より後の時刻で入力してください。</div>', $html);
        // 上部の案内は出さない（小さなフォーム）
        $this->assertStringNotContainsString('入力内容に誤りがあります。', $html);
    }

    // ---- 同時に預かれる頭数（2026-11 追加） ----

    public function test_同時に預かれる頭数は時刻の下にあり_行がないときは3で欄の右に頭(): void
    {
        $this->actingAsRole('admin');

        $html = $this->get(route('settings.schedule.edit'))->assertOk()->getContent();

        $this->assertSame(3, ScheduleSetting::capacity());
        $this->assertMatchesRegularExpression(
            '/<label for="schedule_capacity" class="form-label">同時に預かれる頭数 <span class="text-danger">\*<\/span><\/label>\s*'
            .'<div class="input-group"[^>]*data-schedule-capacity>\s*'
            .'<input type="number" class="form-control\s*"\s+id="schedule_capacity" name="schedule_capacity"\s+value="3"\s+'
            .'min="1" max="99" step="1" inputmode="numeric"[^>]*>\s*'
            .'<span class="input-group-text">頭<\/span>/u',
            $html
        );
        // 営業開始時刻・営業終了時刻のまとまりの下
        $this->assertGreaterThan(strpos($html, 'data-schedule-time-range'), strpos($html, 'data-schedule-capacity'));
    }

    public function test_同時に預かれる頭数を保存して表示する(): void
    {
        $this->actingAsRole('admin');

        $this->update(['schedule_open_time' => '08:00', 'schedule_close_time' => '19:00', 'schedule_capacity' => '5'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '事業所情報の設定を更新しました。');

        $this->assertSame('5', SystemSetting::query()->where('key', 'schedule_capacity')->value('value'));
        $this->assertSame(5, ScheduleSetting::capacity());
        $html = $this->get(route('settings.schedule.edit'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="schedule_capacity"\s+value="5"/', $html);
    }

    public function test_同時に預かれる頭数は1と99を保存できる(): void
    {
        $this->actingAsRole('admin');

        foreach (['1' => 1, '99' => 99] as $input => $expected) {
            $this->update(['schedule_open_time' => '08:00', 'schedule_close_time' => '19:00', 'schedule_capacity' => $input])
                ->assertSessionHasNoErrors();
            $this->assertSame($expected, ScheduleSetting::capacity());
        }
    }

    public function test_同時に預かれる頭数の必須_整数_範囲の文言(): void
    {
        $this->actingAsRole('admin');
        $cases = [
            '' => '同時に預かれる頭数を入力してください。',
            '2.5' => '同時に預かれる頭数は整数で入力してください。',
            'abc' => '同時に預かれる頭数は整数で入力してください。',
            '0' => '同時に預かれる頭数は1〜99の範囲で指定してください。',
            '100' => '同時に預かれる頭数は1〜99の範囲で指定してください。',
        ];

        foreach ($cases as $input => $message) {
            $this->update(['schedule_open_time' => '08:00', 'schedule_close_time' => '19:00', 'schedule_capacity' => (string) $input])
                ->assertSessionHasErrors(['schedule_capacity' => $message]);
        }
        $this->assertSame(0, SystemSetting::query()->count());
    }

    public function test_同時に預かれる頭数のエラーは欄のまとまりの下に出し入力した値を戻す(): void
    {
        $this->actingAsRole('admin');

        $this->update(['schedule_open_time' => '08:00', 'schedule_close_time' => '19:00', 'schedule_capacity' => '0']);
        $html = $this->get(route('settings.schedule.edit'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="form-control\s+is-invalid\s*"\s+id="schedule_capacity" name="schedule_capacity"\s+value="0"/', $html);
        $this->assertMatchesRegularExpression(
            '/<span class="input-group-text">頭<\/span>\s*<\/div>\s*<div class="invalid-feedback d-block">同時に預かれる頭数は1〜99の範囲で指定してください。<\/div>/u',
            $html
        );
    }

    public function test_保存した値が読めないときは3で動く(): void
    {
        SystemSetting::query()->create(['key' => 'schedule_capacity', 'value' => 'abc']);
        $this->assertSame(3, ScheduleSetting::capacity());

        SystemSetting::query()->where('key', 'schedule_capacity')->update(['value' => '0']);
        $this->assertSame(3, ScheduleSetting::capacity());

        SystemSetting::query()->where('key', 'schedule_capacity')->update(['value' => '7']);
        $this->assertSame(7, ScheduleSetting::capacity());
    }
}

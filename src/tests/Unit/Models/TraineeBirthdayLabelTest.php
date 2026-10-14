<?php

namespace Tests\Unit\Models;

use App\Models\Trainee;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Trainee の誕生日の表示のアクセサ（birthday_label・birthday_with_age_label）のテスト。
 *
 * 組み立ての規則そのもの（要件定義書 6-16「年齢の表示規則」）は App\Support\TraineeAge と
 * 固定データ（tests/fixtures/trainee-age-cases.json）で確かめている。ここでは、アクセサが
 * モデルの 3 列と「表示するその日」（アプリのタイムゾーンでの今日）を渡していることを確かめる。
 */
class TraineeBirthdayLabelTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function trainee(?int $year, ?int $month, ?int $day): Trainee
    {
        $t = new Trainee;
        $t->birth_year = $year;
        $t->birth_month = $month;
        $t->birth_day = $day;

        return $t;
    }

    public function test_誕生日と年齢を並べた表示は今日を基準日にする(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->assertSame('2020/03/15（6歳7ヶ月）', $this->trainee(2020, 3, 15)->birthday_with_age_label);
        $this->assertSame('2020年3月生まれ（6歳7ヶ月）', $this->trainee(2020, 3, null)->birthday_with_age_label);
        $this->assertSame('2020年生まれ（約6歳）', $this->trainee(2020, null, null)->birthday_with_age_label);
        $this->assertNull($this->trainee(null, null, null)->birthday_with_age_label);
    }

    public function test_基準日はアプリのタイムゾーンの今日(): void
    {
        // UTC では 10/19 15:00 だが、Asia/Tokyo では 10/20 0:00。誕生日の当日として満年齢が上がる
        Carbon::setTestNow(Carbon::parse('2026-10-19 15:00:00', 'UTC'));

        $this->assertSame('2020/10/20（6歳0ヶ月）', $this->trainee(2020, 10, 20)->birthday_with_age_label);
    }

    public function test_誕生日の部分だけの表示(): void
    {
        $this->assertSame('2020/03/15', $this->trainee(2020, 3, 15)->birthday_label);
        $this->assertSame('2020年3月生まれ', $this->trainee(2020, 3, null)->birthday_label);
        $this->assertSame('2020年生まれ', $this->trainee(2020, null, null)->birthday_label);
        $this->assertNull($this->trainee(null, null, null)->birthday_label);
    }

    public function test_誕生日の3列は整数として読める(): void
    {
        $t = new Trainee;
        $t->setRawAttributes(['birth_year' => '2020', 'birth_month' => '03', 'birth_day' => '05']);

        $this->assertSame(2020, $t->birth_year);
        $this->assertSame(3, $t->birth_month);
        $this->assertSame(5, $t->birth_day);
        $this->assertSame('2020/03/05', $t->birthday_label);
    }
}

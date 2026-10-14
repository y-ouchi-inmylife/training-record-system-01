<?php

namespace Tests\Unit\Support;

use App\Support\TraineeAge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * トレーニーの年齢の表示規則（要件定義書 6-16）のテスト。
 *
 * ケースは tests/fixtures/trainee-age-cases.json に置き、段階2b の JS の入力中の年齢表示の
 * テストでも同じものを使う（PHP と JS で結果が食い違わないようにするため）。
 */
class TraineeAgeTest extends TestCase
{
    public static function cases(): array
    {
        $json = json_decode(file_get_contents(__DIR__.'/../../fixtures/trainee-age-cases.json'), true, 512, JSON_THROW_ON_ERROR);

        $cases = [];
        foreach ($json['cases'] as $case) {
            $cases[$case['label']] = [$case];
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_固定データの各ケース(array $case): void
    {
        [$refYear, $refMonth, $refDay] = array_map('intval', explode('-', $case['ref']));

        $this->assertSame(
            $case['age'],
            TraineeAge::ageLabel($case['year'], $case['month'], $case['day'], $refYear, $refMonth, $refDay),
            '年齢の表示'
        );
        $this->assertSame(
            $case['birthday'],
            TraineeAge::birthdayLabel($case['year'], $case['month'], $case['day']),
            '誕生日の部分だけの表示'
        );
        $this->assertSame(
            $case['birthday_with_age'],
            TraineeAge::birthdayWithAgeLabel($case['year'], $case['month'], $case['day'], $refYear, $refMonth, $refDay),
            '誕生日と年齢を並べた表示'
        );
    }

    public function test_未来の誕生日は年齢を出さず誕生日の部分だけを返す(): void
    {
        $this->assertNull(TraineeAge::ageLabel(2026, 11, 1, 2026, 10, 20));
        $this->assertNull(TraineeAge::ageLabel(2027, null, null, 2026, 10, 20));
        $this->assertSame('2026/11/01', TraineeAge::birthdayWithAgeLabel(2026, 11, 1, 2026, 10, 20));
    }

    public function test_daysInMonth_はうるう年の2月を29日とする(): void
    {
        $this->assertSame(29, TraineeAge::daysInMonth(2024, 2));
        $this->assertSame(28, TraineeAge::daysInMonth(2026, 2));
        $this->assertSame(28, TraineeAge::daysInMonth(2100, 2));
        $this->assertSame(29, TraineeAge::daysInMonth(2000, 2));
        $this->assertSame(30, TraineeAge::daysInMonth(2026, 4));
        $this->assertSame(31, TraineeAge::daysInMonth(2026, 12));
    }
}

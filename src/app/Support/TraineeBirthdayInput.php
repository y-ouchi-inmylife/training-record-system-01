<?php

namespace App\Support;

/**
 * トレーニーの誕生日（年・月・日の 3 つの欄）の入力の判定。
 *
 * 規則は要件定義書 6-16「年齢の表示規則」の「入力のルール」、判定の順と状態は
 * screen-design.md §2-8「誕生日（年・月・日の 3 つの欄）の文言」の表。
 *
 * - 送信時（TraineeRequest）と入力中の表示（JS。resources/js/trainee-age.js）で、同じ手順・
 *   同じ状態の記号を使う。両方のテストは tests/fixtures/trainee-birthday-input-cases.json で確かめる
 * - 状態の記号から、送信時の文言（lang/ja/validation.php の custom.birth_date）と入力中の案内
 *   （JS 側）へそれぞれ対応させる
 * - 基準日は引数で受け取る（TraineeRequest は now() を渡す。テストでは固定の日を渡す）
 */
class TraineeBirthdayInput
{
    // 状態の記号（判定の順。screen-design.md §2-8 の表）
    public const NOT_NUMERIC = 'not_numeric';

    public const MONTH_OUT_OF_RANGE = 'month_out_of_range';

    public const MONTH_WITHOUT_YEAR = 'month_without_year';

    public const DAY_WITHOUT_YEAR = 'day_without_year';

    public const DAY_WITHOUT_MONTH = 'day_without_month';

    public const INVALID_DATE = 'invalid_date';

    public const FUTURE = 'future';

    public const TOO_OLD = 'too_old';

    public const OK = 'ok';

    public const EMPTY = 'empty';

    /** 年の下限（今年から何年前まで受け付けるか） */
    public const MAX_YEARS_AGO = 30;

    /**
     * 欄の値を直す：全角の数字を半角に、前後の空白（全角を含む）を取る。空になったら null。
     * 文字列でない値（配列など）はそのまま返す（判定で「数字でない」になる）。
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value === null || ! is_string($value)) {
            return $value;
        }

        $value = mb_convert_kana($value, 'n');
        $value = preg_replace('/\A[\s　]+|[\s　]+\z/u', '', $value);

        return $value === '' ? null : $value;
    }

    /**
     * 3 つの欄の値から状態の記号を返す。値は normalize() の前でも後でもよい（中で直す）。
     */
    public static function state(mixed $year, mixed $month, mixed $day, int $refYear, int $refMonth, int $refDay): string
    {
        $year = self::normalize($year);
        $month = self::normalize($month);
        $day = self::normalize($day);

        // 1. 数字であること（半角の数字だけ）
        foreach ([$year, $month, $day] as $value) {
            if ($value === null) {
                continue;
            }
            if (! is_string($value) && ! is_int($value)) {
                return self::NOT_NUMERIC;
            }
            if (! preg_match('/\A\d+\z/', (string) $value)) {
                return self::NOT_NUMERIC;
            }
        }

        $y = $year === null ? null : (int) $year;
        $m = $month === null ? null : (int) $month;
        $d = $day === null ? null : (int) $day;

        if ($y === null && $m === null && $d === null) {
            return self::EMPTY;
        }

        // 2. 月は 1〜12
        if ($m !== null && ($m < 1 || $m > 12)) {
            return self::MONTH_OUT_OF_RANGE;
        }

        // 3. 月があるなら年がある
        if ($m !== null && $y === null) {
            return self::MONTH_WITHOUT_YEAR;
        }

        // 4. 日があるなら年・月がある（入力中の案内を分けるため、年がない／月がないの 2 つにする）
        if ($d !== null && $y === null) {
            return self::DAY_WITHOUT_YEAR;
        }
        if ($d !== null && $m === null) {
            return self::DAY_WITHOUT_MONTH;
        }

        // 5. 3 つそろったら実在する日付
        if ($d !== null && ! checkdate($m, $d, $y)) {
            return self::INVALID_DATE;
        }

        // 6. 未来でない（年だけなら今年以前、年・月までなら今月以前、年・月・日なら今日以前）
        $future = match (true) {
            $m === null => $y > $refYear,
            $d === null => [$y, $m] > [$refYear, $refMonth],
            default => [$y, $m, $d] > [$refYear, $refMonth, $refDay],
        };
        if ($future) {
            return self::FUTURE;
        }

        // 7. 年は今年から 30 年前まで
        if ($y < $refYear - self::MAX_YEARS_AGO) {
            return self::TOO_OLD;
        }

        return self::OK;
    }
}

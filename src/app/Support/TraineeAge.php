<?php

namespace App\Support;

/**
 * トレーニーの誕生日（年・月・日を別々に持つ）から、年齢と誕生日の表示を組み立てる。
 *
 * 規則は要件定義書 6-16「年齢の表示規則」。年齢そのものは保存せず、表示のたびに算出する。
 *
 * - 基準日は引数で受け取る（モデルのアクセサは「表示するその日」を渡す。テストでは固定の日を渡す）
 * - 入力中の年齢表示（JS。段階2b）にも同じ計算を書くため、Carbon の差分（diffInMonths など）は
 *   使わず、整数の計算だけで組む。どちらも tests/fixtures/trainee-age-cases.json の同じケースで確かめる
 * - 生まれた日がその月にない場合は、その月の末日を生まれた日とみなす（例：1月31日生まれは
 *   2月28日〔うるう年は29日〕に1ヶ月、2月29日生まれはうるう年でない年の2月28日に1歳）
 */
class TraineeAge
{
    /**
     * 誕生日から基準日までの満の月数。年・月までなら、その月の1日生まれとみなす。
     *
     * 年だけ（月が NULL）のときは月数を出せないので null。基準日より後に生まれている
     * （未来の誕生日）ときも null（入力の検証で防いでいるが、表示を壊さないため）。
     */
    public static function months(int $year, ?int $month, ?int $day, int $refYear, int $refMonth, int $refDay): ?int
    {
        if ($month === null) {
            return null;
        }

        $months = ($refYear - $year) * 12 + ($refMonth - $month);

        // 基準日の月での「生まれた日」。年・月までなら 1 日。その月に生まれた日がなければ末日
        $birthDayInRefMonth = min($day ?? 1, self::daysInMonth($refYear, $refMonth));
        if ($refDay < $birthDayInRefMonth) {
            $months--;
        }

        return $months < 0 ? null : $months;
    }

    /**
     * 年齢の表示（「6歳7ヶ月」「約6歳」「10ヶ月」「1ヶ月未満」「1歳未満」）。
     * 誕生日が未登録、または未来の誕生日なら null。
     */
    public static function ageLabel(?int $year, ?int $month, ?int $day, int $refYear, int $refMonth, int $refDay): ?string
    {
        if ($year === null) {
            return null;
        }

        // 年だけ：基準日の年から生まれ年を引いた数に「約」を付ける。0 なら「1歳未満」
        if ($month === null) {
            $years = $refYear - $year;
            if ($years < 0) {
                return null;
            }

            return $years === 0 ? '1歳未満' : '約'.$years.'歳';
        }

        $months = self::months($year, $month, $day, $refYear, $refMonth, $refDay);
        if ($months === null) {
            return null;
        }

        if ($months === 0) {
            return '1ヶ月未満';
        }
        if ($months < 12) {
            return $months.'ヶ月';
        }

        return intdiv($months, 12).'歳'.($months % 12).'ヶ月';
    }

    /**
     * 誕生日の部分だけの表示（「2020/03/15」「2020年3月生まれ」「2020年生まれ」）。
     * 未登録なら null。受付アンケートの回答の表示（年齢を付けない）でも使う。
     */
    public static function birthdayLabel(?int $year, ?int $month, ?int $day): ?string
    {
        if ($year === null) {
            return null;
        }
        if ($month === null) {
            return $year.'年生まれ';
        }
        if ($day === null) {
            return $year.'年'.$month.'月生まれ';
        }

        return sprintf('%04d/%02d/%02d', $year, $month, $day);
    }

    /**
     * 誕生日と年齢を並べた表示（「2020/03/15（6歳7ヶ月）」など）。未登録なら null。
     * 年齢を出せないとき（未来の誕生日）は誕生日の部分だけを返す。
     */
    public static function birthdayWithAgeLabel(?int $year, ?int $month, ?int $day, int $refYear, int $refMonth, int $refDay): ?string
    {
        $birthday = self::birthdayLabel($year, $month, $day);
        if ($birthday === null) {
            return null;
        }

        $age = self::ageLabel($year, $month, $day, $refYear, $refMonth, $refDay);

        return $age === null ? $birthday : $birthday.'（'.$age.'）';
    }

    /**
     * 会員側のマイページ（S-1402）の誕生日の書き方を、「誕生日」と「（年齢）」の 2 つに分けて返す（2026-10-18 追加）。
     *
     * マイページは項目名を付けないので、誕生日だと分かるよう「生」を付ける：「2020/01/23生」「2020年3月生」「2020年生」。
     * 年齢は ageLabel と同じ計算（会員詳細と同じ）で「（6歳8ヶ月）」の形。年齢を出せない（未来の誕生日）ときは誕生日だけ。
     * 未登録なら空の配列。2 つに分けるのは、画面で「…生」と「（年齢）」の間でだけ折り返すため。
     * 会員詳細（S-0305）の書き方（birthdayWithAgeLabel）は変えない
     *
     * @return list<string>
     */
    public static function clientBirthdayParts(?int $year, ?int $month, ?int $day, int $refYear, int $refMonth, int $refDay): array
    {
        if ($year === null) {
            return [];
        }

        $birthday = match (true) {
            $month === null => $year.'年生',
            $day === null => $year.'年'.$month.'月生',
            default => sprintf('%04d/%02d/%02d生', $year, $month, $day),
        };
        $age = self::ageLabel($year, $month, $day, $refYear, $refMonth, $refDay);

        return $age === null ? [$birthday] : [$birthday, '（'.$age.'）'];
    }

    /**
     * その月の日数（うるう年の 2 月は 29）。
     *
     * PHP の calendar 拡張（cal_days_in_month）に頼らず、JS にもそのまま移せる計算で出す。
     */
    public static function daysInMonth(int $year, int $month): int
    {
        if ($month === 2) {
            $leap = ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;

            return $leap ? 29 : 28;
        }

        return in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }
}

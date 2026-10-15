<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * 性格診断の採点とタイプの判定（2026-10 追加）。
 *
 * 決まりは要件定義書 6-16「性格診断の採点とタイプの判定」。トレーナーさんの今の GAS と同じ結果にする：
 * - カテゴリごとに「未経験」を除いた回答の点数（0〜4）の平均。全問「未経験」のカテゴリは 0
 * - 保存は小数第2位、表示は小数第1位。どちらも丸める前の値から四捨五入（ちょうど半分は切り上げ。GAS の toFixed と同じ）
 * - タイプは丸める前の平均で判定する。いちばん高い平均が 1.5 未満なら calm。同点はカテゴリの順で先のもの
 *
 * 浮動小数点の誤差で「ちょうど半分」や同点の扱いがずれないよう、平均は「点数の合計 / 問数」の分数のまま扱い、
 * 丸めも比べるのも整数の計算だけで行う（float・BCMath は使わない）。問数は多くても 8 なので、整数で足りる。
 * GAS（JavaScript の数値）でも、ちょうど半分になるのは分母が 2 のべき乗（4・8 問）のときだけで、その値は
 * 2 進数でも正確に表せるため、toFixed の「半分は大きい方」と同じ結果になる（固定データで確かめている）。
 *
 * 採点はサーバーだけで行い、JS では採点しない（画面は保存した値を出す）。
 */
class PersonalityDiagnosisScorer
{
    /** いちばん高い平均がこれ未満なら calm（のんびり・平和主義タイプ）。分数で比べるため分子と分母で持つ */
    private const CALM_THRESHOLD_NUMERATOR = 3;

    private const CALM_THRESHOLD_DENOMINATOR = 2;

    /**
     * 50 問の回答を採点する。
     *
     * 回答の形（Q01〜Q50 がそろい、値が 0〜4 の整数か "na"）は、お客様の送信の検証（段階3）で確かめる。
     * ここでも同じことを確かめ、合わなければ InvalidArgumentException を投げる（検証を通らない値で
     * 結果を作らないための守り。お客様に見せるエラーではない）。
     *
     * @param  array<string, int|string>  $answers  キーは Q01〜Q50、値は 0〜4（整数または数字の文字列）か "na"
     */
    public function score(array $answers): PersonalityDiagnosisScore
    {
        $this->assertValid($answers);

        $scores = [];
        $display = [];
        $best = null; // [合計, 問数, カテゴリ]

        foreach (PersonalityDiagnosisQuestions::questionIdsByCategory() as $category => $ids) {
            [$sum, $count] = $this->sumAndCount($answers, $ids);

            $scores[$category] = $this->format($sum, $count, 2);
            $display[$category] = $this->format($sum, $count, 1);

            // 同点はカテゴリの順で先のものを取るので、「より大きい」ときだけ入れ替える
            if ($best === null || $this->compare($sum, $count, $best[0], $best[1]) > 0) {
                $best = [$sum, $count, $category];
            }
        }

        [$bestSum, $bestCount, $bestCategory] = $best;
        $type = $this->compare($bestSum, $bestCount, self::CALM_THRESHOLD_NUMERATOR, self::CALM_THRESHOLD_DENOMINATOR) < 0
            ? 'calm'
            : PersonalityDiagnosisQuestions::typeByCategory()[$bestCategory];

        return new PersonalityDiagnosisScore($scores, $display, $type);
    }

    /**
     * カテゴリの「未経験」を除いた点数の合計と問数。全問「未経験」なら問数は 0
     *
     * @param  array<string, int|string>  $answers
     * @param  list<string>  $ids
     * @return array{0: int, 1: int}
     */
    private function sumAndCount(array $answers, array $ids): array
    {
        $sum = 0;
        $count = 0;
        foreach ($ids as $id) {
            if ((string) $answers[$id] === PersonalityDiagnosisQuestions::NOT_EXPERIENCED) {
                continue;
            }
            $sum += (int) $answers[$id];
            $count++;
        }

        return [$sum, $count];
    }

    /**
     * 平均（合計 / 問数）を小数第 $decimals 位に四捨五入した文字列。問数が 0 なら 0（例 "0.00"）。
     * 平均は 0 以上なので、四捨五入は floor(平均 × 10^n + 0.5) を整数だけで計算する
     */
    private function format(int $sum, int $count, int $decimals): string
    {
        $scale = 10 ** $decimals;
        $scaled = $count === 0 ? 0 : intdiv(2 * $sum * $scale + $count, 2 * $count);

        return intdiv($scaled, $scale).'.'.str_pad((string) ($scaled % $scale), $decimals, '0', STR_PAD_LEFT);
    }

    /**
     * 2 つの平均（a / b と c / d）を比べる。問数が 0 の平均は 0 とみなす。a/b < c/d なら負、等しければ 0、大きければ正
     */
    private function compare(int $a, int $b, int $c, int $d): int
    {
        $left = $b === 0 ? 0 : $a * ($d === 0 ? 1 : $d);
        $right = $d === 0 ? 0 : $c * ($b === 0 ? 1 : $b);

        return $left <=> $right;
    }

    /**
     * Q01〜Q50 がそろっていて、値が 0〜4 か "na" であること
     *
     * @param  array<string, int|string>  $answers
     */
    private function assertValid(array $answers): void
    {
        $allowed = array_map('strval', array_keys(PersonalityDiagnosisQuestions::choices()));
        foreach (array_keys(PersonalityDiagnosisQuestions::questions()) as $id) {
            if (! array_key_exists($id, $answers)) {
                throw new InvalidArgumentException("性格診断の回答に {$id} がありません。");
            }
            if (! (is_int($answers[$id]) || is_string($answers[$id])) || ! in_array((string) $answers[$id], $allowed, true)) {
                throw new InvalidArgumentException("性格診断の回答 {$id} の値が正しくありません。");
            }
        }
    }
}

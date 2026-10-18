<?php

namespace App\Support;

/**
 * 性格診断の採点の結果（PersonalityDiagnosisScorer::score() の戻り値。2026-10 追加）。
 *
 * - scores：7 カテゴリの平均の保存の値（小数第2位の文字列。例 "1.13"。D-1200 の score_*）。全問「未経験」のカテゴリは null（点数なし）
 * - display：7 カテゴリの平均の表示の値（小数第1位の文字列。例 "1.1"。丸める前の値から丸める）。点数なしは null
 * - type：タイプの値（db-schema.md 5-24.。丸める前の値から判定した値）。全カテゴリが点数なしなら null（判定なし）
 *   （2026-10-18 変更：点数なし・判定なしを足した）
 *
 * どちらの配列もキーはカテゴリの値で、並びは PersonalityDiagnosisQuestions::categories() と同じ。
 */
final class PersonalityDiagnosisScore
{
    /**
     * @param  array<string, string|null>  $scores
     * @param  array<string, string|null>  $display
     */
    public function __construct(
        public readonly array $scores,
        public readonly array $display,
        public readonly ?string $type,
    ) {}

    /**
     * 結果の表（D-1200）に保存する列（score_* と personality_type）
     *
     * @return array<string, string|null>
     */
    public function toResultAttributes(): array
    {
        $attributes = [];
        foreach ($this->scores as $category => $score) {
            $attributes['score_'.$category] = $score;
        }
        $attributes['personality_type'] = $this->type;

        return $attributes;
    }
}

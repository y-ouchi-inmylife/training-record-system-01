<?php

namespace App\Support;

/**
 * 「愛犬アンケート＆愛犬性格診断」の案内の名前（2026-10-19 追加。要件定義書 6-16-8・6-16-11）。
 *
 * 2 つの名前（lang/ja/messages.php の intake_survey_name・personality_diagnosis_name）を、combined_survey_name_lines の
 * ひな形（「＆」でつなぐ）で組み立てる。名前を直書きしないので、どちらかの名前を差し替えると、この名前にも出る。
 * 名前は読むたびに組み立てる（lang のファイルを読んだときに組み立てると、あとから名前を差し替えても古い名前が残るため）
 */
class CombinedSurveyName
{
    /** 1 行の名前（「愛犬アンケート＆愛犬性格診断」）。下の行をつないだもの */
    public static function name(): string
    {
        return implode('', self::lines());
    }

    /**
     * 行に分けた名前（「愛犬アンケート＆」「愛犬性格診断」。2026-10-19 追加）。会員詳細のトレーニーの欄で、
     * 「＆」のあとで改行して 2 行で出すときに使う（設計書 S-0305「愛犬アンケート＆愛犬性格診断の案内」）
     *
     * @return array<int, string>
     */
    public static function lines(): array
    {
        $names = [
            'intake' => __('messages.intake_survey_name'),
            'personality' => __('messages.personality_diagnosis_name'),
        ];

        return array_map(
            fn (string $line) => strtr($line, [':intake' => $names['intake'], ':personality' => $names['personality']]),
            __('messages.combined_survey_name_lines')
        );
    }
}

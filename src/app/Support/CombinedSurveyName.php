<?php

namespace App\Support;

/**
 * 「愛犬アンケート＆愛犬性格診断」の案内の名前（2026-10-19 追加。要件定義書 6-16-8・6-16-11）。
 *
 * 2 つの名前（lang/ja/messages.php の intake_survey_name・personality_diagnosis_name）を、combined_survey_name の
 * ひな形（「＆」でつなぐ）で組み立てる。名前を直書きしないので、どちらかの名前を差し替えると、この名前にも出る。
 * 名前は読むたびに組み立てる（lang のファイルを読んだときに組み立てると、あとから名前を差し替えても古い名前が残るため）
 */
class CombinedSurveyName
{
    public static function name(): string
    {
        return __('messages.combined_survey_name', [
            'intake' => __('messages.intake_survey_name'),
            'personality' => __('messages.personality_diagnosis_name'),
        ]);
    }
}

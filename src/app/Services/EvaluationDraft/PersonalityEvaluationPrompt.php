<?php

namespace App\Services\EvaluationDraft;

use App\Models\SystemSetting;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Support\PersonalityDiagnosisQuestions;

/**
 * 愛犬性格診断のアドバイスとスタッフ用メモの自動生成を AI に頼む指示文（2026-10-19 変更：呼び名を「下書き」から変えた）（2026-10-19 追加。要件定義書 6-16-19、設計書 S-0313「AIで下書き」）。
 *
 * - 指示文のひな形は system_settings の evaluation_prompt_current（管理者が「愛犬性格診断のプロンプト」の画面で直す）。
 *   値がなければ DEFAULT_TEMPLATE（トレーナーさんの今の仕組み〔GAS＋Gemini〕の指示文そのまま）
 * - ひな形の中の差し込みの印（{{犬の名前}} など。PLACEHOLDERS）を、診断の結果のデータに置き換える
 * - AI に渡すのは、愛犬の名前・タイプ・点数（7 つ）・自由記述の質問（4 問）だけ。犬種・年齢・性別、会員様（飼い主様）の名前・住所・
 *   電話番号・メールアドレスなどは渡さない（data() に入れない）
 */
class PersonalityEvaluationPrompt
{
    /** 指示文を置く system_settings のキー */
    public const SETTING_KEY = 'evaluation_prompt_current';

    /** 指示文の上限の文字数（設定画面の確かめ。要約プロンプトと同じ） */
    public const MAX_LENGTH = 5000;

    /** 指示文の既定の値（設定がないとき。トレーナーさんの今の GAS の指示文をそのまま） */
    public const DEFAULT_TEMPLATE = <<<'TEXT'
        ドッグトレーナーとして、以下のデータから「お客様用レポート」と「スタッフ用指示書」を作成してください。
        【犬の名前】{{犬の名前}}
        【タイプ】{{タイプ}}
        【スコア】{{スコア}}
        【悩み】{{悩み}}
        【背景】{{背景}}
        【理想】{{理想}}
        【健康】{{健康}}
        形式：お客様用レポート（800字程度）、次に「#####」、最後にスタッフ用メモ（300字程度）。
        TEXT;

    /**
     * 書式の決まり（2026-10-19 追加：AI が Markdown の記号で書き、会員様の画面に記号が出てしまうため）。
     * 指示文（ひな形）とは別に、毎回 AI に渡す（Claude では system に入れる）。設定画面で指示文を書き換えても必ず付く。
     * AI の会社に依らないよう、ここ 1 か所に持つ（Gemini を足すときも同じ決まりを使う）。設定画面（S-0602）に読むだけで出す。
     * 2026-10-19 変更：AI が指示文の呼び名（お客様用レポートなど）を見出しとして書き、会員様の画面に出てしまうため、
     * 呼び名を見出しとして書かない決まりを区切りの決まりの前に足した
     */
    public const FORMAT_RULES = <<<'TEXT'
        次の決まりを必ず守ってください。
        ・出力は普通の文章（プレーンテキスト）で書き、Markdown の記号（#、*、**、-、---、>、表など）は使わないでください。
        ・見出しが必要なときは「【】」で囲んでください。
        ・箇条書きが必要なときは、行の頭に「・」を付けてください。
        ・「お客様用レポート」「スタッフ用指示書」「スタッフ用メモ」などの呼び名を見出しとして書かず、それぞれ本文から書き始めてください。
        ・お客様用レポートとスタッフ用メモの区切りの「#####」だけは、そのまま出力してください。
        TEXT;

    /**
     * 差し込みの印 → 説明（設定画面に一覧で出す）。自由記述の質問（4 問）の当てはめ：
     * 悩み＝こうなったらいいな（wish）、背景＝最近の様子（recent_events）、理想＝理想の毎日（ideal_days）、健康＝体のこと（health_concerns）
     *
     * @return array<string, string>
     */
    public static function placeholders(): array
    {
        return [
            '{{犬の名前}}' => '愛犬の名前（未登録なら空）',
            '{{タイプ}}' => 'タイプ（例「慎重派・しっかり番犬タイプ」。全カテゴリが点数なしなら「判定なし」）',
            '{{スコア}}' => '7 つのカテゴリの点数（例「対人:2.5, 対犬:2.9, …」。小数第1位。点数なしは「—」）',
            '{{悩み}}' => 'この愛犬性格診断で答えた自由記述の質問の「こうなったらいいな」の答え',
            '{{背景}}' => 'この愛犬性格診断で答えた自由記述の質問の「最近の様子」の答え',
            '{{理想}}' => 'この愛犬性格診断で答えた自由記述の質問の「理想の毎日」の答え',
            '{{健康}}' => 'この愛犬性格診断で答えた自由記述の質問の「体のこと」の答え',
        ];
    }

    /** 自由記述の質問（4 問）の当てはめ（差し込みの印 → 愛犬性格診断の結果の列。2026-10-19 変更：その前は愛犬アンケートの回答の列） */
    private const LIFE_COLUMNS = [
        '{{悩み}}' => 'wish',
        '{{背景}}' => 'recent_events',
        '{{理想}}' => 'ideal_days',
        '{{健康}}' => 'health_concerns',
    ];

    /** 今のひな形（設定がなければ既定の値） */
    public static function template(): string
    {
        $value = SystemSetting::query()->where('key', self::SETTING_KEY)->value('value');

        return blank($value) ? self::DEFAULT_TEMPLATE : $value;
    }

    /** AI に送る指示文（ひな形の差し込みの印を置き換えたもの） */
    public static function render(TraineePersonalityDiagnosisResult $result): string
    {
        return strtr(self::template(), self::data($result));
    }

    /**
     * 差し込みの印 → 値。AI に渡すデータはここだけで組み立てる
     *
     * @return array<string, string>
     */
    public static function data(TraineePersonalityDiagnosisResult $result): array
    {
        $trainee = $result->trainee;
        $scores = [];
        foreach ($result->displayScores() as $category => $score) {
            $scores[] = PersonalityDiagnosisQuestions::categories()[$category].':'.($score ?? '—');
        }

        $data = [
            '{{犬の名前}}' => (string) ($trainee?->name ?? ''),
            '{{タイプ}}' => $result->personality_type_label,
            '{{スコア}}' => implode(', ', $scores),
        ];
        // 自由記述の質問（4 問）は、この診断の結果に保存した答え（2026-10-19 変更：4 問を愛犬性格診断へ移した。その前は、愛犬アンケートの
        // 回答のうち診断の回答日以前でいちばん新しいもの〔なければいちばん新しいもの〕を探して使っていた）
        foreach (self::LIFE_COLUMNS as $placeholder => $column) {
            $data[$placeholder] = (string) ($result->{$column} ?? '');
        }

        return $data;
    }
}

<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * 愛犬性格診断の名前（画面に出す正式名称。2026-10-18 追加）。
 *
 * 名前は lang/ja/messages.php の 1 か所に置く（personality_diagnosis_name と、それを「愛犬」「性格診断」に分けた
 * personality_diagnosis_name_parts。どちらも同じファイルの同じ変数から作る）。用語集 No.33。
 */
final class PersonalityDiagnosisName
{
    /** ふつうの名前（「愛犬性格診断」） */
    public static function name(): string
    {
        return __('messages.personality_diagnosis_name');
    }

    /**
     * 狭い場所のラベル用の HTML：「愛犬」と「性格診断」の間にだけ折り返しの印（<wbr>）を入れたもの
     * （会員詳細 S-0305 のトレーニーの欄の行のラベル。言葉の途中で折り返さないよう、出す側で word-break: keep-all を付ける）。
     *
     * 各部分は e() で逃がしてから <wbr> でつなぐ（中身は文のファイルの決まった文字だけで、利用者の入力は入らない）。
     * 分けた部分をつなげたものが名前と合わないとき（名前だけ差し替えたときなど）は、折り返しの印を入れずに名前をそのまま出す
     */
    public static function labelHtml(): HtmlString
    {
        $name = self::name();
        $parts = __('messages.personality_diagnosis_name_parts');
        if (! is_array($parts) || implode('', $parts) !== $name) {
            $parts = [$name];
        }

        return new HtmlString(collect($parts)->map(fn ($part) => e($part))->implode('<wbr>'));
    }
}

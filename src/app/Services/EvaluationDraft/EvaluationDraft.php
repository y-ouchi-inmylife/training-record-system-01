<?php

namespace App\Services\EvaluationDraft;

/**
 * AI の下書き（2026-10-19 追加）。お客様用レポート（アドバイスの欄に入れる）とスタッフ用メモ（スタッフ用メモの欄に入れる）。
 * staffMemoMissing：返事に区切り「#####」がなく、スタッフ用メモを作れなかった（全部をアドバイスに入れた）
 */
final class EvaluationDraft
{
    public function __construct(
        public readonly string $evaluation,
        public readonly string $staffMemo,
        public readonly bool $staffMemoMissing,
    ) {}

    /** 区切り（指示文で、お客様用とスタッフ用の間に出すよう頼んでいる文字） */
    public const SEPARATOR = '#####';

    /**
     * AI の返事を分ける。最初の「#####」の前をアドバイス、後ろをスタッフ用メモにする（前後の空白・改行は取る）。
     * 「#####」がなければ全部をアドバイスにし、スタッフ用メモは空（staffMemoMissing）。返事が空（空白だけ）なら失敗。
     * 分けたあと、それぞれに念のための後始末（stripMarkdown）をする（2026-10-19 追加。区切りを消さないよう、分けるのが先）
     *
     * @throws EvaluationDraftFailedException 返事が空
     */
    public static function fromResponse(string $response): self
    {
        if (self::trim($response) === '') {
            throw new EvaluationDraftFailedException('AI の返事が空でした。');
        }
        $position = mb_strpos($response, self::SEPARATOR);
        if ($position === false) {
            return new self(self::clean($response), '', true);
        }

        return new self(
            self::clean(mb_substr($response, 0, $position)),
            self::clean(mb_substr($response, $position + mb_strlen(self::SEPARATOR))),
            false,
        );
    }

    /**
     * 念のための後始末（2026-10-19 追加）。書式の決まり（PersonalityEvaluationPrompt::FORMAT_RULES）で Markdown の記号を使わないよう
     * 頼んでいるが、守られなかったときに欄・会員様の画面に記号が出ないよう外す。文の中の普通の記号（「3*4」・文中の「-」など）を
     * 壊さないよう、行の頭と「**」の組だけを対象にする：
     * - 「---」「***」「___」だけの行（区切り線）は行ごと外す
     * - 行の頭の「#」〜「######」＋空白（見出しの記号）を外す
     * - 「**」の組を外す（中の文字は残す）
     * - 行の頭の「- 」「* 」（箇条書きの記号）を「・」に置き換える（行の頭の字下げは残す）
     */
    public static function stripMarkdown(string $text): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            if (preg_match('/\A[ \t]*([-*_])(?:[ \t]*\1){2,}[ \t]*\z/u', $line)) {
                continue;
            }
            $line = preg_replace('/\A([ \t]*)#{1,6}[ \t\x{3000}]+/u', '$1', $line);
            $line = preg_replace('/\*\*(.+?)\*\*/u', '$1', $line);
            $line = preg_replace('/\A([ \t]*)[-*][ \t]+/u', '$1・', $line);
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /** 後始末をしてから前後の空白・改行を取る */
    private static function clean(string $text): string
    {
        return self::trim(self::stripMarkdown($text));
    }

    /** 前後の空白・改行（全角の空白も）を取る */
    private static function trim(string $text): string
    {
        return preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', $text);
    }
}

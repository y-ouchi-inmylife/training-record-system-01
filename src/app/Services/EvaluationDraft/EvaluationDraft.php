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

    /** 最初の行から外す呼び名（指示文の中の呼び名。2026-10-19 追加） */
    public const LABELS = ['お客様用レポート', 'お客様用', 'スタッフ用指示書', 'スタッフ用メモ'];

    /**
     * 念のための後始末（2026-10-19 追加）。AI が指示文の呼び名（「お客様用レポート」など）を見出しとして書いたときのために、
     * 最初の行（先頭の空の行は飛ばす）が呼び名だけの行なら外す。呼び名の前後の飾り（【】「」『』＜＞<>:：と空白）は許す。
     * 最初の行だけを見る（本文の途中・呼び名のあとに文が続く行・2 行目以降の呼び名は外さない）。外したあと先頭に残った空の行も取る。
     * Markdown の後始末（stripMarkdown）のあとに呼ぶ（「## お客様用レポート」「**スタッフ用メモ**」でも外れるように）
     */
    public static function stripLeadingLabel(string $text): string
    {
        $text = self::trimStart($text);
        $lines = preg_split('/\R/u', $text, 2);
        $decoration = '[【】「」『』＜＞<>:：\s\x{3000}]*';
        $labels = implode('|', array_map(fn (string $label) => preg_quote($label, '/'), self::LABELS));
        if (preg_match('/\A'.$decoration.'(?:'.$labels.')'.$decoration.'\z/u', $lines[0])) {
            return self::trimStart($lines[1] ?? '');
        }

        return $text;
    }

    /** 後始末をしてから前後の空白・改行を取る（順番：Markdown の後始末 → 呼び名の行を外す → 前後の空白を取る） */
    private static function clean(string $text): string
    {
        return self::trim(self::stripLeadingLabel(self::stripMarkdown($text)));
    }

    /** 先頭の空白・改行（全角の空白も）を取る */
    private static function trimStart(string $text): string
    {
        return preg_replace('/\A[\s\x{3000}]+/u', '', $text);
    }

    /** 前後の空白・改行（全角の空白も）を取る */
    private static function trim(string $text): string
    {
        return preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', $text);
    }
}

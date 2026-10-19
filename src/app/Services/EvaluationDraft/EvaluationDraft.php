<?php

namespace App\Services\EvaluationDraft;

/**
 * AI の下書き（2026-10-19 追加）。お客様用レポート（評価文の欄に入れる）とスタッフ用メモ（スタッフ用メモの欄に入れる）。
 * staffMemoMissing：返事に区切り「#####」がなく、スタッフ用メモを作れなかった（全部を評価文に入れた）
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
     * AI の返事を分ける。最初の「#####」の前を評価文、後ろをスタッフ用メモにする（前後の空白・改行は取る）。
     * 「#####」がなければ全部を評価文にし、スタッフ用メモは空（staffMemoMissing）。返事が空（空白だけ）なら失敗
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
            return new self(self::trim($response), '', true);
        }

        return new self(
            self::trim(mb_substr($response, 0, $position)),
            self::trim(mb_substr($response, $position + mb_strlen(self::SEPARATOR))),
            false,
        );
    }

    /** 前後の空白・改行（全角の空白も）を取る */
    private static function trim(string $text): string
    {
        return preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', $text);
    }
}

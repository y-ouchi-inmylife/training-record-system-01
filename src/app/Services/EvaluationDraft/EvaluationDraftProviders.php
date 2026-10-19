<?php

namespace App\Services\EvaluationDraft;

/**
 * 愛犬性格診断のアドバイスの AI の自動生成で使う AI の会社の対応表（2026-10-20 追加。要件定義書 6-16-19、設計書 S-0313・architecture.md 2-5-1）。
 *
 * しばらく Claude・OpenAI・Gemini の 3 社を比べながら使い、最終的に 1 つに絞るため、S-0313 に会社ごとのボタンを置く。
 * AI の名前（provider。S-0313 のボタンから送られる）→ 実装・ボタンの名前を、ここ 1 か所で決める。並びはボタンの並び。
 * キーかモデルの設定が空の会社は使えない（ボタンを出さない・送られても呼ばない。各実装の isConfigured()）。
 * 2026-10-20 変更：その前は設定 EVALUATION_DRAFT_DRIVER で AppServiceProvider が実装を 1 つ選んでいた（この設定は外した）
 */
class EvaluationDraftProviders
{
    /** @var array<string, array{label: string, class: class-string<EvaluationDraftGenerator>}> AI の名前 → ボタンの名前・実装 */
    public const PROVIDERS = [
        'claude' => ['label' => 'Claude', 'class' => ClaudeEvaluationDraftGenerator::class],
        'openai' => ['label' => 'OpenAI', 'class' => OpenAiEvaluationDraftGenerator::class],
        'gemini' => ['label' => 'Gemini', 'class' => GeminiEvaluationDraftGenerator::class],
    ];

    /**
     * 使える会社（キーとモデルの設定がそろっているもの）の名前とボタンの名前。並びはボタンの並び
     *
     * @return array<string, string> AI の名前 → ボタンの名前
     */
    public function available(): array
    {
        $available = [];
        foreach (self::PROVIDERS as $name => $provider) {
            if ($provider['class']::isConfigured()) {
                $available[$name] = $provider['label'];
            }
        }

        return $available;
    }

    /** 使える会社か（決まった 3 つのどれかで、キーとモデルの設定がそろっている） */
    public function isAvailable(mixed $name): bool
    {
        return is_string($name) && array_key_exists($name, $this->available());
    }

    /** AI の名前から実装を作る。使えない会社なら null（AI は呼ばない） */
    public function make(mixed $name): ?EvaluationDraftGenerator
    {
        if (! $this->isAvailable($name)) {
            return null;
        }

        return app(self::PROVIDERS[$name]['class']);
    }
}

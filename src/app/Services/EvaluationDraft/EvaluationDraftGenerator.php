<?php

namespace App\Services\EvaluationDraft;

use App\Models\TraineePersonalityDiagnosisResult;

/**
 * 愛犬性格診断のアドバイスとスタッフ用メモを AI で自動生成する（2026-10-19 変更：呼び名を「下書き」から変えた。2026-10-19 追加。要件定義書 6-16-19、設計書 S-0313「AIで下書き」）。
 *
 * コントローラーはこのインターフェイスだけを使う。AI の会社ごとの実装を 1 つずつ持ち、S-0313 で押したボタンの AI の名前から
 * EvaluationDraftProviders（対応表の 1 か所）が選ぶ（2026-10-20 変更：Claude・OpenAI・Gemini の 3 社にした。その前は設定
 * config('services.evaluation_draft.driver') で AppServiceProvider が Claude を選んでいた）。
 * 実装は static isConfigured()（キーとモデルの設定がそろっているか）も持つ（EvaluationDraftProviders がボタンを出すかを決めるのに使う）。
 */
interface EvaluationDraftGenerator
{
    /**
     * 下書きを作る。保存はしない。
     *
     * @throws EvaluationDraftFailedException AI の呼び出しに失敗した・返事が空だった
     */
    public function generate(TraineePersonalityDiagnosisResult $result): EvaluationDraft;
}

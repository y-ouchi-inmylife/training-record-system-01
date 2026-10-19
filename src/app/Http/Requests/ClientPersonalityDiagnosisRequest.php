<?php

namespace App\Http\Requests;

use App\Support\PersonalityDiagnosisQuestions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 性格診断の回答（S-1415。D-1200。2026-10 追加）のバリデーション。
 *
 * お客様がログインせずに送る（性格診断用 URL のトークンで対象のトレーニーが決まる）ため、authorize() は常に true。
 * トークンが使えるかどうかはコントローラで確かめる（愛犬アンケートの ClientIntakeSurveyRequest と同じ）。
 *
 * - 暮らしの様子の 4 問（wish・recent_events・ideal_days・health_concerns。2026-10-19 に愛犬アンケートから移した）：どれも任意・
 *   2,000 文字以内。検証の前に改行を LF にそろえる（prepareForValidation()。ブラウザは maxlength で改行を 1 文字と数えるが、送信では
 *   CRLF の 2 文字になるため）。項目名は lang/ja/validation.php の設問の短い呼び名
 * - answers.Q01〜answers.Q50：すべて必須。値は 0〜4 か na（未経験）。設問と選択肢は PersonalityDiagnosisQuestions から
 * - 文言は、未回答は「選んでください。」、値が正しくないときは「選択された値は有効ではありません。」（設計書 S-1415
 *   「バリデーションチェック」）。画面の上の「まだ答えていない質問が N 問あります。」は Blade で数える
 */
class ClientPersonalityDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $choices = implode(',', array_keys(PersonalityDiagnosisQuestions::choices()));

        $rules = ['answers' => ['nullable', 'array']];
        foreach (array_keys(PersonalityDiagnosisQuestions::questions()) as $id) {
            $rules['answers.'.$id] = ['required', 'in:'.$choices];
        }
        foreach (array_keys(PersonalityDiagnosisQuestions::lifeQuestions()) as $column) {
            $rules[$column] = 'nullable|string|max:'.PersonalityDiagnosisQuestions::LIFE_ANSWER_MAX_LENGTH;
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [];
        foreach (array_keys(PersonalityDiagnosisQuestions::questions()) as $id) {
            $messages['answers.'.$id.'.required'] = '選んでください。';
            $messages['answers.'.$id.'.in'] = '選択された値は有効ではありません。';
        }

        return $messages;
    }

    /**
     * 検証の前に、暮らしの様子の 4 問の改行を LF にそろえる（CRLF → LF、残った CR → LF）
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (array_keys(PersonalityDiagnosisQuestions::lifeQuestions()) as $column) {
            $value = $this->input($column);
            if (is_string($value)) {
                $normalized[$column] = str_replace(["\r\n", "\r"], "\n", $value);
            }
        }
        $this->merge($normalized);
    }

    /**
     * 検証を通った暮らしの様子の 4 問（列名 → 答え。空・空白だけは null）
     *
     * @return array<string, string|null>
     */
    public function lifeAnswers(): array
    {
        $answers = [];
        foreach (array_keys(PersonalityDiagnosisQuestions::lifeQuestions()) as $column) {
            $value = $this->validated($column);
            $answers[$column] = blank($value) ? null : (string) $value;
        }

        return $answers;
    }

    /**
     * 検証を通った 50 問の回答（キーは Q01〜Q50、値は 0〜4 の整数か "na"）。設問にないキーは含めない
     *
     * @return array<string, int|string>
     */
    public function answers(): array
    {
        $answers = [];
        foreach (array_keys(PersonalityDiagnosisQuestions::questions()) as $id) {
            $value = (string) $this->validated('answers.'.$id);
            $answers[$id] = $value === PersonalityDiagnosisQuestions::NOT_EXPERIENCED ? $value : (int) $value;
        }

        return $answers;
    }
}

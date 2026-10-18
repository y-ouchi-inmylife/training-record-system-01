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

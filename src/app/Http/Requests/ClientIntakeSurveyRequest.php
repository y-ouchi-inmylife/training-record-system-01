<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTraineeBirthday;
use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyResponse;
use App\Models\TraineeIntakeSurveyToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 受付アンケートの回答（S-1413。D-1100。2026-10 追加）のバリデーション。
 *
 * お客様がログインせずに送る（受付アンケート用 URL のトークンで対象のトレーニーが決まる）ため、
 * authorize() は常に true。トークンが使えるかどうかはコントローラで確かめる
 * （使えなければ使えない URL の画面を出す）。
 *
 * - 名前：トレーニーの名前が未登録なら必須、登録済みなら受け取らない（exclude。送られてきても使わない。
 *   回答には回答時点のトレーニーの名前を記録する。要件定義書 D-1100）。ここでのトレーニーの名前は
 *   ロックの前に読んだもので、コントローラでロックしたあとにもう一度確かめる
 * - 性別は男の子・女の子だけ（「不明」は置かない）、去勢・避妊は「分からない」を dont_know で受ける
 * - 誕生日はトレーナー側（TraineeRequest）と同じ判定（trait NormalizesTraineeBirthday）
 * - 項目名と文言は lang/ja/validation.php（自由記述は設問の短い呼び名。screen-design.md S-1413）
 */
class ClientIntakeSurveyRequest extends FormRequest
{
    use NormalizesTraineeBirthday;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => $this->traineeHasName() ? 'exclude' : 'required|string|max:50',
            'breed' => 'nullable|string|max:100',
            'sex' => 'nullable|in:'.implode(',', array_keys(TraineeIntakeSurveyResponse::sexLabels())),
            // 誕生日は年・月・日の 3 項目。形式・範囲・組み合わせは withValidator でまとめて判定する
            'birth_year' => 'nullable',
            'birth_month' => 'nullable',
            'birth_day' => 'nullable',
            'neuter_status' => 'nullable|in:'.implode(',', array_keys(TraineeIntakeSurveyResponse::neuterStatusLabels())),
        ];
        // 自由記述 4 欄（どれも任意・2,000 文字以内）
        foreach (array_keys(TraineeIntakeSurveyResponse::questions()) as $column) {
            $rules[$column] = 'nullable|string|max:2000';
        }

        return $rules;
    }

    /**
     * ルートのトークンのトレーニーに名前があるか。トークンが見つからないとき（使えない URL）は
     * 名前を受け取らない（コントローラが使えない URL の画面を出すため、名前の検証は要らない）
     */
    private function traineeHasName(): bool
    {
        $token = TraineeIntakeSurveyToken::query()
            ->where('token', (string) $this->route('token'))
            ->first();
        $trainee = $token === null ? null : Trainee::query()->find($token->trainee_id);

        return $trainee === null || $trainee->has_name;
    }
}

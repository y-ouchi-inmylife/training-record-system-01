<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTraineeBirthday;
use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyResponse;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 受付アンケートの回答（S-1413。D-1100。2026-10 追加）のバリデーション。
 *
 * お客様がログインせずに送る（受付アンケート用 URL のトークンで対象のトレーニーが決まる）ため、
 * authorize() は常に true。トークンが使えるかどうかはコントローラで確かめる
 * （使えなければ使えない URL の画面を出す）。
 *
 * - 名前：常に必須（トレーニーの名前の有無で分けない。2026-10 変更。当初は、未登録なら必須、登録済みなら
 *   受け取らない〔exclude〕とし、コントローラでロックしたあとに確かめ直していた）
 * - 性別・去勢・避妊：トレーニーと同じ値（男の子／女の子／不明、している／していない／不明。Trainee の
 *   対応表のキー。2026-10 変更。当初は性別に「不明」を置かず、去勢・避妊は「分からない」〔dont_know〕だった）
 * - トークンが見つからないとき（使えない URL）も同じ規則で検証する。入力エラーで戻った先の回答画面
 *   （GET）が、使えない URL の画面を出す
 * - 誕生日はトレーナー側（TraineeRequest）と同じ判定（trait NormalizesTraineeBirthday）
 * - 自由記述 4 欄は、検証の前に改行を LF にそろえる（下の prepareForValidation() 参照）
 * - 項目名と文言は lang/ja/validation.php（自由記述は設問の短い呼び名。screen-design.md S-1413）
 */
class ClientIntakeSurveyRequest extends FormRequest
{
    use NormalizesTraineeBirthday {
        prepareForValidation as normalizeTraineeBirthday;
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:50',
            'breed' => 'nullable|string|max:100',
            'sex' => 'nullable|in:'.implode(',', array_keys(Trainee::sexLabels())),
            // 誕生日は年・月・日の 3 項目。形式・範囲・組み合わせは withValidator でまとめて判定する
            'birth_year' => 'nullable',
            'birth_month' => 'nullable',
            'birth_day' => 'nullable',
            'neuter_status' => 'nullable|in:'.implode(',', array_keys(Trainee::neuterStatusLabels())),
        ];
        // 自由記述 4 欄（どれも任意・2,000 文字以内）
        foreach (array_keys(TraineeIntakeSurveyResponse::questions()) as $column) {
            $rules[$column] = 'nullable|string|max:2000';
        }

        return $rules;
    }

    /**
     * 検証の前に値を直す。
     *
     * - 誕生日の 3 つの欄：trait NormalizesTraineeBirthday の処理（全角の数字を半角に、など）
     * - 自由記述 4 欄：改行を LF にそろえる（CRLF → LF、残った CR → LF。2026-10 追加）。
     *   ブラウザは textarea の maxlength で改行を 1 文字と数えるが、送信では改行が CRLF（2 文字）になるため、
     *   そのままだと、改行を含めて 2,000 字近く書いたとき、ブラウザでは入力できたのにサーバーの
     *   「2000文字以内」でエラーになることがある。保存される文章も LF になる（表示は white-space: pre-wrap
     *   なので見え方は変わらない）
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeTraineeBirthday();

        $normalized = [];
        foreach (array_keys(TraineeIntakeSurveyResponse::questions()) as $column) {
            $value = $this->input($column);
            if (is_string($value)) {
                $normalized[$column] = str_replace(["\r\n", "\r"], "\n", $value);
            }
        }
        $this->merge($normalized);
    }
}

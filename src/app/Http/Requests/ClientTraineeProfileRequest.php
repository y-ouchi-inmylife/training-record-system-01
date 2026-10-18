<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTraineeBirthday;
use App\Models\Trainee;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 会員の愛犬の情報の変更（S-1417。要件定義書 6-15-20。2026-10-18 追加）のバリデーション。
 *
 * - 名前・犬種・性別・誕生日・去勢・避妊の規則は、受付アンケート（ClientIntakeSurveyRequest。S-1413）の
 *   愛犬の基本情報と同じ。規則は basicInfoRules() の 1 か所にまとめ、受付アンケートでも使う
 * - 誕生日はトレーナー側・受付アンケートと同じ判定（trait NormalizesTraineeBirthday。エラーのキーは birth_date）
 * - 暮らしの様子（自由記述）・写真は受け取らない（rules() にないキーは validated() に入らない）
 * - 項目名と文言は lang/ja/validation.php（受付アンケートと同じ）
 */
class ClientTraineeProfileRequest extends FormRequest
{
    use NormalizesTraineeBirthday;

    /**
     * 自分の愛犬だけを変えられる。ほかの会員の愛犬は 403（写真の登録・削除と同じ扱い）。
     * 入力の検証より先に確かめる（ほかの会員の愛犬に正しくない値を送っても、入力エラーではなく 403 にする）
     */
    public function authorize(): bool
    {
        $trainee = $this->route('trainee');

        return $trainee instanceof Trainee && $trainee->client_id === auth('client')->id();
    }

    public function rules(): array
    {
        return self::basicInfoRules();
    }

    /**
     * 愛犬の基本情報（名前・犬種・性別・誕生日の年・月・日・去勢・避妊）の規則。
     * 受付アンケート（ClientIntakeSurveyRequest）と共用する
     *
     * - 名前：必須
     * - 性別・去勢・避妊：トレーニーと同じ値（Trainee の対応表のキー）
     * - 誕生日：形式・範囲・組み合わせは trait NormalizesTraineeBirthday の withValidator でまとめて判定する
     *
     * @return array<string, string>
     */
    public static function basicInfoRules(): array
    {
        return [
            'name' => 'required|string|max:50',
            'breed' => 'nullable|string|max:100',
            'sex' => 'nullable|in:'.implode(',', array_keys(Trainee::sexLabels())),
            'birth_year' => 'nullable',
            'birth_month' => 'nullable',
            'birth_day' => 'nullable',
            'neuter_status' => 'nullable|in:'.implode(',', array_keys(Trainee::neuterStatusLabels())),
        ];
    }
}

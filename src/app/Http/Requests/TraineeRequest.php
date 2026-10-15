<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTraineeBirthday;
use Illuminate\Foundation\Http\FormRequest;

/**
 * トレーニー登録・編集の共通バリデーションルール（D-0700）。
 *
 * ClientRequest と同じ方針：ルーティング側のミドルウェア（practitioners）で
 * 認可を担保し、ここでは常に true を返す。
 *
 * 属性名：`name` は lang/ja/validation.php の attributes 側で「名前」になっているため、
 * attributes() では定義しない（下の attributes() のコメント参照）。
 *   経緯：当初は lang 側の `name` が「氏名」だったため、本 Request でだけ「名前」に
 *   上書きしていた。段階 2 で lang 側を「名前」に揃えてから、上書きをやめた。
 *
 * 誕生日（2026-10 変更）：年・月・日の 3 項目（birth_year・birth_month・birth_day）で受け、
 * 分かるところだけ入る。入力のルールは要件定義書 6-16「年齢の表示規則」の「入力のルール」。
 * 全角の数字の直し方と判定（キー `birth_date` に 1 つだけ載せる）は、受付アンケート
 * （ClientIntakeSurveyRequest）と共用の trait NormalizesTraineeBirthday にある（2026-10 に切り出した）。
 */
class TraineeRequest extends FormRequest
{
    use NormalizesTraineeBirthday;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 名前は任意（2026-10 変更。受付アンケートの案内だけを先に発行する運用のため）
            'name' => 'nullable|string|max:50',
            'breed' => 'nullable|string|max:100',
            'sex' => 'nullable|in:male,female,unknown',
            // 誕生日は年・月・日の 3 項目。形式・範囲・組み合わせは withValidator でまとめて判定する
            'birth_year' => 'nullable',
            'birth_month' => 'nullable',
            'birth_day' => 'nullable',
            // 去勢・避妊（2026-10 追加。値は Trainee::neuterStatusLabels() のキー。D-0700・5-21）
            'neuter_status' => 'nullable|in:neutered,not_neutered,unknown',
            // 備考（note）は 2026-10 になくした（要件定義書 D-0700）。古い画面から送られてきても、
            // ルールにないので validated() に含まれず、保存されない
        ];
    }

    /**
     * 属性名の日本語ラベル。
     *
     * `name` は段階 2 で lang/ja/validation.php の attributes 側を「名前」に揃えたため、
     * ここでは重複定義しない。lang 側に未登録のトレーニー固有の項目名だけを定義する。
     */
    public function attributes(): array
    {
        return [
            'breed' => '犬種',
            'sex' => '性別',
            'neuter_status' => '去勢・避妊',
        ];
    }
}

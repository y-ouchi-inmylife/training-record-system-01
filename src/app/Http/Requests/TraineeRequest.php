<?php

namespace App\Http\Requests;

use App\Support\TraineeBirthdayInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
 * 全角の数字は prepareForValidation で半角に直してから判定する。
 * 判定は withValidator（after）で上から順に行い、最初の 1 つだけをキー `birth_date` に載せる
 * （3 つの欄のまとまりの下に 1 つだけ出すため。screen-design.md §2-7 の但し書き・S-0308）。
 */
class TraineeRequest extends FormRequest
{
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
            'note' => 'nullable|string',
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
            'note' => '備考',
        ];
    }

    /**
     * 誕生日の 3 つの欄を、判定の前に直す：全角の数字を半角に、前後の空白（全角を含む）を取り、
     * 空になったら null（要件定義書 6-16「入力のルール」。2026-10 追加）。保存される値も直したあとの値になる。
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['birth_year', 'birth_month', 'birth_day'] as $key) {
            if ($this->has($key)) {
                $normalized[$key] = TraineeBirthdayInput::normalize($this->input($key));
            }
        }
        $this->merge($normalized);
    }

    /**
     * 誕生日（年・月・日）の判定。screen-design.md §2-8 の表の上から順に判定し、最初に当てはまった
     * 1 つだけをキー `birth_date` に載せる。判定の本体は App\Support\TraineeBirthdayInput（入力中の
     * 表示の JS と同じ手順・同じ状態の記号）。状態の記号を、lang の custom.birth_date の文言に対応させる。
     *
     * 基準日（今日・今年）は now() から取る（テストでは Carbon::setTestNow で固定する）。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $today = now();
            $state = TraineeBirthdayInput::state(
                $this->input('birth_year'),
                $this->input('birth_month'),
                $this->input('birth_day'),
                $today->year, $today->month, $today->day,
            );

            if (! in_array($state, [TraineeBirthdayInput::OK, TraineeBirthdayInput::EMPTY], true)) {
                $validator->errors()->add('birth_date', __('validation.custom.birth_date.'.$state));
            }
        });
    }
}

<?php

namespace App\Http\Requests;

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
            'name' => 'required|string|max:50',
            'breed' => 'nullable|string|max:100',
            'sex' => 'nullable|in:male,female,unknown',
            // 誕生日は年・月・日の 3 項目。形式・範囲・組み合わせは withValidator でまとめて判定する
            'birth_year' => 'nullable',
            'birth_month' => 'nullable',
            'birth_day' => 'nullable',
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
            'note' => '備考',
        ];
    }

    /**
     * 誕生日（年・月・日）の判定。screen-design.md S-0308「バリデーションチェック」の表の
     * 上から順に判定し、最初に当てはまった 1 つだけをキー `birth_date` に載せる。
     *
     * 基準日（今日・今年）は now() から取る（テストでは Carbon::setTestNow で固定する）。
     * 入力中の年齢表示（JS。段階2b）も同じ順・同じルールで判定する。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $error = $this->birthDateError(
                $this->input('birth_year'),
                $this->input('birth_month'),
                $this->input('birth_day'),
            );

            if ($error !== null) {
                $validator->errors()->add('birth_date', __('validation.custom.birth_date.'.$error));
            }
        });
    }

    /**
     * 誕生日の判定の本体。当てはまったルールの名前（lang の custom.birth_date のキー）を返す。
     * 問題がなければ null。値は空欄なら null（ConvertEmptyStringsToNull）、それ以外は文字列で届く。
     */
    private function birthDateError(mixed $year, mixed $month, mixed $day): ?string
    {
        // 1. 数字であること（半角の数字だけ）
        foreach ([$year, $month, $day] as $value) {
            if ($value === null) {
                continue;
            }
            // 配列などが送られた場合も「数字ではない」として扱う
            if (! is_string($value) && ! is_int($value)) {
                return 'numeric';
            }
            if (! preg_match('/^\d+$/', (string) $value)) {
                return 'numeric';
            }
        }

        $y = $year === null ? null : (int) $year;
        $m = $month === null ? null : (int) $month;
        $d = $day === null ? null : (int) $day;

        // 2. 月は 1〜12
        if ($m !== null && ($m < 1 || $m > 12)) {
            return 'month_range';
        }

        // 3. 月があるなら年がある
        if ($m !== null && $y === null) {
            return 'month_without_year';
        }

        // 4. 日があるなら年・月がある
        if ($d !== null && ($y === null || $m === null)) {
            return 'day_without_year_month';
        }

        if ($y === null) {
            return null; // 未入力
        }

        // 5. 3 つそろったら実在する日付
        if ($d !== null && ! checkdate($m, $d, $y)) {
            return 'invalid_date';
        }

        // 6. 未来でない（年だけなら今年以前、年・月までなら今月以前、年・月・日なら今日以前）
        $today = now();
        $future = match (true) {
            $m === null => $y > $today->year,
            $d === null => [$y, $m] > [$today->year, $today->month],
            default => [$y, $m, $d] > [$today->year, $today->month, $today->day],
        };
        if ($future) {
            return 'future';
        }

        // 7. 年は今年から 30 年前まで
        if ($y < $today->year - 30) {
            return 'year_range';
        }

        return null;
    }
}

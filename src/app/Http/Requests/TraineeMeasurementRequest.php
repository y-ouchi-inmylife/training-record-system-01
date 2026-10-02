<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * トレーニー計測値の登録・編集の共通バリデーションルール（D-0800）。
 *
 * 認可はルートのミドルウェア（practitioners）で担保。
 * 一般トレーナーも操作可能（削除も含めて、設計書 6-16-5〜6-16-7）。
 *
 * トレーニーID の取得元は登録／編集で異なる：
 *   - 登録（`POST /trainees/{trainee}/measurements`）：URL パラメータ `trainee` から取る
 *   - 編集（`PUT /trainee-measurements/{measurement}`）：対象レコードの `trainee_id` を使う
 */
class TraineeMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'measured_date' => 'required|date',
            // 既存 training_records.training_time と同じ書き方（H:i）に合わせる。
            'measured_time' => 'required|date_format:H:i',
            'weight_kg' => 'required|numeric|gt:0|max:999.99|decimal:0,2',
            // 同一トレーニー・同一日時の二重登録を防ぐ（DB のユニーク制約と併せて二層で担保）。
            // 実装は withValidator でルート引数に応じて動的に組み立てる。
        ];
    }

    /**
     * ユニーク制約はトレーニーID の取得元が登録／編集で異なるため、
     * withValidator でルート引数に応じて動的に組み立てる。
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $measurement = $this->route('measurement'); // 編集時のみ存在
        $trainee = $this->route('trainee'); // 登録時のみ存在

        // 編集時は既存レコードの trainee_id を使う（トレーニー切替は起こさない設計）。
        // 登録時は URL パラメータの trainee モデルから id を取る。
        $traineeId = $measurement?->trainee_id ?? $trainee?->id;
        if (! $traineeId) {
            return;
        }

        $rule = Rule::unique('trainee_measurements')
            ->where('trainee_id', $traineeId)
            ->where('measured_date', $this->input('measured_date'))
            ->where('measured_time', $this->input('measured_time'));

        if ($measurement) {
            $rule->ignore($measurement->id);
        }

        // Laravel の Validator に unique ルールを追加する。attribute は
        // measured_time 相当にまとめると分かりやすいが、複合キーのため
        // 別名の擬似属性 `duplicate_measurement` にメッセージを載せる。
        $validator->addRules([
            'measured_time' => [$rule],
        ]);
    }

    /**
     * `name` を「氏名」に上書きした段階① の TraineeRequest と同じ方針で、
     * トレーニー計測値の文脈に合わせた属性名をここで定義する
     * （グローバルの `lang/ja/validation.php` は変更しない）。
     */
    public function attributes(): array
    {
        return [
            'measured_date' => '計測日',
            'measured_time' => '計測時刻',
            'weight_kg' => '体重',
        ];
    }

    // measured_time.unique は lang/ja/validation.php の custom.measured_time.unique
    // 「この日時の計測値はすでに登録されています。」に集約（設計書 §2-8）
}

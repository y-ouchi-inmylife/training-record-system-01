<?php

namespace App\Http\Requests\Concerns;

use App\Support\TraineeBirthdayInput;
use Illuminate\Validation\Validator;

/**
 * トレーニーの誕生日（年・月・日の 3 つの欄。birth_year・birth_month・birth_day）の受け付け。
 *
 * トレーナー側のトレーニー登録・編集（TraineeRequest。S-0308／S-0310）と、お客様の愛犬アンケート
 * （ClientIntakeSurveyRequest。S-1413）で共用する（2026-10。TraineeRequest から切り出した）。
 * 入力のルールは要件定義書 6-16「年齢の表示規則」の「入力のルール」、判定の順と文言は
 * screen-design.md §2-8「誕生日（年・月・日の 3 つの欄）の文言」の表。
 *
 * - 全角の数字は prepareForValidation で半角に直してから判定する（保存される値も直したあとの値）
 * - 判定は withValidator（after）で上から順に行い、最初の 1 つだけをキー `birth_date` に載せる
 *   （3 つの欄のまとまりの下に 1 つだけ出すため。screen-design.md §2-7 の但し書き）
 * - rules() には 3 つとも `nullable` だけを書いておく（形式・範囲・組み合わせはここでまとめて判定する）
 */
trait NormalizesTraineeBirthday
{
    /**
     * 誕生日の 3 つの欄を、判定の前に直す：全角の数字を半角に、前後の空白（全角を含む）を取り、
     * 空になったら null（要件定義書 6-16「入力のルール」）。
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

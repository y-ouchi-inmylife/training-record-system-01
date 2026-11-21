<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 予定の設定（S-1601。2026-11 追加）の更新の検証。
 *
 * 認可はルートのミドルウェア（admin-only。要約プロンプト S-0601・愛犬性格診断のプロンプト S-0602 と同じ）で担保。
 * ルールは screen-design.md S-1601 のバリデーションの表のとおり。
 * 「営業終了時刻は営業開始時刻より後」（同じ時刻は不可）は、2 つの欄の形式が正しいときだけ確かめる
 * （形式の誤りに重ねて前後の文言を出さないため）。
 * 項目名・文言は lang/ja/validation.php に集約（§2-8）。
 */
class ScheduleSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedule_open_time' => 'required|date_format:H:i',
            'schedule_close_time' => 'required|date_format:H:i',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['schedule_open_time', 'schedule_close_time'])) {
                return;
            }

            // 「HH:MM」どうしは文字列で比べて前後が分かる
            if ((string) $this->input('schedule_close_time') <= (string) $this->input('schedule_open_time')) {
                $validator->errors()->add(
                    'schedule_close_time',
                    __('validation.custom.schedule_close_time.after_open_time')
                );
            }
        });
    }
}

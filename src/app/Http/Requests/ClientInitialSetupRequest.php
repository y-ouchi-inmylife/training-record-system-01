<?php

namespace App\Http\Requests;

use App\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

/**
 * クライアント初回設定（S-1403）のバリデーションルール。
 *
 * トレーナー側の [[ClientRequest]] と必須項目が異なる（電話番号・住所が必須）ため、
 * 別に用意する。ルールは設計書 api-design.md `POST /client-portal/setup/{token}`。
 *
 * 認可はルートの公開設定・トークン検証で担保しており、本 FormRequest では常に true を返す。
 */
class ClientInitialSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // パスワード
            'password' => ['required', 'string', 'confirmed', new StrongPassword()],

            // 氏名
            'last_name' => 'required|string|max:50',
            'first_name' => 'nullable|string|max:50',
            'last_name_kana' => ['nullable', 'string', 'max:50', 'regex:/^[\p{Hiragana}\s　]+$/u'],
            'first_name_kana' => ['nullable', 'string', 'max:50', 'regex:/^[\p{Hiragana}\s　]+$/u'],

            // 連絡先（電話番号 phone1・都道府県 address1・市区町村 address2・
            // 町名番地 address3 は必須。郵便番号は任意で、入力された場合のみ
            // 形式チェック。DB が NULL 許容で、郵便番号は住所検索の入口という
            // 位置づけのため。設計書 S-1403 備考 / 6-15-5 参照）
            'phone1' => ['required', 'string', 'max:20', 'regex:/^[0-9\-]+$/'],
            'phone2' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-]+$/'],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{3}-?\d{4}$/'],
            'address1' => 'required|string|max:50',
            'address2' => 'required|string|max:50',
            'address3' => 'required|string|max:100',
            'address4' => 'nullable|string|max:100',

            // 愛犬（トレーニー）— TraineeRequest と同じルール（S-0308 と揃える）。
            // フィールド名の `trainee_` プレフィックスは、会員自身の項目
            // （last_name など）と区別するため（api-design.md 参照）。
            'trainee_name' => 'required|string|max:50',
            'trainee_breed' => 'nullable|string|max:100',
            'trainee_sex' => 'nullable|in:male,female,unknown',
            'trainee_birth_date' => 'nullable|date|before_or_equal:today',
            'trainee_note' => 'nullable|string',
        ];
    }

    /**
     * 属性名の日本語ラベル。`in` / `date` / `before_or_equal` などの汎用ルールで
     * 自動生成されるメッセージ内の `:attribute` を日本語にするために定義する。
     * 他の規則の文言は lang/ja/validation.php に集約する（段階 2 で messages() を
     * 削除した。設計書 §2-8「文言の置き場所」）。
     */
    public function attributes(): array
    {
        return [
            'trainee_name' => '愛犬の名前',
            'trainee_breed' => '犬種',
            'trainee_sex' => '性別',
            'trainee_birth_date' => '誕生日',
            'trainee_note' => '備考',
        ];
    }
}

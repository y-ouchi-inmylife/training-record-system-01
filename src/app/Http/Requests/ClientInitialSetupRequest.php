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
 * 愛犬（トレーニー）の項目は 2026-10 に外した（受付アンケートに分離。requirements.md 6-15-5）。
 * それに伴い、trainee_* の属性名だけを定義していた attributes() も外した（他の項目の
 * 属性名は lang/ja/validation.php の attributes で足りる）。
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
        ];
    }
}

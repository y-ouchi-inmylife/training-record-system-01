<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * クライアント基本情報（連絡先）の変更（S-1411 / 6-15-9）バリデーション。
 *
 * 電話番号・都道府県・市区町村・町名番地を必須で受け取り、氏名・メールアドレス・
 * パスワードは受け付けない（別画面）。郵便番号は任意（住所検索の入口という位置づけで、
 * DB も NULL 許容。設計書 S-1411 備考参照）。ルールは S-1403 初回設定の連絡先項目と揃える。
 */
class ClientProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone1' => ['required', 'string', 'max:20', 'regex:/^[0-9\-]+$/'],
            'phone2' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\-]+$/'],
            'postal_code' => ['nullable', 'string', 'regex:/^\d{3}-?\d{4}$/'],
            'address1' => 'required|string|max:50',
            'address2' => 'required|string|max:50',
            'address3' => 'required|string|max:100',
            'address4' => 'nullable|string|max:100',
        ];
    }

    // messages() は段階 2 で削除。文言は lang/ja/validation.php に集約（設計書 §2-8）。
}

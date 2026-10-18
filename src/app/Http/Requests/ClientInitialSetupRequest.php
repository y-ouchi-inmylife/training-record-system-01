<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ClientContactRules;
use App\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

/**
 * クライアント初回設定（S-1403）のバリデーションルール。
 *
 * トレーナー側の [[ClientRequest]] と必須項目が異なるため、別に用意する。ルールは設計書 api-design.md `POST /client-portal/setup/{token}`。
 * 2026-10-18 変更：住所・電話番号を任意にした（住所は一部だけ入れたらそろえて入れてもらう。ClientContactRules）。
 * その前は電話番号・都道府県・市区町村・町名番地が必須だった。
 *
 * 愛犬（トレーニー）の項目は 2026-10 に外した（受付アンケートに分離。requirements.md 6-15-5）。
 * それに伴い、trainee_* の属性名だけを定義していた attributes() も外した（他の項目の
 * 属性名は lang/ja/validation.php の attributes で足りる）。
 *
 * 認可はルートの公開設定・トークン検証で担保しており、本 FormRequest では常に true を返す。
 */
class ClientInitialSetupRequest extends FormRequest
{
    use ClientContactRules;

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

            // 連絡先（住所・電話番号は任意。2026-10-18 変更。設計書 S-1403 / 6-15-5 参照）
            ...$this->contactRules(required: false),
        ];
    }
}

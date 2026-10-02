<?php

namespace App\Http\Requests;

use App\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

/**
 * クライアントパスワード再設定 完了（S-1408 / 6-15-12）バリデーション。
 *
 * トークン付き URL から届いたお客様が、新しいパスワードを設定するとき。
 * 現在のパスワードは求めない（忘れているため。決定事項 #8）。
 * 新しいパスワードは S-1403 初回設定と同じ強度要件（StrongPassword）と、
 * 確認用との一致を求める。
 */
class ClientPasswordResetCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_password' => ['required', 'string', 'confirmed', new StrongPassword()],
        ];
    }

    // messages() は段階 2 で削除。文言は lang/ja/validation.php に集約（設計書 §2-8）。
}

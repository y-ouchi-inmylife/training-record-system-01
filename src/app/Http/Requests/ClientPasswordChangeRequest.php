<?php

namespace App\Http\Requests;

use App\Rules\StrongPassword;
use Illuminate\Foundation\Http\FormRequest;

/**
 * クライアントパスワード変更（S-1410 / 6-15-11）バリデーション。
 *
 * 現在のパスワードは client guard で照合する。新しいパスワードは
 * S-1403 初回設定と同じ強度要件（StrongPassword）と、確認用との一致を求める。
 */
class ClientPasswordChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Laravel の current_password ルールは第 1 引数にガード名を取れる
            'current_password' => ['required', 'string', 'current_password:client'],
            'new_password' => ['required', 'string', 'confirmed', new StrongPassword()],
        ];
    }

    // messages() は段階 2 で削除。文言は lang/ja/validation.php に集約（設計書 §2-8）。
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * クライアントメールアドレス変更 申し込み（S-1409 / 6-15-10）バリデーション。
 *
 * 新しいメールアドレスと現在のパスワードを受け取り、申し込みを行う。
 * 実際の切替は確認リンクを開いた時点で行うため、この時点では clients.email は
 * 書き換えない（コントローラ側の処理）。
 */
class ClientEmailChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $client = Auth::guard('client')->user();

        return [
            // 新しいメールアドレス：形式・重複（自クライアント除外）・現在と異なること
            'new_email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('clients', 'email')->ignore($client?->id),
                function ($attribute, $value, $fail) use ($client) {
                    if ($client && $client->email === $value) {
                        $fail('現在と同じメールアドレスです。');
                    }
                },
            ],
            // 現在のパスワードは client guard で照合
            'current_password' => ['required', 'string', 'current_password:client'],
        ];
    }

    // messages() は段階 2 で削除。文言は lang/ja/validation.php に集約
    // （設計書 §2-8。重複は custom.new_email.unique「このメールアドレスは登録できません。」で例外）。
}

<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Lang;

/**
 * パスワード強度バリデーションルール
 *
 * 文言は `lang/ja/validation.php` の `strong_password.*` に置き、項目名は
 * 画面のラベル（`attributes.password` / `attributes.new_password` など）を
 * 使う（画面設計書 §2-8「例外」の強度の節）。
 *
 * 例：
 * - `password` キーに付けると「パスワードは8文字以上で入力してください。」
 * - `new_password` キーに付けると「新しいパスワードは8文字以上で入力してください。」
 *
 * 最後の `common` 文（よく使われるパスワード）は項目名を含まない固定文言。
 */
class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 項目名は attributes セクションから引く。未登録なら生のキーをそのまま使う
        // （フォールバック。段階 2 で主要な項目は attributes に揃えてある）。
        $attrName = Lang::has('validation.attributes.' . $attribute)
            ? __('validation.attributes.' . $attribute)
            : $attribute;

        if (strlen($value) < 8) {
            $fail(__('validation.strong_password.min', ['attribute' => $attrName]));
            return;
        }

        if (!preg_match('/[A-Z]/', $value)) {
            $fail(__('validation.strong_password.uppercase', ['attribute' => $attrName]));
            return;
        }

        if (!preg_match('/[a-z]/', $value)) {
            $fail(__('validation.strong_password.lowercase', ['attribute' => $attrName]));
            return;
        }

        if (!preg_match('/[0-9]/', $value)) {
            $fail(__('validation.strong_password.digits', ['attribute' => $attrName]));
            return;
        }

        if (!preg_match('/[!@#$%^&*()\-_=+\[\]{};:\'",.<>\/?\\\\|`~]/', $value)) {
            $fail(__('validation.strong_password.symbols', ['attribute' => $attrName]));
            return;
        }

        // よく使われるパスワードを禁止（項目名を含まない固定文言）
        $commonPasswords = ['password', 'password1!', 'admin123!', 'Password1!', 'Admin123!'];
        if (in_array(strtolower($value), array_map('strtolower', $commonPasswords))) {
            $fail(__('validation.strong_password.common'));
        }
    }
}

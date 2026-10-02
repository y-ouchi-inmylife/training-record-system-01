<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * パスワード強度バリデーションルール
 *
 * 文言は `lang/ja/validation.php` の `strong_password.*` に置き、`:attribute`
 * を含んだままの文字列を `$fail()` に渡す。項目名の置換は Laravel の
 * Validator に任せる（`addFailure` の後の `makeReplacements` で、言語ファイル
 * の `attributes` セクションと、`Validator::make()` の第 4 引数で渡される
 * attributes 上書きの両方が解決される）。
 *
 * 例：
 * - `password` キーに付けると `attributes.password = 'パスワード'` が引かれ、
 *   「パスワードは8文字以上で入力してください。」
 * - `new_password` キーに付けると `attributes.new_password = '新しいパスワード'`
 *   が引かれ、「新しいパスワードは8文字以上で入力してください。」
 * - 呼び出し側で `Validator::make(..., [], ['password' => 'ラベル'])` で
 *   項目名を上書きしたときは、そのラベルに置き換わる
 *
 * 最後の `common` 文（よく使われるパスワード）は項目名を含まない固定文言。
 */
class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (strlen($value) < 8) {
            $fail('validation.strong_password.min')->translate();
            return;
        }

        if (!preg_match('/[A-Z]/', $value)) {
            $fail('validation.strong_password.uppercase')->translate();
            return;
        }

        if (!preg_match('/[a-z]/', $value)) {
            $fail('validation.strong_password.lowercase')->translate();
            return;
        }

        if (!preg_match('/[0-9]/', $value)) {
            $fail('validation.strong_password.digits')->translate();
            return;
        }

        if (!preg_match('/[!@#$%^&*()\-_=+\[\]{};:\'",.<>\/?\\\\|`~]/', $value)) {
            $fail('validation.strong_password.symbols')->translate();
            return;
        }

        // よく使われるパスワードを禁止（項目名を含まない固定文言）
        $commonPasswords = ['password', 'password1!', 'admin123!', 'Password1!', 'Admin123!'];
        if (in_array(strtolower($value), array_map('strtolower', $commonPasswords))) {
            $fail('validation.strong_password.common')->translate();
        }
    }
}

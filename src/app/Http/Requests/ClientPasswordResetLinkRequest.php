<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * パスワード再設定リンクの申し込み（S-1407 / 6-15-12）バリデーション。
 *
 * ログイン画面の「パスワードを忘れた方」から遷移して、登録アドレスを入力する。
 * メールアドレスの形式のみ検証し、**登録の有無はチェックしない**
 * （第三者に登録の有無を露呈させないため。設計書 6-15-12 の備考）。
 *
 * 元の指示では `ClientPasswordResetRequestRequest.php` の名前だったが、
 * 「Request」が二重で読みにくいため `ClientPasswordResetLinkRequest` に変更した。
 * 意図：「パスワード再設定リンクを申し込むリクエスト」。
 */
class ClientPasswordResetLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 登録の有無はチェックしない。形式のみ確認する
            'email' => ['required', 'email', 'max:255'],
        ];
    }

    // messages() は段階 2 で削除。文言は lang/ja/validation.php に集約（設計書 §2-8）。
}

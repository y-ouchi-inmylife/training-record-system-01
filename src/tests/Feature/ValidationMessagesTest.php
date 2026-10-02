<?php

namespace Tests\Feature;

use App\Rules\StrongPassword;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * `lang/ja/validation.php` の規約（画面設計書 §2-8）どおりに文言が
 * 組み立てられるかを検証する。
 *
 * DB・認証を使う規則（unique / exists / current_password）は、文言そのものだけを
 * `__('validation.…', ['attribute' => …])` で確かめる。それ以外は Validator::make()
 * で実際にルール評価を回し、文言を比べる。
 */
class ValidationMessagesTest extends TestCase
{
    /** 入力の必須（§2-8「必須（入力）」）→「{項目}を入力してください。」 */
    public function test_入力の必須は項目名入りの文言になる(): void
    {
        $v = Validator::make([], ['last_name' => 'required']);
        $this->assertTrue($v->fails());
        $this->assertSame('姓を入力してください。', $v->errors()->first('last_name'));
    }

    /** 選択の必須（§2-8「必須（選択）」）：都道府県（select） */
    public function test_都道府県の必須は選択してくださいになる(): void
    {
        $v = Validator::make([], ['address1' => 'required']);
        $this->assertTrue($v->fails());
        $this->assertSame('都道府県を選択してください。', $v->errors()->first('address1'));
    }

    /** 選択の必須：権限（select） */
    public function test_権限の必須は選択してくださいになる(): void
    {
        $v = Validator::make([], ['role' => 'required']);
        $this->assertTrue($v->fails());
        $this->assertSame('権限を選択してください。', $v->errors()->first('role'));
    }

    /** 選択の必須：担当1（select） */
    public function test_担当1の必須は選択してくださいになる(): void
    {
        $v = Validator::make([], ['trainer1_id' => 'required']);
        $this->assertTrue($v->fails());
        $this->assertSame('担当1を選択してください。', $v->errors()->first('trainer1_id'));
    }

    /** メールアドレスの形式（§2-8「形式」） */
    public function test_メールアドレスの形式は形式が正しくありませんになる(): void
    {
        $v = Validator::make(['email' => 'not-an-email'], ['email' => 'email']);
        $this->assertTrue($v->fails());
        $this->assertSame('メールアドレスの形式が正しくありません。', $v->errors()->first('email'));
    }

    /** 確認用との不一致（§2-8「確認用との不一致」）：新しいパスワード */
    public function test_新しいパスワードの確認が一致しないと項目名入りの文言になる(): void
    {
        $v = Validator::make(
            ['new_password' => 'Secret123!', 'new_password_confirmation' => 'wrong'],
            ['new_password' => 'confirmed']
        );
        $this->assertTrue($v->fails());
        $this->assertSame('新しいパスワード（確認）が一致しません。', $v->errors()->first('new_password'));
    }

    /** 項目どうしの関係（§2-8「項目どうしの関係」）：開始日 ≦ 終了日 */
    public function test_終了日が開始日より前なら項目間の関係の文言になる(): void
    {
        $v = Validator::make(
            ['date_from' => '2026-05-01', 'date_to' => '2026-04-01'],
            [
                'date_from' => 'nullable|date',
                'date_to'   => 'nullable|date|after_or_equal:date_from',
            ]
        );
        $this->assertTrue($v->fails());
        $this->assertSame('終了日は開始日以降の日付で入力してください。', $v->errors()->first('date_to'));
    }

    /** パスワードの強度：項目名「パスワード」（§2-8「例外・強度」）
     * 文字数不足のケースで、文言に「パスワード」が入ることを確かめる。 */
    public function test_パスワードの強度の文言に項目名パスワードが入る(): void
    {
        $v = Validator::make(['password' => 'a'], ['password' => [new StrongPassword()]]);
        $this->assertTrue($v->fails());
        $this->assertSame('パスワードは8文字以上で入力してください。', $v->errors()->first('password'));
    }

    /** パスワードの強度：項目名「新しいパスワード」
     * キーが new_password のとき、attributes.new_password から「新しいパスワード」が
     * 入ることを確かめる（StrongPassword 可変化）。 */
    public function test_新しいパスワードの強度の文言に項目名新しいパスワードが入る(): void
    {
        $v = Validator::make(['new_password' => 'a'], ['new_password' => [new StrongPassword()]]);
        $this->assertTrue($v->fails());
        $this->assertSame('新しいパスワードは8文字以上で入力してください。', $v->errors()->first('new_password'));
    }

    /** パスワードの強度：大文字が無い → 項目名入りの「〜に大文字を1文字以上含めてください。」 */
    public function test_パスワードの大文字不足の文言に項目名が入る(): void
    {
        $v = Validator::make(['new_password' => 'abcdefgh1!'], ['new_password' => [new StrongPassword()]]);
        $this->assertTrue($v->fails());
        $this->assertSame('新しいパスワードに大文字を1文字以上含めてください。', $v->errors()->first('new_password'));
    }

    /** パスワードの強度：よく使われるパスワード → 項目名を含まない固定文言 */
    public function test_よく使われるパスワードの文言は項目名を含まない固定文言である(): void
    {
        $v = Validator::make(['new_password' => 'Password1!'], ['new_password' => [new StrongPassword()]]);
        $this->assertTrue($v->fails());
        $this->assertSame('よく使われるパスワードは使用できません。', $v->errors()->first('new_password'));
    }

    /** ログインID の使えない文字（§2-8「例外・具体文言の優先」） */
    public function test_ログインIDの正規表現違反は具体文言になる(): void
    {
        $v = Validator::make(
            ['login_id' => 'bad user!'],
            ['login_id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_]+$/']]
        );
        $this->assertTrue($v->fails());
        $this->assertSame('ログインIDに使用できない文字が含まれています。', $v->errors()->first('login_id'));
    }

    /** 重複（§2-8「重複」）：unique の文言そのもの（DB は使わず trans 関数で確認） */
    public function test_unique系の既定文言はすでに使われていますになる(): void
    {
        // 既定文言：':attributeはすでに使われています。' の形
        $message = __('validation.unique', ['attribute' => 'ログインID']);
        $this->assertSame('このログインIDはすでに使われています。', $message);
    }

    /** 現在のパスワードの誤り（§2-8「現在のパスワードの誤り」）
     * current_password ルールの既定文言（DB・認証は使わず trans 関数で確認） */
    public function test_current_passwordの既定文言は現在のパスワードが正しくありませんになる(): void
    {
        $this->assertSame('現在のパスワードが正しくありません。', __('validation.current_password'));
    }

    /** 会員側のメールアドレスの重複（§2-8「例外・登録の有無の露呈回避」） */
    public function test_会員側メールアドレスのunique文言は登録できませんに縮まる(): void
    {
        $this->assertSame(
            'このメールアドレスは登録できません。',
            __('validation.custom.email.unique')
        );
        $this->assertSame(
            'このメールアドレスは登録できません。',
            __('validation.custom.new_email.unique')
        );
    }
}

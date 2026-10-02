<?php

namespace Tests\Feature;

use App\Models\Trainer;
use Tests\TestCase;

/**
 * 段階 3-5 の描画確認用テスト。ログイン（S-0101）・強制パスワード変更（S-0102）・
 * マイプロフィール（S-1201）・パスワード変更（S-1202）のビューを ViewErrorBag で
 * 描画し、§2-7 の規約に合うかを確かめる（これまでの描画テストと同じ作り方。
 * DB は使わない）。
 *
 * ログインは §2-7 の共通ルール「認証の失敗・無効化・ロックは専用のキー login で
 * フォームの上に alert-danger で出し、欄の下の表示や上部の案内は出さない」の
 * 挙動も確かめる。
 */
class LoginAndProfileFormErrorRenderingTest extends TestCase
{
    private function makeTrainer(): Trainer
    {
        $t = new Trainer();
        $t->id = 1;
        $t->login_id = 'sato';
        $t->name = '佐藤一郎';
        $t->role = 'staff';
        $t->must_change_password = true;
        return $t;
    }

    private function renderLogin(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('auth.login');
    }

    private function renderChangePassword(array $errors): \Illuminate\Testing\TestView
    {
        // auth/change-password は auth()->user() を参照するためモックが要る。
        // 強制変更の警告（must_change_password）の alert は認証ユーザーの状態に依存するので、
        // ここは入力エラーの描画確認が目的のため、認証ユーザー不要の最小限の描画を取る。
        $this->actingAs($this->makeTrainer());
        return $this->withViewErrors($errors)->view('auth.change-password');
    }

    private function renderProfileEdit(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('profile.edit', [
            'trainer' => $this->makeTrainer(),
        ]);
    }

    private function renderProfilePassword(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('profile.password');
    }

    // ---- ログイン（S-0101）----

    public function test_ログイン_formにnovalidateが付く(): void
    {
        $view = $this->renderLogin([]);
        $view->assertSee('novalidate', false);
    }

    public function test_ログイン_エラーなしでは何も出ない(): void
    {
        $view = $this->renderLogin([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_ログイン_入力エラーでは上部案内と欄下文言が出る(): void
    {
        $view = $this->renderLogin([
            'login_id' => 'ERR_login_id',
            'password' => 'ERR_password',
        ]);
        $view->assertSee('入力内容に誤りがあります。');
        $view->assertSee('ERR_login_id');
        $view->assertSee('ERR_password');
        $view->assertSee('is-invalid', false);
    }

    public function test_ログイン_認証失敗の文言はフォームの上に出て欄下にも上部案内にも出ない(): void
    {
        $view = $this->renderLogin([
            'login' => 'ログインIDまたはパスワードが正しくありません。',
        ]);
        // フォームの上に文言が出る
        $view->assertSee('ログインIDまたはパスワードが正しくありません。');
        // 上部案内「入力内容に誤りがあります」は出ない（§2-7 共通ルール）
        $view->assertDontSee('入力内容に誤りがあります');
        // 入力欄に is-invalid・欄下文言は付かない（キーが login_id/password ではなく login のため）
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_ログイン_無効化やロックの文言もフォームの上に出て欄には影響しない(): void
    {
        foreach (['このアカウントは無効化されています。管理者にお問い合わせください。',
                  'アカウントがロックされています。管理者に連絡してください。'] as $msg) {
            $view = $this->renderLogin(['login' => $msg]);
            $view->assertSee($msg);
            $view->assertDontSee('入力内容に誤りがあります');
            $view->assertDontSee('is-invalid', false);
        }
    }

    // ---- 強制パスワード変更（S-0102）----

    public function test_強制PW変更_formにnovalidateが付く(): void
    {
        $view = $this->renderChangePassword([]);
        $view->assertSee('id="change-password-form"', false);
        $view->assertSee('novalidate', false);
    }

    public function test_強制PW変更_エラーなしでは何も出ない(): void
    {
        $view = $this->renderChangePassword([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_強制PW変更_エラーで上部案内と欄下文言が出る(): void
    {
        $view = $this->renderChangePassword(['new_password' => 'ERR_new_password']);
        $view->assertSee('入力内容に誤りがあります。');
        $view->assertSee('ERR_new_password');
        $view->assertSee('is-invalid', false);
    }

    // ---- マイプロフィール（S-1201）----

    public function test_マイプロフィール_formにnovalidateが付く(): void
    {
        $view = $this->renderProfileEdit([]);
        $view->assertSee('id="profile-edit-form"', false);
        $view->assertSee('novalidate', false);
    }

    public function test_マイプロフィール_エラーなしでは何も出ない(): void
    {
        $view = $this->renderProfileEdit([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
    }

    public function test_マイプロフィール_エラーで上部案内と欄下文言が出る(): void
    {
        $view = $this->renderProfileEdit(['name' => 'ERR_name']);
        $view->assertSee('入力内容に誤りがあります。');
        $view->assertSee('ERR_name');
        $view->assertSee('is-invalid', false);
    }

    // ---- パスワード変更（S-1202）----

    public function test_PW変更_formにnovalidateが付く(): void
    {
        $view = $this->renderProfilePassword([]);
        $view->assertSee('id="profile-password-form"', false);
        $view->assertSee('novalidate', false);
    }

    public function test_PW変更_エラーなしでは何も出ない(): void
    {
        $view = $this->renderProfilePassword([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
    }

    public function test_PW変更_全項目にエラーで上部案内と各欄文言が出る(): void
    {
        $errors = [
            'current_password' => 'ERR_current_password',
            'new_password' => 'ERR_new_password',
        ];
        $view = $this->renderProfilePassword($errors);
        $view->assertSee('入力内容に誤りがあります。');
        foreach ($errors as $message) {
            $view->assertSee($message);
        }
        $view->assertSee('is-invalid', false);
    }

    /**
     * current_password ルールの既定文言が「現在のパスワードが正しくありません。」
     * になることを確かめる（決定事項 3 の根拠。認証を伴うため、Validator::make
     * ではなく __() ヘルパで言語ファイル直読する）。
     */
    public function test_current_passwordルールの既定文言は現在のパスワードが正しくありませんになる(): void
    {
        $this->assertSame('現在のパスワードが正しくありません。', __('validation.current_password'));
    }
}

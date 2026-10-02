<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 段階 4-1 の描画確認用テスト。会員ログイン（S-1401）・メールアドレス登録
 * （S-1405）・パスワード再設定申し込み（S-1407）・パスワード再設定（S-1408）の
 * 4 画面を ViewErrorBag で描画し、§2-7 の規約に合うかを確かめる。
 *
 * 認証失敗やメール送信失敗の「入力エラーではない失敗」を専用のキー
 * （login / form）で返したときの挙動も確かめる。DB は使わない。
 */
class ClientLoginAndEmailResetFormErrorRenderingTest extends TestCase
{
    private function renderLogin(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('client.login');
    }

    private function renderEmailRegistration(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('client.email-registration.index', [
            'token' => 'dummy-token',
            'submittedEmail' => null,
        ]);
    }

    private function renderPasswordResetRequest(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('client.password-reset.request', [
            'submitted' => false,
        ]);
    }

    private function renderPasswordResetReset(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('client.password-reset.reset', [
            'token' => 'dummy-token',
        ]);
    }

    // ---- 会員ログイン（S-1401）----

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
            'email' => 'ERR_email',
            'password' => 'ERR_password',
        ]);
        $view->assertSee('入力内容に誤りがあります。赤字の項目を確認してください。');
        $view->assertSee('ERR_email');
        $view->assertSee('ERR_password');
        $view->assertSee('is-invalid', false);
    }

    public function test_ログイン_認証失敗はフォームの上に出て欄下にも上部案内にも出ない(): void
    {
        $view = $this->renderLogin([
            'login' => 'メールアドレスまたはパスワードが正しくありません。',
        ]);
        $view->assertSee('メールアドレスまたはパスワードが正しくありません。');
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    // ---- メールアドレス登録（S-1405）----

    public function test_メアド登録_formにnovalidateが付く(): void
    {
        $view = $this->renderEmailRegistration([]);
        $view->assertSee('novalidate', false);
    }

    public function test_メアド登録_エラーなしでは何も出ない(): void
    {
        $view = $this->renderEmailRegistration([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
    }

    public function test_メアド登録_入力エラーでは上部案内と欄下文言が出る(): void
    {
        $view = $this->renderEmailRegistration(['email' => 'ERR_email']);
        $view->assertSee('入力内容に誤りがあります。赤字の項目を確認してください。');
        $view->assertSee('ERR_email');
        $view->assertSee('is-invalid', false);
    }

    public function test_メアド登録_メール送信失敗はフォームの上に出て欄下にも上部案内にも出ない(): void
    {
        $view = $this->renderEmailRegistration([
            'form' => 'メールの送信に失敗しました。時間を置いて再度お試しください。',
        ]);
        $view->assertSee('メールの送信に失敗しました。時間を置いて再度お試しください。');
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    // ---- パスワード再設定申し込み（S-1407）----

    public function test_PW再設定申込_formにnovalidateが付く(): void
    {
        $view = $this->renderPasswordResetRequest([]);
        $view->assertSee('novalidate', false);
    }

    public function test_PW再設定申込_エラーなしでは何も出ない(): void
    {
        $view = $this->renderPasswordResetRequest([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
    }

    public function test_PW再設定申込_入力エラーでは上部案内と欄下文言が出る(): void
    {
        $view = $this->renderPasswordResetRequest(['email' => 'ERR_email']);
        $view->assertSee('入力内容に誤りがあります。赤字の項目を確認してください。');
        $view->assertSee('ERR_email');
        $view->assertSee('is-invalid', false);
    }

    // ---- パスワード再設定（S-1408）----

    public function test_PW再設定_formにnovalidateが付く(): void
    {
        $view = $this->renderPasswordResetReset([]);
        $view->assertSee('novalidate', false);
    }

    public function test_PW再設定_エラーなしでは何も出ない(): void
    {
        $view = $this->renderPasswordResetReset([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_PW再設定_入力エラーでは上部案内と欄下文言が出る(): void
    {
        $view = $this->renderPasswordResetReset(['new_password' => 'ERR_new_password']);
        $view->assertSee('入力内容に誤りがあります。赤字の項目を確認してください。');
        $view->assertSee('ERR_new_password');
        $view->assertSee('is-invalid', false);
    }
}

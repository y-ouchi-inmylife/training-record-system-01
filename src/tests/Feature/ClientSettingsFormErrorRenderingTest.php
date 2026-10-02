<?php

namespace Tests\Feature;

use App\Models\Client;
use Tests\TestCase;

/**
 * 段階 4-3 の描画確認用テスト。会員の設定の変更 3 画面（S-1409 メールアドレス変更・
 * S-1410 パスワード変更・S-1411 登録情報変更）のビューを ViewErrorBag で描画し、
 * §2-7 の規約に合うかを確かめる。
 *
 * 3 画面とも、コントローラーで `withErrors` を返す箇所は FormRequest の自動バリデーションのみで、
 * 現状「入力エラー以外の失敗」を `form` キーで返す運用はない。将来追加された場合に備えて、
 * `form` キーのみを渡したときに上部案内・欄下文言・欄の赤枠が出ないことも確かめる。
 * DB は使わない（Client モデルは非永続化で属性だけ渡す）。
 */
class ClientSettingsFormErrorRenderingTest extends TestCase
{
    private function dummyClient(): Client
    {
        return new Client([
            'email' => 'owner@example.com',
            'last_name' => '山田',
            'first_name' => '太郎',
            'last_name_kana' => 'やまだ',
            'first_name_kana' => 'たろう',
            'phone1' => '03-0000-0000',
            'phone2' => null,
            'postal_code' => null,
            'address1' => '東京都',
            'address2' => '千代田区',
            'address3' => '永田町1-1',
            'address4' => null,
        ]);
    }

    private function renderEmail(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('client.settings.email');
    }

    private function renderPassword(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('client.settings.password', [
            'client' => $this->dummyClient(),
        ]);
    }

    private function renderEdit(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('client.settings.edit', [
            'client' => $this->dummyClient(),
        ]);
    }

    // ---- S-1409 メールアドレスの変更 ----

    public function test_メアド変更_formにnovalidateが付く(): void
    {
        $view = $this->renderEmail([]);
        $view->assertSee('novalidate', false);
    }

    public function test_メアド変更_エラーなしでは何も出ない(): void
    {
        $view = $this->renderEmail([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_メアド変更_入力エラーでは上部案内と欄下文言が出る(): void
    {
        $view = $this->renderEmail([
            'new_email' => 'ERR_new_email',
            'current_password' => 'ERR_current_password',
        ]);
        $view->assertSee('入力内容に誤りがあります。');
        $view->assertSee('ERR_new_email');
        $view->assertSee('ERR_current_password');
        $view->assertSee('is-invalid', false);
    }

    public function test_メアド変更_formキーのみでは上部案内も欄下も欄赤枠も出ない(): void
    {
        $view = $this->renderEmail([
            'form' => '送信に失敗しました。',
        ]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_メアド変更_確認メールの送信失敗はフォームの上に出て欄下にも上部案内にも出ない(): void
    {
        $view = $this->renderEmail([
            'form' => '確認メールを送信できませんでした。時間を置いて再度お試しください。',
        ]);
        $view->assertSee('確認メールを送信できませんでした。時間を置いて再度お試しください。');
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    // ---- S-1410 パスワードの変更 ----

    public function test_PW変更_formにnovalidateが付く(): void
    {
        $view = $this->renderPassword([]);
        $view->assertSee('novalidate', false);
    }

    public function test_PW変更_エラーなしでは何も出ない(): void
    {
        $view = $this->renderPassword([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_PW変更_入力エラーでは上部案内と欄下文言が出る(): void
    {
        $view = $this->renderPassword([
            'current_password' => 'ERR_current_password',
            'new_password' => 'ERR_new_password',
        ]);
        $view->assertSee('入力内容に誤りがあります。');
        $view->assertSee('ERR_current_password');
        $view->assertSee('ERR_new_password');
        $view->assertSee('is-invalid', false);
    }

    public function test_PW変更_formキーのみでは上部案内も欄下も欄赤枠も出ない(): void
    {
        $view = $this->renderPassword([
            'form' => '送信に失敗しました。',
        ]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_PW変更_通知メールの送信失敗はフォームの上に出て欄下にも上部案内にも出ない(): void
    {
        $view = $this->renderPassword([
            'form' => 'パスワードを変更できませんでした。時間を置いて再度お試しください。',
        ]);
        $view->assertSee('パスワードを変更できませんでした。時間を置いて再度お試しください。');
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    // ---- S-1411 登録情報の変更 ----

    public function test_登録情報変更_formにnovalidateが付く(): void
    {
        $view = $this->renderEdit([]);
        $view->assertSee('novalidate', false);
    }

    public function test_登録情報変更_エラーなしでは何も出ない(): void
    {
        $view = $this->renderEdit([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_登録情報変更_全項目の入力エラーで上部案内と欄下文言が出る(): void
    {
        $view = $this->renderEdit([
            'phone1' => 'ERR_phone1',
            'phone2' => 'ERR_phone2',
            'postal_code' => 'ERR_postal_code',
            'address1' => 'ERR_address1',
            'address2' => 'ERR_address2',
            'address3' => 'ERR_address3',
            'address4' => 'ERR_address4',
        ]);

        $view->assertSee('入力内容に誤りがあります。');
        $view->assertSee('ERR_phone1');
        $view->assertSee('ERR_phone2');
        $view->assertSee('ERR_postal_code');
        $view->assertSee('ERR_address1');
        $view->assertSee('ERR_address2');
        $view->assertSee('ERR_address3');
        $view->assertSee('ERR_address4');
        $view->assertSee('is-invalid', false);
        $view->assertSee('invalid-feedback', false);
    }

    public function test_登録情報変更_formキーのみでは上部案内も欄下も欄赤枠も出ない(): void
    {
        $view = $this->renderEdit([
            'form' => '送信に失敗しました。',
        ]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_登録情報変更_住所検索の結果の表示要素と郵便番号の欄下エラーが共存する(): void
    {
        $view = $this->renderEdit(['postal_code' => 'ERR_postal_code']);

        $view->assertSee('id="address-search-message"', false);
        $view->assertSee('ERR_postal_code');
        $view->assertSee('has-validation', false);
    }
}

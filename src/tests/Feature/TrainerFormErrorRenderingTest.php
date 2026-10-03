<?php

namespace Tests\Feature;

use App\Models\Trainer;
use Tests\TestCase;

/**
 * 段階 3-4 の描画確認用テスト。トレーナー登録（S-0801）・編集（S-0803）・
 * パスワードリセット（S-0804）のビューを ViewErrorBag で描画し、§2-7 の
 * 規約に合うかを確かめる（これまでの描画テストと同じ作り方。DB は使わない）。
 */
class TrainerFormErrorRenderingTest extends TestCase
{
    private function renderCreate(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('trainers.create');
    }

    private function makeTrainer(): Trainer
    {
        $t = new Trainer();
        $t->id = 1;
        $t->login_id = 'sato';
        $t->name = '佐藤一郎';
        $t->role = 'staff';
        return $t;
    }

    private function renderEdit(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('trainers.edit', [
            'trainer' => $this->makeTrainer(),
        ]);
    }

    private function renderResetPassword(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('trainers.reset-password', [
            'trainer' => $this->makeTrainer(),
        ]);
    }

    // ---- 登録（S-0801）----

    public function test_登録_formにnovalidateが付く(): void
    {
        $view = $this->renderCreate([]);
        $view->assertSee('id="trainer-create-form"', false);
        $view->assertSee('novalidate', false);
    }

    public function test_登録_エラーなしでは上部案内も欄下文言も出ない(): void
    {
        $view = $this->renderCreate([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('invalid-feedback', false);
        $view->assertDontSee('is-invalid', false);
    }

    public function test_登録_全項目にエラーで上部案内と各欄のエラーが出る(): void
    {
        $errors = [
            'login_id' => 'ERR_login_id',
            'name' => 'ERR_name',
            'password' => 'ERR_password',
            'role' => 'ERR_role',
        ];
        $view = $this->renderCreate($errors);

        $view->assertSee('入力内容に誤りがあります。');
        // 旧い一覧（<li>${message}</li>）が出ていないこと（各欄下に 1 件ずつだけ出す）
        foreach ($errors as $message) {
            $view->assertSee($message);
            $view->assertDontSee('<li>' . $message, false);
        }
        $view->assertSee('is-invalid', false);
    }

    // ---- 編集（S-0803）----

    public function test_編集_formにnovalidateが付く(): void
    {
        $view = $this->renderEdit([]);
        $view->assertSee('id="trainer-edit-form"', false);
        $view->assertSee('novalidate', false);
    }

    public function test_編集_エラーなしでは上部案内も欄下文言も出ない(): void
    {
        $view = $this->renderEdit([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('invalid-feedback', false);
        $view->assertDontSee('is-invalid', false);
    }

    public function test_編集_全項目にエラーで上部案内と各欄のエラーが出る(): void
    {
        $errors = [
            'login_id' => 'ERR_login_id',
            'name' => 'ERR_name',
            'role' => 'ERR_role',
        ];
        $view = $this->renderEdit($errors);

        $view->assertSee('入力内容に誤りがあります。');
        foreach ($errors as $message) {
            $view->assertSee($message);
        }
        $view->assertSee('is-invalid', false);
    }

    // ---- 登録・編集の横並び（§4-5。2026-10）----

    public function test_登録_ラベルが横並びの固定幅になる(): void
    {
        $html = (string) $this->renderCreate([]);
        foreach (['login_id', 'name', 'password', 'password_confirmation', 'role'] as $field) {
            $this->assertMatchesRegularExpression(
                '/<label for="' . $field . '" class="col-md-auto col-form-label text-md-end form-label-fixed">/',
                $html,
                $field
            );
        }
        // 縦積みの form-label は残っていない
        $this->assertStringNotContainsString('class="form-label"', $html);
    }

    public function test_登録_注意書きは入力欄の下でエラーの赤字より後に出る(): void
    {
        $html = (string) $this->renderCreate(['login_id' => 'ERR_login_id', 'password' => 'ERR_password']);

        $this->assertLessThan(strpos($html, '※半角英数字とアンダースコア(_)のみ'), strpos($html, 'id="login_id"'));
        $this->assertLessThan(strpos($html, '※半角英数字とアンダースコア(_)のみ'), strpos($html, 'ERR_login_id'));
        $this->assertLessThan(strpos($html, '※初回ログイン時に変更が求められます。'), strpos($html, 'id="password"'));
        $this->assertLessThan(strpos($html, '※初回ログイン時に変更が求められます。'), strpos($html, 'ERR_password'));
        // パスワード要件は、パスワード（確認）の入力欄の下
        $this->assertLessThan(strpos($html, 'パスワード要件：'), strpos($html, 'id="password_confirmation"'));
        // 注意書きはラベルの中に置かない
        $this->assertDoesNotMatchRegularExpression('/<label[^>]*>[^<]*(<span class="text-danger">\*<\/span>)?\s*<span class="form-text">/', $html);
    }

    public function test_編集_ラベルが横並びの固定幅で_注意書きは入力欄の下に出る(): void
    {
        $html = (string) $this->renderEdit(['login_id' => 'ERR_login_id']);
        foreach (['login_id', 'name', 'role'] as $field) {
            $this->assertMatchesRegularExpression(
                '/<label for="' . $field . '" class="col-md-auto col-form-label text-md-end form-label-fixed">/',
                $html,
                $field
            );
        }
        $this->assertStringNotContainsString('class="form-label"', $html);
        $this->assertLessThan(strpos($html, '※半角英数字とアンダースコア(_)のみ'), strpos($html, 'ERR_login_id'));
    }

    // ---- パスワードリセット（S-0804）----

    public function test_リセット_formにnovalidateが付く(): void
    {
        $view = $this->renderResetPassword([]);
        $view->assertSee('id="trainer-reset-password-form"', false);
        $view->assertSee('novalidate', false);
    }

    public function test_リセット_エラーなしでは上部案内も欄下文言も出ない(): void
    {
        $view = $this->renderResetPassword([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('invalid-feedback', false);
        $view->assertDontSee('is-invalid', false);
    }

    public function test_リセット_入力欄のnameが新しいパスワードに揃っている(): void
    {
        $view = $this->renderResetPassword([]);
        // S-0804：name/id/for が password → new_password / new_password_confirmation
        $view->assertSee('name="new_password"', false);
        $view->assertSee('id="new_password"', false);
        $view->assertSee('for="new_password"', false);
        $view->assertSee('name="new_password_confirmation"', false);
        $view->assertSee('id="new_password_confirmation"', false);
        $view->assertSee('for="new_password_confirmation"', false);
        // 旧い名前が残っていない
        $view->assertDontSee('name="password"', false);
        $view->assertDontSee('name="password_confirmation"', false);
    }

    public function test_リセット_新しいパスワードにエラーで上部案内と欄下文言が出る(): void
    {
        $errors = ['new_password' => 'ERR_new_password'];
        $view = $this->renderResetPassword($errors);

        $view->assertSee('入力内容に誤りがあります。');
        $view->assertSee('ERR_new_password');
        $view->assertSee('is-invalid', false);
    }
}

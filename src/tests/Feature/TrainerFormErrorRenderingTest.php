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

    // ---- ブラウザの自動入力を止める指定（§4-5。2026-10）----

    /**
     * id の入力欄（input / select）の開始タグを取り出す（Blade の -> を含む値でも途中で切れないようにする）
     */
    private function tagOf(string $html, string $id): string
    {
        $this->assertMatchesRegularExpression('/<(input|select)\b(?:->|[^>])*\bid="' . $id . '"(?:->|[^>])*>/s', $html, $id);
        preg_match('/<(input|select)\b(?:->|[^>])*\bid="' . $id . '"(?:->|[^>])*>/s', $html, $m);

        return $m[0];
    }

    public function test_登録_ログインID名前権限はoff_パスワードはnew_passwordになる(): void
    {
        $html = (string) $this->renderCreate([]);
        foreach (['login_id', 'name', 'role'] as $id) {
            $this->assertStringContainsString('autocomplete="off"', $this->tagOf($html, $id), $id);
        }
        foreach (['password', 'password_confirmation'] as $id) {
            $this->assertStringContainsString('autocomplete="new-password"', $this->tagOf($html, $id), $id);
        }
    }

    public function test_編集_ログインID名前権限はoffになる(): void
    {
        $html = (string) $this->renderEdit([]);
        foreach (['login_id', 'name', 'role'] as $id) {
            $this->assertStringContainsString('autocomplete="off"', $this->tagOf($html, $id), $id);
        }
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

    public function test_リセット_新しいパスワードはnew_password_表示の欄はoffになる(): void
    {
        $html = (string) $this->renderResetPassword([]);
        foreach (['new_password', 'new_password_confirmation'] as $id) {
            $this->assertStringContainsString('autocomplete="new-password"', $this->tagOf($html, $id), $id);
        }
        foreach (['login_id', 'trainer_name'] as $id) {
            $this->assertStringContainsString('autocomplete="off"', $this->tagOf($html, $id), $id);
        }
        // 別の人のパスワードを決める画面なので、管理者自身の username の隠し項目は置かない
        $this->assertStringNotContainsString('autocomplete="username"', $html);
    }

    public function test_リセット_新しいパスワードにエラーで上部案内と欄下文言が出る(): void
    {
        $errors = ['new_password' => 'ERR_new_password'];
        $view = $this->renderResetPassword($errors);

        $view->assertSee('入力内容に誤りがあります。');
        $view->assertSee('ERR_new_password');
        $view->assertSee('is-invalid', false);
    }

    // ---- 横並びのレイアウト（§4-5。2026-10）----

    private const HORIZONTAL_LABEL = 'class="col-md-auto col-form-label text-md-end form-label-fixed"';

    /**
     * 各ラベルが横並びの固定幅になり、縦積みの form-label が残っていないことを確かめる
     */
    private function assertHorizontalLabels(string $html, array $fields): void
    {
        foreach ($fields as $field) {
            $this->assertStringContainsString('<label for="' . $field . '" ' . self::HORIZONTAL_LABEL . '>', $html, $field);
        }
        $this->assertStringNotContainsString('class="form-label"', $html);
    }

    /**
     * 確認の欄のラベルは、見える文言が「（確認）」で、見えない「新しいパスワード」を含む。
     * パスワード要件の説明は、確認の入力欄より後にある
     */
    private function assertConfirmationLabelAndRequirements(string $html): void
    {
        $this->assertStringContainsString(
            '<label for="new_password_confirmation" ' . self::HORIZONTAL_LABEL . '><span class="visually-hidden">新しいパスワード</span>（確認） <span class="text-danger">*</span></label>',
            $html
        );
        $this->assertLessThan(strpos($html, 'パスワード要件：'), strpos($html, 'id="new_password_confirmation"'));
    }

    public function test_リセット_横並びで_確認のラベルとパスワード要件の位置がそろう(): void
    {
        $html = (string) $this->renderResetPassword([]);
        $this->assertHorizontalLabels($html, ['login_id', 'trainer_name', 'new_password', 'new_password_confirmation']);
        $this->assertConfirmationLabelAndRequirements($html);
    }
}

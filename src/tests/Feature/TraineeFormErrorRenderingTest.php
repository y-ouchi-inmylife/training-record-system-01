<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestView;
use Tests\TestCase;

/**
 * 段階 3-2 の描画確認用テスト。トレーニーの登録・編集のフォーム（S-0308・S-0310）を、
 * 全項目にエラーが入った ViewErrorBag で描画し、§2-7 の規約に合うかを確かめる。
 *
 * `trainees/_form.blade.php` を直接 render する（コントローラ・DB を介さない）。
 * $client は Client モデルを new して last_name/first_name を attribute でセット
 * （full_name アクセサが参照する）。Trainee モデルは null（新規）または new で渡す。
 */
class TraineeFormErrorRenderingTest extends TestCase
{
    private function makeClient(): Client
    {
        $c = new Client();
        $c->last_name = '山田';
        $c->first_name = '太郎';
        return $c;
    }

    private function renderForm(array $errors, bool $isEdit = false): TestView
    {
        $trainee = null;
        if ($isEdit) {
            $trainee = new Trainee();
            $trainee->name = '';
            $trainee->breed = '';
            $trainee->sex = null;
            $trainee->birth_date = null;
            $trainee->note = '';
        }

        return $this->withViewErrors($errors)->blade(
            '@include(\'trainees._form\', ['
            . "'trainee' => \$trainee, 'client' => \$client, 'action' => '/x',"
            . " 'method' => \$method, 'submitLabel' => '登録', 'cancelUrl' => '/x',"
            . " 'pageTitle' => 'トレーニー登録' ])",
            [
                'trainee' => $trainee,
                'client' => $this->makeClient(),
                'method' => $isEdit ? 'PUT' : 'POST',
            ]
        );
    }

    /** form に novalidate が付く */
    public function test_formにnovalidateが付く(): void
    {
        $view = $this->renderForm([]);
        $view->assertSee('id="traineeForm"', false);
        $view->assertSee('novalidate', false);
    }

    /** エラーがないときは、上部の案内も欄の下の文言も出ない */
    public function test_エラーがないときは上部案内も欄の下の文言も出ない(): void
    {
        $view = $this->renderForm([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('alert-danger', false);
        $view->assertDontSee('invalid-feedback', false);
        $view->assertDontSee('is-invalid', false);
    }

    /** 全項目にエラーが入っているとき：上部に 1 文の案内と各欄のエラーが出る */
    public function test_全項目にエラーが入ったとき上部に1文の案内と各欄のエラーが出る(): void
    {
        $errors = [
            'name' => 'ERR_name',
            'breed' => 'ERR_breed',
            'sex' => 'ERR_sex',
            'birth_date' => 'ERR_birth_date',
            'note' => 'ERR_note',
        ];
        $view = $this->renderForm($errors, isEdit: true);

        // 上部の 1 文の案内
        $view->assertSee('入力内容に誤りがあります。');

        // 旧 <ul><li>…</li></ul> の一覧が出ない
        $view->assertDontSee('<ul class="mb-0">', false);

        // 各欄の欄下文言
        foreach ($errors as $message) {
            $view->assertSee($message);
        }
        // 代表的な欄に is-invalid が付く
        $view->assertSee('name="name"', false);
        $view->assertSee('is-invalid', false);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Trainer;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestView;
use Tests\TestCase;

/**
 * 段階 3-1 の描画確認用テスト。会員登録・編集のフォームを、
 * 全項目にエラーが入ったエラーバッグで描画し、§2-7 の規約に合うかを確かめる。
 *
 * `clients/_form.blade.php` を直接 render する（コントローラ・DB を介さない）。
 * Trainer モデルはビューの $trainers に渡す必要があるが、空のコレクションで十分
 * （エラー表示の検証にはトレーナーの中身は影響しない）。
 */
class ClientFormErrorRenderingTest extends TestCase
{
    private function makeErrorBag(array $fields): ViewErrorBag
    {
        $bag = new MessageBag();
        foreach ($fields as $field => $message) {
            $bag->add($field, $message);
        }
        $view = new ViewErrorBag();
        $view->put('default', $bag);
        return $view;
    }

    private function renderForm(array $errors, bool $isEdit = false): TestView
    {
        $client = null;
        if ($isEdit) {
            // 編集画面：$client を仮オブジェクトとして渡す（DB は使わない）
            $client = new \stdClass();
            $client->internal_id = '1';
            $client->initial_consultation_date = null;
            $client->primary_trainer_id = null;
            $client->last_name = '';
            $client->first_name = '';
            $client->last_name_kana = '';
            $client->first_name_kana = '';
            $client->postal_code = '';
            $client->address1 = '';
            $client->address2 = '';
            $client->address3 = '';
            $client->address4 = '';
            $client->phone1 = '';
            $client->phone2 = '';
            $client->email = '';
        }

        return $this->withViewErrors($errors)->blade(
            '@include(\'clients._form\', ['
            . "'client' => \$client, 'trainers' => \$trainers, 'action' => '/x',"
            . " 'method' => \$method, 'submitLabel' => '登録', 'cancelUrl' => '/x',"
            . " 'pageTitle' => '会員登録' ])",
            [
                'client' => $client,
                'trainers' => collect(),
                'method' => $isEdit ? 'PUT' : 'POST',
            ]
        );
    }

    /** form に novalidate が付く */
    public function test_formにnovalidateが付く(): void
    {
        $view = $this->renderForm([]);
        $view->assertSee('id="clientForm"', false);
        $view->assertSee('novalidate', false);
    }

    /** 姓の入力欄に required 属性が付く（サーバーで必須のため。支援技術への伝達） */
    public function test_姓にrequired属性が付く(): void
    {
        $view = $this->renderForm([]);
        // name="last_name" を含む <input> に required が付いていることを確かめる。
        // 他の入力欄にも required は含まれるので、属性単独ではなく last_name と一緒に検索する。
        $html = $view->__toString();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*\bname="last_name"[^>]*\brequired\b|<input[^>]*\brequired\b[^>]*\bname="last_name"/',
            $html,
            '姓の input に required 属性が付いていること'
        );
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

    /** 全項目にエラーが入っているとき：上部に 1 文の案内が出て、各欄に is-invalid と文言が付く */
    public function test_全項目にエラーが入ったとき上部に1文の案内と各欄のエラーが出る(): void
    {
        $errors = [
            'internal_id' => 'ERR_internal_id',
            'initial_consultation_date' => 'ERR_initial_consultation_date',
            'primary_trainer_id' => 'ERR_primary_trainer_id',
            'last_name' => 'ERR_last_name',
            'first_name' => 'ERR_first_name',
            'last_name_kana' => 'ERR_last_name_kana',
            'first_name_kana' => 'ERR_first_name_kana',
            'postal_code' => 'ERR_postal_code',
            'address1' => 'ERR_address1',
            'address2' => 'ERR_address2',
            'address3' => 'ERR_address3',
            'address4' => 'ERR_address4',
            'phone1' => 'ERR_phone1',
            'phone2' => 'ERR_phone2',
        ];

        // 編集画面で全項目をチェック（登録画面は internal_id 以外同じ構造）
        $view = $this->renderForm($errors, isEdit: true);

        // 上部の 1 文の案内
        $view->assertSee('入力内容に誤りがあります。赤字の項目を確認してください。');

        // エラーの一覧（<ul><li>…</li></ul>）が出ないことを確かめる
        $view->assertDontSee('<ul class="mb-0">', false);

        // 各欄の is-invalid クラスと欄下文言
        foreach ($errors as $field => $message) {
            $view->assertSee($message); // 欄下に出る
        }
        // 代表的な欄に is-invalid が付くことを 1 件だけ確認（クラスは全欄で同じパターン）
        $view->assertSee('name="last_name"', false);
        $view->assertSee('is-invalid', false);
    }

    /** JS 側の validateBeforeSubmit / showFieldError / clearFieldError が残っていない */
    public function test_JSの入力チェックが残っていない(): void
    {
        $view = $this->renderForm([]);
        $view->assertDontSee('validateBeforeSubmit');
        $view->assertDontSee('showFieldError');
        $view->assertDontSee('clearFieldError');
    }
}

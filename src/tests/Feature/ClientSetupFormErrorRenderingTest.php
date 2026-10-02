<?php

namespace Tests\Feature;

use App\Models\Client;
use Tests\TestCase;

/**
 * 段階 4-2 の描画確認用テスト。会員初回設定（S-1403）のビューを ViewErrorBag で
 * 描画し、§2-7 の規約に合うかを確かめる。
 *
 * 登録完了メールの送信失敗など「入力エラーではない失敗」を専用のキー `form` で
 * 返したときの挙動も確かめる。DB は使わない（Client モデルは非永続化で属性だけ渡す）。
 */
class ClientSetupFormErrorRenderingTest extends TestCase
{
    private function renderSetup(array $errors): \Illuminate\Testing\TestView
    {
        // DB に書き込まないため Client は newInstance で属性だけ渡す。
        $client = new Client([
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

        return $this->withViewErrors($errors)->view('client.setup.index', [
            'token' => 'dummy-token',
            'client' => $client,
            'existingTrainee' => null,
        ]);
    }

    public function test_初回設定_formにnovalidateが付く(): void
    {
        $view = $this->renderSetup([]);
        $view->assertSee('novalidate', false);
    }

    public function test_初回設定_エラーなしでは何も出ない(): void
    {
        $view = $this->renderSetup([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_初回設定_全項目の入力エラーで上部案内と欄下文言が出る(): void
    {
        // 全項目にエラーを入れる（本体＋愛犬の情報）。
        $view = $this->renderSetup([
            'password' => 'ERR_password',
            'last_name' => 'ERR_last_name',
            'first_name' => 'ERR_first_name',
            'last_name_kana' => 'ERR_last_name_kana',
            'first_name_kana' => 'ERR_first_name_kana',
            'phone1' => 'ERR_phone1',
            'phone2' => 'ERR_phone2',
            'postal_code' => 'ERR_postal_code',
            'address1' => 'ERR_address1',
            'address2' => 'ERR_address2',
            'address3' => 'ERR_address3',
            'address4' => 'ERR_address4',
            'trainee_name' => 'ERR_trainee_name',
            'trainee_breed' => 'ERR_trainee_breed',
            'trainee_sex' => 'ERR_trainee_sex',
            'trainee_birth_date' => 'ERR_trainee_birth_date',
            'trainee_note' => 'ERR_trainee_note',
        ]);

        $view->assertSee('入力内容に誤りがあります。赤字の項目を確認してください。');

        // 全項目の文言が欄の下に出る。
        $view->assertSee('ERR_password');
        $view->assertSee('ERR_last_name');
        $view->assertSee('ERR_first_name');
        $view->assertSee('ERR_last_name_kana');
        $view->assertSee('ERR_first_name_kana');
        $view->assertSee('ERR_phone1');
        $view->assertSee('ERR_phone2');
        $view->assertSee('ERR_postal_code');
        $view->assertSee('ERR_address1');
        $view->assertSee('ERR_address2');
        $view->assertSee('ERR_address3');
        $view->assertSee('ERR_address4');
        $view->assertSee('ERR_trainee_name');
        $view->assertSee('ERR_trainee_breed');
        $view->assertSee('ERR_trainee_sex');
        $view->assertSee('ERR_trainee_birth_date');
        $view->assertSee('ERR_trainee_note');

        $view->assertSee('is-invalid', false);
        $view->assertSee('invalid-feedback', false);
    }

    public function test_初回設定_formキーはフォームの上に出て欄下にも上部案内にも出ない(): void
    {
        $view = $this->renderSetup([
            'form' => '初回設定を完了できませんでした。時間を置いて再度お試しください。改善しない場合は担当のトレーナーにご連絡ください。',
        ]);

        $view->assertSee('初回設定を完了できませんでした。時間を置いて再度お試しください。改善しない場合は担当のトレーナーにご連絡ください。');
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_初回設定_住所検索の結果の表示要素と郵便番号の欄下エラーが共存する(): void
    {
        $view = $this->renderSetup(['postal_code' => 'ERR_postal_code']);

        // 住所検索の結果表示（address-search.js が書き込む要素）が描画されている。
        $view->assertSee('id="address-search-message"', false);

        // 郵便番号の欄下エラーも出る（input-group の中、has-validation で表示）。
        $view->assertSee('ERR_postal_code');
        $view->assertSee('has-validation', false);
    }
}

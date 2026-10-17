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
        // 全項目にエラーを入れる（2026-10 に愛犬の情報を外したため、会員の項目だけ）。
        $view = $this->renderSetup([
            'password' => 'ERR_password',
            'last_name' => 'ERR_last_name',
            'first_name' => 'ERR_first_name',
            'last_name_kana' => 'ERR_last_name_kana',
            'first_name_kana' => 'ERR_first_name_kana',
            'phone1' => 'ERR_phone1',
            'phone2' => 'ERR_phone2',
            'emergency_contact_relationship' => 'ERR_emergency_contact_relationship',
            'postal_code' => 'ERR_postal_code',
            'address1' => 'ERR_address1',
            'address2' => 'ERR_address2',
            'address3' => 'ERR_address3',
            'address4' => 'ERR_address4',
        ]);

        $view->assertSee('入力内容に誤りがあります。');

        // 全項目の文言が欄の下に出る。
        $view->assertSee('ERR_password');
        $view->assertSee('ERR_last_name');
        $view->assertSee('ERR_first_name');
        $view->assertSee('ERR_last_name_kana');
        $view->assertSee('ERR_first_name_kana');
        $view->assertSee('ERR_phone1');
        $view->assertSee('ERR_phone2');
        $view->assertSee('ERR_emergency_contact_relationship');
        $view->assertSee('ERR_postal_code');
        $view->assertSee('ERR_address1');
        $view->assertSee('ERR_address2');
        $view->assertSee('ERR_address3');
        $view->assertSee('ERR_address4');

        $view->assertSee('is-invalid', false);
        $view->assertSee('invalid-feedback', false);
    }

    /**
     * 2026-10 変更：愛犬（トレーニー）の情報は初回設定では扱わない（受付アンケートに分離。
     * requirements.md 6-15-5）。小見出し・入力欄・多頭飼いの案内文が出ないこと。
     */
    public function test_初回設定_愛犬の情報の欄が出ない(): void
    {
        $view = $this->renderSetup([]);

        $view->assertDontSee('愛犬の情報');
        foreach (['trainee_name', 'trainee_breed', 'trainee_sex', 'trainee_birth_date', 'trainee_note'] as $field) {
            $view->assertDontSee('name="'.$field.'"', false);
        }
        $view->assertDontSee('ほかにもトレーニングを受ける愛犬がいる場合は');
        // 性別の選択肢（男の子／女の子／不明）も出ない
        $view->assertDontSee('男の子');
        $view->assertDontSee('女の子');
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

    /**
     * 2026-10 変更：ログイン情報（パスワード・パスワード確認）をまとまりの最後
     *（登録ボタンの直前）に移した。入力エラーで戻ったとき、一番上まで戻って
     * パスワードを入れ直す必要がなくなるようにするため。同じ 2026-10 に愛犬の情報を
     * 外したため、ログイン情報はご連絡先（建物名・部屋番号）の後に来る。
     */
    public function test_初回設定_ログイン情報はまとまりの最後に描画される(): void
    {
        $html = $this->renderSetup([])->__toString();

        // 「ご連絡先」の最後の欄（建物名・部屋番号）より後に、
        // 「ログイン情報」小見出しとパスワード欄が来ること。
        $posAddress4 = mb_strpos($html, 'name="address4"');
        $posLoginHeading = mb_strpos($html, 'ログイン情報');
        $posPassword = mb_strpos($html, 'id="password"');

        $this->assertNotFalse($posAddress4);
        $this->assertNotFalse($posLoginHeading);
        $this->assertNotFalse($posPassword);

        $this->assertGreaterThan($posAddress4, $posLoginHeading);
        $this->assertGreaterThan($posAddress4, $posPassword);

        // 「ログイン情報」まとまりの中にメールアドレスの表示行（text-muted small ラベル）
        // が入っていること。owner@example.com は hidden の username input（フォーム冒頭）
        // にも値として入るため、ここは表示行のラベル文字列で位置を見る。
        $posEmailLabel = mb_strpos($html, 'メールアドレス</div>');
        $this->assertNotFalse($posEmailLabel);
        $this->assertGreaterThan($posLoginHeading, $posEmailLabel);
        $this->assertGreaterThan($posEmailLabel, $posPassword);
    }

    /**
     * 小見出しは「お名前」「ご連絡先」「ログイン情報」の 3 か所（2026-10 に「愛犬の情報」を外した）。
     */
    public function test_初回設定_小見出しは3か所(): void
    {
        $html = $this->renderSetup([])->__toString();

        $this->assertSame(3, substr_count($html, '<h2 class="fs-6 fw-bold mb-2">'));
        $posName = mb_strpos($html, '<h2 class="fs-6 fw-bold mb-2">お名前</h2>');
        $posContact = mb_strpos($html, '<h2 class="fs-6 fw-bold mb-2">ご連絡先</h2>');
        $posLogin = mb_strpos($html, '<h2 class="fs-6 fw-bold mb-2">ログイン情報</h2>');
        $this->assertNotFalse($posName);
        $this->assertNotFalse($posContact);
        $this->assertNotFalse($posLogin);
        $this->assertGreaterThan($posName, $posContact);
        $this->assertGreaterThan($posContact, $posLogin);
    }

    /**
     * 2026-10 追加：パスワード以外の欄にエラーがあって戻ったとき、
     * パスワード欄の上に「確認のため、パスワードをもう一度入力してください。」の
     * 一言を控えめな .form-text で出す。
     */
    public function test_初回設定_パスワード以外のエラーで戻るとパスワード再入力の案内が出る(): void
    {
        $view = $this->renderSetup(['phone1' => 'ERR_phone1']);
        $view->assertSee('確認のため、パスワードをもう一度入力してください。');
    }

    public function test_初回設定_エラーなしではパスワード再入力の案内は出ない(): void
    {
        $view = $this->renderSetup([]);
        $view->assertDontSee('確認のため、パスワードをもう一度入力してください。');
    }

    /**
     * パスワード自体のエラーのみのときは、欄の下の赤字で原因が伝わるため、
     * 一言の案内は出さない。
     */
    public function test_初回設定_パスワードだけのエラーのときは案内を出さない(): void
    {
        $view = $this->renderSetup(['password' => 'ERR_password']);
        // パスワードの欄下エラーは出る
        $view->assertSee('ERR_password');
        // 一言の案内は出ない（.form-text で区別）
        $view->assertDontSee('確認のため、パスワードをもう一度入力してください。');
    }

    /**
     * 2026-10 変更：まとまりの区切り線は 1 本（お客様のプロフィール／ログイン情報の境目）だけ。
     * 愛犬の情報を外したため、線は愛犬の備考の下から、建物名・部屋番号の下に移った。
     */
    public function test_初回設定_区切り線は建物名部屋番号の下の1本だけ(): void
    {
        $html = $this->renderSetup([])->__toString();
        $this->assertSame(1, substr_count($html, 'border-bottom'));

        $posLine = mb_strpos($html, 'border-bottom');
        $posAddress4 = mb_strpos($html, 'name="address4"');
        $posAddress3 = mb_strpos($html, 'name="address3"');
        $posLoginHeading = mb_strpos($html, 'ログイン情報');

        // 線は建物名・部屋番号の欄を包む div に付く（町名・番地より後、建物名・部屋番号の直前、ログイン情報より前）
        $this->assertGreaterThan($posAddress3, $posLine);
        $this->assertLessThan($posAddress4, $posLine);
        $this->assertLessThan($posLoginHeading, $posLine);
    }

    public function test_初回設定_緊急連絡先の見出しの下に電話番号と続柄を横に並べる(): void
    {
        $html = (string) $this->renderSetup([]);

        $this->assertSame(1, preg_match('/<fieldset class="mb-3">\s*<legend class="form-label fs-6 mb-1">緊急連絡先（ご家族等）<\/legend>(.*?)<\/fieldset>/us', $html, $m));
        $this->assertMatchesRegularExpression('/<div class="row g-2">\s*<div class="col-6 col-sm-7">\s*<label for="phone2" class="form-label small mb-1">電話番号<\/label>/u', $m[1]);
        $this->assertMatchesRegularExpression('/<div class="col-6 col-sm-5">\s*<label for="emergency_contact_relationship" class="form-label small mb-1">続柄<\/label>/u', $m[1]);
        $this->assertStringContainsString('id="emergency_contact_relationship" name="emergency_contact_relationship" maxlength="20"', $m[1]);
        $this->assertStringContainsString('placeholder="例: 母、夫、娘"', $m[1]);
        // 電話番号（必須）の欄は見出しの外、郵便番号は見出しのあと
        $this->assertTrue(strpos($html, 'id="phone1"') < strpos($html, '<fieldset class="mb-3">'));
        $this->assertTrue(strpos($html, '</fieldset>') < strpos($html, 'id="postal_code"'));
        $this->assertStringNotContainsString('電話番号（予備）', $html);
    }

    public function test_初回設定_続柄だけのエラーでも上部案内とパスワード再入力の案内が出る(): void
    {
        $view = $this->renderSetup(['emergency_contact_relationship' => 'ERR_rel']);

        $view->assertSee('入力内容に誤りがあります。');
        $view->assertSee('確認のため、パスワードをもう一度入力してください。');
    }
}

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

        $view->assertSee('入力内容に誤りがあります。');

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

    /**
     * 2026-10 変更：ログイン情報（パスワード・パスワード確認）を 4 まとまりの最後
     *（愛犬の情報の後、登録ボタンの直前）に移した。入力エラーで戻ったとき、
     * 一番上まで戻ってパスワードを入れ直す必要がなくなるようにするため。
     */
    public function test_初回設定_ログイン情報はまとまりの最後に描画される(): void
    {
        $html = $this->renderSetup([])->__toString();

        // 「愛犬の情報」小見出し／備考（trainee_note）の位置より後に、
        // 「ログイン情報」小見出しとパスワード欄が来ること。
        $posTraineeHeading = mb_strpos($html, '愛犬の情報');
        $posTraineeNote = mb_strpos($html, 'name="trainee_note"');
        $posLoginHeading = mb_strpos($html, 'ログイン情報');
        $posPassword = mb_strpos($html, 'id="password"');

        $this->assertNotFalse($posTraineeHeading);
        $this->assertNotFalse($posTraineeNote);
        $this->assertNotFalse($posLoginHeading);
        $this->assertNotFalse($posPassword);

        $this->assertGreaterThan($posTraineeHeading, $posLoginHeading);
        $this->assertGreaterThan($posTraineeNote, $posLoginHeading);
        $this->assertGreaterThan($posTraineeNote, $posPassword);

        // 「ログイン情報」まとまりの中にメールアドレスの表示行（text-muted small ラベル）
        // が入っていること。owner@example.com は hidden の username input（フォーム冒頭）
        // にも値として入るため、ここは表示行のラベル文字列で位置を見る。
        $posEmailLabel = mb_strpos($html, 'メールアドレス</div>');
        $this->assertNotFalse($posEmailLabel);
        $this->assertGreaterThan($posLoginHeading, $posEmailLabel);
        $this->assertGreaterThan($posEmailLabel, $posPassword);
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
     * 2026-10 変更：まとまりの区切り線は 1 本（愛犬の情報／ログイン情報の境目）だけに絞った。
     * 他 2 本（お名前／ご連絡先、ご連絡先／愛犬の情報）は、各まとまりの小見出しで内容の区切りが
     * 伝わるため削除。
     */
    public function test_初回設定_区切り線は1本だけ描画される(): void
    {
        $html = $this->renderSetup([])->__toString();
        $this->assertSame(1, substr_count($html, 'border-bottom'));
    }

    /**
     * 2026-10 変更：備考の注意書きを入力欄の下（テキストエリアと欄下エラーの後）に戻し、
     * 文言を「ほかにもトレーニングを受ける愛犬がいる場合は、名前・犬種などをこの欄に
     * ご記入ください。」にした。パスワード欄の「8 文字以上で…」の説明と同じ並び。
     * 欄下エラーは Bootstrap の .is-invalid ~ .invalid-feedback の「以後の兄弟」
     * セレクタで、注意書きが挟まっても入力欄のすぐ下に出る（並び順で手前にあるため）。
     */
    public function test_初回設定_備考の注意書きがテキストエリアより後に描画される(): void
    {
        $html = $this->renderSetup([])->__toString();

        $posTextarea = mb_strpos($html, 'id="trainee_note"');
        $posHelp = mb_strpos($html, 'ほかにもトレーニングを受ける愛犬がいる場合は、名前・犬種などをこの欄にご記入ください。');

        $this->assertNotFalse($posTextarea);
        $this->assertNotFalse($posHelp);
        $this->assertGreaterThan($posTextarea, $posHelp);
    }

    public function test_初回設定_備考の注意書きの旧文言が残っていない(): void
    {
        $view = $this->renderSetup([]);
        $view->assertDontSee('備考にご記入ください');
        $view->assertDontSee('こちらにご記入ください');
    }

    /**
     * 2026-10 追加：備考のテキストエリアと注意書きを aria-describedby で紐付け、
     * 読み上げでも案内が入力の説明として伝わるようにする。
     */
    public function test_初回設定_備考のaria_describedbyが注意書きのidを指す(): void
    {
        $view = $this->renderSetup([]);
        $view->assertSee('aria-describedby="trainee_note-help"', false);
        $view->assertSee('id="trainee_note-help"', false);
    }
}

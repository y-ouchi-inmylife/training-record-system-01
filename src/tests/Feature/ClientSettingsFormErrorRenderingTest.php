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
            'emergency_contact_relationship' => 'ERR_emergency_contact_relationship',
            'postal_code' => 'ERR_postal_code',
            'address1' => 'ERR_address1',
            'address2' => 'ERR_address2',
            'address3' => 'ERR_address3',
            'address4' => 'ERR_address4',
        ]);

        $view->assertSee('入力内容に誤りがあります。');
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

    // ---- 緊急連絡先（ご家族等）と続柄（2026-10-17 変更：その前は「電話番号（予備）」。2026-10-18 変更：ひとまとまりに見せる） ----

    public function test_登録情報変更_緊急連絡先の見出しの下に電話番号と続柄を横に並べる(): void
    {
        $client = $this->dummyClient();
        $client->phone2 = '090-1111-2222';
        $client->emergency_contact_relationship = '娘';
        $html = (string) $this->withViewErrors([])->view('client.settings.edit', ['client' => $client]);

        $this->assertEmergencyFieldset($html, '090-1111-2222', '娘');
    }

    public function test_登録情報の表示_緊急連絡先は電話番号と続柄を1つの値で出し続柄の項目は出さない(): void
    {
        foreach ([
            '両方' => ['090-1111-2222', '母', '090-1111-2222（母）'],
            '電話番号だけ' => ['090-1111-2222', null, '090-1111-2222'],
            '続柄だけ' => [null, '母', '（続柄：母）'],
            'どちらもない' => [null, null, ''],
        ] as $case => [$phone, $relationship, $expected]) {
            $client = $this->dummyClient();
            $client->phone2 = $phone;
            $client->emergency_contact_relationship = $relationship;
            $html = (string) $this->view('client.settings.show', ['client' => $client]);

            $this->assertMatchesRegularExpression(
                '/<div class="text-muted small mb-1">緊急連絡先（ご家族等）<\/div>\s*<div style="min-height: 1.5rem;">'.preg_quote($expected, '/').'<\/div>/u',
                $html,
                $case
            );
            $this->assertStringNotContainsString('>続柄</div>', $html, $case);
            $this->assertStringNotContainsString('電話番号（予備）', $html, $case);
        }
    }

    public function test_登録情報の表示_緊急連絡先が空でもラベルを出す(): void
    {
        $view = $this->view('client.settings.show', ['client' => $this->dummyClient()]);

        $view->assertSee('<div class="text-muted small mb-1">緊急連絡先（ご家族等）</div>', false);
    }

    /**
     * 会員側の入力（S-1403・S-1411）：「緊急連絡先（ご家族等）」の fieldset と legend の中に、「電話番号」「続柄」の欄を横に並べる
     * （576px 以上は 7：5、それより狭いときは 6：6）
     */
    private function assertEmergencyFieldset(string $html, string $phone, string $relationship): void
    {
        $this->assertSame(1, preg_match('/<fieldset class="mb-3">\s*<legend class="form-label fs-6 mb-1">緊急連絡先（ご家族等）<\/legend>(.*?)<\/fieldset>/us', $html, $m));
        $inner = $m[1];
        $this->assertMatchesRegularExpression('/<div class="row g-2">\s*<div class="col-6 col-sm-7">\s*<label for="phone2" class="form-label small mb-1">電話番号<\/label>/u', $inner);
        $this->assertMatchesRegularExpression('/<div class="col-6 col-sm-5">\s*<label for="emergency_contact_relationship" class="form-label small mb-1">続柄<\/label>/u', $inner);
        $this->assertStringContainsString('value="'.$phone.'"', $inner);
        $this->assertStringContainsString('value="'.$relationship.'"', $inner);
        $this->assertStringContainsString('placeholder="例: 母、夫、娘"', $inner);
        // 欄下のエラーの置き場所は、それぞれの欄の中
        $this->assertStringContainsString('id="phone2"', $inner);
        $this->assertStringContainsString('id="emergency_contact_relationship"', $inner);
        $this->assertStringNotContainsString('電話番号（予備）', $html);
    }

    public function test_登録情報変更_緊急連絡先と続柄のエラーはそれぞれの欄の下に出る(): void
    {
        $html = (string) $this->renderEdit(['phone2' => 'ERR_phone2', 'emergency_contact_relationship' => 'ERR_rel']);

        $this->assertMatchesRegularExpression('/id="phone2".*?ERR_phone2.*?<div class="col-6 col-sm-5">/us', $html);
        $this->assertMatchesRegularExpression('/id="emergency_contact_relationship".*?ERR_rel.*?<\/fieldset>/us', $html);
    }

    /**
     * 入力欄の並び：住所（郵便番号〜建物名・部屋番号）→ 電話番号 → 緊急連絡先（電話番号・続柄）（2026-10-18 変更：
     * 登録情報の表示〔S-1406〕の順にそろえた）。Tab キーの順を変える tabindex（0 以上）も付けない
     */
    private function assertContactOrder(string $html): void
    {
        $ids = ['postal_code', 'address1', 'address2', 'address3', 'address4', 'phone1', 'phone2', 'emergency_contact_relationship'];
        $positions = array_map(fn ($id) => strpos($html, 'id="'.$id.'"'), $ids);
        foreach ($positions as $i => $pos) {
            $this->assertNotFalse($pos, $ids[$i]);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
        $this->assertDoesNotMatchRegularExpression('/tabindex="(?!-1")/u', $html);
    }

    public function test_登録情報変更_住所から電話番号緊急連絡先の順に並べる(): void
    {
        $html = (string) $this->renderEdit([]);

        $this->assertContactOrder($html);
        // 最後の欄（緊急連絡先）のあとに［変更する］
        $this->assertTrue(strpos($html, 'id="emergency_contact_relationship"') < strpos($html, '>変更する</button>'));
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

/**
 * 愛犬アンケートからの送信がセッション切れ（419）になったときの画面（2026-10 追加。設計書 S-1413 備考
 * 「入力中にセッションが切れたとき」）。愛犬アンケートの画面からの送信のときだけお客様向けの文言にし、
 * ほかの画面の 419 は今のまま。
 *
 * テストでは Laravel が CSRF トークンの確認を飛ばす（ValidateCsrfToken::runningUnitTests()）ため、
 * 確認を飛ばさないように差し替えた ValidateCsrfToken をコンテナに登録して、トークンなしの POST で 419 を起こす。
 * 送信の中身（トークンやトレーニー）は CSRF の確認より先には見ないため、表は作らない。
 */
class ClientIntakeSurveySessionExpiredTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    public function test_愛犬アンケートの送信が419ならお客様向けの文言を出す(): void
    {
        $response = $this->post('/client-portal/intake-survey/abcdefghijklmnopqrstuvwxyz012345', ['breed' => '柴']);

        $response->assertStatus(419);
        $html = $response->getContent();
        $this->assertStringContainsString('<h1 class="mb-4">時間がたったため送信できませんでした</h1>', $html);
        $this->assertStringContainsString('お手数ですが、もう一度案内のページ（QR コードやリンク）を開いて、はじめからご入力ください。', $html);
        $this->assertStringContainsString('<div class="c-login c-login--wide">', $html);
        $this->assertStringNotContainsString('再度ログインしてください', $html);
        $this->assertStringNotContainsString('419 Page Expired', $html);
    }

    public function test_ほかの画面の419は今のまま(): void
    {
        $response = $this->post('/login', ['login_id' => 'x', 'password' => 'y']);

        $response->assertStatus(419);
        $response->assertSee('しばらく操作がなかったため、セッションの有効期限が切れました。再度ログインしてください。');
        $response->assertDontSee('時間がたったため送信できませんでした');
    }

    public function test_会員側のほかの画面の419も今のまま(): void
    {
        $response = $this->post('/client-portal/setup/abcdefghijklmnopqrstuvwxyz012345', []);

        $response->assertStatus(419);
        $response->assertSee('再度ログインしてください。');
        $response->assertDontSee('時間がたったため送信できませんでした');
    }
}

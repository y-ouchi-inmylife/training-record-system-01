<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 段階 3-6 の描画確認用テスト。要約プロンプト（S-0601）と IP アドレス制限
 * （S-1002）の 2 画面を ViewErrorBag で描画し、§2-7 の規約に合うかを確かめる。
 * DB は使わない（空の Collection と文字列を渡す）。
 */
class SettingsFormErrorRenderingTest extends TestCase
{
    private function renderSummaryPrompts(array $errors, string $prompt = ''): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('settings.summary-prompts', [
            'currentPrompt' => $prompt,
        ]);
    }

    private function renderIpRestriction(array $errors): \Illuminate\Testing\TestView
    {
        return $this->withViewErrors($errors)->view('settings.ip-restriction', [
            'settings' => collect(),
            'ipWhitelist' => collect(),
            'currentIp' => '192.168.0.1',
        ]);
    }

    // ---- 要約プロンプト（S-0601）----

    public function test_要約プロンプト_formにnovalidateが付く(): void
    {
        $view = $this->renderSummaryPrompts([]);
        $view->assertSee('id="summaryPromptForm"', false);
        $view->assertSee('novalidate', false);
    }

    public function test_要約プロンプト_テキストエリアにrequiredが付く(): void
    {
        $view = $this->renderSummaryPrompts([]);
        $html = $view->__toString();
        // name="current_prompt" を含む <textarea> に required 属性が付いていること
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*\bname="current_prompt"[^>]*\brequired\b|<textarea[^>]*\brequired\b[^>]*\bname="current_prompt"/',
            $html,
            'current_prompt の textarea に required 属性が付いていること'
        );
    }

    public function test_要約プロンプト_エラーなしでは何も出ない(): void
    {
        $view = $this->renderSummaryPrompts([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_要約プロンプト_エラーで上部案内と欄下文言が出る(): void
    {
        $view = $this->renderSummaryPrompts(['current_prompt' => 'ERR_current_prompt']);
        $view->assertSee('入力内容に誤りがあります。赤字の項目を確認してください。');
        $view->assertSee('ERR_current_prompt');
        $view->assertSee('is-invalid', false);
        $view->assertSee('invalid-feedback', false);
    }

    // ---- IP アドレス制限（S-1002）----

    public function test_IP制限_formにnovalidateが付く(): void
    {
        $view = $this->renderIpRestriction([]);
        $view->assertSee('novalidate', false);
    }

    public function test_IP制限_エラーなしでは何も出ない(): void
    {
        $view = $this->renderIpRestriction([]);
        $view->assertDontSee('入力内容に誤りがあります');
        // alert-danger は本画面の中で ip_restriction エラー用には使わなくなった。
        // 他の alert（例：form-text の警告案内）があるため、文言と invalid-feedback で判定する
        $view->assertDontSee('invalid-feedback', false);
        $view->assertDontSee('is-invalid', false);
    }

    public function test_IP制限_エラーで上部案内と行まとまりの下に文言が出る(): void
    {
        $msg = "行 1: 「abc」は無効なIPアドレス形式です。\n行 2: 「192.168.1.0」が重複しています。";
        $view = $this->renderIpRestriction(['ip_restriction' => $msg]);

        // 上部の 1 文の案内
        $view->assertSee('入力内容に誤りがあります。赤字の項目を確認してください。');

        // 行のまとまりの下に invalid-feedback d-block で表示される
        $view->assertSee('invalid-feedback d-block', false);
        $view->assertSee('行 1: 「abc」は無効なIPアドレス形式です。');
        $view->assertSee('行 2: 「192.168.1.0」が重複しています。');

        $html = $view->__toString();

        // 旧い alert-danger の中には ip_restriction の文言が入らない
        // （alert-danger 自体は上部の <x-form-error-summary /> で使っているため、
        // 「alert-danger の class を持つ要素内に ip_restriction の文言がない」で判定）
        $this->assertDoesNotMatchRegularExpression(
            '/<div[^>]*class="[^"]*\balert-danger\b[^"]*"[^>]*>[^<]*行 1:/u',
            $html,
            'ip_restriction のエラーは alert-danger ではなく invalid-feedback d-block で出す'
        );

        // 行の入力欄に is-invalid は付かない（まとめた 1 つの文言方式で、
        // 行ごとの赤枠は付けない方針）。本画面には他に is-invalid を使う欄がない
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*\bis-invalid\b/',
            $html,
            '行ごとの入力欄に is-invalid は付かない'
        );
    }
}

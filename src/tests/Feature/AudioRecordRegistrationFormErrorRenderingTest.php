<?php

namespace Tests\Feature;

use App\Models\Trainer;
use Tests\TestCase;

/**
 * 段階 5-2 の描画確認用テスト。音声記録の登録 3 画面（S-0501 録音準備・
 * S-0503 文字起こしテキスト・S-0504 音声ファイルのアップロード）のビューを
 * ViewErrorBag で描画し、§2-7 の規約に合うかを確かめる。
 *
 * S-0501／S-0504 は「小さなフォーム」の例外で上部の案内は出さず、欄下だけに
 * 出す。S-0503 は項目が 3 つあるので上部の案内も出す。
 *
 * 旧い要素（.client-id-error、#file-server-error の旧位置の @error ブロック）が
 * 残っていないことも確かめる。DB は使わない（Trainer は非永続化）。
 */
class AudioRecordRegistrationFormErrorRenderingTest extends TestCase
{
    private function dummyTrainer(): Trainer
    {
        $trainer = new Trainer([
            'name' => 'テスト太郎',
            'role' => 'practitioner',
        ]);
        $trainer->id = 1;
        $trainer->exists = true;
        return $trainer;
    }

    private function bindSessionToRequest(): void
    {
        $this->app['request']->setLaravelSession($this->app['session.store']);
    }

    // ---- S-0501 録音準備 ----

    private function renderRecordingIndex(array $errors): \Illuminate\Testing\TestView
    {
        $this->actingAs($this->dummyTrainer(), 'web');
        $this->bindSessionToRequest();
        return $this->withViewErrors($errors)->view('recording-v2.index');
    }

    public function test_録音準備_formにnovalidateが付く(): void
    {
        $view = $this->renderRecordingIndex([]);
        $view->assertSee('novalidate', false);
    }

    public function test_録音準備_エラーなしでは何も出ない(): void
    {
        $view = $this->renderRecordingIndex([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_録音準備_clientIdエラーは上部案内なしで欄下に出る(): void
    {
        $view = $this->renderRecordingIndex(['client_id' => 'ERR_client_id']);
        // 小さなフォームの例外：上部の案内は出さない
        $view->assertDontSee('入力内容に誤りがあります');
        // Select2 のため block で欄下に出る
        $view->assertSee('ERR_client_id');
        $view->assertSee('is-invalid', false);
        $view->assertSee('<div class="invalid-feedback d-block"', false);
    }

    public function test_録音準備_旧いclientIdError要素が残っていない(): void
    {
        // 旧い HTML 要素（`<div class="invalid-feedback client-id-error">`）が残っていない
        // ことを確かめる。コメント中の「.client-id-error」とは区別するため、属性値として
        // `client-id-error` を使うパターンだけを探す。
        $view = $this->renderRecordingIndex([]);
        $view->assertDontSee('class="invalid-feedback client-id-error"', false);
    }

    // ---- S-0503 文字起こしテキスト ----

    private function renderTextPaste(array $errors): \Illuminate\Testing\TestView
    {
        $this->actingAs($this->dummyTrainer(), 'web');
        $this->bindSessionToRequest();
        return $this->withViewErrors($errors)->view('audio.text-paste-create', [
            'defaultTitle' => '20260101_0000',
        ]);
    }

    public function test_文字起こし_formにnovalidateが付く(): void
    {
        $view = $this->renderTextPaste([]);
        $view->assertSee('novalidate', false);
    }

    public function test_文字起こし_エラーなしでは何も出ない(): void
    {
        $view = $this->renderTextPaste([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_文字起こし_全項目のエラーで上部案内と欄下文言が出る(): void
    {
        $view = $this->renderTextPaste([
            'client_id' => 'ERR_client_id',
            'title' => 'ERR_title',
            'transcription_text' => 'ERR_transcription_text',
        ]);
        $view->assertSee('入力内容に誤りがあります。赤字の項目を確認してください。');
        $view->assertSee('ERR_client_id');
        $view->assertSee('ERR_title');
        $view->assertSee('ERR_transcription_text');
        $view->assertSee('is-invalid', false);
        $view->assertSee('invalid-feedback', false);
    }

    // ---- S-0504 音声ファイルのアップロード ----

    private function renderUpload(array $errors): \Illuminate\Testing\TestView
    {
        $this->actingAs($this->dummyTrainer(), 'web');
        $this->bindSessionToRequest();
        return $this->withViewErrors($errors)->view('audio.upload-create');
    }

    public function test_音声UP_formにnovalidateが付く(): void
    {
        $view = $this->renderUpload([]);
        $view->assertSee('novalidate', false);
    }

    public function test_音声UP_エラーなしでは何も出ない(): void
    {
        $view = $this->renderUpload([]);
        $view->assertDontSee('入力内容に誤りがあります');
        // 入力欄に is-invalid が付いていないこと（JS 文字列中の 'is-invalid' とは区別するため、
        // class 属性に含まれるパターンだけを探す）。
        $view->assertDontSee('form-control is-invalid', false);
        $view->assertDontSee('form-select select2-client-upload is-invalid', false);
        // 欄下文言（<div class="invalid-feedback d-block">）が描画されていないこと。
        // JS のコメント中にも「invalid-feedback d-block」の文字列があるので、属性付きパターンで判定する。
        $view->assertDontSee('<div class="invalid-feedback d-block"', false);
    }

    public function test_音声UP_全項目のエラーは上部案内なしで欄下に出る(): void
    {
        $view = $this->renderUpload([
            'client_id' => 'ERR_client_id',
            'file' => 'ERR_file',
        ]);
        // 小さなフォームの例外：上部の案内は出さない
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertSee('ERR_client_id');
        $view->assertSee('ERR_file');
        $view->assertSee('is-invalid', false);
        $view->assertSee('<div class="invalid-feedback d-block"', false);
    }

    public function test_音声UP_file_server_error_idを保つ(): void
    {
        // JS 側（インライン script）で #file-server-error を document.getElementById で
        // 探して非表示にするため、エラーがあるときに ID が描画されていることを確かめる。
        $view = $this->renderUpload(['file' => 'ERR_file']);
        $view->assertSee('id="file-server-error"', false);
    }
}

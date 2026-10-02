<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainer;
use Tests\TestCase;

/**
 * 段階 5 の最後の確認用テスト。録音実行（S-0502）の session.blade.php を描画して、
 * 担当1・担当2 の入力エラーの表示を §2-7「非同期の保存（fetch）」に揃えた変更が
 * 入っていることを確かめる。
 *
 * JS の動き（alert → 欄の下）は Vitest 等のテストの仕組みがないため確認できないので、
 * 以下の静的な要素だけを見る：
 *   - <select name="trainer1_id">／<select name="trainer2_id">
 *   - @vite 経由で form-errors.js が読み込まれる
 *   - 文言の定数に、言語ファイルの文言（句点付き）が埋め込まれる
 *   - alert( の数が従来の 8 件から 6 件に減っている（担当 2 件を除いた分）
 *
 * DB は使わない（Client・Trainer は非永続化で属性だけ渡す）。
 */
class RecordingSessionViewRenderingTest extends TestCase
{
    private function dummyTrainer(): Trainer
    {
        $trainer = new Trainer([
            'name' => 'テスト太郎',
            'login_id' => 'test',
            'role' => 'practitioner',
        ]);
        $trainer->id = 1;
        $trainer->exists = true;
        return $trainer;
    }

    private function dummyClient(): Client
    {
        $client = new Client([
            'internal_id' => '0001',
            'last_name' => '山田',
            'first_name' => '太郎',
        ]);
        $client->id = 1;
        $client->exists = true;
        return $client;
    }

    private function renderSession(): \Illuminate\Testing\TestView
    {
        $this->actingAs($this->dummyTrainer(), 'web');
        return $this->view('recording-v2.session', [
            'client' => $this->dummyClient(),
        ]);
    }

    public function test_担当1と担当2の_select_に_name_属性が付く(): void
    {
        $view = $this->renderSession();
        $view->assertSee('name="trainer1_id"', false);
        $view->assertSee('name="trainer2_id"', false);
    }

    public function test_form_errors_js_が_vite_経由で読み込まれる(): void
    {
        $view = $this->renderSession();
        // @vite が描画するリンクに form-errors が含まれる（ビルドされた成果物のパスに
        // form-errors の名前が入る。ハッシュ付きの実ファイル名が manifest から引かれる）
        $view->assertSee('form-errors', false);
    }

    public function test_担当1と担当2の文言の定数に言語ファイルの文言が埋め込まれる(): void
    {
        $view = $this->renderSession();
        // 定数の宣言（JS 内）
        $view->assertSee('TRAINER1_REQUIRED_MESSAGE', false);
        $view->assertSee('TRAINER2_DIFFERENT_MESSAGE', false);
        // 言語ファイルの文言が @json 経由で埋め込まれていることを確かめる。
        // Blade の @json は JSON_UNESCAPED_UNICODE を付けないため、日本語は
        // Unicode エスケープ形式で入る。json_encode の既定と一致するか調べる。
        $html = $view->__toString();
        $expectedTrainer1 = json_encode(__('validation.custom.trainer1_id.required'));
        $expectedTrainer2 = json_encode(__('validation.custom.trainer2_id.different'));
        $this->assertStringContainsString(
            'TRAINER1_REQUIRED_MESSAGE = ' . $expectedTrainer1,
            $html
        );
        $this->assertStringContainsString(
            'TRAINER2_DIFFERENT_MESSAGE = ' . $expectedTrainer2,
            $html
        );
    }

    public function test_旧いalertの句点なし文言が残っていない(): void
    {
        $view = $this->renderSession();
        // 旧い「担当1を選択してください」（句点なし）の alert が残っていない
        // （画面側の直書きではなく、言語ファイル経由で句点付きになっているはず）
        $view->assertDontSee("alert('担当1を選択してください')", false);
        $view->assertDontSee("alert('担当2は担当1と異なるトレーナーを選択してください。')", false);
    }

    public function test_残りの_alert_の数が変わっていない(): void
    {
        // 変更前は 8 件（画面移動の警告、マイク拒否 ×2、アップロード失敗 ×2、担当1、担当2、
        // エラー）。段階 5 で担当1・担当2 の 2 つを欄の下に移したため、6 件に減る。
        $view = $this->renderSession();
        $html = $view->__toString();
        $this->assertSame(6, substr_count($html, 'alert('));
    }
}

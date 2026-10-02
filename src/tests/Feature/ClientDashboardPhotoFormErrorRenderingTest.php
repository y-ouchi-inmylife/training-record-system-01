<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * 段階 4-4 の描画確認用テスト。会員ダッシュボード S-1402 のトレーニー写真の登録
 * フォームを ViewErrorBag で描画し、§2-7 の規約に合うかを確かめる。
 *
 * 愛犬が複数いる場合に、どの愛犬のフォームでエラーかを old('_trainee_id') で絞り込む仕様
 * （計測値モーダル S-0309 と同じ流儀）が、片方のカードだけに案内・欄下・form 文言を
 * 出すことも確かめる。DB は使わない（Client モデルは非永続化で属性だけ渡す）。
 */
class ClientDashboardPhotoFormErrorRenderingTest extends TestCase
{
    private function dummyClient(): Client
    {
        $client = new Client([
            'email' => 'owner@example.com',
            'last_name' => '山田',
            'first_name' => '太郎',
            'last_name_kana' => 'やまだ',
            'first_name_kana' => 'たろう',
        ]);
        $client->id = 1;
        $client->exists = true;
        return $client;
    }

    private function dummyWeightCharts(array $ids = [1]): array
    {
        return array_map(fn ($id) => [
            'id' => $id,
            'name' => 'ポチ' . $id,
            'photoUrl' => null,
            'datasets' => [],
        ], $ids);
    }

    private function renderDashboard(array $errors, array $old = [], ?array $weightCharts = null): \Illuminate\Testing\TestView
    {
        $this->actingAs($this->dummyClient(), 'client');
        // old() ヘルパーは app('request')->old() 経由で Request の session を見る。
        // $this->view() は実リクエストを通さないため、Request にセッションを直接 bind する。
        $session = $this->app['session.store'];
        if ($old !== []) {
            $session->put('_old_input', $old);
        }
        $this->app['request']->setLaravelSession($session);
        return $this->withViewErrors($errors)->view('client.dashboard', [
            'sessions' => new Collection([]),
            'weightCharts' => $weightCharts ?? $this->dummyWeightCharts([1]),
        ]);
    }

    public function test_写真フォームにnovalidateが付く(): void
    {
        $view = $this->renderDashboard([]);
        $view->assertSee('novalidate', false);
    }

    public function test_エラーなしでは何も出ない(): void
    {
        $view = $this->renderDashboard([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_photo入力エラーで上部案内は出ず欄下文言だけが出る(): void
    {
        // 段階 5-1：本画面は小さなフォーム（photo 1 項目のみ）のため、§2-7「画面上部の
        // 短い案内」の例外として上部の案内（<x-form-error-summary />）は出さない。
        $view = $this->renderDashboard(
            errors: ['photo' => 'ERR_photo'],
            old: ['_trainee_id' => 1],
        );

        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertSee('ERR_photo');
        $view->assertSee('invalid-feedback', false);
    }

    public function test_formキーで変換失敗はフォームの上に出て上部案内も欄下も出ない(): void
    {
        $view = $this->renderDashboard(
            errors: ['form' => '写真の登録に失敗しました。時間をおいて試してみてください。'],
            old: ['_trainee_id' => 1],
        );

        $view->assertSee('写真の登録に失敗しました。時間をおいて試してみてください。');
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_2頭並ぶとき片方のphotoエラーはもう片方のカードに出ない(): void
    {
        // トレーニー 1 でエラーが起きた想定
        $view = $this->renderDashboard(
            errors: ['photo' => 'ERR_photo_for_trainee_1'],
            old: ['_trainee_id' => 1],
            weightCharts: $this->dummyWeightCharts([1, 2]),
        );

        // トレーニー 1 のカードにはエラーが出る（上部案内は出ない。小さなフォームの例外）
        $view->assertSee('ERR_photo_for_trainee_1');
        $view->assertDontSee('入力内容に誤りがあります');

        // トレーニー 2 のカードにエラーが出ないことは、画面全体で「ERR_...」が
        // 1 回しか出ないことから裏付ける（カードごとに重複表示されない）
        $this->assertSame(1, substr_count($view->__toString(), 'ERR_photo_for_trainee_1'));
    }

    public function test_formキーで2頭並ぶとき該当トレーニーのカードだけに出る(): void
    {
        $view = $this->renderDashboard(
            errors: ['form' => 'ERR_form_message'],
            old: ['_trainee_id' => 2],
            weightCharts: $this->dummyWeightCharts([1, 2]),
        );

        $view->assertSee('ERR_form_message');
        $this->assertSame(1, substr_count($view->__toString(), 'ERR_form_message'));
    }
}

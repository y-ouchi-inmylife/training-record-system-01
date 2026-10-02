<?php

namespace Tests\Feature;

use App\Models\Trainer;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * 段階 5-1 の描画確認用テスト。検索フォームの日付前後のエラー表示を 3 画面
 * （S-0304 会員一覧・S-0402 トレーニング記録一覧・S-0805 操作履歴）で確かめる。
 *
 * §2-7 の「小さなフォーム」の例外に合わせて、**上部の案内は出さない**。
 * date_to のエラーは終了日の欄の下に出る。入力欄に is-invalid が付く。
 *
 * DB は使わない（Trainer モデルは非永続化、Paginator は空）。
 */
class SearchDateRangeErrorRenderingTest extends TestCase
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

    private function emptyPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 20);
    }

    private function emptyTrainers(): Collection
    {
        return new Collection([]);
    }

    private function bindSessionToRequest(): void
    {
        // view() ヘルパーで old() や $errors を効かせるため、Request に Session を bind する。
        $this->app['request']->setLaravelSession($this->app['session.store']);
    }

    // ---- S-0304 会員一覧 ----

    private function renderClients(array $errors): \Illuminate\Testing\TestView
    {
        $this->actingAs($this->dummyTrainer(), 'web');
        $this->bindSessionToRequest();
        return $this->withViewErrors($errors)->view('clients.index', [
            'clients' => $this->emptyPaginator(),
            'trainers' => $this->emptyTrainers(),
            'sortBy' => 'initial_consultation_date',
            'sortDir' => 'desc',
        ]);
    }

    public function test_会員一覧_formにnovalidateが付く(): void
    {
        $view = $this->renderClients([]);
        $view->assertSee('novalidate', false);
    }

    public function test_会員一覧_エラーなしでは何も出ない(): void
    {
        $view = $this->renderClients([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_会員一覧_dateToエラーで上部案内は出ず欄下文言だけが出る(): void
    {
        $view = $this->renderClients([
            'date_to' => 'ERR_date_to',
        ]);
        // 上部の案内は出ない（小さなフォームの例外）
        $view->assertDontSee('入力内容に誤りがあります');
        // 旧い単独の alert-danger（検索フォーム上部のもの）も出ない
        // 文言は欄の下にだけ出る（1 回のみ）
        $view->assertSee('ERR_date_to');
        $this->assertSame(1, substr_count($view->__toString(), 'ERR_date_to'));
        $view->assertSee('is-invalid', false);
        $view->assertSee('invalid-feedback', false);
    }

    // ---- S-0402 トレーニング記録一覧 ----

    private function renderTrainingRecords(array $errors): \Illuminate\Testing\TestView
    {
        $this->actingAs($this->dummyTrainer(), 'web');
        $this->bindSessionToRequest();
        return $this->withViewErrors($errors)->view('training-records.index', [
            'records' => $this->emptyPaginator(),
            'trainers' => $this->emptyTrainers(),
        ]);
    }

    public function test_トレーニング記録一覧_formにnovalidateが付く(): void
    {
        $view = $this->renderTrainingRecords([]);
        $view->assertSee('novalidate', false);
    }

    public function test_トレーニング記録一覧_エラーなしでは何も出ない(): void
    {
        $view = $this->renderTrainingRecords([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_トレーニング記録一覧_dateToエラーで上部案内は出ず欄下文言だけが出る(): void
    {
        $view = $this->renderTrainingRecords([
            'date_to' => 'ERR_date_to_tr',
        ]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertSee('ERR_date_to_tr');
        $this->assertSame(1, substr_count($view->__toString(), 'ERR_date_to_tr'));
        $view->assertSee('is-invalid', false);
    }

    // ---- S-0805 操作履歴 ----

    private function renderAccessLogs(array $errors): \Illuminate\Testing\TestView
    {
        $this->actingAs($this->dummyTrainer(), 'web');
        $this->bindSessionToRequest();
        return $this->withViewErrors($errors)->view('access-logs.index', [
            'logs' => $this->emptyPaginator(),
            'trainers' => $this->emptyTrainers(),
        ]);
    }

    public function test_操作履歴_formにnovalidateが付く(): void
    {
        $view = $this->renderAccessLogs([]);
        $view->assertSee('novalidate', false);
    }

    public function test_操作履歴_エラーなしでは何も出ない(): void
    {
        $view = $this->renderAccessLogs([]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertDontSee('is-invalid', false);
        $view->assertDontSee('invalid-feedback', false);
    }

    public function test_操作履歴_dateToエラーで上部案内は出ず欄下文言だけが出る(): void
    {
        $view = $this->renderAccessLogs([
            'date_to' => 'ERR_date_to_al',
        ]);
        $view->assertDontSee('入力内容に誤りがあります');
        $view->assertSee('ERR_date_to_al');
        $this->assertSame(1, substr_count($view->__toString(), 'ERR_date_to_al'));
        $view->assertSee('is-invalid', false);
    }
}

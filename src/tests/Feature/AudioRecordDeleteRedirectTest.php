<?php

namespace Tests\Feature;

use App\Http\Controllers\AudioRecordController;
use App\Models\AudioRecord;
use App\Models\Trainer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * 音声記録一覧（S-0505）の削除のあとの戻り先の確認（2026-10）。
 *
 * テスト用 SQLite ではマイグレーションが使えない（AudioRecordUpdateJsonTest と同じ事情）ため、
 *   - 戻り先のクエリを組み立てる処理（buildListRedirectQuery）は、トレーナーの存在の確かめ方を引数で渡して確かめる
 *   - コントローラーは、DB に書き込む前に応答が決まる場面（処理中で削除を断る）だけを確かめる
 *   - 画面は、DB に保存しない記録 1 件の一覧を描画して、削除のフォームと DONE_MESSAGES を確かめる
 * 削除の成功・ページ番号が最後のページを超えたときの転送は DB を伴うため、ここでは対象外（ブラウザで確認する）。
 */
class AudioRecordDeleteRedirectTest extends TestCase
{
    private function build(mixed $page, mixed $trainerId, array $existingTrainerIds = [3]): array
    {
        return AudioRecordController::buildListRedirectQuery(
            $page,
            $trainerId,
            fn (int $id) => in_array($id, $existingTrainerIds, true)
        );
    }

    // ---- 戻り先のクエリ ----

    public function test_正しいページ番号と存在するトレーナーは使う(): void
    {
        $this->assertSame(['page' => 2, 'trainer_id' => 3], $this->build('2', '3'));
        $this->assertSame(['page' => 1], $this->build('1', null));
    }

    public function test_全員の絞り込みは使う(): void
    {
        $this->assertSame(['page' => 5, 'trainer_id' => 'all'], $this->build('5', 'all'));
    }

    public function test_値がないときは何も付けない(): void
    {
        $this->assertSame([], $this->build(null, null));
        $this->assertSame([], $this->build('', ''));
    }

    public function test_不正なページ番号は使わない(): void
    {
        foreach (['0', '-1', 'abc', '1.5', '2abc', ' 2', '01', '9999999999'] as $page) {
            $this->assertSame([], $this->build($page, null), "page={$page}");
        }
        $this->assertSame([], $this->build(['2'], null));
    }

    public function test_不正なトレーナーは使わない(): void
    {
        foreach (['0', '-3', 'abc', '3abc', 'ALL', '03'] as $trainerId) {
            $this->assertSame([], $this->build(null, $trainerId), "trainer_id={$trainerId}");
        }
        // 存在しないトレーナー
        $this->assertSame([], $this->build(null, '99'));
        $this->assertSame([], $this->build(null, ['3']));
    }

    // ---- コントローラー（処理中で削除を断るとき） ----

    private function processingRecord(): AudioRecord
    {
        $record = new AudioRecord;
        $record->forceFill(['id' => 7, 'status' => AudioRecord::STATUS_TRANSCRIBING, 'title' => 't']);
        $record->updated_at = now();

        return $record;
    }

    private function deleteRequest(array $input): Request
    {
        $request = Request::create('/audio-records/7', 'DELETE', $input);
        $request->setLaravelSession($this->app['session.store']);

        return $request;
    }

    public function test_削除を断るときもページ番号と絞り込みを保つ(): void
    {
        $response = app(AudioRecordController::class)->destroy(
            $this->deleteRequest(['page' => '2', 'trainer_id' => 'all']),
            $this->processingRecord()
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(route('audio-records.index', ['page' => 2, 'trainer_id' => 'all']), $response->getTargetUrl());
        $this->assertSame('処理中の音声ファイルは削除できません。', $response->getSession()->get('error'));
    }

    public function test_音声ファイルのみ削除を断るときもページ番号と絞り込みを保つ(): void
    {
        $response = app(AudioRecordController::class)->deleteAudioOnly(
            $this->deleteRequest(['page' => '3', 'trainer_id' => 'all']),
            $this->processingRecord()
        );

        $this->assertSame(route('audio-records.index', ['page' => 3, 'trainer_id' => 'all']), $response->getTargetUrl());
        $this->assertSame('処理中の音声ファイルは削除できません。', $response->getSession()->get('error'));
    }

    public function test_ほかのサイトのurlや余計なクエリは戻り先に入らない(): void
    {
        $response = app(AudioRecordController::class)->destroy(
            $this->deleteRequest(['page' => 'https://example.com/', 'trainer_id' => 'all', 'highlight' => '7', 'done' => 'saved']),
            $this->processingRecord()
        );

        $this->assertSame(route('audio-records.index', ['trainer_id' => 'all']), $response->getTargetUrl());
    }

    // ---- 画面 ----

    private function renderIndex(): string
    {
        $trainer = new Trainer(['name' => 'テスト太郎', 'role' => 'practitioner']);
        $trainer->id = 1;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
        $this->app['request']->setLaravelSession($this->app['session.store']);

        $record = new AudioRecord;
        $record->forceFill(['id' => 7, 'status' => AudioRecord::STATUS_UNPROCESSED, 'title' => 't']);
        $record->created_at = now();
        $record->exists = true;

        return (string) $this->view('audio.index', [
            'audioRecords' => new LengthAwarePaginator([$record], 1, 5, 1, ['path' => '/audio-records']),
            'trainers' => collect(),
            'selectedTrainerId' => 1,
        ]);
    }

    public function test_削除のフォームにページ番号と絞り込みの隠し項目がある(): void
    {
        $html = $this->renderIndex();
        // 2 つの削除のフォームに 1 組ずつ
        $this->assertSame(2, substr_count($html, '<input type="hidden" name="page" value="" data-list-query="page">'));
        $this->assertSame(2, substr_count($html, '<input type="hidden" name="trainer_id" value="" data-list-query="trainer_id">'));
    }

    public function test_削除のフォームのonsubmitが書かれた送り先で空かを確かめる(): void
    {
        $html = $this->renderIndex();
        $this->assertSame(2, substr_count($html, "if (!this.getAttribute('action'))"));
        $this->assertStringNotContainsString('if (!this.action)', $html);
    }

    public function test_完了のメッセージに音声ファイルの削除がある(): void
    {
        $html = $this->renderIndex();
        $this->assertStringContainsString("audio_deleted: '音声ファイルを削除しました。'", $html);
    }
}

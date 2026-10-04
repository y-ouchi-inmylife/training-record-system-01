<?php

namespace Tests\Feature;

use App\Models\Trainer;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * 音声記録一覧（S-0505）の「文字起こし」「要約」で、API の応答が処理中（キューで処理中）なら
 * 状態を返す API を問い合わせて終わりを待つ処理があることの確認（2026-10）。
 *
 * JS の動きは Vitest 等のテストの仕組みがないため確認できないので、描画した HTML に
 * 問い合わせの処理（間隔・上限・通信の失敗の回数・文言）が入っていることだけを見る。
 * DB は使わない（記録は 0 件の一覧で描画する）。
 */
class AudioIndexPollingRenderingTest extends TestCase
{
    private function renderIndex(): string
    {
        $trainer = new Trainer([
            'name' => 'テスト太郎',
            'login_id' => 'test',
            'role' => 'practitioner',
        ]);
        $trainer->id = 1;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');

        return $this->view('audio.index', [
            'audioRecords' => new LengthAwarePaginator([], 0, 20),
            'trainers' => collect(),
            'selectedTrainerId' => null,
        ])->__toString();
    }

    public function test_文字起こし要約で状態を問い合わせて終わりを待つ処理がある(): void
    {
        $html = $this->renderIndex();

        $this->assertStringContainsString("'/status'", $html);
        $this->assertStringContainsString('const ACTION_POLL_INTERVAL_MS = 3000;', $html);
        // 上限は止まったとみなす時間（30 分）にそろえる
        $this->assertStringContainsString('const ACTION_POLL_TIMEOUT_MS = 30 * 60 * 1000;', $html);
        $this->assertStringContainsString('const ACTION_POLL_MAX_FAILURES = 3;', $html);
        $this->assertStringContainsString('処理に時間がかかっています。しばらくしてから一覧を読み込み直してください。', $html);
        $this->assertStringContainsString('return waitForAction(audioId, (result && result.data) || {});', $html);
        // 終わったら今までどおり読み込み直して完了のメッセージを出す
        $this->assertStringContainsString('navigateWithHighlight(audioId, kind);', $html);
    }
}

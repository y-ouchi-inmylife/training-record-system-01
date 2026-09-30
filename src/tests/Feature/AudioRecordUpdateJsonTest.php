<?php

namespace Tests\Feature;

use App\Http\Controllers\AudioRecordController;
use App\Models\AudioRecord;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 音声記録の更新（AudioRecordController::update）の JSON の応答の確認（S-0505 要約前の保存）。
 *
 * 本プロジェクトのマイグレーションは MySQL 専用の CHECK 制約を生 SQL で追加するため、
 * テスト用 SQLite では RefreshDatabase が利用できない（DateRangeFilterValidationTest と同じ事情）。
 * そこで DB に保存しない AudioRecord を使い、コントローラーを直接呼んで、DB に書き込む前に
 * 応答が決まる場面（処理中で断る・入力エラー）だけを検証する。
 * 成功（200）は DB への保存を伴うため、ここでは対象外（ブラウザで確認する）。
 */
class AudioRecordUpdateJsonTest extends TestCase
{
    /**
     * DB に保存しない音声記録を作る
     */
    private function makeRecord(string $status): AudioRecord
    {
        $record = new AudioRecord;
        $record->forceFill(['id' => 1, 'status' => $status, 'title' => 't']);
        $record->updated_at = now(); // 直前に処理中になった（止まっていない）扱い

        return $record;
    }

    private function makeRequest(array $input, bool $json): Request
    {
        $server = $json ? ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] : [];
        $request = Request::create('/audio-records/1', 'PUT', $input, [], [], $server);
        $request->setLaravelSession($this->app['session.store']);

        return $request;
    }

    public function test_処理中の記録はjsonを求めると409と文言を返す(): void
    {
        $response = app(AudioRecordController::class)->update(
            $this->makeRequest(['title' => 't'], true),
            $this->makeRecord(AudioRecord::STATUS_SUMMARIZING)
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(
            ['error' => ['message' => '処理中の音声記録は編集できません。処理が完了してから編集してください。']],
            $response->getData(true)
        );
    }

    public function test_処理中の記録は通常の送信では今までどおり一覧へリダイレクトする(): void
    {
        $response = app(AudioRecordController::class)->update(
            $this->makeRequest(['title' => 't'], false),
            $this->makeRecord(AudioRecord::STATUS_TRANSCRIBING)
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(route('audio-records.index'), $response->getTargetUrl());
        $this->assertSame(
            '処理中の音声記録は編集できません。処理が完了してから編集してください。',
            $response->getSession()->get('error')
        );
    }

    public function test_入力エラーはjsonを求めると422と項目ごとのメッセージを返す(): void
    {
        $request = $this->makeRequest(['title' => ''], true);

        try {
            app(AudioRecordController::class)->update($request, $this->makeRecord(AudioRecord::STATUS_TRANSCRIBED));
            $this->fail('ValidationException が投げられるはず');
        } catch (ValidationException $e) {
            // 例外ハンドラーの標準の描画（JSON を求める場合は 422）で確かめる
            $response = $this->app->make(ExceptionHandler::class)->render($request, $e);
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame(['表示名を入力してください。'], json_decode($response->getContent(), true)['errors']['title']);
        }
    }
}

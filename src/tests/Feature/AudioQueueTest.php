<?php

namespace Tests\Feature;

use App\Http\Controllers\AudioRecordController;
use App\Jobs\CreateTrainingRecordFromAudioJob;
use App\Jobs\SummarizeJob;
use App\Jobs\TranscribeAudioJob;
use App\Models\AudioRecord;
use App\Models\TrainingRecord;
use App\Services\SummarizationService;
use App\Services\TranscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\CreatesRecordingTables;
use Tests\TestCase;

/**
 * 文字起こし・要約・トレーニング記録の自動作成をキューで動かす形にしたことの確認（2026-10）。
 *
 * - 文字起こし・要約の API は、ジョブを並び audio に渡し、その時点の状態を返す
 * - 状態を返す API（GET /api/audio-records/{id}/status）
 * - 録音実行の「作成する」から呼ぶ API は、検証してから 3 つのジョブをひとつながりで渡す
 * - ひとつながりの途中で失敗したら、次のジョブは動かず、状態は「エラー」になる
 * - ジョブは 1 回だけ試し、並びは audio
 *
 * ジョブは Bus::fake()・Queue::fake() で差し替えるか、サービスを差し替えて動かす。
 * 実際の文字起こし・要約（外部の API）は動かさない。
 */
class AudioQueueTest extends TestCase
{
    use CreatesRecordingTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAudioRecordsTable();
        $this->createTrainingRecordsTable();
        $this->createClientsAndTrainersTables();
        DB::table('clients')->insert(['id' => 1]);
        DB::table('trainers')->insert([['id' => 1], ['id' => 2]]);

        config([
            'openai.api_key' => 'test-key',
            'services.anthropic.api_key' => 'test-key',
        ]);
    }

    private function makeAudio(array $attributes = []): AudioRecord
    {
        return AudioRecord::forceCreate(array_merge([
            'title' => 't',
            'file_path' => 'audio/test.webm',
            'status' => AudioRecord::STATUS_UNPROCESSED,
        ], $attributes));
    }

    private function autoCreateRequest(array $input): Request
    {
        $request = Request::create('/api/audio-records/1/auto-create-training-record', 'POST', $input, [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $request->setLaravelSession($this->app['session.store']);

        return $request;
    }

    private function validInput(): array
    {
        return [
            'client_id' => 1,
            'training_date' => '2026-10-04',
            'training_time' => '10:30',
            'trainer1_id' => 1,
            'trainer2_id' => 2,
        ];
    }

    private function mockTranscription(?\Throwable $throw = null): void
    {
        $this->mock(TranscriptionService::class, function ($mock) use ($throw) {
            $expectation = $mock->shouldReceive('transcribe')->once();
            $throw ? $expectation->andThrow($throw) : $expectation->andReturn(['text' => '文字起こしの本文', 'duration' => 12.4]);
        });
    }

    private function mockSummarization(bool $called = true): void
    {
        $this->mock(SummarizationService::class, function ($mock) use ($called) {
            if ($called) {
                $mock->shouldReceive('summarize')->once()->andReturn('要約の本文');
            } else {
                $mock->shouldNotReceive('summarize');
            }
        });
    }

    // ===== 文字起こし・要約の API =====

    public function test_キューがdatabaseのとき文字起こしはaudioの並びにジョブを積み処理中を返す(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $audio = $this->makeAudio();

        $response = app(AudioRecordController::class)->transcribe($audio);

        Queue::assertPushedOn('audio', TranscribeAudioJob::class);
        $data = $response->getData(true)['data'];
        $this->assertSame('transcribing', $data['status']);
        $this->assertTrue($data['is_processing']);
        $this->assertSame('文字起こしを受け付けました。', $data['message']);
    }

    public function test_キューがdatabaseのとき要約はaudioの並びにジョブを積み処理中を返す(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $audio = $this->makeAudio(['status' => AudioRecord::STATUS_TRANSCRIBED, 'transcription_text' => '本文']);

        $response = app(AudioRecordController::class)->summarize($audio);

        Queue::assertPushedOn('audio', SummarizeJob::class);
        $data = $response->getData(true)['data'];
        $this->assertSame('summarizing', $data['status']);
        $this->assertTrue($data['is_processing']);
    }

    public function test_syncのとき文字起こしはジョブの結果の状態を返す(): void
    {
        config(['queue.default' => 'sync']);
        $this->mockTranscription();
        $audio = $this->makeAudio();

        $response = app(AudioRecordController::class)->transcribe($audio);

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame('transcribed', $data['status']);
        $this->assertFalse($data['is_processing']);
        $this->assertSame('文字起こしが完了しました。', $data['message']);
        $this->assertSame('文字起こしの本文', $audio->fresh()->transcription_text);
    }

    public function test_syncのとき要約はジョブの結果の状態を返す(): void
    {
        config(['queue.default' => 'sync']);
        $this->mockSummarization();
        $audio = $this->makeAudio(['status' => AudioRecord::STATUS_TRANSCRIBED, 'transcription_text' => '本文']);

        $response = app(AudioRecordController::class)->summarize($audio);

        $data = $response->getData(true)['data'];
        $this->assertSame('completed', $data['status']);
        $this->assertSame('要約が完了しました。', $data['message']);
        $this->assertSame('要約の本文', $audio->fresh()->summary_text);
    }

    public function test_syncのとき文字起こしに失敗すると500を返し状態はエラーになる(): void
    {
        config(['queue.default' => 'sync']);
        $this->mockTranscription(new RuntimeException('API エラー'));
        $audio = $this->makeAudio();

        $response = app(AudioRecordController::class)->transcribe($audio);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('error', $audio->fresh()->status);
    }

    // ===== 状態を返す API =====

    public function test_状態を返すAPIは状態と処理中かとエラーかと止まったかを返す(): void
    {
        $audio = $this->makeAudio(['status' => AudioRecord::STATUS_SUMMARIZING]);

        $data = app(AudioRecordController::class)->status($audio)->getData(true)['data'];

        $this->assertSame($audio->id, $data['id']);
        $this->assertSame('summarizing', $data['status']);
        $this->assertTrue($data['is_processing']);
        $this->assertFalse($data['is_error']);
        $this->assertFalse($data['is_stalled']);
    }

    public function test_状態を返すAPIは30分たった処理中を止まったとみなす(): void
    {
        $audio = $this->makeAudio(['status' => AudioRecord::STATUS_TRANSCRIBING]);
        AudioRecord::where('id', $audio->id)->update(['updated_at' => now()->subMinutes(31)]);

        $data = app(AudioRecordController::class)->status($audio->fresh())->getData(true)['data'];

        $this->assertTrue($data['is_stalled']);
    }

    public function test_状態を返すAPIはエラーを返す(): void
    {
        $audio = $this->makeAudio(['status' => AudioRecord::STATUS_ERROR]);

        $data = app(AudioRecordController::class)->status($audio)->getData(true)['data'];

        $this->assertFalse($data['is_processing']);
        $this->assertTrue($data['is_error']);
    }

    public function test_新しいAPIは今の音声記録のAPIと同じ権限の確かめ方で登録されている(): void
    {
        $transcribe = Route::getRoutes()->getByName('api.audio-records.transcribe');
        foreach (['api.audio-records.status', 'api.audio-records.auto-create-training-record'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertSame($transcribe->gatherMiddleware(), $route->gatherMiddleware(), $name);
            $this->assertSame($transcribe->getDomain(), $route->getDomain(), $name);
        }
        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('api.audio-records.status')->methods());
        $this->assertSame(['POST'], Route::getRoutes()->getByName('api.audio-records.auto-create-training-record')->methods());
    }

    public function test_未ログインでは状態を返すAPIを使えない(): void
    {
        $audio = $this->makeAudio();
        $host = config('subdomain.trainer_host');

        $this->getJson('http://' . $host . '/api/audio-records/' . $audio->id . '/status')->assertStatus(401);
    }

    // ===== 録音実行の「作成する」から呼ぶ API =====

    public function test_作成するは文字起こし中にして3つのジョブをこの順でひとつながりでaudioに渡す(): void
    {
        Bus::fake();
        $audio = $this->makeAudio();

        $response = app(AudioRecordController::class)->startAutoCreate($this->autoCreateRequest($this->validInput()), $audio);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['success']);
        $this->assertSame('transcribing', $audio->fresh()->status);
        Bus::assertChained([
            TranscribeAudioJob::class,
            SummarizeJob::class,
            CreateTrainingRecordFromAudioJob::class,
        ]);
        Bus::assertDispatched(TranscribeAudioJob::class, fn ($job) => $job->queue === 'audio');
    }

    public function test_作成するは担当1が未選択なら422で受け付けない(): void
    {
        Bus::fake();
        $audio = $this->makeAudio();
        $input = $this->validInput();
        $input['trainer1_id'] = '';

        try {
            app(AudioRecordController::class)->startAutoCreate($this->autoCreateRequest($input), $audio);
            $this->fail('ValidationException が投げられていない');
        } catch (ValidationException $e) {
            $this->assertSame([__('validation.custom.trainer1_id.required')], $e->errors()['trainer1_id']);
        }

        Bus::assertNothingDispatched();
        $this->assertSame('unprocessed', $audio->fresh()->status);
    }

    public function test_作成するは担当2が担当1と同じなら422で受け付けない(): void
    {
        Bus::fake();
        $audio = $this->makeAudio();
        $input = $this->validInput();
        $input['trainer2_id'] = 1;

        try {
            app(AudioRecordController::class)->startAutoCreate($this->autoCreateRequest($input), $audio);
            $this->fail('ValidationException が投げられていない');
        } catch (ValidationException $e) {
            $this->assertSame([__('validation.custom.trainer2_id.different')], $e->errors()['trainer2_id']);
        }

        Bus::assertNothingDispatched();
    }

    public function test_作成するは処理中や音声ファイルがないとき409で受け付けない(): void
    {
        Bus::fake();
        $processing = $this->makeAudio(['status' => AudioRecord::STATUS_TRANSCRIBING]);
        $noFile = $this->makeAudio(['file_path' => null]);

        $first = app(AudioRecordController::class)->startAutoCreate($this->autoCreateRequest($this->validInput()), $processing);
        $second = app(AudioRecordController::class)->startAutoCreate($this->autoCreateRequest($this->validInput()), $noFile);

        $this->assertSame(409, $first->getStatusCode());
        $this->assertSame('現在、文字起こしが実行中です。しばらくお待ちください。', $first->getData(true)['error']['message']);
        $this->assertSame(409, $second->getStatusCode());
        Bus::assertNothingDispatched();
    }

    public function test_syncのとき作成するは3つとも終えてトレーニング記録を作る(): void
    {
        config(['queue.default' => 'sync']);
        $this->mockTranscription();
        $this->mockSummarization();
        $audio = $this->makeAudio();

        $response = app(AudioRecordController::class)->startAutoCreate($this->autoCreateRequest($this->validInput()), $audio);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('completed', $audio->fresh()->status);
        $this->assertSame(1, TrainingRecord::count());
        $record = TrainingRecord::first();
        $this->assertSame('要約の本文', $record->record_content);
        $this->assertSame(1, (int) $record->trainer1_id);
        $this->assertSame(2, (int) $record->trainer2_id);
        $this->assertSame('2026-10-04', $record->training_date->format('Y-m-d'));
    }

    public function test_ひとつながりの途中で文字起こしが失敗したら要約と記録の作成に進まず状態はエラーになる(): void
    {
        config(['queue.default' => 'sync']);
        $this->mockTranscription(new RuntimeException('API エラー'));
        $this->mockSummarization(called: false);
        $audio = $this->makeAudio();

        $response = app(AudioRecordController::class)->startAutoCreate($this->autoCreateRequest($this->validInput()), $audio);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertSame('error', $audio->fresh()->status);
        $this->assertSame(0, TrainingRecord::count());
    }

    public function test_ひとつながりの途中で要約が失敗したら記録の作成に進まず状態はエラーになる(): void
    {
        config(['queue.default' => 'sync']);
        $this->mockTranscription();
        $this->mock(SummarizationService::class, function ($mock) {
            $mock->shouldReceive('summarize')->once()->andThrow(new RuntimeException('API エラー'));
        });
        $audio = $this->makeAudio();

        $response = app(AudioRecordController::class)->startAutoCreate($this->autoCreateRequest($this->validInput()), $audio);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('error', $audio->fresh()->status);
        $this->assertSame('文字起こしの本文', $audio->fresh()->transcription_text);
        $this->assertSame(0, TrainingRecord::count());
    }

    // ===== ジョブ =====

    public function test_音声のジョブは1回だけ試し並びはaudio(): void
    {
        $jobs = [
            new TranscribeAudioJob(1),
            new SummarizeJob(1),
            new CreateTrainingRecordFromAudioJob(['audio_record_id' => 1]),
        ];
        foreach ($jobs as $job) {
            $this->assertSame(1, $job->tries, $job::class);
            $this->assertSame('audio', $job->queue, $job::class);
        }
        // 時間の上限は変えない
        $this->assertSame(600, (new TranscribeAudioJob(1))->timeout);
        $this->assertSame(600, (new SummarizeJob(1))->timeout);
    }

    public function test_時間切れなどでfailedが呼ばれると処理中の状態がエラーになる(): void
    {
        $transcribing = $this->makeAudio(['status' => AudioRecord::STATUS_TRANSCRIBING]);
        $summarizing = $this->makeAudio(['status' => AudioRecord::STATUS_SUMMARIZING]);

        (new TranscribeAudioJob($transcribing->id))->failed(new RuntimeException('timed out'));
        (new SummarizeJob($summarizing->id))->failed(new RuntimeException('timed out'));

        $this->assertSame('error', $transcribing->fresh()->status);
        $this->assertSame('error', $summarizing->fresh()->status);
    }

    public function test_failedは処理中でない状態を変えない(): void
    {
        $completed = $this->makeAudio(['status' => AudioRecord::STATUS_COMPLETED]);

        (new TranscribeAudioJob($completed->id))->failed(new RuntimeException('x'));
        (new SummarizeJob($completed->id))->failed(new RuntimeException('x'));
        (new CreateTrainingRecordFromAudioJob(['audio_record_id' => $completed->id]))->failed(new RuntimeException('x'));

        $this->assertSame('completed', $completed->fresh()->status);
    }

    public function test_ひとつながりの要約のジョブは文字起こし済みから要約中にして始める(): void
    {
        $this->mockSummarization();
        $audio = $this->makeAudio(['status' => AudioRecord::STATUS_TRANSCRIBED, 'transcription_text' => '本文']);

        (new SummarizeJob($audio->id, continueFromTranscription: true))->handle(app(SummarizationService::class));

        $this->assertSame('completed', $audio->fresh()->status);
    }

    public function test_単独の要約のジョブは文字起こし済みのままなら動かない(): void
    {
        $this->mockSummarization(called: false);
        $audio = $this->makeAudio(['status' => AudioRecord::STATUS_TRANSCRIBED, 'transcription_text' => '本文']);

        (new SummarizeJob($audio->id))->handle(app(SummarizationService::class));

        $this->assertSame('transcribed', $audio->fresh()->status);
    }

    public function test_記録の作成のジョブは要約が完了していなければ記録を作らない(): void
    {
        $audio = $this->makeAudio(['status' => AudioRecord::STATUS_ERROR]);

        app()->call([new CreateTrainingRecordFromAudioJob(array_merge($this->validInput(), ['audio_record_id' => $audio->id])), 'handle']);

        $this->assertSame(0, TrainingRecord::count());
    }

    // ===== 止まったとみなす時間 =====

    public function test_止まったとみなす時間は30分(): void
    {
        $this->assertSame(30, AudioRecord::PROCESSING_STALL_MINUTES);
    }
}

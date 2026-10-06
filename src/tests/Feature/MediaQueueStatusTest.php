<?php

namespace Tests\Feature;

use App\Http\Controllers\MediaRecordController;
use App\Jobs\ConvertMediaJob;
use App\Jobs\GenerateThumbnailJob;
use App\Models\MediaRecord;
use App\Services\MediaConversionService;
use App\Services\MediaThumbnailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * メディアの変換・サムネイルを、キューでも sync でも動く形にしたことの確認（2026-10）。
 *
 * - 変換・サムネイルの API は、ジョブを渡したあと、その時点の状態を返す
 *   （キュー〔database〕なら processing、sync ならジョブの結果）
 * - 状態を返す API（GET /api/media-records/{id}/status）
 * - ジョブは 1 回だけ試し、失敗したら状態を「エラー」にする
 * - 登録のモーダルに、状態の問い合わせの処理がある
 *
 * 本プロジェクトのマイグレーションは MySQL 専用の CHECK 制約を生 SQL で追加するため、
 * テスト用 SQLite では RefreshDatabase が使えない。そこで、media_records テーブルを
 * テストの中で作る。実際の変換・サムネイル（FFmpeg・ImageMagick・ストレージ）は動かさず、
 * キューは Queue::fake()、サービスは差し替えで確かめる。
 */
class MediaQueueStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('media_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainer_id')->nullable();
            $table->string('type', 20);
            $table->string('title')->nullable();
            $table->string('original_filename');
            $table->string('original_path');
            $table->string('display_path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('mime_type');
            $table->bigInteger('file_size')->nullable();
            $table->string('conversion_status', 20)->default('not_required');
            $table->string('thumbnail_status', 20)->default('pending');
            $table->timestamps();
        });
    }

    private function makeMedia(array $attributes = []): MediaRecord
    {
        return MediaRecord::forceCreate(array_merge([
            'type' => MediaRecord::TYPE_PHOTO,
            'original_filename' => 'photo.heic',
            'original_path' => 'media/202610/photo.heic',
            'mime_type' => 'image/heic',
            'conversion_status' => MediaRecord::CONVERSION_PENDING,
            'thumbnail_status' => MediaRecord::THUMBNAIL_PENDING,
        ], $attributes));
    }

    // ===== 変換・サムネイルの API（キュー） =====

    public function test_キューがdatabaseのとき変換はジョブをキューに積み処理中を返す(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $media = $this->makeMedia();

        $response = app(MediaRecordController::class)->convert($media);

        Queue::assertPushed(ConvertMediaJob::class);
        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame('processing', $data['conversion_status']);
        $this->assertTrue($data['is_processing']);
        $this->assertFalse($data['has_error']);
    }

    public function test_キューがdatabaseのときサムネイルはジョブをキューに積み処理中を返す(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $media = $this->makeMedia();

        $response = app(MediaRecordController::class)->generateThumbnail($media);

        Queue::assertPushed(GenerateThumbnailJob::class);
        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame('processing', $data['thumbnail_status']);
        $this->assertTrue($data['is_processing']);
        $this->assertNull($data['thumbnail_url']);
    }

    // ===== 変換・サムネイルの API（sync） =====

    public function test_syncのとき変換はジョブの結果の状態を返す(): void
    {
        config(['queue.default' => 'sync']);
        $this->mock(MediaConversionService::class, function ($mock) {
            $mock->shouldReceive('convertPhotoToJpeg')->once()->andReturn('media/202610/photo.jpg');
        });
        $media = $this->makeMedia();

        $response = app(MediaRecordController::class)->convert($media);

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame('done', $data['conversion_status']);
        $this->assertSame('media/202610/photo.jpg', $data['display_path']);
        $this->assertFalse($data['is_processing']);
    }

    public function test_syncのときサムネイルはジョブの結果の状態とサムネイルのURLを返す(): void
    {
        config(['queue.default' => 'sync']);
        Storage::fake(MediaRecord::STORAGE_DISK);
        Storage::disk(MediaRecord::STORAGE_DISK)->buildTemporaryUrlsUsing(
            fn ($path) => 'https://example.test/' . $path
        );
        $this->mock(MediaThumbnailService::class, function ($mock) {
            $mock->shouldReceive('generatePhotoThumbnail')->once()->andReturn('media/202610/thumb.jpg');
        });
        $media = $this->makeMedia(['conversion_status' => MediaRecord::CONVERSION_DONE]);

        $response = app(MediaRecordController::class)->generateThumbnail($media);

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame('done', $data['thumbnail_status']);
        $this->assertSame('https://example.test/media/202610/thumb.jpg', $data['thumbnail_url']);
        $this->assertSame('photo.heic', $data['display_title']);
        $this->assertFalse($data['is_processing']);
    }

    public function test_syncのとき変換に失敗すると500を返し状態はエラーになる(): void
    {
        config(['queue.default' => 'sync']);
        $this->mock(MediaConversionService::class, function ($mock) {
            $mock->shouldReceive('convertPhotoToJpeg')->once()->andThrow(new RuntimeException('変換失敗'));
        });
        $media = $this->makeMedia();

        $response = app(MediaRecordController::class)->convert($media);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('error', $media->fresh()->conversion_status);
    }

    // ===== 状態を返す API =====

    public function test_状態を返すAPIは変換とサムネイルの状態を返す(): void
    {
        $media = $this->makeMedia([
            'conversion_status' => MediaRecord::CONVERSION_DONE,
            'thumbnail_status' => MediaRecord::THUMBNAIL_PROCESSING,
        ]);

        $response = app(MediaRecordController::class)->status($media);

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame($media->id, $data['id']);
        $this->assertSame('done', $data['conversion_status']);
        $this->assertSame('processing', $data['thumbnail_status']);
        $this->assertTrue($data['is_processing']);
        $this->assertFalse($data['has_error']);
        $this->assertNull($data['thumbnail_url']);
    }

    public function test_状態を返すAPIはエラーを返す(): void
    {
        $media = $this->makeMedia([
            'conversion_status' => MediaRecord::CONVERSION_ERROR,
            'thumbnail_status' => MediaRecord::THUMBNAIL_PENDING,
        ]);

        $data = app(MediaRecordController::class)->status($media)->getData(true)['data'];

        $this->assertFalse($data['is_processing']);
        $this->assertTrue($data['has_error']);
    }

    public function test_状態を返すAPIは今のメディアのAPIと同じ権限の確かめ方で登録されている(): void
    {
        $status = Route::getRoutes()->getByName('api.media-records.status');
        $convert = Route::getRoutes()->getByName('api.media-records.convert');

        $this->assertNotNull($status);
        $this->assertSame(['GET', 'HEAD'], $status->methods());
        $this->assertSame('api/media-records/{mediaRecord}/status', $status->uri());
        // 認証（auth）・トレーナーの権限（practitioners）・IP 制限（check-ip）・ドメインが、変換の API と同じ
        $this->assertSame($convert->gatherMiddleware(), $status->gatherMiddleware());
        $this->assertContains('auth', $status->gatherMiddleware());
        $this->assertContains('practitioners', $status->gatherMiddleware());
        $this->assertSame($convert->getDomain(), $status->getDomain());
    }

    public function test_未ログインでは状態を返すAPIを使えない(): void
    {
        // ルートは Route::domain(config('subdomain.trainer_host')) で囲まれているが、
        // 開発環境・テスト環境では TRAINER_HOST を未設定にして null に倒す運用のため
        // （config/subdomain.php のコメント参照）、Route::domain は「どのホストでも受ける」
        // 扱いになる。テストの既定ホスト（localhost）に相対パスでそのまま送って 401 を確かめる。
        $media = $this->makeMedia();

        $response = $this->getJson('/api/media-records/' . $media->id . '/status');

        $response->assertStatus(401);
    }

    // ===== ジョブ =====

    public function test_変換とサムネイルのジョブの並びはmedia(): void
    {
        // 音声のジョブ（audio）と並びを分ける（2026-10）
        $this->assertSame('media', (new ConvertMediaJob(1))->queue);
        $this->assertSame('media', (new GenerateThumbnailJob(1))->queue);
    }

    public function test_キューがdatabaseのとき変換とサムネイルはmediaの並びに積む(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();

        app(MediaRecordController::class)->convert($this->makeMedia());
        app(MediaRecordController::class)->generateThumbnail($this->makeMedia());

        Queue::assertPushedOn('media', ConvertMediaJob::class);
        Queue::assertPushedOn('media', GenerateThumbnailJob::class);
    }

    public function test_変換とサムネイルのジョブは1回だけ試す(): void
    {
        $this->assertSame(1, (new ConvertMediaJob(1))->tries);
        $this->assertSame(1, (new GenerateThumbnailJob(1))->tries);
        // 時間の上限は変えない
        $this->assertSame(600, (new ConvertMediaJob(1))->timeout);
        $this->assertSame(600, (new GenerateThumbnailJob(1))->timeout);
    }

    public function test_変換のジョブが例外で失敗すると状態がエラーになる(): void
    {
        $media = $this->makeMedia(['conversion_status' => MediaRecord::CONVERSION_PROCESSING]);
        $service = $this->mock(MediaConversionService::class, function ($mock) {
            $mock->shouldReceive('convertPhotoToJpeg')->once()->andThrow(new RuntimeException('変換失敗'));
        });

        try {
            (new ConvertMediaJob($media->id))->handle($service);
            $this->fail('例外が投げられていない');
        } catch (RuntimeException $e) {
            $this->assertSame('変換失敗', $e->getMessage());
        }

        $this->assertSame('error', $media->fresh()->conversion_status);
    }

    public function test_サムネイルのジョブが例外で失敗すると状態がエラーになる(): void
    {
        $media = $this->makeMedia(['thumbnail_status' => MediaRecord::THUMBNAIL_PROCESSING]);
        $service = $this->mock(MediaThumbnailService::class, function ($mock) {
            $mock->shouldReceive('generatePhotoThumbnail')->once()->andThrow(new RuntimeException('生成失敗'));
        });

        try {
            (new GenerateThumbnailJob($media->id))->handle($service);
            $this->fail('例外が投げられていない');
        } catch (RuntimeException $e) {
            $this->assertSame('生成失敗', $e->getMessage());
        }

        $this->assertSame('error', $media->fresh()->thumbnail_status);
    }

    public function test_時間切れなどでfailedが呼ばれると処理中の状態がエラーになる(): void
    {
        $converting = $this->makeMedia(['conversion_status' => MediaRecord::CONVERSION_PROCESSING]);
        $thumbnailing = $this->makeMedia(['thumbnail_status' => MediaRecord::THUMBNAIL_PROCESSING]);

        (new ConvertMediaJob($converting->id))->failed(new RuntimeException('timed out'));
        (new GenerateThumbnailJob($thumbnailing->id))->failed(new RuntimeException('timed out'));

        $this->assertSame('error', $converting->fresh()->conversion_status);
        $this->assertSame('error', $thumbnailing->fresh()->thumbnail_status);
    }

    public function test_failedは処理中でない状態を変えない(): void
    {
        $done = $this->makeMedia([
            'conversion_status' => MediaRecord::CONVERSION_DONE,
            'thumbnail_status' => MediaRecord::THUMBNAIL_DONE,
        ]);

        (new ConvertMediaJob($done->id))->failed(new RuntimeException('x'));
        (new GenerateThumbnailJob($done->id))->failed(new RuntimeException('x'));

        $this->assertSame('done', $done->fresh()->conversion_status);
        $this->assertSame('done', $done->fresh()->thumbnail_status);
    }

    // ===== 登録のモーダル =====

    public function test_登録のモーダルに状態の問い合わせの処理がある(): void
    {
        $html = Blade::render("@include('media-records._upload-modal') @stack('scripts')");

        $this->assertStringContainsString("'/status'", $html);
        $this->assertStringContainsString('const POLL_INTERVAL_MS = 3000;', $html);
        $this->assertStringContainsString('const POLL_TIMEOUT_MS = 15 * 60 * 1000;', $html);
        $this->assertStringContainsString('const POLL_MAX_FAILURES = 3;', $html);
        $this->assertStringContainsString("waitUntilFinished(media, 'conversion_status'", $html);
        $this->assertStringContainsString("waitUntilFinished(media, 'thumbnail_status'", $html);
        $this->assertStringContainsString('処理に時間がかかっています。しばらくしてから一覧で確かめてください。', $html);
        $this->assertStringContainsString('id="mediaUploadSummaryPending"', $html);
    }
}

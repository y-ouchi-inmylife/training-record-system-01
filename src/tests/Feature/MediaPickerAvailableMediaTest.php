<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MediaRecord;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * メディアを選ぶモーダル（メディア追加 S-0401-M02 / S-0404-M01、トレーナー用写真の選択 S-0309-M02）の候補の API
 * （GET /api/training-records/available-media）と、モーダルの JS（2026-11 変更）。
 *
 * - 候補は、記録に紐づけ済みかどうかに関わらずすべて出す（training_record_id を付けても外さない）
 * - 画面の JS は training_record_id を送らない。二重の追加はフォームの mediaSelection.add が ID で防ぐ
 *
 * マイグレーションは MySQL 専用の CHECK 制約を含むため、使う表だけをテストの中で作る（MediaRecordLinkedTraineesTest と同じ）。
 * モーダルは登録・編集の画面全体を開くと読む表が多いため、JS は Blade の元のファイルの中身で確かめる。
 */
class MediaPickerAvailableMediaTest extends TestCase
{
    use CreatesClientCountTables;

    private int $recordSeq = 0;

    private int $mediaSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createAccessLogsTable();
        $this->createTrainersTableForTraineeShow();
        $this->createTrainingRecordsTableForPersonalityDiagnosis();
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->string('trainer_photo_path', 500)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
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
        Schema::create('media_record_training_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('media_record_id');
            $table->unsignedBigInteger('training_record_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('trainers')->insert(['id' => 200, 'name' => 'テスト', 'role' => 'staff', 'display_order' => 1]);
        $trainer = new Trainer(['name' => 'テスト', 'role' => 'staff']);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    // ===== ヘルパー =====

    private function makeClient(string $lastName, string $firstName): Client
    {
        return Client::query()->create(['internal_id' => (string) fake()->unique()->numberBetween(1, 9999), 'last_name' => $lastName, 'first_name' => $firstName]);
    }

    /** メディアを作る。作った順に新しくなる（候補は登録日時の新しい順） */
    private function makeMedia(): MediaRecord
    {
        $createdAt = now()->setDate(2026, 11, 1)->setTime(9, 0)->addMinutes(++$this->mediaSeq);

        return MediaRecord::forceCreate([
            'trainer_id' => 200,
            'type' => MediaRecord::TYPE_PHOTO,
            'original_filename' => 'IMG_'.$this->mediaSeq.'.JPG',
            'original_path' => 'media/202611/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'conversion_status' => MediaRecord::CONVERSION_NOT_REQUIRED,
            'thumbnail_status' => MediaRecord::THUMBNAIL_PENDING,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** 会員の記録を作り、メディアをひも付ける。記録の ID を返す */
    private function link(MediaRecord $media, Client $client, string $trainingDate = '2026-11-01'): int
    {
        $recordId = DB::table('training_records')->insertGetId([
            'client_id' => $client->id,
            'training_date' => $trainingDate,
            'trainer1_id' => 200,
        ]);
        DB::table('media_record_training_record')->insert([
            'media_record_id' => $media->id,
            'training_record_id' => $recordId,
            'sort_order' => ++$this->recordSeq,
        ]);

        return $recordId;
    }

    private function candidateIds(string $query = ''): array
    {
        $json = $this->getJson('/api/training-records/available-media?'.$query)->assertOk()->json();

        return array_column($json['data'], 'id');
    }

    // ===== 候補の出し方（A） =====

    public function test_候補には記録に紐づけ済みのメディアも出る(): void
    {
        $client = $this->makeClient('大内', '佳和');
        $linked = $this->makeMedia();
        $recordId = $this->link($linked, $client);
        $unlinked = $this->makeMedia();

        // 付けなくても、付けても（以前は付けると外していた）同じ
        $this->assertSame([$unlinked->id, $linked->id], $this->candidateIds());
        $this->assertSame([$unlinked->id, $linked->id], $this->candidateIds('training_record_id='.$recordId));
        // 存在しない記録の ID でも 404 にしない（パラメータごと受け取らない）
        $this->assertSame([$unlinked->id, $linked->id], $this->candidateIds('training_record_id=99999'));
    }

    public function test_メディア追加モーダルのjsは記録のidを送らず重複を防ぐ処理がある(): void
    {
        $form = file_get_contents(resource_path('views/training-records/_form.blade.php'));

        $this->assertStringNotContainsString("params.set('training_record_id'", $form);
        $this->assertStringNotContainsString('trainingRecordId', $form);
        // フォームにすでにある ID（保存済みは $mediaInitial から、未保存は追加したもの）は追加しない
        $this->assertStringContainsString('if (this.items.some(i => i.id === media.id)) return;', $form);
        // 外したものは items から消える（選び直して追加できる）
        $this->assertStringContainsString('this.items = this.items.filter(i => i.id !== id);', $form);
    }

    public function test_トレーナー用写真の選択モーダルも記録のidを送らない(): void
    {
        $modal = file_get_contents(resource_path('views/trainees/_trainer-photo-modal.blade.php'));

        $this->assertStringNotContainsString('training_record_id', $modal);
        $this->assertStringContainsString("type: 'photo',", $modal);
    }
}

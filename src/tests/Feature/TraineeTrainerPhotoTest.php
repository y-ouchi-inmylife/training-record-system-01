<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\MediaRecord;
use App\Models\Trainee;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * トレーナー用の写真の設定・外す（要件定義書 6-16-20。2026-11 追加）と、候補の API の type=photo。
 *
 * - 一般トレーナー（staff）も設定できる。写真のメディアを選ぶと trainee-trainer-photos/ にファイルができ、
 *   trainer_photo_path が入る（コピー方式。元のメディアは変わらない）。updated_by とアクセスログも残る
 * - 動画・変換の終わっていない HEIC・存在しないメディアは選べない（同じ文言）
 * - 選び直すと古いファイルが消える。外すと NULL に戻り、ファイルが消える（会員写真は残る）
 * - トレーニーを削除すると、会員写真とトレーナー用の写真の両方のファイルが消える
 * - available-media は type を付けなければ今と同じ件数を返し、type=photo なら選べる写真だけを返す
 *
 * magick は使わない：テストの環境（Windows の開発機・CI）に magick がないことがあるため、Process::fake で
 * magick の呼び出しを受け、出力先（引数の最後）に JPEG の代わりのバイト列を書いて「変換できた」状態を作る。
 * 実際の magick での変換（長辺 400px の JPEG になること）は、Mac で TraineePhotoImageService を直接動かして確かめた。
 */
class TraineeTrainerPhotoTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Trainee::STORAGE_DISK);

        $this->createClientsTable();
        $this->createAccessLogsTable();
        $this->createTrainersTableForTraineeShow();
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

        // トレーニー詳細（S-0309）を開くテストのため、詳細で読む表も作る（TraineeShowIntakeSurveyTest と同じ）
        Schema::create('trainee_measurements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trainee_id');
            $table->date('measured_date');
            $table->time('measured_time');
            $table->decimal('weight_kg', 5, 2);
        });
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();
        $this->createTrainingRecordsTableForPersonalityDiagnosis();

        DB::table('trainers')->insert(['id' => 200, 'name' => 'テスト', 'role' => 'staff', 'display_order' => 1]);
        $this->actingAsRole('staff');

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        $this->trainee = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'モモ']);

        // magick の代わり：出力先（コマンドの最後の引数）にバイト列を書いて成功を返す
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            file_put_contents(end($command), 'converted-jpeg');

            return Process::result();
        });
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => 'テスト', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    /** メディアを作る（表示用の画像のファイルも media ディスクに置く） */
    private function makeMedia(array $attributes = []): MediaRecord
    {
        $media = MediaRecord::forceCreate(array_merge([
            'trainer_id' => 200,
            'type' => MediaRecord::TYPE_PHOTO,
            'original_filename' => 'IMG_0001.JPG',
            'original_path' => 'media/202611/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
            'conversion_status' => MediaRecord::CONVERSION_NOT_REQUIRED,
            'thumbnail_status' => MediaRecord::THUMBNAIL_PENDING,
        ], $attributes));
        if (! array_key_exists('display_path', $attributes)) {
            $media->forceFill(['display_path' => $media->original_path])->save();
        }
        if ($media->display_path) {
            Storage::disk(MediaRecord::STORAGE_DISK)->put($media->display_path, 'original-image');
        }

        return $media->fresh();
    }

    private function setPhoto(MediaRecord $media)
    {
        return $this->from(route('trainees.show', $this->trainee))
            ->post(route('trainee-trainer-photo.store', $this->trainee), [
                '_trainer_photo_modal' => '1',
                'media_record_id' => $media->id,
            ]);
    }

    public function test_アドレスとルート名は設計書のとおり(): void
    {
        $this->assertSame(url('/trainees/'.$this->trainee->id.'/trainer-photo'), route('trainee-trainer-photo.store', $this->trainee));
        $this->assertSame(url('/trainees/'.$this->trainee->id.'/trainer-photo'), route('trainee-trainer-photo.destroy', $this->trainee));
    }

    public function test_一般トレーナーが写真を選ぶとコピーができてtrainer_photo_pathが入る(): void
    {
        $media = $this->makeMedia();

        $response = $this->setPhoto($media);

        $response->assertRedirect(route('trainees.show', $this->trainee));
        $response->assertSessionHas('success', 'トレーナー用の写真を設定しました。');
        $trainee = $this->trainee->fresh();
        $this->assertMatchesRegularExpression('#^trainee-trainer-photos/\d{6}/[0-9a-f-]{36}\.jpg$#', $trainee->trainer_photo_path);
        Storage::disk(Trainee::STORAGE_DISK)->assertExists($trainee->trainer_photo_path);
        $this->assertSame('converted-jpeg', Storage::disk(Trainee::STORAGE_DISK)->get($trainee->trainer_photo_path));
        $this->assertSame(200, (int) $trainee->updated_by);
        // 元のメディアは変わらない（コピー方式）
        Storage::disk(MediaRecord::STORAGE_DISK)->assertExists($media->display_path);
        // magick には会員写真と同じ指定で渡す
        Process::assertRan(fn (PendingProcess $p) => in_array('-auto-orient', $p->command, true)
            && in_array('400x400>', $p->command, true) && in_array('85', $p->command, true));
        // アクセスログ
        $this->assertTrue(AccessLog::query()->where('action', 'create_trainee_trainer_photo')
            ->where('target_type', 'Trainee')->where('target_id', $this->trainee->id)->exists());
    }

    public function test_変換の終わったheicは選べる(): void
    {
        $media = $this->makeMedia([
            'mime_type' => 'image/heic', 'conversion_status' => MediaRecord::CONVERSION_DONE,
            'display_path' => 'media/202611/display.jpg',
        ]);

        $this->setPhoto($media)->assertSessionHasNoErrors();

        $this->assertNotNull($this->trainee->fresh()->trainer_photo_path);
    }

    public function test_動画と変換の終わっていないheicと存在しないメディアは選べない(): void
    {
        $video = $this->makeMedia(['type' => MediaRecord::TYPE_VIDEO, 'mime_type' => 'video/mp4']);
        $heicPending = $this->makeMedia(['mime_type' => 'image/heic', 'conversion_status' => MediaRecord::CONVERSION_PENDING, 'display_path' => null]);
        $heicError = $this->makeMedia(['mime_type' => 'image/heic', 'conversion_status' => MediaRecord::CONVERSION_ERROR, 'display_path' => null]);

        foreach ([$video->id, $heicPending->id, $heicError->id, 99999] as $id) {
            $this->from(route('trainees.show', $this->trainee))
                ->post(route('trainee-trainer-photo.store', $this->trainee), ['_trainer_photo_modal' => '1', 'media_record_id' => $id])
                ->assertRedirect(route('trainees.show', $this->trainee))
                ->assertSessionHasErrors(['media_record_id' => '選んだメディアはトレーナー用の写真に使えません。']);
        }
        $this->assertNull($this->trainee->fresh()->trainer_photo_path);
        Process::assertNothingRan();
    }

    public function test_選ばずに送ると写真を選んでくださいになる(): void
    {
        $this->from(route('trainees.show', $this->trainee))
            ->post(route('trainee-trainer-photo.store', $this->trainee), ['_trainer_photo_modal' => '1'])
            ->assertSessionHasErrors(['media_record_id' => '写真を選んでください。']);
    }

    public function test_エラーで戻るとモーダルを開き直してグリッドの下に文言を出す(): void
    {
        $this->from(route('trainees.show', $this->trainee))
            ->post(route('trainee-trainer-photo.store', $this->trainee), ['_trainer_photo_modal' => '1']);

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringContainsString('data-reopen="1"', $html);
        $this->assertMatchesRegularExpression('/<div class="invalid-feedback d-block">写真を選んでください。<\/div>/u', $html);
        // モーダルでは上部の案内は出さない
        $this->assertStringNotContainsString('入力内容に誤りがあります。', $html);
    }

    public function test_変換に失敗したらformのキーで戻り何も変えない(): void
    {
        Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'magick: error'));
        $media = $this->makeMedia();

        $this->setPhoto($media)
            ->assertRedirect(route('trainees.show', $this->trainee))
            ->assertSessionHasErrors(['form' => 'トレーナー用の写真の設定に失敗しました。時間をおいて試してみてください。']);

        $this->assertNull($this->trainee->fresh()->trainer_photo_path);
        $this->assertSame([], Storage::disk(Trainee::STORAGE_DISK)->allFiles(Trainee::TRAINER_PHOTO_DIR));
    }

    public function test_選び直すと古いファイルが消える(): void
    {
        $this->setPhoto($this->makeMedia());
        $old = $this->trainee->fresh()->trainer_photo_path;

        $this->setPhoto($this->makeMedia());
        $new = $this->trainee->fresh()->trainer_photo_path;

        $this->assertNotSame($old, $new);
        Storage::disk(Trainee::STORAGE_DISK)->assertMissing($old);
        Storage::disk(Trainee::STORAGE_DISK)->assertExists($new);
    }

    public function test_外すと空に戻りファイルが消え会員写真は残る(): void
    {
        Storage::disk(Trainee::STORAGE_DISK)->put('trainees/202611/member.jpg', 'member');
        $this->trainee->forceFill(['photo_path' => 'trainees/202611/member.jpg'])->save();
        $this->setPhoto($this->makeMedia());
        $key = $this->trainee->fresh()->trainer_photo_path;

        $response = $this->delete(route('trainee-trainer-photo.destroy', $this->trainee));

        $response->assertRedirect(route('trainees.show', $this->trainee));
        $response->assertSessionHas('success', 'トレーナー用の写真を外しました。');
        $trainee = $this->trainee->fresh();
        $this->assertNull($trainee->trainer_photo_path);
        $this->assertSame('trainees/202611/member.jpg', $trainee->photo_path);
        Storage::disk(Trainee::STORAGE_DISK)->assertMissing($key);
        Storage::disk(Trainee::STORAGE_DISK)->assertExists('trainees/202611/member.jpg');
        $this->assertTrue(AccessLog::query()->where('action', 'delete_trainee_trainer_photo')->where('target_id', $this->trainee->id)->exists());
    }

    public function test_設定していなければ外すは404(): void
    {
        $this->delete(route('trainee-trainer-photo.destroy', $this->trainee))->assertNotFound();
    }

    public function test_トレーニーを削除すると会員写真とトレーナー用の写真の両方のファイルが消える(): void
    {
        Storage::disk(Trainee::STORAGE_DISK)->put('trainees/202611/member.jpg', 'member');
        $this->trainee->forceFill(['photo_path' => 'trainees/202611/member.jpg'])->save();
        $this->setPhoto($this->makeMedia());
        $trainerKey = $this->trainee->fresh()->trainer_photo_path;
        $this->actingAsRole('admin');

        $this->delete(route('trainees.destroy', $this->trainee))->assertRedirect(route('clients.show', $this->client->id));

        $this->assertFalse(Trainee::query()->whereKey($this->trainee->id)->exists());
        Storage::disk(Trainee::STORAGE_DISK)->assertMissing('trainees/202611/member.jpg');
        Storage::disk(Trainee::STORAGE_DISK)->assertMissing($trainerKey);
    }

    public function test_トレーナー用の写真は配列とjsonに出さず空の判定に入る(): void
    {
        $this->trainee->forceFill(['trainer_photo_path' => 'trainee-trainer-photos/202611/x.jpg'])->save();
        $trainee = $this->trainee->fresh();

        $this->assertArrayNotHasKey('trainer_photo_path', $trainee->toArray());
        $this->assertStringNotContainsString('trainee-trainer-photos', $trainee->toJson());
        $this->assertContains('trainer_photo_path', Trainee::CONTENT_COLUMNS);
        // 会員側のフォームの一括代入では入らない
        $trainee->fill(['trainer_photo_path' => 'other.jpg']);
        $this->assertSame('trainee-trainer-photos/202611/x.jpg', $trainee->trainer_photo_path);
    }

    public function test_available_mediaはtypeを付けなければ今と同じ件数でtype_photoなら選べる写真だけ(): void
    {
        $photo = $this->makeMedia();
        $heicDone = $this->makeMedia(['mime_type' => 'image/heic', 'conversion_status' => MediaRecord::CONVERSION_DONE, 'display_path' => 'media/202611/d.jpg']);
        $this->makeMedia(['type' => MediaRecord::TYPE_VIDEO, 'mime_type' => 'video/mp4']);
        $this->makeMedia(['mime_type' => 'image/heic', 'conversion_status' => MediaRecord::CONVERSION_PENDING, 'display_path' => null]);
        $this->makeMedia(['mime_type' => 'image/heic', 'conversion_status' => MediaRecord::CONVERSION_ERROR, 'display_path' => null]);

        $all = $this->getJson('/api/training-records/available-media?trainer_id=all')->assertOk()->json();
        $this->assertSame(5, $all['meta']['total']);

        $photos = $this->getJson('/api/training-records/available-media?trainer_id=all&type=photo')->assertOk()->json();
        $this->assertSame(2, $photos['meta']['total']);
        $this->assertEqualsCanonicalizing([$photo->id, $heicDone->id], array_column($photos['data'], 'id'));

        $this->getJson('/api/training-records/available-media?type=video')->assertUnprocessable();
    }

    public function test_トレーニー詳細で4通りの写真の枠を出す(): void
    {
        $show = fn () => $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        // どちらもなし：写真なし・［写真を選ぶ］
        $html = $show();
        $this->assertStringContainsString('写真なし</span>', $html);
        $this->assertStringContainsString('>写真を選ぶ</button>', $html);
        $this->assertStringNotContainsString('trainee-show-photo-overlay"', $html);

        // 会員写真だけ：会員写真を大きく、重ねない
        Storage::disk(Trainee::STORAGE_DISK)->put('trainees/202611/member.jpg', 'member');
        $this->trainee->forceFill(['photo_path' => 'trainees/202611/member.jpg'])->save();
        $html = $show();
        $this->assertStringContainsString('alt="モモの写真"', $html);
        $this->assertStringNotContainsString('trainee-show-photo-overlay"', $html);
        $this->assertStringContainsString('>写真を選ぶ</button>', $html);

        // 両方：トレーナー用を大きく、会員写真を重ねる・［変更］［外す］
        $this->setPhoto($this->makeMedia());
        $html = $show();
        $this->assertStringContainsString('alt="モモのトレーナー用の写真"', $html);
        $this->assertStringContainsString('<div class="trainee-show-photo-overlay">', $html);
        $this->assertStringContainsString('alt="会員写真" title="会員写真"', $html);
        $this->assertStringContainsString('>変更</button>', $html);
        $this->assertStringContainsString('>外す</button>', $html);
        $this->assertStringContainsString('会員写真と、選んだもとのメディアはそのまま残ります', $html);

        // トレーナー用だけ：重ねない
        $this->trainee->forceFill(['photo_path' => null])->save();
        $html = $show();
        $this->assertStringContainsString('alt="モモのトレーナー用の写真"', $html);
        $this->assertStringNotContainsString('<div class="trainee-show-photo-overlay">', $html);
    }
}

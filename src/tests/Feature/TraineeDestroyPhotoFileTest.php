<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\Trainer;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * トレーニーの削除で、会員写真のファイルもストレージから消すことの確認（2026-11 追加。
 * 要件定義書 6-16-4・DB 設計書 D-0700「トレーニーの削除時」）。
 *
 * それまでは、トレーニーを削除しても photo_path のファイルが media ディスクに残っていた。
 * 行の削除が成功したあとにファイルを消す（ファイルの削除に失敗しても、トレーニーの削除は取り消さない）。
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 * テスト用 SQLite ではマイグレーション（MySQL 専用の CHECK 制約）が通らないため、
 * 既存のテストと同じく、確かめに必要な列だけでテーブルを作る。
 */
class TraineeDestroyPhotoFileTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Trainee::STORAGE_DISK);

        $this->createClientsTable();
        $this->createAccessLogsTable();
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->string('trainer_photo_path', 500)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => 'テスト', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    /** 会員写真のファイルを置き、そのキーを持つトレーニーを作る */
    private function makeTraineeWithPhoto(): Trainee
    {
        $path = 'trainees/202611/'.fake()->uuid().'.jpg';
        Storage::disk(Trainee::STORAGE_DISK)->put($path, 'jpeg-bytes');

        return Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'モモ', 'photo_path' => $path]);
    }

    public function test_写真のあるトレーニーを管理者が削除すると行とファイルの両方が消える(): void
    {
        $this->actingAsRole('admin');
        $trainee = $this->makeTraineeWithPhoto();
        // 別のトレーニーの写真は消えないこと（対象のキーだけを消す）
        $other = $this->makeTraineeWithPhoto();
        Storage::disk(Trainee::STORAGE_DISK)->assertExists($trainee->photo_path);

        $response = $this->delete(route('trainees.destroy', $trainee));

        $response->assertRedirect(route('clients.show', $this->client->id));
        $response->assertSessionHas('success', 'トレーニーを削除しました。');
        $this->assertFalse(Trainee::query()->whereKey($trainee->id)->exists());
        Storage::disk(Trainee::STORAGE_DISK)->assertMissing($trainee->photo_path);
        Storage::disk(Trainee::STORAGE_DISK)->assertExists($other->photo_path);
    }

    public function test_写真のないトレーニーの削除も今までどおり通る(): void
    {
        $this->actingAsRole('admin');
        $trainee = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'ハル']);

        $response = $this->delete(route('trainees.destroy', $trainee));

        $response->assertRedirect(route('clients.show', $this->client->id));
        $response->assertSessionHas('success', 'トレーニーを削除しました。');
        $this->assertFalse(Trainee::query()->whereKey($trainee->id)->exists());
    }

    public function test_ファイルの削除に失敗してもトレーニーの削除は取り消さない(): void
    {
        $this->actingAsRole('admin');
        $trainee = $this->makeTraineeWithPhoto();

        // media ディスクの削除が例外を投げる状況を作る（本番の media ディスクは throw=>true）
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->once()->andThrow(new \RuntimeException('storage down'));
        Storage::shouldReceive('disk')->with(Trainee::STORAGE_DISK)->andReturn($disk);

        $response = $this->delete(route('trainees.destroy', $trainee));

        $response->assertRedirect(route('clients.show', $this->client->id));
        $response->assertSessionHas('success', 'トレーニーを削除しました。');
        $this->assertFalse(Trainee::query()->whereKey($trainee->id)->exists());
    }

    public function test_一般トレーナーは削除できず行もファイルも残る(): void
    {
        $this->actingAsRole('staff');
        $trainee = $this->makeTraineeWithPhoto();

        $response = $this->delete(route('trainees.destroy', $trainee));

        $response->assertForbidden();
        $response->assertSee('管理者のみ削除できます。');
        $this->assertTrue(Trainee::query()->whereKey($trainee->id)->exists());
        Storage::disk(Trainee::STORAGE_DISK)->assertExists($trainee->photo_path);
    }
}

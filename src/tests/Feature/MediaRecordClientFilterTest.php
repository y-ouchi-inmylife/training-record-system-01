<?php

namespace Tests\Feature;

use App\Http\Controllers\MediaRecordController;
use App\Models\Client;
use App\Models\MediaRecord;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * メディア一覧（S-1302）の会員での絞り込み（2026-11 追加。設計書 S-1302「会員」・api-design.md GET /media-records）。
 *
 * - client_id を付けると、その会員のトレーニング記録にひも付いたメディアだけが出る
 * - 登録者の条件と AND（trainer_id が自分のままなら、ほかのトレーナーが登録したものは出ない）
 * - 数字でない・存在しない client_id は無視する（エラーにしない）
 * - 選んだ会員は、会員の欄に <option selected>（「内部ID 姓 名」）で出る
 * - ページ送りのリンクに client_id が引き継がれる
 *
 * マイグレーションは MySQL 専用の CHECK 制約を含むため、使う表だけをテストの中で作る（MediaRecordLinkedTraineesTest と同じ）。
 */
class MediaRecordClientFilterTest extends TestCase
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

        // 200 がログイン中のトレーナー、201 はほかのトレーナー
        DB::table('trainers')->insert([
            ['id' => 200, 'name' => 'テスト', 'role' => 'staff', 'display_order' => 1],
            ['id' => 201, 'name' => 'ほかの人', 'role' => 'staff', 'display_order' => 2],
        ]);
        $trainer = new Trainer(['name' => 'テスト', 'role' => 'staff']);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    // ===== ヘルパー =====

    private function makeClient(string $internalId, string $lastName, string $firstName): Client
    {
        return Client::query()->create(['internal_id' => $internalId, 'last_name' => $lastName, 'first_name' => $firstName]);
    }

    /** メディアを作る。作った順に新しくなる（一覧は登録日時の新しい順） */
    private function makeMedia(int $trainerId = 200): MediaRecord
    {
        $createdAt = now()->setDate(2026, 11, 1)->setTime(9, 0)->addMinutes(++$this->mediaSeq);

        return MediaRecord::forceCreate([
            'trainer_id' => $trainerId,
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

    private function link(MediaRecord $media, Client $client, string $trainingDate = '2026-11-01'): void
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
    }

    private function openIndex(array $query = []): TestResponse
    {
        return $this->get(route('media-records.index', $query))->assertOk();
    }

    /** 一覧に出たメディアの ID（並びのまま） */
    private function shownIds(TestResponse $response): array
    {
        return $response->viewData('mediaRecords')->getCollection()->pluck('id')->all();
    }

    // ===== 絞り込み =====

    public function test_会員を選ぶとその会員の記録にひも付いたメディアだけが出る(): void
    {
        $oouchi = $this->makeClient('1', '大内', '佳和');
        $yamada = $this->makeClient('2', '山田', '花子');
        $mine = $this->makeMedia();
        $this->link($mine, $oouchi);
        $both = $this->makeMedia();
        $this->link($both, $oouchi);
        $this->link($both, $yamada);
        $onlyYamada = $this->makeMedia();
        $this->link($onlyYamada, $yamada);
        $unlinked = $this->makeMedia();

        $response = $this->openIndex(['client_id' => $oouchi->id]);

        $this->assertSame([$both->id, $mine->id], $this->shownIds($response));
        $this->assertNotContains($onlyYamada->id, $this->shownIds($response));
        $this->assertNotContains($unlinked->id, $this->shownIds($response));
        // 同じ会員の記録が複数あっても 1 件だけ（JOIN で行を増やさない）
        $this->link($mine, $oouchi, '2026-11-02');
        $this->assertSame(2, $this->openIndex(['client_id' => $oouchi->id])->viewData('mediaRecords')->total());
    }

    public function test_会員を選ばなければ今までどおり登録者の条件だけで出る(): void
    {
        $oouchi = $this->makeClient('1', '大内', '佳和');
        $linked = $this->makeMedia();
        $this->link($linked, $oouchi);
        $unlinked = $this->makeMedia();

        $this->assertSame([$unlinked->id, $linked->id], $this->shownIds($this->openIndex()));
    }

    public function test_登録者の条件とかけ合わせて自分のままならほかのトレーナーのものは出ず全員なら出る(): void
    {
        $oouchi = $this->makeClient('1', '大内', '佳和');
        $mine = $this->makeMedia(200);
        $this->link($mine, $oouchi);
        $others = $this->makeMedia(201);
        $this->link($others, $oouchi);

        // trainer_id なし（＝ログイン中のトレーナー）
        $this->assertSame([$mine->id], $this->shownIds($this->openIndex(['client_id' => $oouchi->id])));
        // ほかのトレーナーを選ぶ
        $this->assertSame([$others->id], $this->shownIds($this->openIndex(['client_id' => $oouchi->id, 'trainer_id' => 201])));
        // 全員
        $this->assertSame([$others->id, $mine->id], $this->shownIds($this->openIndex(['client_id' => $oouchi->id, 'trainer_id' => 'all'])));
    }

    public function test_数字でない会員のidは無視しエラーにしない(): void
    {
        $oouchi = $this->makeClient('1', '大内', '佳和');
        $linked = $this->makeMedia();
        $this->link($linked, $oouchi);
        $unlinked = $this->makeMedia();

        foreach (['abc', '1a', '-1', '1.5', ''] as $value) {
            $response = $this->openIndex(['client_id' => $value]);
            $this->assertSame([$unlinked->id, $linked->id], $this->shownIds($response), "client_id={$value}");
            $this->assertNull($response->viewData('selectedClient'));
        }
        // 配列で来ても無視する
        $response = $this->get('/media-records?client_id[]=1')->assertOk();
        $this->assertSame([$unlinked->id, $linked->id], $this->shownIds($response));
    }

    public function test_存在しない会員のidは無視し欄も未選択にする(): void
    {
        $oouchi = $this->makeClient('1', '大内', '佳和');
        $linked = $this->makeMedia();
        $this->link($linked, $oouchi);
        $unlinked = $this->makeMedia();

        $response = $this->openIndex(['client_id' => 9999]);

        $this->assertSame([$unlinked->id, $linked->id], $this->shownIds($response));
        $this->assertNull($response->viewData('selectedClient'));
        $this->assertStringNotContainsString('selected>9999', $response->getContent());
        $this->assertMatchesRegularExpression('#<select id="client-filter" class="form-select">\s*<option value=""></option>\s*</select>#', $response->getContent());
    }

    // ===== 会員の欄 =====

    public function test_選んだ会員が会員の欄に選んだ状態で出る(): void
    {
        $oouchi = $this->makeClient('12', '大内', '佳和');

        $html = $this->openIndex(['client_id' => $oouchi->id])->getContent();

        $this->assertStringContainsString('<label for="client-filter" class="form-label mb-0 text-nowrap">会員:</label>', $html);
        $this->assertMatchesRegularExpression(
            '#<select id="client-filter" class="form-select">\s*<option value=""></option>\s*<option value="'.$oouchi->id.'" selected>12 大内 佳和</option>\s*</select>#u',
            $html
        );
    }

    public function test_会員の欄はselect2で候補のapiを使い未選択は全員で外せる(): void
    {
        $html = $this->openIndex()->getContent();

        $this->assertStringContainsString("theme: 'bootstrap-5',", $html);
        $this->assertStringContainsString("placeholder: '全員',", $html);
        $this->assertStringContainsString('allowClear: true,', $html);
        $this->assertStringContainsString('url: "\/api\/clients\/search",', $html);
        // 選んだら・外したら、page を消して client_id を差し替える（外したら消す）
        $this->assertStringContainsString("url.searchParams.set('client_id', value);", $html);
        $this->assertStringContainsString("url.searchParams.delete('client_id');", $html);
        // 狭い画面では折り返す
        $this->assertStringContainsString('<div class="d-flex flex-wrap align-items-center gap-2 mb-4">', $html);
    }

    // ===== ページ送り =====

    public function test_ページ送りのリンクに会員の条件が引き継がれる(): void
    {
        $oouchi = $this->makeClient('1', '大内', '佳和');
        // 1 ページ 24 件を超える数をひも付ける
        for ($i = 0; $i < MediaRecordController::INDEX_PER_PAGE + 1; $i++) {
            $this->link($this->makeMedia(), $oouchi);
        }

        $response = $this->openIndex(['client_id' => $oouchi->id, 'trainer_id' => 'all']);
        $paginator = $response->viewData('mediaRecords');

        $this->assertSame(MediaRecordController::INDEX_PER_PAGE + 1, $paginator->total());
        $nextUrl = $paginator->nextPageUrl();
        $this->assertStringContainsString('client_id='.$oouchi->id, $nextUrl);
        $this->assertStringContainsString('trainer_id=all', $nextUrl);
        $this->assertStringContainsString('page=2', $nextUrl);
        $this->assertStringContainsString(e($nextUrl), $response->getContent());
    }
}

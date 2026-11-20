<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MediaRecord;
use App\Models\Trainee;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\ComponentAttributeBag;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * メディアを選ぶモーダル（メディア追加 S-0401-M02 / S-0404-M01、トレーナー用写真の選択 S-0309-M02）の候補の API
 * （GET /api/training-records/available-media）と、モーダルの JS（2026-11 変更）。
 *
 * - 候補は、記録に紐づけ済みかどうかに関わらずすべて出す（training_record_id を付けても外さない）
 * - 画面の JS は training_record_id を送らない。二重の追加はフォームの mediaSelection.add が ID で防ぐ
 * - 候補の各メディアに linked_labels（「トレーニー名：会員名」を最大 3 件。メディア一覧と同じ文字列・並び）と
 *   linked_others_count（4 人目以降の人数）が入る。type=photo でも同じ。クエリの数はメディアの件数で増えない
 * - 2 つのモーダルは、肉球の <template> と行を作る関数を共通のパーシャル（media-records/_linked-clients-js）から読む
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

    /** 会員を作り、トレーニーを登録順に作る（名前 null は名前未登録） */
    private function makeClient(string $lastName, string $firstName, array $traineeNames = []): Client
    {
        $client = Client::query()->create(['internal_id' => (string) fake()->unique()->numberBetween(1, 9999), 'last_name' => $lastName, 'first_name' => $firstName]);
        foreach ($traineeNames as $name) {
            Trainee::query()->create(['client_id' => $client->id, 'name' => $name]);
        }

        return $client;
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

    /** 候補の API を呼び、id ごとのメディアの値を返す */
    private function candidates(string $query = ''): array
    {
        $json = $this->getJson('/api/training-records/available-media?'.$query)->assertOk()->json();

        return array_column($json['data'], null, 'id');
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

    // ===== トレーニー・会員（B） =====

    public function test_候補にひも付くトレーニー名と会員名が入る(): void
    {
        $one = $this->makeMedia();
        $this->link($one, $this->makeClient('大内', '佳和', ['アイ', 'アムロ']));

        $four = $this->makeMedia();
        $this->link($four, $this->makeClient('大内', '花子', ['モモ']), '2026-11-04');
        $this->link($four, $this->makeClient('山田', '太郎', [null]), '2026-11-03');
        $this->link($four, $this->makeClient('佐藤', '次郎'), '2026-11-02');
        $this->link($four, $this->makeClient('鈴木', '一郎', ['ソラ']), '2026-11-01');
        $this->link($four, $this->makeClient('田中', '三郎', ['ハナ']), '2026-10-31');

        $none = $this->makeMedia();

        $data = $this->candidates();

        $this->assertSame(['アイ、アムロ：大内 佳和'], $data[$one->id]['linked_labels']);
        $this->assertSame(0, $data[$one->id]['linked_others_count']);
        // 新しい順に 3 件まで。名前未登録・トレーニーなしの書き方もメディア一覧と同じ
        $this->assertSame(['モモ：大内 花子', '名前未登録：山田 太郎', '佐藤 次郎'], $data[$four->id]['linked_labels']);
        $this->assertSame(2, $data[$four->id]['linked_others_count']);
        $this->assertSame([], $data[$none->id]['linked_labels']);
        $this->assertSame(0, $data[$none->id]['linked_others_count']);

        // メディア一覧と同じメソッドの結果と一致する
        $media = MediaRecord::query()->withLinkedClients()->findOrFail($four->id);
        $this->assertSame($media->linkedClientLabels()->take(3)->all(), $data[$four->id]['linked_labels']);
        // 今の項目は変わらない
        foreach (['id', 'type', 'mime_type', 'conversion_status', 'thumbnail_status', 'thumbnail_url', 'display_title', 'original_filename', 'created_at', 'trainer_name'] as $key) {
            $this->assertArrayHasKey($key, $data[$one->id]);
        }
    }

    public function test_type_photoでも同じ項目が入る(): void
    {
        $photo = $this->makeMedia();
        $this->link($photo, $this->makeClient('大内', '佳和', ['アイ']));

        $data = $this->candidates('type=photo');

        $this->assertSame(['アイ：大内 佳和'], $data[$photo->id]['linked_labels']);
        $this->assertSame(0, $data[$photo->id]['linked_others_count']);
    }

    public function test_候補のクエリの数はメディアの件数で増えない(): void
    {
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/training-records/available-media?trainer_id=all')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $this->link($this->makeMedia(), $this->makeClient('大内', '佳和', ['アイ']));
        $one = $countQueries();

        for ($i = 0; $i < 5; $i++) {
            $m = $this->makeMedia();
            $this->link($m, $this->makeClient('会員'.$i, '太郎', ['犬'.$i]));
            $this->link($m, $this->makeClient('別会員'.$i, '花子'), '2026-10-0'.($i + 1));
        }
        $six = $countQueries();

        $this->assertSame($one, $six);
    }

    public function test_二つのモーダルは共通のパーシャルで肉球の複製とtextcontentで行を作る(): void
    {
        $form = file_get_contents(resource_path('views/training-records/_form.blade.php'));
        $photoModal = file_get_contents(resource_path('views/trainees/_trainer-photo-modal.blade.php'));
        foreach ([$form, $photoModal] as $source) {
            $this->assertStringContainsString("@include('media-records._linked-clients-js')", $source);
            $this->assertStringContainsString('body.appendChild(window.buildMediaLinkedClients(m));', $source);
        }

        $html = view('media-records._linked-clients-js')->render();

        // 肉球は <x-icon-paw /> と同じ形を <template> に 1 つ出し、JS で複製する
        $paw = trim(view('components.icon-paw', ['attributes' => new ComponentAttributeBag(['class' => 'text-primary flex-shrink-0'])])->render());
        $this->assertStringContainsString('<template id="mediaLinkedPawIconTemplate">'.$paw, $html);
        $this->assertStringContainsString('pawTemplate.content.firstElementChild.cloneNode(true)', $html);
        $this->assertStringNotContainsString('<ellipse', substr($html, strpos($html, '<script>')));
        // 名前は textContent で入れる（innerHTML は使わない）
        $this->assertStringContainsString('name.textContent = label;', $html);
        $this->assertStringNotContainsString('innerHTML', $html);
        // メディア一覧のカードと同じクラス・字下げ
        $this->assertStringContainsString("wrap.className = 'media-card-trainees mt-1 pt-1 border-top';", $html);
        $this->assertStringContainsString("row.className = 'd-flex align-items-center gap-1';", $html);
        $this->assertStringContainsString("others.className = 'media-card-others text-muted';", $html);
        $this->assertStringContainsString("others.style.paddingLeft = 'calc(1em + 0.25rem)';", $html);
        $this->assertStringContainsString("wrap.style.borderTop = 'var(--bs-border-width) solid transparent';", $html);
    }
}

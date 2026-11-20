<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MediaRecord;
use App\Models\Trainee;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * メディア一覧（S-1302）・メディア詳細（S-1302-M01）に、ひも付くトレーニーと会員を出す（2026-11 追加）。
 *
 * - 「会員名：トレーニー」の組み立て（Client::traineesWithNameLabel）：1 頭・複数頭・名前未登録・トレーニーなし
 * - 一覧のカード：会員ごとにまとめて 1 人 1 行・最大 3 行、新しい順、4 人以上は 3 行目に「ほか N名」、ひも付きなしは 1 行分の空欄
 * - 詳細：記録ごとに 1 行（トレーニング日の新しい順、同じ日は記録 ID の大きい順）
 * - 削除の確認文：ひも付きの件数を渡し、1 件以上なら 1 文足す
 * - 一覧のクエリの数がメディアの件数で増えない
 *
 * マイグレーションは MySQL 専用の CHECK 制約を含むため、使う表だけをテストの中で作る（MediaQueueStatusTest と同じ）。
 */
class MediaRecordLinkedTraineesTest extends TestCase
{
    use CreatesClientCountTables;

    private int $recordSeq = 0;

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

    // ===== 組み立てのヘルパー =====

    /** 会員を作り、トレーニーを登録順に作る（名前 null は名前未登録） */
    private function makeClient(string $lastName, ?string $firstName, array $traineeNames = []): Client
    {
        $client = Client::query()->create(['internal_id' => fake()->unique()->numerify('####'), 'last_name' => $lastName, 'first_name' => $firstName]);
        foreach ($traineeNames as $name) {
            Trainee::query()->create(['client_id' => $client->id, 'name' => $name]);
        }

        return $client;
    }

    private function makeMedia(string $createdAt = '2026-11-10 10:00:00'): MediaRecord
    {
        return MediaRecord::forceCreate([
            'trainer_id' => 200,
            'type' => MediaRecord::TYPE_PHOTO,
            'original_filename' => 'IMG_0001.JPG',
            'original_path' => 'media/202611/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'conversion_status' => MediaRecord::CONVERSION_NOT_REQUIRED,
            'thumbnail_status' => MediaRecord::THUMBNAIL_PENDING,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** 会員のトレーニング記録を作り、メディアにひも付ける。記録の ID を返す */
    private function link(MediaRecord $media, Client $client, string $trainingDate): int
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

    /** 一覧を開き、そのメディアのカードの「会員名：トレーニー」の部分の HTML を返す */
    private function cardTraineesHtml(string $html, MediaRecord $media): string
    {
        $start = strpos($html, 'data-media-id="'.$media->id.'"');
        $this->assertNotFalse($start, 'カードが見つからない');
        $section = substr($html, $start);
        $from = strpos($section, 'class="media-card-trainees');
        $this->assertNotFalse($from, '「会員名：トレーニー」の枠が見つからない');
        $section = substr($section, $from);
        // 枠の終わり：次のカード（または一覧の終わり）の手前まで
        $next = strpos($section, 'data-media-id=');
        $end = $next !== false ? $next : strpos($section, '<div class="mt-3">');

        return substr($section, 0, $end);
    }

    /** カードの「会員名：トレーニー」の行を、タグを外した文字列で返す */
    private function cardLines(string $traineesHtml): array
    {
        preg_match_all('#<div class="d-flex align-items-center gap-1">(.*?)</div>#su', $traineesHtml, $m);

        return array_map(fn ($line) => trim(strip_tags($line)), $m[1]);
    }

    private function openIndex(): TestResponse
    {
        return $this->get(route('media-records.index', ['trainer_id' => 'all']))->assertOk();
    }

    // ===== 表示文字列 =====

    public function test_トレーニーが1頭なら会員名とコロンと名前(): void
    {
        $client = $this->makeClient('大内', '佳和', ['アイ']);

        $this->assertSame('大内 佳和：アイ', $client->traineesWithNameLabel());
    }

    public function test_トレーニーが複数頭なら登録順に読点で区切る(): void
    {
        $client = $this->makeClient('大内', '佳和', ['アイ', 'アムロ']);

        $this->assertSame('大内 佳和：アイ、アムロ', $client->traineesWithNameLabel());
    }

    public function test_名前未登録のトレーニーは名前未登録と出す(): void
    {
        $client = $this->makeClient('大内', '佳和', [null]);

        $this->assertSame('大内 佳和：名前未登録', $client->traineesWithNameLabel());
    }

    public function test_トレーニーがいなければ会員名だけで名が空でも空白を残さない(): void
    {
        $this->assertSame('大内 佳和', $this->makeClient('大内', '佳和')->traineesWithNameLabel());
        $this->assertSame('大内', $this->makeClient('大内', null)->traineesWithNameLabel());
    }

    // ===== 一覧（S-1302）のカード =====

    public function test_会員1人なら1行(): void
    {
        $media = $this->makeMedia();
        $this->link($media, $this->makeClient('大内', '佳和', ['アイ']), '2026-11-01');

        $html = $this->cardTraineesHtml($this->openIndex()->getContent(), $media);

        $this->assertSame(['大内 佳和：アイ'], $this->cardLines($html));
        // 表示名と分ける区切り線と、行の先頭の人のアイコン（飾りなので aria-hidden）
        $this->assertStringContainsString('class="media-card-trainees mt-1 pt-1 border-top"', $html);
        $this->assertStringContainsString('<div class="d-flex align-items-center gap-1"><i class="bi bi-person text-muted flex-shrink-0" aria-hidden="true"></i><span class="text-truncate" style="min-width: 0;">大内 佳和：アイ</span></div>', $html);
        // 名前は本文色（時刻の text-muted を枠に付けない）
        $this->assertStringNotContainsString('media-card-trainees text-muted', $html);
        // ツールチップ・リンクは付けない
        $this->assertStringNotContainsString('title=', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function test_会員3人ならトレーニング日の新しい順に3行で同じ会員はまとめる(): void
    {
        $media = $this->makeMedia();
        $oouchi = $this->makeClient('大内', '佳和', ['アイ', 'アムロ']);
        $yamada = $this->makeClient('山田', '花子', ['モモ']);
        $sato = $this->makeClient('佐藤', '次郎', ['ハナ']);
        $this->link($media, $oouchi, '2026-10-01');
        $this->link($media, $yamada, '2026-11-05');
        $this->link($media, $sato, '2026-10-20');
        // 大内さんの新しい記録があれば、大内さんは 1 行のまま、その日付で並ぶ
        $this->link($media, $oouchi, '2026-10-25');

        $html = $this->cardTraineesHtml($this->openIndex()->getContent(), $media);

        $this->assertSame([
            '山田 花子：モモ',
            '大内 佳和：アイ、アムロ',
            '佐藤 次郎：ハナ',
        ], $this->cardLines($html));
        $this->assertStringNotContainsString('ほか', $html);
    }

    public function test_会員4人なら3行目にほか1名を付け名前の部分だけを省略する(): void
    {
        $media = $this->makeMedia();
        $this->link($media, $this->makeClient('大内', '佳和', ['アイ']), '2026-11-04');
        $this->link($media, $this->makeClient('山田', '花子', ['モモ']), '2026-11-03');
        $this->link($media, $this->makeClient('佐藤', '次郎', ['ハナ']), '2026-11-02');
        $this->link($media, $this->makeClient('鈴木', '一郎', ['ソラ']), '2026-11-01');

        $html = $this->cardTraineesHtml($this->openIndex()->getContent(), $media);

        $this->assertSame([
            '大内 佳和：アイ',
            '山田 花子：モモ',
            '佐藤 次郎：ハナほか1名',
        ], $this->cardLines($html));
        $this->assertStringNotContainsString('ソラ', $html);
        // アイコンと「ほか N名」は省略の外（flex-shrink-0）に置き、名前の部分だけを text-truncate にする
        $this->assertStringContainsString('<i class="bi bi-person text-muted flex-shrink-0" aria-hidden="true"></i><span class="text-truncate" style="min-width: 0;">佐藤 次郎：ハナ</span><span class="flex-shrink-0">ほか1名</span></div>', $html);
        $this->assertSame(3, substr_count($html, 'bi-person'));
    }

    public function test_ひも付きがなければ区切り線とアイコンを出さず区切り線の分も含めて1行分の高さを取る(): void
    {
        $media = $this->makeMedia();

        $html = $this->cardTraineesHtml($this->openIndex()->getContent(), $media);

        $this->assertSame([], $this->cardLines($html));
        $this->assertStringNotContainsString('border-top"', $html);
        $this->assertStringNotContainsString('bi-person', $html);
        // 同じ余白と同じ太さの透明な線で、ひも付きが 1 人のカードと高さをそろえる
        $this->assertStringContainsString('class="media-card-trainees mt-1 pt-1" style="border-top: var(--bs-border-width) solid transparent;" aria-hidden="true">&nbsp;</div>', $html);
    }

    // ===== 詳細（S-1302-M01） =====

    public function test_詳細は記録ごとに1行でトレーニング日の新しい順に並ぶ(): void
    {
        $media = $this->makeMedia();
        $oouchi = $this->makeClient('大内', '佳和', ['アイ']);
        $yamada = $this->makeClient('山田', '花子', ['モモ']);
        $this->link($media, $oouchi, '2026-10-04');
        $this->link($media, $yamada, '2026-11-01');
        $this->link($media, $oouchi, '2026-11-01');   // 同じ日：記録 ID の大きいほうが先
        $this->link($media, $oouchi, '2026-09-30');

        $response = $this->openIndex();
        $data = $response->viewData('mediaModalData')[$media->id];

        $this->assertSame([
            ['date' => '2026/11/01', 'label' => '大内 佳和：アイ'],
            ['date' => '2026/11/01', 'label' => '山田 花子：モモ'],
            ['date' => '2026/10/04', 'label' => '大内 佳和：アイ'],
            ['date' => '2026/09/30', 'label' => '大内 佳和：アイ'],
        ], $data['training_records']);
        $this->assertSame(4, $data['training_record_count']);

        // 項目と、日付と表示の間の全角空白
        $html = $response->getContent();
        $this->assertStringContainsString('<dt class="col-sm-3">トレーニング記録</dt>', $html);
        $this->assertStringContainsString('id="mediaMetaTrainingRecords"', $html);
        $this->assertStringContainsString("div.textContent = line.date + '　' + line.label;", $html);
    }

    public function test_詳細はひも付きがなければ空(): void
    {
        $media = $this->makeMedia();

        $data = $this->openIndex()->viewData('mediaModalData')[$media->id];

        $this->assertSame([], $data['training_records']);
        $this->assertSame(0, $data['training_record_count']);
    }

    public function test_詳細のデータには表示に要る値だけを渡しモデルを渡さない(): void
    {
        $media = $this->makeMedia();
        $this->link($media, $this->makeClient('大内', '佳和', ['アイ']), '2026-11-01');

        $data = $this->openIndex()->viewData('mediaModalData')[$media->id];

        $this->assertSame(['date', 'label'], array_keys($data['training_records'][0]));
        $this->assertIsString($data['training_records'][0]['label']);
    }

    // ===== 削除の確認文 =====

    public function test_削除の確認文はひも付きがあれば1文を足し0件なら今のまま(): void
    {
        $html = $this->openIndex()->getContent();

        // ひも付きの件数（training_record_count）が 1 件以上のときだけ、今の確認文の前に 1 文足す
        $this->assertStringContainsString(
            "let confirmMessage = 'このメディアを削除します。レコードとストレージ上のファイルがともに削除され、元に戻せません。よろしいですか?';",
            $html
        );
        $this->assertStringContainsString('const linkedCount = (mediaModalData[currentMediaId] || {}).training_record_count || 0;', $html);
        $this->assertStringContainsString('if (linkedCount > 0) {', $html);
        $this->assertStringContainsString(
            "confirmMessage = 'このメディアは ' + linkedCount + ' 件のトレーニング記録で使われています。削除すると記録からも消えます。\\n' + confirmMessage;",
            $html
        );
        $this->assertStringContainsString('if (!confirm(confirmMessage)) {', $html);
    }

    public function test_削除の確認文に使う件数は記録の件数(): void
    {
        $linked = $this->makeMedia('2026-11-10 10:00:00');
        $client = $this->makeClient('大内', '佳和', ['アイ']);
        $this->link($linked, $client, '2026-11-01');
        $this->link($linked, $client, '2026-11-02');
        $unlinked = $this->makeMedia('2026-11-10 11:00:00');

        $data = $this->openIndex()->viewData('mediaModalData');

        $this->assertSame(2, $data[$linked->id]['training_record_count']);
        $this->assertSame(0, $data[$unlinked->id]['training_record_count']);
    }

    // ===== クエリの数 =====

    public function test_一覧のクエリの数はメディアの件数で増えない(): void
    {
        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->openIndex();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        // 1 件のとき
        $media = $this->makeMedia();
        $this->link($media, $this->makeClient('大内', '佳和', ['アイ', 'アムロ']), '2026-11-01');
        $one = $countQueries();

        // 6 件（会員もトレーニーも別々）に増やしても同じ
        for ($i = 0; $i < 5; $i++) {
            $m = $this->makeMedia('2026-11-1'.$i.' 09:00:00');
            $this->link($m, $this->makeClient('会員'.$i, '太郎', ['犬'.$i]), '2026-11-0'.($i + 2));
            $this->link($m, $this->makeClient('別会員'.$i, '花子'), '2026-10-0'.($i + 2));
        }
        $six = $countQueries();

        $this->assertSame($one, $six);
    }
}

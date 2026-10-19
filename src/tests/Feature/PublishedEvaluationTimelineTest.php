<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\Trainer;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 公開済みの愛犬性格診断のアドバイスを、会員詳細（S-0305）とマイページ（S-1402）の記録の一覧に混ぜる（2026-10-18 追加。
 * 要件定義書 6-3-3・6-15-6・6-16-16）。会員の愛犬性格診断のアドバイス（S-1418。要件定義書 6-15-21）。
 *
 * - 並びは記録と同じ新しい順（アドバイスの日付・時刻は公開した日時。時刻のない記録はその日のいちばん後ろ）
 * - 下書き・公開を取り消したもの・ほかの会員のトレーニーのアドバイスは出さない
 * - 件数（「これまでの記録（N件）」「全 N 回」）は記録だけ
 * - トレーナー側の記録の一覧（S-0402）・ダッシュボードの最近の記録（S-0201）・記録数の推移（S-1101）には混ぜない
 * - S-1418 は自分のトレーニーの公開済みのアドバイスだけ（それ以外は 404）。グラフはその結果の時点
 */
class PublishedEvaluationTimelineTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    private int $traineeId;

    protected function setUp(): void
    {
        parent::setUp();
        // 「今日」を固定する（ダッシュボードの最近の記録は今日から 1 週間。記録の未来日の色も今日で決まる）
        Carbon::setTestNow('2026-10-20 12:00:00');

        $this->createClientsTable();
        $this->createClientCountTables();
        $this->createAccessLogsTable();
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->string('breed', 100)->nullable();
            $table->string('sex', 20)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->unsignedTinyInteger('birth_month')->nullable();
            $table->unsignedTinyInteger('birth_day')->nullable();
            $table->string('neuter_status', 20)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
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
        Schema::create('media_records', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('media_record_training_record', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('media_record_id');
            $table->unsignedBigInteger('training_record_id');
            $table->integer('sort_order')->default(0);
        });
        Schema::create('audio_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
        });
        Schema::create('client_email_registration_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->boolean('is_used')->default(false);
            $table->timestamp('expires_at')->nullable();
        });

        DB::table('trainers')->insert([
            ['id' => 200, 'name' => '佐藤', 'role' => 'staff'],
            ['id' => 201, 'name' => '村山', 'role' => 'staff'],
        ]);
        $this->client = $this->makeClient('0001', '山田');
        $this->traineeId = $this->trainee($this->client, 'アイ');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeClient(string $internalId, string $lastName): Client
    {
        return Client::query()->create([
            'internal_id' => $internalId, 'last_name' => $lastName, 'first_name' => '太郎', 'email' => $internalId.'@example.com',
        ]);
    }

    private function trainee(Client $client, ?string $name): int
    {
        return DB::table('trainees')->insertGetId(['client_id' => $client->id, 'name' => $name]);
    }

    private function actingAsTrainer(): void
    {
        $trainer = new Trainer(['name' => '佐藤', 'role' => 'staff']);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    /** 記録を入れる（時刻は省略できる） */
    private function record(string $date, ?string $time = null, ?Client $client = null, string $content = ''): int
    {
        return DB::table('training_records')->insertGetId([
            'client_id' => ($client ?? $this->client)->id, 'training_date' => $date, 'training_time' => $time, 'trainer1_id' => 200,
            'record_content' => $content === '' ? 'ノート '.$date.($time ? ' '.$time : '') : $content,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * 結果を入れる。$publishedAt を渡すと、その日時に公開済み（公開したトレーナーは村山）。$text だけなら下書き
     */
    private function addResult(string $answeredAt, ?string $text = null, ?string $publishedAt = null, ?int $traineeId = null, string $type = 'calm', string $score = '1.00'): TraineePersonalityDiagnosisResult
    {
        $attributes = [
            'trainee_id' => $traineeId ?? $this->traineeId, 'answered_at' => $answeredAt, 'question_version' => 'v1',
            'answers' => ['Q01' => 0], 'personality_type' => $type,
            'evaluation_text' => $text,
            'evaluation_published_at' => $publishedAt,
            'evaluation_published_by' => $publishedAt === null ? null : 201,
        ];
        foreach (['people', 'dogs', 'possessiveness', 'separation', 'fear', 'trainability', 'excitability'] as $category) {
            $attributes['score_'.$category] = $score;
        }

        return TraineePersonalityDiagnosisResult::query()->create($attributes);
    }

    private function clientShow(?Client $client = null): string
    {
        $this->actingAsTrainer();

        return $this->get(route('clients.show', $client ?? $this->client))->assertOk()->getContent();
    }

    private function mypage(?Client $client = null): string
    {
        return $this->actingAs($client ?? $this->client, 'client')->get(route('client-portal.dashboard'))->assertOk()->getContent();
    }

    /** 会員詳細の一覧の並び（'r{記録の id}' / 'e{結果の id}'） */
    private function clientShowOrder(string $html): array
    {
        preg_match_all('/data-timeline-(record|evaluation)="(\d+)"/', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($match) => ($match[1] === 'record' ? 'r' : 'e').$match[2], $m);
    }

    // ---- 会員詳細（S-0305） ----

    public function test_会員詳細は公開済みのアドバイスを記録と新しい順に混ぜる(): void
    {
        $r1 = $this->record('2026-10-10', '10:00:00');
        $r2 = $this->record('2026-10-14', '15:00:00');
        $r3 = $this->record('2026-10-14', null);
        $r4 = $this->record('2026-10-14', '09:00:00');
        $e1 = $this->addResult('2026-10-01 09:00:00', '前のアドバイス', '2026-10-12 10:00:00');
        $e2 = $this->addResult('2026-10-13 09:00:00', '同じ日のアドバイス', '2026-10-14 11:30:00');
        $e3 = $this->addResult('2026-10-15 09:00:00', 'いちばん新しいアドバイス', '2026-10-16 08:00:00');

        $html = $this->clientShow();

        // 同じ日（10/14）は時刻の新しい順、時刻のない記録はその日のいちばん後ろ
        $this->assertSame(
            ['e'.$e3->id, 'r'.$r2, 'e'.$e2->id, 'r'.$r4, 'r'.$r3, 'e'.$e1->id, 'r'.$r1],
            $this->clientShowOrder($html)
        );
    }

    public function test_会員詳細は日付と時刻が同じなら記録が先(): void
    {
        $r = $this->record('2026-10-14', '11:30:00');
        $e = $this->addResult('2026-10-13 09:00:00', 'アドバイス', '2026-10-14 11:30:00');

        $this->assertSame(['r'.$r, 'e'.$e->id], $this->clientShowOrder($this->clientShow()));
    }

    public function test_会員詳細のアドバイスの行の中身とリンク(): void
    {
        $e = $this->addResult('2026-10-13 09:00:00', "1行目\n2行目", '2026-10-14 11:30:00');

        $html = $this->clientShow();

        $href = route('trainee-personality-diagnosis-results.show', [$this->traineeId, $e->id]);
        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote($href, '/').'" class="record-block-link" data-timeline-evaluation="'.$e->id.'">\s*'
            .'<div class="record-block record-block--evaluation">\s*<div class="record-block__line1">\s*'
            .'<span class="fw-bold">2026\/10\/14<\/span>\s*'
            .'<span class="badge record-block__tag">愛犬性格診断（アイ）<\/span>\s*'
            .'<span><span class="text-muted">担当：<\/span>村山<\/span>\s*<\/div>\s*'
            .'<div class="small fw-bold mt-2 text-body">アドバイス<\/div>\s*'
            .'<div class="record-block__line3 record-block__clamp">1行目\n2行目<\/div>/u',
            $html
        );
        // 2026-10-19 変更：小見出しは「アドバイス」（その前は「評価文」）
        $this->assertStringNotContainsString('評価文', $html);
    }

    public function test_会員詳細のアドバイスの行は公開したトレーナーが削除されていれば担当を出さない(): void
    {
        $e = $this->addResult('2026-10-13 09:00:00', 'アドバイス', '2026-10-14 11:30:00');
        $e->update(['evaluation_published_by' => null]);

        $html = $this->clientShow();

        $block = substr($html, strpos($html, 'data-timeline-evaluation="'.$e->id.'"'), 600);
        $this->assertStringNotContainsString('担当：', substr($block, 0, strpos($block, '</a>')));
    }

    public function test_会員詳細は下書きと公開を取り消したものとほかの会員のアドバイスを出さない(): void
    {
        $this->record('2026-10-10');
        $this->addResult('2026-10-01 09:00:00', '下書きのアドバイス');
        $unpublished = $this->addResult('2026-10-02 09:00:00', '公開を取り消したアドバイス', '2026-10-05 10:00:00');
        $unpublished->update(['evaluation_published_at' => null, 'evaluation_published_by' => null]);
        $other = $this->makeClient('0002', '佐藤');
        $this->addResult('2026-10-03 09:00:00', 'ほかの会員のアドバイス', '2026-10-06 10:00:00', $this->trainee($other, 'ハナ'));

        $html = $this->clientShow();

        $this->assertStringNotContainsString('data-timeline-evaluation', $html);
        $this->assertStringNotContainsString('下書きのアドバイス', $html);
        $this->assertStringNotContainsString('公開を取り消したアドバイス', $html);
        $this->assertStringNotContainsString('ほかの会員のアドバイス', $html);
    }

    public function test_会員詳細の見出しの件数は記録だけ(): void
    {
        $this->record('2026-10-10');
        $this->record('2026-10-11');
        $this->addResult('2026-10-01 09:00:00', 'アドバイス', '2026-10-12 10:00:00');

        $html = $this->clientShow();

        $this->assertStringContainsString('<h6 class="mb-0" data-client-records-heading>これまでの記録（2件）</h6>', $html);
        $this->assertSame(3, count($this->clientShowOrder($html)));
    }

    public function test_会員詳細の記録の欄の見出しはこれまでの記録でボタンはトレーニング記録を登録(): void
    {
        $this->record('2026-10-10');
        $this->addResult('2026-10-01 09:00:00', 'アドバイス', '2026-10-12 10:00:00');

        $html = $this->clientShow();

        // 2026-10-18 変更：ユーザーレビューで、見出しを「これまでの記録」、ボタンを［トレーニング記録を登録］にした（アドバイスも並ぶため）
        $this->assertMatchesRegularExpression(
            '/<div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">\s*'
            .'<h6 class="mb-0" data-client-records-heading>これまでの記録（1件）<\/h6>\s*'
            .'<a href="'.preg_quote(route('training-records.create', ['client_id' => $this->client->id]), '/').'"\s*'
            .'class="btn btn-primary ms-auto">トレーニング記録を登録<\/a>/u',
            $html
        );
        $this->assertStringNotContainsString('>トレーニング記録（', $html);
        $this->assertStringNotContainsString('>新規登録</a>', $html);
    }

    public function test_会員詳細はアドバイスだけでも一覧に出し件数は0件(): void
    {
        $e = $this->addResult('2026-10-01 09:00:00', 'アドバイス', '2026-10-12 10:00:00');

        $html = $this->clientShow();

        $this->assertStringContainsString('<h6 class="mb-0" data-client-records-heading>これまでの記録（0件）</h6>', $html);
        $this->assertSame(['e'.$e->id], $this->clientShowOrder($html));
        $this->assertStringNotContainsString('トレーニング記録はありません', $html);
        // 記録がないときの文（2026-10-19 変更：「記録はありません」）も、アドバイスがあれば出さない
        $this->assertStringNotContainsString('記録はありません', $html);
    }

    public function test_会員詳細のアドバイスの札は名前がなければ名前未登録(): void
    {
        $id = $this->trainee($this->client, null);
        $this->addResult('2026-10-01 09:00:00', 'アドバイス', '2026-10-12 10:00:00', $id);

        $this->assertStringContainsString('愛犬性格診断（名前未登録）', $this->clientShow());
    }

    // ---- マイページ（S-1402） ----

    /** マイページのカードの並び（'r{記録の日付}' / 'e{結果の id}'）。最新のカードが先頭 */
    private function mypageOrder(string $html): array
    {
        preg_match_all('/<article class="c-session( c-session--hero)?"( data-session-evaluation="(\d+)")?>.*?<p class="visually-hidden">(\d+)年(\d+)月(\d+)日/su', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($match) => $match[3] !== '' ? 'e'.$match[3] : sprintf('r%04d-%02d-%02d', $match[4], $match[5], $match[6]), $m);
    }

    public function test_マイページは公開済みのアドバイスを記録と新しい順に混ぜる(): void
    {
        $this->record('2026-10-10', '10:00:00');
        $this->record('2026-10-14', '15:00:00');
        $this->record('2026-10-14', null);
        $e1 = $this->addResult('2026-10-01 09:00:00', '前のアドバイス', '2026-10-12 10:00:00');
        $e2 = $this->addResult('2026-10-13 09:00:00', '同じ日のアドバイス', '2026-10-14 11:30:00');

        $html = $this->mypage();

        $this->assertSame(['r2026-10-14', 'e'.$e2->id, 'r2026-10-14', 'e'.$e1->id, 'r2026-10-10'], $this->mypageOrder($html));
        // 同じ日の記録は時刻のあるほうが先（時刻のない記録はその日のいちばん後ろ）
        $this->assertTrue(strpos($html, 'ノート 2026-10-14 15:00:00') < strpos($html, '同じ日のアドバイス'));
        $this->assertTrue(strpos($html, '同じ日のアドバイス') < strpos($html, 'ノート 2026-10-14<'));
    }

    public function test_マイページの全N回は記録だけを数える(): void
    {
        $this->record('2026-10-10');
        $this->record('2026-10-11');
        $this->addResult('2026-10-01 09:00:00', 'アドバイス', '2026-10-12 10:00:00');
        $this->addResult('2026-10-02 09:00:00', 'アドバイス2', '2026-10-09 10:00:00');

        $html = $this->mypage();

        $this->assertMatchesRegularExpression('/全 <span class="num-tabular">2<\/span> 回/u', $html);
        $this->assertSame(4, count($this->mypageOrder($html)));
    }

    public function test_マイページのアドバイスのカードの中身とリンク(): void
    {
        $this->record('2026-10-20');
        $e = $this->addResult('2026-10-13 09:00:00', str_repeat('あ', 80), '2026-10-14 11:30:00');

        $html = $this->mypage();

        $card = $this->evaluationCard($html, $e->id);
        $this->assertStringStartsWith('<article class="c-session" data-session-evaluation="'.$e->id.'">', $card);
        $this->assertStringContainsString('<span class="c-date-month">10月</span>', $card);
        $this->assertStringContainsString('<span class="c-date-day num-tabular">14</span>', $card);
        $this->assertStringContainsString('<span class="c-date-weekday">（水）</span>', $card);
        $this->assertStringContainsString('<p class="visually-hidden">2026年10月14日（水）のアイちゃんの愛犬性格診断</p>', $card);
        $this->assertStringContainsString('<p class="c-session-tag mb-1" aria-hidden="true">愛犬性格診断</p>', $card);
        $this->assertStringContainsString('<p class="c-session-heading">アイちゃんの愛犬性格診断</p>', $card);
        // これまでの記録のカードは記録のノートと同じ切り方（Str::limit の 70。文字の幅で数えるので全角は 35 字）
        $this->assertStringContainsString('<p class="c-session-note">'.str_repeat('あ', 35).'…</p>', $card);
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('client-portal.personality-diagnosis-results.show', $e->id), '/').'" class="c-session-cta">\s*続きを読む <span aria-hidden="true">→<\/span>/u', $card);
        // 写真・動画のサムネイルはない
        $this->assertStringNotContainsString('c-session-media', $card);
    }

    public function test_マイページはアドバイスがいちばん新しければ最新のカードにする(): void
    {
        $this->record('2026-10-10');
        $e = $this->addResult('2026-10-13 09:00:00', str_repeat('い', 130), '2026-10-14 11:30:00');

        $html = $this->mypage();

        $latest = substr($html, strpos($html, 'id="c-latest-heading"'));
        $latest = substr($latest, 0, strpos($latest, '</section>'));
        $this->assertStringContainsString('<article class="c-session c-session--hero" data-session-evaluation="'.$e->id.'">', $latest);
        // 最新のカードは記録のノートと同じ切り方（Str::limit の 120。全角は 60 字）
        $this->assertStringContainsString('<p class="c-session-note">'.str_repeat('い', 60).'…</p>', $latest);
        $this->assertSame(['e'.$e->id, 'r2026-10-10'], $this->mypageOrder($html));
        $this->assertMatchesRegularExpression('/全 <span class="num-tabular">1<\/span> 回/u', $html);
    }

    public function test_マイページはアドバイスだけなら最新のカードに出し全N回は出さない(): void
    {
        $e = $this->addResult('2026-10-13 09:00:00', 'アドバイス', '2026-10-14 11:30:00');

        $html = $this->mypage();

        $this->assertStringContainsString('data-session-evaluation="'.$e->id.'"', $html);
        $this->assertStringNotContainsString('aria-label="空状態"', $html);
        $this->assertStringNotContainsString('id="c-past-heading"', $html);
        $this->assertStringNotContainsString('data-record-count', $html);
    }

    public function test_マイページはアドバイスが2件だけなら全N回は出さない(): void
    {
        $this->addResult('2026-10-13 09:00:00', 'アドバイス', '2026-10-14 11:30:00');
        $this->addResult('2026-10-01 09:00:00', 'アドバイス2', '2026-10-02 11:30:00');

        $html = $this->mypage();

        $this->assertStringContainsString('id="c-past-heading"', $html);
        $this->assertStringNotContainsString('data-record-count', $html);
    }

    public function test_マイページの記録のカードには札を出さない(): void
    {
        $this->record('2026-10-20');
        $this->record('2026-10-01');
        $e = $this->addResult('2026-10-13 09:00:00', 'アドバイス', '2026-10-14 11:30:00');

        $html = $this->mypage();

        $this->assertSame(1, substr_count($html, '<p class="c-session-tag'));
        $this->assertStringContainsString('c-session-tag', $this->evaluationCard($html, $e->id));
    }

    public function test_マイページは下書きと公開を取り消したものとほかの会員のアドバイスを出さない(): void
    {
        $this->record('2026-10-10');
        $this->addResult('2026-10-01 09:00:00', '下書きのアドバイス');
        $unpublished = $this->addResult('2026-10-02 09:00:00', '公開を取り消したアドバイス', '2026-10-05 10:00:00');
        $unpublished->update(['evaluation_published_at' => null, 'evaluation_published_by' => null]);
        $other = $this->makeClient('0002', '佐藤');
        $this->addResult('2026-10-03 09:00:00', 'ほかの会員のアドバイス', '2026-10-06 10:00:00', $this->trainee($other, 'ハナ'));

        $html = $this->mypage();

        $this->assertStringNotContainsString('data-session-evaluation', $html);
        $this->assertStringNotContainsString('下書きのアドバイス', $html);
        $this->assertStringNotContainsString('公開を取り消したアドバイス', $html);
        $this->assertStringNotContainsString('ほかの会員のアドバイス', $html);
    }

    public function test_マイページのアドバイスのカードは名前がなければ愛犬(): void
    {
        $id = $this->trainee($this->client, null);
        $e = $this->addResult('2026-10-01 09:00:00', 'アドバイス', '2026-10-12 10:00:00', $id);

        $card = $this->evaluationCard($this->mypage(), $e->id);

        $this->assertStringContainsString('<p class="c-session-heading">愛犬の愛犬性格診断</p>', $card);
    }

    private function evaluationCard(string $html, int $resultId): string
    {
        $start = strpos($html, '<article class="c-session'.(str_contains($html, 'c-session--hero" data-session-evaluation="'.$resultId.'"') ? ' c-session--hero' : '').'" data-session-evaluation="'.$resultId.'">');
        $this->assertNotFalse($start, 'アドバイスのカードが見つからない');

        return substr($html, $start, strpos($html, '</article>', $start) - $start);
    }

    // ---- 会員の愛犬性格診断のアドバイス（S-1418） ----

    private function evaluationPage(TraineePersonalityDiagnosisResult $result, ?Client $client = null)
    {
        return $this->actingAs($client ?? $this->client, 'client')
            ->get(route('client-portal.personality-diagnosis-results.show', $result->id));
    }

    public function test_アドバイスの画面は見出しとタイプとグラフとアドバイスを出す(): void
    {
        $this->addResult('2026-09-01 09:00:00', null, null, null, 'calm', '1.00');
        $e = $this->addResult('2026-10-13 09:00:00', "1行目\n\n3行目", '2026-10-14 11:30:00', null, 'calm', '2.00');

        $html = $this->evaluationPage($e)->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="c-detail-title">アイちゃんの愛犬性格診断（<span class="num-tabular">2026/10/13</span>）</h1>', $html);
        $this->assertMatchesRegularExpression('/data-evaluation-meta>\s*<div><span class="num-tabular">2026\/10\/14<\/span> 公開<\/div>\s*<div>村山<\/div>\s*<\/div>/u', $html);
        // タイプは「・」のあとでだけ折り返す
        $this->assertStringContainsString('data-evaluation-type>'.str_replace('・', '・<wbr>', e($e->personality_type_label)).'</p>', $html);
        $this->assertStringContainsString('<p class="eyebrow" id="c-evaluation-heading">トレーナーからのアドバイス</p>', $html);
        // 2026-10-19 変更：その前は「トレーナーからの評価」。「評価」という言葉は会員様の画面に出さない
        $this->assertStringNotContainsString('トレーナーからの評価', $html);
        $this->assertStringNotContainsString('評価', $html);
        $this->assertStringContainsString('<div class="c-detail-body" data-evaluation-text>1行目'."\n\n".'3行目</div>', $html);
        $this->assertStringContainsString('data-personality-radar-compact', $html);
        $this->assertStringContainsString('personality-radar', $html);
        // 戻るのはマイページ（S-1404 と同じ「HOME」）
        $this->assertMatchesRegularExpression('/<a href="'.preg_quote(route('client-portal.dashboard'), '/').'" class="c-detail-back">\s*HOME\s*<\/a>/u', $html);
        $radar = $this->radarData($html);
        $this->assertSame(['current', 'previous'], array_column($radar['series'], 'key'));
        $this->assertSame(['2026/10/13', '2026/09/01'], array_column($radar['series'], 'date'));
    }

    public function test_アドバイスの画面のグラフはあとから新しい結果を足しても変わらない(): void
    {
        $this->addResult('2026-09-01 09:00:00', null, null, null, 'calm', '1.00');
        $e = $this->addResult('2026-10-13 09:00:00', 'アドバイス', '2026-10-14 11:30:00', null, 'calm', '2.00');
        $before = $this->radarData($this->evaluationPage($e)->assertOk()->getContent());

        $this->addResult('2026-10-19 09:00:00', null, null, null, 'calm', '3.00');
        $after = $this->radarData($this->evaluationPage($e)->assertOk()->getContent());

        $this->assertSame($before, $after);
        $this->assertSame('2026/10/13', $after['series'][0]['date']);
        $this->assertSame('2.0', $after['series'][0]['values'][0]);
    }

    public function test_アドバイスの画面は点数の表とトレーナー側へのリンクと状態を出さない(): void
    {
        $e = $this->addResult('2026-10-13 09:00:00', 'アドバイス', '2026-10-14 11:30:00');

        $html = $this->evaluationPage($e)->assertOk()->getContent();

        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringNotContainsString('/trainees/', $html);
        $this->assertStringNotContainsString('公開済み', $html);
        $this->assertStringNotContainsString('50問', $html);
    }

    public function test_アドバイスの画面は公開済みのアドバイスを直すとすぐ出る(): void
    {
        $e = $this->addResult('2026-10-13 09:00:00', '前の文', '2026-10-14 11:30:00');
        $e->update(['evaluation_text' => '直した文']);

        $html = $this->evaluationPage($e)->assertOk()->getContent();

        $this->assertStringContainsString('直した文', $html);
        $this->assertStringNotContainsString('前の文', $html);
    }

    public function test_アドバイスの画面は公開したトレーナーが削除されていれば日だけ(): void
    {
        $e = $this->addResult('2026-10-13 09:00:00', 'アドバイス', '2026-10-14 11:30:00');
        $e->update(['evaluation_published_by' => null]);

        $html = $this->evaluationPage($e)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-evaluation-meta>\s*<div><span class="num-tabular">2026\/10\/14<\/span> 公開<\/div>\s*<\/div>/u', $html);
    }

    public function test_アドバイスの画面は下書きとなしと公開を取り消したものは404(): void
    {
        $draft = $this->addResult('2026-10-01 09:00:00', '下書き');
        $none = $this->addResult('2026-10-02 09:00:00');
        $unpublished = $this->addResult('2026-10-03 09:00:00', '公開を取り消した', '2026-10-05 10:00:00');
        $unpublished->update(['evaluation_published_at' => null, 'evaluation_published_by' => null]);

        $this->evaluationPage($draft)->assertNotFound();
        $this->evaluationPage($none)->assertNotFound();
        $this->evaluationPage($unpublished)->assertNotFound();
    }

    public function test_アドバイスの画面はほかの会員のアドバイスと存在しない結果は404(): void
    {
        $other = $this->makeClient('0002', '佐藤');
        $e = $this->addResult('2026-10-03 09:00:00', 'ほかの会員のアドバイス', '2026-10-06 10:00:00', $this->trainee($other, 'ハナ'));

        $this->evaluationPage($e)->assertNotFound();
        $this->actingAs($this->client, 'client')->get('/client-portal/personality-diagnosis-results/999')->assertNotFound();
        // 持ち主の会員なら見られる
        $this->evaluationPage($e, $other)->assertOk();
    }

    public function test_アドバイスの画面はログインしていなければログイン画面へ(): void
    {
        $e = $this->addResult('2026-10-13 09:00:00', 'アドバイス', '2026-10-14 11:30:00');

        $this->get(route('client-portal.personality-diagnosis-results.show', $e->id))
            ->assertRedirect(route('client-portal.login'));
    }

    public function test_アドバイスの画面はトレーナーのログインでは見られない(): void
    {
        $e = $this->addResult('2026-10-13 09:00:00', 'アドバイス', '2026-10-14 11:30:00');
        $this->actingAsTrainer();

        $this->get(route('client-portal.personality-diagnosis-results.show', $e->id))
            ->assertRedirect(route('client-portal.login'));
    }

    public function test_アドバイスの画面を見てもアクセスログに残さない(): void
    {
        $e = $this->addResult('2026-10-13 09:00:00', 'アドバイス', '2026-10-14 11:30:00');

        $this->evaluationPage($e)->assertOk();

        $this->assertSame(0, DB::table('access_logs')->count());
    }

    private function radarData(string $html): array
    {
        $this->assertSame(1, preg_match('/data-personality-radar="([^"]+)"/', $html, $m));

        return json_decode(html_entity_decode($m[1]), true);
    }

    // ---- 混ぜない画面 ----

    public function test_トレーナー側の記録の一覧にアドバイスを混ぜない(): void
    {
        $this->record('2026-10-14');
        $this->addResult('2026-10-13 09:00:00', '公開したアドバイス', '2026-10-15 11:30:00');
        $this->actingAsTrainer();

        $html = $this->get(route('training-records.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<td>2026/10/14</td>', $html);
        $this->assertStringNotContainsString('<td>2026/10/15</td>', $html);
        $this->assertStringNotContainsString('公開したアドバイス', $html);
    }

    public function test_ダッシュボードの最近の記録にアドバイスを混ぜない(): void
    {
        $this->record('2026-10-19');
        $this->addResult('2026-10-13 09:00:00', '公開したアドバイス', '2026-10-20 09:00:00');
        $this->actingAsTrainer();

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<td>2026/10/19</td>', $html);
        $this->assertStringNotContainsString('公開したアドバイス', $html);
        $this->assertStringNotContainsString('<td>2026/10/20</td>', $html);
    }

    public function test_記録数の推移にアドバイスを数えない(): void
    {
        $this->record('2026-10-14');
        $this->addResult('2026-10-13 09:00:00', '公開したアドバイス', '2026-10-15 11:30:00');
        $this->addResult('2026-10-01 09:00:00', '公開したアドバイス2', '2026-10-02 11:30:00');
        $this->actingAsTrainer();
        // 記録数の推移は MySQL の YEAR・MONTH を使うので、テストの SQLite に同じ関数を足す
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('YEAR', fn ($date) => (int) substr((string) $date, 0, 4), 1);
        $pdo->sqliteCreateFunction('MONTH', fn ($date) => (int) substr((string) $date, 5, 2), 1);

        $response = $this->get(route('statistics.clients', ['view_type' => 'calendar_year']))->assertOk();

        $this->assertSame(1, (int) collect($response->viewData('periodData'))->sum('total_records'));
        $this->assertSame(1, (int) collect($response->viewData('monthlyData'))->sum('total_records'));
    }

    // ---- スタッフ用メモは会員様の画面に出さない（2026-10-19 追加。設計書 S-0313「スタッフ用メモ」） ----

    public function test_スタッフ用メモはマイページにもアドバイスの画面にも会員詳細にも出さない(): void
    {
        $result = $this->addResult('2026-10-01 09:00:00', '公開したアドバイス', '2026-10-12 10:00:00');
        $result->update(['staff_memo' => 'スタッフだけのメモ']);

        $this->assertStringNotContainsString('スタッフだけのメモ', $this->mypage());
        $page = $this->evaluationPage($result)->assertOk()->getContent();
        $this->assertStringContainsString('公開したアドバイス', $page);
        $this->assertStringNotContainsString('スタッフだけのメモ', $page);
        // トレーナー側の会員詳細のタイムラインにも出さない（スタッフ用メモは S-0313 だけ）
        $this->assertStringNotContainsString('スタッフだけのメモ', $this->clientShow());
    }
}

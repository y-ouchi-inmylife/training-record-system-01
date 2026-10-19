<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Client;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\Trainer;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * トレーナー側のダッシュボード（S-0201）の「最近の愛犬性格診断」（2026-10-20 追加。要件定義書 6-2-1）。
 * アドバイスの公開し忘れがひと目で分かるように、最近のトレーニング記録の上に、すべての診断の結果を出す。
 *
 * - 期間で絞らない。5 件ずつ（DashboardController::DIAGNOSES_PER_PAGE）のページ送り（ページの番号は diagnoses_page。見た目は S-0402 と同じ）。
 *   2026-10-20 変更：その前は過去 30 日のものだけ。同じ日に、ページ送りを 20 件ずつから 5 件ずつにした
 * - 並びは回答日時の新しい順。アドバイスの状態は S-0313 と同じバッジ（列の名前は「アドバイス」）。
 *   未公開（なし・下書き）があれば、すべての診断の中の件数を見出しに出す
 * - 見せる範囲は最近のトレーニング記録と同じ（役割で変えない。「主担当のみ」は効かない）
 * 「今日」は 2026-10-20 12:00 に固定する。
 */
class DashboardRecentDiagnosesTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    private int $traineeId;

    protected function setUp(): void
    {
        parent::setUp();
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
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
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

        DB::table('trainers')->insert([
            ['id' => 200, 'name' => '佐藤', 'role' => 'staff'],
            ['id' => 201, 'name' => '村山', 'role' => 'admin'],
        ]);
        $this->client = $this->makeClient('0001', '山田', 201);
        $this->traineeId = $this->trainee($this->client, 'アイ');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeClient(string $internalId, string $lastName, ?int $primaryTrainerId = null): Client
    {
        $client = Client::query()->create([
            'internal_id' => $internalId, 'last_name' => $lastName, 'first_name' => '太郎', 'email' => $internalId.'@example.com',
        ]);
        DB::table('clients')->where('id', $client->id)->update(['primary_trainer_id' => $primaryTrainerId]);

        return $client->fresh();
    }

    private function trainee(Client $client, ?string $name): int
    {
        return DB::table('trainees')->insertGetId(['client_id' => $client->id, 'name' => $name]);
    }

    private function actingAsRole(string $role = 'staff'): void
    {
        $trainer = new Trainer(['name' => '佐藤', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    /** 結果を入れる。$status は none（アドバイスなし）・draft（下書き）・published（公開済み） */
    private function addResult(string $answeredAt, string $status = 'none', ?int $traineeId = null, ?string $type = 'guardian'): TraineePersonalityDiagnosisResult
    {
        $attributes = [
            'trainee_id' => $traineeId ?? $this->traineeId, 'answered_at' => $answeredAt, 'question_version' => 'v1',
            'answers' => ['Q01' => 0], 'personality_type' => $type,
            'evaluation_text' => $status === 'none' ? null : 'アドバイス',
            'evaluation_published_at' => $status === 'published' ? '2026-10-19 10:00:00' : null,
            'evaluation_published_by' => $status === 'published' ? 201 : null,
        ];
        foreach (['people', 'dogs', 'possessiveness', 'separation', 'fear', 'trainability', 'excitability'] as $category) {
            $attributes['score_'.$category] = $type === null ? null : '2.00';
        }

        return TraineePersonalityDiagnosisResult::query()->create($attributes);
    }

    private function dashboard(string $query = ''): string
    {
        return $this->get(route('dashboard').$query)->assertOk()->getContent();
    }

    /** 表の行（tbody の tr）ごとの HTML */
    private function rows(string $html): array
    {
        $this->assertSame(1, preg_match('/<table class="table table-hover" data-recent-diagnoses>(.*?)<\/table>/s', $html, $m));
        preg_match_all('/<tr style="cursor: pointer;".*?<\/tr>/s', $m[1], $rows);

        return $rows[0];
    }

    public function test_期間で絞らず31日以上前の診断も出す(): void
    {
        // 2026-10-20 変更：その前は今日を含む 30 日間（今日の 29 日前の 0:00 から）のものだけを出していた
        $today = $this->addResult('2026-10-20 08:00:00');
        $old = $this->addResult('2026-09-19 10:00:00');      // 31 日前
        $older = $this->addResult('2025-01-10 10:00:00');    // 1 年以上前
        $this->actingAsRole();

        $rows = $this->rows($this->dashboard());

        $this->assertCount(3, $rows);
        foreach ([[$today, '2026/10/20'], [$old, '2026/09/19'], [$older, '2025/01/10']] as $i => [$result, $date]) {
            $this->assertStringContainsString('<td class="text-nowrap">'.$date.'</td>', $rows[$i]);
            $this->assertStringContainsString($this->showUrl($result), $rows[$i]);
        }
    }

    /** 21 件以上を入れる（$count 件。いちばん新しいものから 1 日ずつ古くする） */
    private function addMany(int $count, string $status = 'published'): array
    {
        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = $this->addResult(Carbon::parse('2026-10-20 09:00:00')->subDays($i)->format('Y-m-d H:i:s'), $status);
        }

        return $results;
    }

    public function test_1ページの件数より1件多ければ2ページ目に1件(): void
    {
        // 5 件ずつ（2026-10-20 変更：その前は 20 件ずつ）。件数はコントローラーの定数 1 か所で決める
        $perPage = DashboardController::DIAGNOSES_PER_PAGE;
        $this->assertSame(5, $perPage);
        $results = $this->addMany($perPage + 1);
        $this->actingAsRole();

        $html = $this->dashboard();
        $rows = $this->rows($html);
        $this->assertCount($perPage, $rows);
        $this->assertStringContainsString($this->showUrl($results[0]), $rows[0]);
        $this->assertStringContainsString($this->showUrl($results[$perPage - 1]), $rows[$perPage - 1]);
        // ページの番号は diagnoses_page
        $this->assertStringContainsString('data-recent-diagnoses-pagination', $html);
        $this->assertStringContainsString('全6件中 1〜5件', $html);
        $this->assertStringContainsString(route('dashboard').'?diagnoses_page=2', $html);
        $this->assertStringNotContainsString('?page=2', $html);
        // ページ送りは表のあと、最近のトレーニング記録の前
        $this->assertLessThan(strpos($html, 'data-recent-diagnoses-pagination'), strpos($html, 'data-recent-diagnoses>'));
        $this->assertLessThan(strpos($html, '<h4 class="mb-0">最近のトレーニング記録</h4>'), strpos($html, 'data-recent-diagnoses-pagination'));

        $page2 = $this->dashboard('?diagnoses_page=2');
        $rows2 = $this->rows($page2);
        $this->assertCount(1, $rows2);
        $this->assertStringContainsString($this->showUrl($results[$perPage]), $rows2[0]);
        $this->assertStringContainsString('全6件中 6〜6件', $page2);
    }

    public function test_ページ送りはトレーニング記録一覧と同じ共通のビューで包まずに出す(): void
    {
        $this->addMany(DashboardController::DIAGNOSES_PER_PAGE + 1);
        $this->actingAsRole();

        $html = $this->dashboard();

        // S-0402 と同じ共通のページ送りのビュー（vendor/pagination/bootstrap-5）を、同じ件数・同じページで描いたものと同じ
        $paginator = new LengthAwarePaginator(range(1, 5), 6, 5, 1, ['path' => route('dashboard'), 'pageName' => 'diagnoses_page']);
        $this->assertMatchesRegularExpression(
            '/<div class="mb-4" data-recent-diagnoses-pagination>\s*'.preg_quote(trim((string) $paginator->links()), '/').'\s*<\/div>/u',
            $html
        );
        $this->assertStringContainsString('<nav class="d-flex flex-wrap justify-content-center align-items-center column-gap-3 row-gap-2 w-100 mb-3" aria-label="ページ送り">', $html);
        // 表とページ送りのあいだは S-0402 と同じ（表の囲みに mb-4 を付けない）
        $this->assertMatchesRegularExpression('/<div class="table-responsive ">\s*<table class="table table-hover" data-recent-diagnoses>/u', $html);
    }

    public function test_1ページの件数以下ならページ送りを出さない(): void
    {
        $this->addMany(DashboardController::DIAGNOSES_PER_PAGE);
        $this->actingAsRole();

        $html = $this->dashboard();

        $this->assertCount(DashboardController::DIAGNOSES_PER_PAGE, $this->rows($html));
        $this->assertStringNotContainsString('data-recent-diagnoses-pagination', $html);
        $this->assertStringNotContainsString('aria-label="ページ送り"', $html);
        // ページ送りがないときは、表の下に次の欄との空き（mb-4）
        $this->assertMatchesRegularExpression('/<div class="table-responsive mb-4">\s*<table class="table table-hover" data-recent-diagnoses>/u', $html);
    }

    public function test_ページを送ってもトレーニング記録の絞り込みは外れず絞り込みを変えてもページは戻らない(): void
    {
        $this->addMany(DashboardController::DIAGNOSES_PER_PAGE + 1);
        $this->actingAsRole();

        // 絞り込み（主担当のみ）のままページを送る：ページ送りのリンクに recent が付き、絞り込みは「主担当のみ」のまま
        $html = $this->dashboard('?recent=primary');
        $this->assertStringContainsString(route('dashboard').'?recent=primary&amp;diagnoses_page=2', $html);
        $page2 = $this->dashboard('?recent=primary&diagnoses_page=2');
        $this->assertCount(1, $this->rows($page2));
        $this->assertStringContainsString('<option value="primary" selected>', $page2);
        // 2 ページ目で絞り込みを変えるとき、フォームは今のページも送る（絞り込みのセレクトの隣の hidden）
        $this->assertMatchesRegularExpression('/<select name="recent".*?<\/select>\s*(?:\{\{--.*?--\}\}\s*)?<input type="hidden" name="diagnoses_page" value="2">\s*<\/form>/su', $page2);
        // 1 ページ目では送らない
        $this->assertStringNotContainsString('name="diagnoses_page"', $html);
    }

    public function test_見出しの未公開の件数はほかのページにある分も数える(): void
    {
        // 1 ページ目の分は公開済み、2 ページ目の 1 件だけ下書き
        $this->addMany(DashboardController::DIAGNOSES_PER_PAGE, 'published');
        $this->addResult('2026-01-01 09:00:00', 'draft');
        $this->actingAsRole();

        $html = $this->dashboard();

        $this->assertCount(DashboardController::DIAGNOSES_PER_PAGE, $this->rows($html));
        $this->assertStringNotContainsString('下書き</span>', implode('', $this->rows($html)));
        $this->assertStringContainsString('<h4 class="mb-0" data-recent-diagnoses-heading>最近の愛犬性格診断<span class="text-nowrap" data-unpublished-diagnosis-count>（未公開 1件）</span></h4>', $html);
        // 2 ページ目でも同じ（すべての診断の中の件数）
        $this->assertStringContainsString('（未公開 1件）', $this->dashboard('?diagnoses_page=2'));
    }

    private function showUrl(TraineePersonalityDiagnosisResult $result): string
    {
        return route('trainee-personality-diagnosis-results.show', [$result->trainee_id, $result->id]);
    }

    public function test_並びは回答日時の新しい順で列は回答日と会員のリンクとトレーニーとタイプとアドバイス(): void
    {
        $other = $this->makeClient('0002', '柏原');
        $noName = $this->trainee($other, null);
        $older = $this->addResult('2026-10-15 09:00:00', 'published');
        $newer = $this->addResult('2026-10-19 09:00:00', 'draft', $noName, null);
        $middle = $this->addResult('2026-10-18 09:00:00');
        $this->actingAsRole();

        $html = $this->dashboard();
        $rows = $this->rows($html);

        // 列の見出し
        // 列の見出し（狭い幅でも折り返さず、表を横にスクロールする）
        $this->assertMatchesRegularExpression('/<thead class="table-light">\s*<tr>\s*<th class="text-nowrap">回答日<\/th>\s*<th class="text-nowrap">会員<\/th>\s*<th class="text-nowrap">トレーニー<\/th>\s*<th class="text-nowrap">タイプ<\/th>\s*<th class="text-nowrap">アドバイス<\/th>\s*<\/tr>\s*<\/thead>/u', $html);
        // 列の名前は「アドバイス」（2026-10-20 変更：その前は「状態」）
        $this->assertStringNotContainsString('>状態</th>', $html);
        $this->assertStringContainsString('<div class="table-responsive mb-4">'."\n".'            <table class="table table-hover" data-recent-diagnoses>', $html);
        // 新しい順
        $this->assertCount(3, $rows);
        foreach ([$newer, $middle, $older] as $i => $result) {
            $this->assertStringContainsString('onclick="location.href=\''.$this->showUrl($result).'\'"', $rows[$i]);
        }
        // 1 行目：回答日・会員（S-0305 へのリンク。押しても行のリンクは動かない）・名前のないトレーニーは「名前未登録」・判定なし・下書き
        $this->assertMatchesRegularExpression(
            '/<td class="text-nowrap">2026\/10\/19<\/td>\s*'
            .'<td class="text-nowrap"><a href="'.preg_quote(route('clients.show', $other), '/').'" onclick="event.stopPropagation\(\);">柏原 太郎<\/a><\/td>\s*'
            .'<td class="text-nowrap">名前未登録<\/td>\s*'
            .'<td style="word-break: keep-all;">判定なし<\/td>\s*'
            .'<td class="text-nowrap"><span class="badge bg-warning text-dark">下書き<\/span><\/td>/u',
            $rows[0]
        );
        // 2 行目：トレーニーの名前・タイプ（「・」のあとでだけ折り返す）
        $type = TraineePersonalityDiagnosisResult::query()->find($middle->id)->personality_type_label;
        $this->assertStringContainsString('<td class="text-nowrap"><a href="'.route('clients.show', $this->client).'" onclick="event.stopPropagation();">山田 太郎</a></td>', $rows[1]);
        $this->assertStringContainsString('<td class="text-nowrap">アイ</td>', $rows[1]);
        $this->assertStringContainsString('<td style="word-break: keep-all;">'.str_replace('・', '・<wbr>', e($type)).'</td>', $rows[1]);
    }

    public function test_状態のバッジはS_0313と同じ文と色(): void
    {
        $none = $this->addResult('2026-10-19 09:00:00', 'none');
        $draft = $this->addResult('2026-10-18 09:00:00', 'draft');
        $published = $this->addResult('2026-10-17 09:00:00', 'published');
        $this->actingAsRole();

        $rows = $this->rows($this->dashboard());

        $expected = [
            [$none, 'bg-secondary', 'なし'],
            [$draft, 'bg-warning text-dark', '下書き'],
            [$published, 'bg-success', '公開済み'],
        ];
        foreach ($expected as $i => [$result, $class, $label]) {
            $fresh = $result->fresh();
            // S-0313 のバッジと同じ判定（モデルの属性）
            $this->assertSame($class, $fresh->evaluation_status_badge_class);
            $this->assertSame($label, $fresh->evaluation_status_label);
            $this->assertStringContainsString('<span class="badge '.$class.'">'.$label.'</span>', $rows[$i]);
            // S-0313 の画面に出るバッジと同じ
            $this->assertStringContainsString('<span class="badge '.$class.'" data-evaluation-status>'.$label.'</span>', $this->get($this->showUrl($result))->assertOk()->getContent());
        }
    }

    public function test_見出しは未公開があれば件数を出し0件なら出さない(): void
    {
        $this->addResult('2026-10-19 09:00:00', 'none');
        $this->addResult('2026-10-18 09:00:00', 'draft');
        $this->addResult('2026-10-17 09:00:00', 'published');
        $this->addResult('2025-09-01 09:00:00', 'draft'); // 古い診断も数える（2026-10-20 変更：その前は過去 30 日の外は数えなかった）
        $this->actingAsRole();

        // 「（未公開 n件）」は途中で折り返さない
        $this->assertStringContainsString('<h4 class="mb-0" data-recent-diagnoses-heading>最近の愛犬性格診断<span class="text-nowrap" data-unpublished-diagnosis-count>（未公開 3件）</span></h4>', $this->dashboard());

        TraineePersonalityDiagnosisResult::query()->update(['evaluation_text' => '公開した文', 'evaluation_published_at' => '2026-10-19 10:00:00']);
        $html = $this->dashboard();
        $this->assertStringContainsString('<h4 class="mb-0" data-recent-diagnoses-heading>最近の愛犬性格診断</h4>', $html);
        $this->assertStringNotContainsString('未公開', $html);
    }

    public function test_0件のときは表の代わりにありませんの文(): void
    {
        $this->actingAsRole();

        $html = $this->dashboard();

        // 2026-10-20 変更：その前は「過去30日の愛犬性格診断はありません。」
        $this->assertMatchesRegularExpression('/<div class="alert alert-secondary mb-4" data-recent-diagnoses-empty>\s*愛犬性格診断はありません。\s*<\/div>/u', $html);
        $this->assertStringNotContainsString('data-recent-diagnoses>', $html);
        $this->assertStringNotContainsString('data-recent-diagnoses-pagination', $html);
        $this->assertStringContainsString('<h4 class="mb-0" data-recent-diagnoses-heading>最近の愛犬性格診断</h4>', $html);
    }

    public function test_見せる範囲は最近のトレーニング記録と同じで役割で変えず主担当のみの絞り込みは効かない(): void
    {
        // 主担当がほかのトレーナー（村山）の会員と、主担当のない会員
        $other = $this->makeClient('0002', '柏原');
        $this->addResult('2026-10-19 09:00:00', 'draft');
        $this->addResult('2026-10-18 09:00:00', 'none', $this->trainee($other, 'まま'));

        foreach (['staff', 'admin'] as $role) {
            $this->actingAsRole($role);
            $this->assertCount(2, $this->rows($this->dashboard()), $role);
            // 最近のトレーニング記録の「主担当のみ」にしても、この表は変わらない
            $html = $this->dashboard('?recent=primary');
            $this->assertCount(2, $this->rows($html), $role);
            $this->assertStringContainsString('<option value="primary" selected>', $html);
        }
    }

    public function test_問い合わせの数は行の数で増えない(): void
    {
        $this->actingAsRole();
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->dashboard();
            $queries = collect(DB::getQueryLog())->pluck('query')
                ->filter(fn (string $sql) => str_contains($sql, 'trainee_personality_diagnosis_results') || str_contains($sql, '"trainees"') || str_contains($sql, '"clients"'));
            DB::disableQueryLog();

            return $queries->count();
        };

        $this->addResult('2026-10-19 09:00:00');
        $one = $count();
        foreach (['0002', '0003', '0004', '0005'] as $i => $internalId) {
            $this->addResult('2026-10-1'.$i.' 09:00:00', 'draft', $this->trainee($this->makeClient($internalId, '会員'.$i), '犬'.$i));
        }
        $five = $count();

        $this->assertCount(5, $this->rows($this->dashboard()));
        // 結果・トレーニー・会員の 3 つを、行の数によらず 1 回ずつ（ほかの欄の問い合わせも同じ数え方で数える）
        $this->assertGreaterThanOrEqual(3, $one);
        $this->assertSame($one, $five);
    }

    public function test_表は最近のトレーニング記録の上(): void
    {
        $this->addResult('2026-10-19 09:00:00');
        $this->actingAsRole();

        $html = $this->dashboard();

        $this->assertLessThan(strpos($html, '<h4 class="mb-0">最近のトレーニング記録</h4>'), strpos($html, 'data-recent-diagnoses-heading'));
        $this->assertLessThan(strpos($html, '<h4 class="mb-0">最近のトレーニング記録</h4>'), strpos($html, 'data-recent-diagnoses>'));
        // ウェルカムメッセージの下
        $this->assertLessThan(strpos($html, 'data-recent-diagnoses-heading'), strpos($html, 'ようこそ、'));
        // 最近のトレーニング記録は今のまま
        $this->assertStringContainsString('過去1週間のトレーニング記録はありません。', $html);
    }
}

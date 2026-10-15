<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\Trainer;
use App\Support\PersonalityDiagnosisQuestions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * トレーニー詳細（S-0309）の性格診断の欄（2026-10 追加。設計書 S-0309「性格診断の欄」）。
 *
 * - 暮らしの様子の欄の下に、いつも出す（id="personality-diagnosis"・scroll-margin-top）。結果がなければ「（未回答）」だけ
 * - 上にレーダーチャート 1 つ、下にすべての回の結果の表（古い順。回答日・タイプ・7 カテゴリ・［50問の回答］）
 * - 50 問の回答は 1 回ごとのモーダル（2026-10 変更：当初は回ごとに見出しの行・グラフ・点数の表を並べ、開閉していた）
 *
 * 点数は固定データ（tests/fixtures/personality-diagnosis-cases.json）を結果として入れ、表示の値が固定データの display と
 * 一致することを確かめる。
 */
class TraineeShowPersonalityDiagnosisTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createAccessLogsTable();
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
            $table->text('note')->nullable();
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
            $table->timestamps();
        });
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $trainer = new Trainer(['name' => 'テスト', 'role' => 'staff']);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田']);
        $this->trainee = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'モモ']);
    }

    private function fixtureCase(string $name): array
    {
        $json = json_decode(file_get_contents(__DIR__.'/../fixtures/personality-diagnosis-cases.json'), true, 512, JSON_THROW_ON_ERROR);

        return collect($json['cases'])->firstWhere('name', $name);
    }

    /** 固定データの 1 件を、期待値の保存の値・タイプで結果として入れる */
    private function addResult(string $caseName, string $answeredAt, ?Trainee $trainee = null): TraineePersonalityDiagnosisResult
    {
        $case = $this->fixtureCase($caseName);
        $attributes = [
            'trainee_id' => ($trainee ?? $this->trainee)->id,
            'answered_at' => $answeredAt,
            'question_version' => 'v1',
            'answers' => $case['answers'],
            'personality_type' => $case['expected']['type'],
        ];
        foreach ($case['expected']['scores'] as $category => $score) {
            $attributes['score_'.$category] = $score;
        }

        return TraineePersonalityDiagnosisResult::query()->create($attributes);
    }

    private function page(): string
    {
        return $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();
    }

    /** 性格診断の欄（カードの始まりから、50 問の回答のモーダル・計測値のモーダルの手前まで） */
    private function card(string $html): string
    {
        $start = strpos($html, '<div class="card mb-3" id="personality-diagnosis"');
        $this->assertNotFalse($start);
        $ends = array_filter([
            strpos($html, 'data-personality-diagnosis-answers', $start),
            strpos($html, 'id="measurementModal-', $start),
        ]);
        $end = min($ends);

        return substr($html, $start, $end - $start);
    }

    /** 結果の表の行（回答日 → [タイプ, 7 カテゴリの値…]）。表の上から順 */
    private function tableRows(string $html): array
    {
        preg_match_all('/<tr>\s*<th scope="row" class="fw-normal text-nowrap">([0-9\/]+)<\/th>\s*<td class="text-nowrap">([^<]+)<\/td>((?:\s*<td class="text-end">[0-9.]+<\/td>){7})/u', $html, $m, PREG_SET_ORDER);

        $rows = [];
        foreach ($m as $row) {
            preg_match_all('/>([0-9.]+)</', $row[3], $values);
            $rows[$row[1]] = array_merge([$row[2]], $values[1]);
        }

        return $rows;
    }

    /** 1 回分のモーダル */
    private function modal(string $html, TraineePersonalityDiagnosisResult $result): string
    {
        $start = strpos($html, '<div class="modal fade" id="personality-diagnosis-answers-'.$result->id.'"');
        $this->assertNotFalse($start);
        $end = strpos($html, '<div class="modal fade"', $start + 10);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    private function expectedRow(string $caseName): array
    {
        $case = $this->fixtureCase($caseName);
        $row = [PersonalityDiagnosisQuestions::types()[$case['expected']['type']]];
        foreach (array_keys(PersonalityDiagnosisQuestions::categories()) as $category) {
            $row[] = $case['expected']['display'][$category];
        }

        return $row;
    }

    public function test_暮らしの様子の欄の後ろにidとscroll_margin_topを付けて出す(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('<div class="card mb-3" id="personality-diagnosis" style="scroll-margin-top: 4.5rem;">', $html);
        $this->assertGreaterThan(strpos($html, 'id="intake-survey"'), strpos($html, 'id="personality-diagnosis"'));
        $this->assertMatchesRegularExpression('/<div class="card-header">\s*<h6 class="mb-0">性格診断<\/h6>\s*<\/div>/u', $this->card($html));
    }

    public function test_結果がなければ未回答だけで表もグラフもない(): void
    {
        $html = $this->page();
        $card = $this->card($html);

        $this->assertMatchesRegularExpression('/<div class="card-body">\s*<p class="mb-0 text-muted">（未回答）<\/p>\s*<\/div>/u', $card);
        $this->assertStringNotContainsString('<table', $card);
        $this->assertStringNotContainsString('<canvas', $card);
        $this->assertStringNotContainsString('data-personality-diagnosis-answers', $html);
    }

    public function test_結果の表は古い順ですべての回を1つの表に出す(): void
    {
        $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->addResult('past-3', '2025-12-01 10:00:00');
        $this->addResult('past-2', '2026-03-10 11:00:00');

        $card = $this->card($this->page());

        $this->assertSame([
            '2025/12/01' => $this->expectedRow('past-3'),
            '2026/03/10' => $this->expectedRow('past-2'),
            '2026/06/01' => $this->expectedRow('past-1'),
        ], $this->tableRows($card));
        // 表は 1 つ。列の見出し（th scope="col"）は回答日・タイプ・7 カテゴリ・ボタンの列
        $this->assertSame(1, substr_count($card, '<table'));
        $this->assertStringContainsString('<div class="table-responsive position-relative">', $card);
        preg_match_all('/<th scope="col"[^>]*>(.*?)<\/th>/u', $card, $heads);
        $this->assertSame(
            array_merge(['回答日', 'タイプ'], array_values(PersonalityDiagnosisQuestions::categories()), ['<span class="visually-hidden">50問の回答</span>']),
            $heads[1]
        );
        // 回ごとの開閉はない
        $this->assertStringNotContainsString('aria-expanded', $card);
        $this->assertStringNotContainsString('data-bs-toggle="collapse"', $card);
    }

    public function test_表示の値は保存の値から四捨五入し固定データのdisplayと一致する(): void
    {
        foreach (json_decode(file_get_contents(__DIR__.'/../fixtures/personality-diagnosis-cases.json'), true)['cases'] as $case) {
            $result = $this->addResult($case['name'], '2026-10-15 09:00:00');

            $this->assertSame($case['expected']['display'], $result->fresh()->displayScores(), $case['name']);
        }
    }

    public function test_各行の50問の回答のボタンがその回のモーダルを開く(): void
    {
        $old = $this->addResult('past-2', '2026-03-10 11:00:00');
        $new = $this->addResult('past-1', '2026-06-01 09:00:00');

        $card = $this->card($this->page());

        foreach ([[$old, '2026/03/10'], [$new, '2026/06/01']] as [$result, $date]) {
            $this->assertMatchesRegularExpression(
                '/<th scope="row" class="fw-normal text-nowrap">'.preg_quote($date, '/').'<\/th>[\s\S]*?'
                .'<button type="button" class="btn btn-sm btn-outline-primary text-nowrap"\s*'
                .'data-bs-toggle="modal" data-bs-target="#personality-diagnosis-answers-'.$result->id.'"\s*'
                .'aria-label="'.preg_quote($date, '/').'の50問の回答">50問の回答<\/button>/u',
                $card
            );
        }
    }

    public function test_モーダルにその回の50問の回答をカテゴリごとに出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $answers = $this->fixtureCase('past-1')['answers'];

        $modal = $this->modal($this->page(), $result);

        $this->assertStringContainsString('<div class="modal-dialog modal-dialog-scrollable modal-lg">', $modal);
        $this->assertMatchesRegularExpression('/<h5 class="modal-title" id="personality-diagnosis-answers-'.$result->id.'-label">50問の回答（2026\/06\/01）<\/h5>/u', $modal);
        foreach (PersonalityDiagnosisQuestions::categories() as $label) {
            $this->assertStringContainsString('>'.$label.'</p>', $modal);
        }
        $this->assertSame(50, substr_count($modal, '<dt class="col-sm-8 fw-normal">'));
        // 数字の答えと「未経験」（past-1 の Q01 は na、Q02 は 4）
        $this->assertSame('na', $answers['Q01']);
        $this->assertSame(4, $answers['Q02']);
        $this->assertMatchesRegularExpression('/<dt class="col-sm-8 fw-normal">①知らない人が家に入ってきた時に吠える・唸る<\/dt>\s*<dd class="col-sm-4 mb-1">未経験<\/dd>/u', $modal);
        $this->assertMatchesRegularExpression('/<dt class="col-sm-8 fw-normal">②窓の外を誰かが通りかかっただけで、敏感に反応して吠える<\/dt>\s*<dd class="col-sm-4 mb-1">4：常にある<\/dd>/u', $modal);
    }

    public function test_別のトレーニーの結果は出さない(): void
    {
        $other = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'ハル']);
        $this->addResult('past-3', '2026-10-15 09:00:00', $other);

        $html = $this->page();

        $this->assertStringContainsString('（未回答）', $this->card($html));
        $this->assertStringNotContainsString('聡明・スマート優等生タイプ', $html);
        $this->assertStringNotContainsString('data-personality-diagnosis-answers', $html);
    }

    // ---- レーダーチャート（1 つ。今回・前回・初回を重ねる） ----

    /** 欄のレーダーチャートの canvas の data-personality-radar（JSON を戻したもの） */
    private function radarData(string $html): array
    {
        $this->assertSame(1, preg_match_all('/<canvas id="personality-diagnosis-radar" role="img" aria-label="レーダーチャート（点数は下の表のとおり）"\s*data-personality-radar="([^"]*)"><\/canvas>/u', $html, $m));

        return json_decode(html_entity_decode($m[1][0], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
    }

    private function series(string $key, string $caseName, string $date): array
    {
        $display = $this->fixtureCase($caseName)['expected']['display'];

        return [
            'key' => $key,
            'date' => $date,
            'values' => array_map(fn ($c) => $display[$c], array_keys(PersonalityDiagnosisQuestions::categories())),
        ];
    }

    public function test_グラフ_結果が1件なら今回だけ(): void
    {
        $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page();

        $this->assertSame([
            'labels' => array_values(PersonalityDiagnosisQuestions::categories()),
            'series' => [$this->series('current', 'past-1', '2026/06/01')],
        ], $this->radarData($html));
        $this->assertStringContainsString('personality-radar', $html);
    }

    public function test_グラフ_結果が2件なら今回と前回で初回は重ねない(): void
    {
        $this->addResult('past-2', '2026-03-10 11:00:00');
        $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->assertSame([
            $this->series('current', 'past-1', '2026/06/01'),
            $this->series('previous', 'past-2', '2026/03/10'),
        ], $this->radarData($this->page())['series']);
    }

    public function test_グラフ_結果が3件なら今回と前回と初回(): void
    {
        $this->addResult('past-3', '2025-12-01 10:00:00');
        $this->addResult('past-2', '2026-03-10 11:00:00');
        $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->assertSame([
            $this->series('current', 'past-1', '2026/06/01'),
            $this->series('previous', 'past-2', '2026/03/10'),
            $this->series('initial', 'past-3', '2025/12/01'),
        ], $this->radarData($this->page())['series']);
    }

    public function test_グラフ_結果が4件なら間の回は重ねない(): void
    {
        $this->addResult('past-4', '2025-09-01 10:00:00');
        $this->addResult('past-3', '2025-12-01 10:00:00');
        $this->addResult('past-2', '2026-03-10 11:00:00');
        $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page();

        // 今回＝いちばん新しい回、前回＝その 1 つ前、初回＝いちばん古い回。2025/12/01（古い順で 2 つ目）は重ねない
        $this->assertSame([
            $this->series('current', 'past-1', '2026/06/01'),
            $this->series('previous', 'past-2', '2026/03/10'),
            $this->series('initial', 'past-4', '2025/09/01'),
        ], $this->radarData($html)['series']);
        // 表には 4 回とも出る
        $this->assertCount(4, $this->tableRows($this->card($html)));
    }

    public function test_凡例はグラフの下のHTMLの枠に出す(): void
    {
        foreach ([['past-1'], ['past-1', 'past-2'], ['past-1', 'past-2', 'past-3']] as $count => $names) {
            TraineePersonalityDiagnosisResult::query()->delete();
            foreach ($names as $i => $name) {
                $this->addResult($name, sprintf('2026-0%d-01 09:00:00', 6 - $i));
            }

            $card = $this->card($this->page());

            // 凡例を入れる枠（中身は JS がグラフと同じ値から作る）。グラフの枠の外（下）に、欄の幅いっぱいで置く
            $this->assertMatchesRegularExpression(
                '/<\/canvas>\s*<\/div>\s*<ul class="list-unstyled d-flex flex-wrap justify-content-center column-gap-3 row-gap-1 small mt-2 mb-3"\s*'
                .'data-personality-radar-legend="personality-diagnosis-radar"><\/ul>/u',
                $card,
                ($count + 1).' 件'
            );
            // 凡例の項目の数は、グラフに重ねる回の数と同じ（JS が series から作る）
            $this->assertCount($count + 1, $this->radarData($card)['series']);
        }
    }

    public function test_結果がなければ凡例の枠も出さない(): void
    {
        $this->assertStringNotContainsString('data-personality-radar-legend', $this->page());
    }

    public function test_会員詳細の性格診断のリンクの飛び先とこの欄のidが一致する(): void
    {
        // 会員詳細（S-0305）のテンプレートの「性格診断」のリンクの飛び先（描いたときの href は ClientCountRenderingTest で確かめている）
        $template = file_get_contents(resource_path('views/clients/show.blade.php'));
        $this->assertSame(1, preg_match('/route\(\'trainees\.show\', \$trainee\) \}\}#([a-z-]+)"\s*aria-label="\{\{ \$trainee->display_name \}\}の性格診断"/u', $template, $m));

        $this->assertSame('personality-diagnosis', $m[1]);
        $this->assertStringContainsString('id="'.$m[1].'"', $this->page());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\Trainer;
use App\Support\PersonalityDiagnosisQuestions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AssertsPersonalityDiagnosisName;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * トレーニー詳細（S-0309）の性格診断の欄（2026-10 追加。設計書 S-0309「性格診断の欄」）。
 *
 * - 計測値の下（いちばん下）に、いつも出す（id="personality-diagnosis"・scroll-margin-top。2026-10 変更：当初は自由記述の質問の欄の下。2026-10-19 に自由記述の質問の欄を外した）。結果がなければ「（未回答）」だけ
 * - 上にレーダーチャート 1 つ、下にすべての回の結果の表（新しい順。2026-10 変更：当初は古い順。回答日・タイプ・7 カテゴリ・評価文の状態・［詳細］）
 * - 各行の［詳細］で愛犬性格診断詳細（S-0313）へ。50 問の回答・アドバイス・削除は S-0313（2026-10-18 変更：当初は各行の［回答］で
 *   50 問の回答のモーダルを出し、管理者には各行の［削除］と削除確認モーダル S-0309-M01 を出していた。S-0313 の形は
 *   TraineePersonalityDiagnosisResultShowTest）
 *
 * 点数は固定データ（tests/fixtures/personality-diagnosis-cases.json）を結果として入れ、表示の値が固定データの display と
 * 一致することを確かめる。
 */
class TraineeShowPersonalityDiagnosisTest extends TestCase
{
    use AssertsPersonalityDiagnosisName;
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
        $this->createTrainingRecordsTableForPersonalityDiagnosis();

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

    /** 性格診断の欄（カードの始まりから、削除確認・50 問の回答のモーダル、計測値のモーダルの手前まで） */
    private function card(string $html): string
    {
        $start = strpos($html, '<div class="card mb-3" id="personality-diagnosis"');
        $this->assertNotFalse($start);
        $ends = array_filter([
            strpos($html, 'data-personality-diagnosis-answers', $start),
            strpos($html, 'data-personality-diagnosis-delete', $start),
            strpos($html, 'id="measurementModal-', $start),
        ]);
        $end = min($ends);

        return substr($html, $start, $end - $start);
    }

    /** 結果の表の行（回答日 → [タイプ, 7 カテゴリの値…]）。表の上から順。タイプは折り返しの印（<wbr>）を除いた文 */
    private function tableRows(string $html): array
    {
        preg_match_all('/<tr>\s*<th scope="row" class="fw-normal text-nowrap">([0-9\/]+)<\/th>\s*<td style="word-break: keep-all;">(.*?)<\/td>((?:\s*<td class="text-end text-nowrap">[0-9.]+<\/td>){7})/u', $html, $m, PREG_SET_ORDER);

        $rows = [];
        foreach ($m as $row) {
            preg_match_all('/>([0-9.]+)</', $row[3], $values);
            $rows[$row[1]] = array_merge([str_replace('<wbr>', '', $row[2])], $values[1]);
        }

        return $rows;
    }

    /** 結果の表の行の結果の id（表の上から順。各行の［詳細］のリンク先から取る） */
    private function tableRowIds(string $html): array
    {
        preg_match_all('/personality-diagnosis-results\/(\d+)" class="btn btn-sm btn-outline-primary text-nowrap"/u', $html, $m);

        return array_map('intval', $m[1]);
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

    public function test_計測値の後ろのいちばん下にidとscroll_margin_topを付けて出す(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('<div class="card mb-3" id="personality-diagnosis" style="scroll-margin-top: 4.5rem;">', $html);
        // 並びは 計測値 → 性格診断（いちばん下。2026-10-19 変更：その下の自由記述の質問の欄を外した）
        $this->assertGreaterThan(strpos($html, 'data-trainee-photo-measurements'), strpos($html, 'id="personality-diagnosis"'));
        $this->assertStringNotContainsString('id="intake-survey"', $html);
        $this->assertMatchesRegularExpression('/<div class="card-header">\s*<h6 class="mb-0">愛犬性格診断<\/h6>\s*<\/div>/u', $this->card($html));
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

    public function test_結果の表は新しい順ですべての回を1つの表に出す(): void
    {
        $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->addResult('past-3', '2025-12-01 10:00:00');
        $this->addResult('past-2', '2026-03-10 11:00:00');

        $card = $this->card($this->page());

        // 新しい順（最新を上。2026-10 変更：当初は古い順）
        $this->assertSame([
            '2026/06/01' => $this->expectedRow('past-1'),
            '2026/03/10' => $this->expectedRow('past-2'),
            '2025/12/01' => $this->expectedRow('past-3'),
        ], $this->tableRows($card));
        // 表は 1 つ。列の見出し（th scope="col"）は回答日・タイプ・7 カテゴリ・アドバイス（2026-10-18 追加）・ボタンの列（読み上げ用に「操作」）
        $this->assertSame(1, substr_count($card, '<table'));
        $this->assertStringContainsString('<div class="table-responsive position-relative">', $card);
        preg_match_all('/<th scope="col"[^>]*>(.*?)<\/th>/u', $card, $heads);
        $this->assertSame(
            array_merge(['回答日', 'タイプ'], array_values(PersonalityDiagnosisQuestions::categories()), ['アドバイス', '<span class="visually-hidden">操作</span>']),
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

    public function test_各行の詳細はその回の愛犬性格診断詳細へ移る白抜きの青のリンク(): void
    {
        $old = $this->addResult('past-2', '2026-03-10 11:00:00');
        $new = $this->addResult('past-1', '2026-06-01 09:00:00');

        $card = $this->card($this->page());

        foreach ([[$old, '2026/03/10'], [$new, '2026/06/01']] as [$result, $date]) {
            $this->assertMatchesRegularExpression(
                '/<th scope="row" class="fw-normal text-nowrap">'.preg_quote($date, '/').'<\/th>[\s\S]*?'
                .'<a href="'.preg_quote(route('trainee-personality-diagnosis-results.show', [$this->trainee, $result]), '/').'" class="btn btn-sm btn-outline-primary text-nowrap"\s*'
                .'aria-label="'.preg_quote($date, '/').'の愛犬性格診断の詳細">詳細<\/a>/u',
                $card
            );
        }
        $this->assertSame(2, substr_count($card, '">詳細</a>'));
        // ［回答］とモーダルは出さない（2026-10-18 変更：50 問の回答は S-0313 に移した）
        $this->assertStringNotContainsString('>回答</button>', $card);
        $this->assertStringNotContainsString('data-bs-toggle="modal"', $card);
    }

    public function test_アドバイスの列になしと下書きと公開済みを色のバッジで出す(): void
    {
        $none = $this->addResult('past-3', '2025-12-01 10:00:00');
        $draft = $this->addResult('past-2', '2026-03-10 11:00:00');
        $published = $this->addResult('past-1', '2026-06-01 09:00:00');
        $draft->update(['evaluation_text' => '下書きの文']);
        // 公開済みは公開した日時で決まる（2026-10-18 変更：その前は結び付いたトレーニング記録で決めていた）
        $published->update(['evaluation_text' => '公開した文', 'evaluation_published_at' => '2026-06-02 10:00:00']);

        $card = $this->card($this->page());

        preg_match_all('/<td class="text-nowrap"><span class="badge ([^"]+)">(なし|下書き|公開済み)<\/span><\/td>/u', $card, $m);
        $this->assertSame(['公開済み', '下書き', 'なし'], $m[2]);
        // 色：公開済み＝緑、下書き＝黄（文字は黒）、なし＝灰（2026-10-18 変更：その前は文字だけで、「未作成」は薄い文字）
        $this->assertSame(['bg-success', 'bg-warning text-dark', 'bg-secondary'], $m[1]);
        $this->assertStringNotContainsString('未作成', $card);
        $this->assertNotNull($none);
        // 列名は「アドバイス」（2026-10-19 変更：その前は「評価文」）。画面のどこにも「評価文」は出さない
        // （削除の確認の文は TraineeShowIntakeSurveyTest で確かめる）
        $this->assertStringContainsString('<th scope="col" class="fw-normal text-muted text-nowrap">アドバイス</th>', $card);
        $this->assertStringNotContainsString('評価文', $this->page());
    }

    public function test_アドバイスの状態を出してもクエリの数は結果の数で増えない(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->page();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->addResult('past-1', '2026-06-01 09:00:00');
        $one = $count();
        $this->addResult('past-2', '2026-03-10 11:00:00');
        $this->addResult('past-3', '2025-12-01 10:00:00');

        $this->assertSame($one, $count());
    }

    public function test_別のトレーニーの結果は出さない(): void
    {
        $other = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'ハル']);
        $this->addResult('past-3', '2026-10-15 09:00:00', $other);

        $html = $this->page();

        $this->assertStringContainsString('（未回答）', $this->card($html));
        $this->assertStringNotContainsString('聡明・スマート優等生タイプ', $html);
        $this->assertStringNotContainsString('personality-diagnosis-results/', $html);
    }

    // ---- レーダーチャート（1 つ。今回・前回・初回を重ねる） ----

    /** 欄のレーダーチャートの canvas の data-personality-radar（JSON を戻したもの） */
    private function radarData(string $html): array
    {
        $this->assertSame(1, preg_match_all('/<canvas id="personality-diagnosis-radar" role="img" aria-label="レーダーチャート（点数は表のとおり）"\s*data-personality-radar="([^"]*)"><\/canvas>/u', $html, $m));

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
            // カテゴリの値（labels と同じ並び。訓練性能の軸を真上に置くため JS が使う。2026-10-18 追加）
            'keys' => ['people', 'dogs', 'possessiveness', 'separation', 'fear', 'trainability', 'excitability'],
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

            // 凡例を入れる枠（中身は JS がグラフと同じ値から作る）。グラフの枠の外（下）に置く。
            // 並べ方はクラスだけで切り替える：ふだんは 1 行（折り返しあり）、xl 以上で横に並べたときは縦に 3 行（flex-xl-column）
            $this->assertMatchesRegularExpression(
                '/<\/canvas>\s*<\/div>\s*<div class="d-flex justify-content-center mt-2">\s*'
                .'<ul class="list-unstyled d-flex flex-wrap flex-xl-column align-items-xl-start justify-content-center column-gap-3 row-gap-1 small mb-0"\s*'
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

    // ---- グラフと表の並び（xl 以上は横に並べる。2026-10 変更） ----

    public function test_グラフと表はxl以上で横に並べそれより狭いときは縦に積む枠に入れる(): void
    {
        $this->addResult('past-1', '2026-06-01 09:00:00');

        $card = $this->card($this->page());

        // 1 行（row。上にそろえる）の中に、グラフの列（xl で 4/12）と表の列（xl で 8/12）。xl 未満はどちらも col-12（縦に積む）
        $this->assertSame(1, substr_count($card, 'data-personality-diagnosis-layout'));
        $this->assertMatchesRegularExpression(
            '/<div class="row gy-3 align-items-start" data-personality-diagnosis-layout>\s*'
            .'(?:\{\{--.*?--\}\}\s*)?<div class="col-12 col-xl-4">\s*.*?<canvas id="personality-diagnosis-radar"/us',
            $card
        );
        $graphCol = strpos($card, '<div class="col-12 col-xl-4">');
        $tableCol = strpos($card, '<div class="col-12 col-xl-8">');
        $this->assertNotFalse($tableCol);
        // グラフ（と凡例）が左の列、表が右の列
        $this->assertTrue($graphCol < strpos($card, '<canvas') && strpos($card, 'data-personality-radar-legend') < $tableCol);
        $this->assertGreaterThan($tableCol, strpos($card, '<div class="table-responsive position-relative">'));
        // グラフの枠（trainee-personality-radar。列の中で中央）。左右の列の割合は今までどおり（4：8）
        $this->assertStringContainsString('<div class="trainee-personality-radar mx-auto">', $card);
    }

    // ---- レーダーチャートの大きさ（最大 300px。2026-10 変更：当初は最大 420px） ----

    public function test_レーダーチャートは最大300pxの正方形の枠に入れ文字は小さくしない(): void
    {
        $this->addResult('past-1', '2026-06-01 09:00:00');

        $card = $this->card($this->page());

        // 枠はクラスだけで指定する（以前の style="max-width: 420px;" は使わない）
        $this->assertStringContainsString('<div class="trainee-personality-radar mx-auto">', $card);
        $this->assertStringNotContainsString('max-width: 420px', $card);
        // 会員側の小さな表示（compact。文字を小さくする）は使わない
        $this->assertStringNotContainsString('data-personality-radar-compact', $card);
        $this->assertStringNotContainsString('c-personality-radar', $card);

        // 枠の指定は app.scss に最大 300px だけ（高さは決めない＝Chart.js の正方形のまま）
        $trainer = file_get_contents(resource_path('sass/app.scss'));
        $this->assertMatchesRegularExpression('/\.trainee-personality-radar \{\s*max-width: 300px;\s*\}/u', $trainer);
        $this->assertSame(1, substr_count($trainer, '.trainee-personality-radar'));
        // 会員側の CSS には置かない
        $this->assertStringNotContainsString('trainee-personality-radar', file_get_contents(resource_path('sass/client.scss')));
    }

    public function test_表はタイプだけ折り返してよくほかは折り返さない(): void
    {
        $this->addResult('past-1', '2026-06-01 09:00:00');

        $card = $this->card($this->page());

        $this->assertSame(1, substr_count($card, '<td style="word-break: keep-all;">'));
        $this->assertSame(1, substr_count($card, '<th scope="row" class="fw-normal text-nowrap">'));
        // 点数 7 つ＋ボタンの列
        $this->assertSame(8, substr_count($card, '<td class="text-end text-nowrap">'));
        // アドバイスの状態とボタンの列も折り返さない
        $this->assertSame(1, preg_match_all('/<td class="text-nowrap"><span class="badge bg-secondary">なし<\/span><\/td>/u', $card));
        $this->assertMatchesRegularExpression('/<td class="text-end text-nowrap">\s*<a href="[^"]+" class="btn btn-sm btn-outline-primary text-nowrap"/u', $card);
    }

    public function test_表の文字は少し小さくしボタンはbtn_smのまま(): void
    {
        $this->actingAsRole('admin');
        $this->addResult('past-1', '2026-06-01 09:00:00');

        $card = $this->card($this->page());

        // 表（見出しと中身）に small。行の高さは table-sm
        $this->assertStringContainsString('<table class="table table-sm small align-middle mb-0" data-personality-diagnosis-results>', $card);
        // ボタンは btn-sm のまま（表の文字に合わせて小さくしない）
        $this->assertStringContainsString('class="btn btn-sm btn-outline-primary text-nowrap"', $card);
    }

    public function test_タイプは中点のあとに折り返しの印を入れほかの位置では折り返さない(): void
    {
        // 3 つの例：愛情たっぷり・甘えん坊タイプ／エネルギッシュ・アスリートタイプ／慎重派・しっかり番犬タイプ
        $this->addResult('tie-possessiveness-fear', '2025-12-01 10:00:00');
        $this->addResult('rounding-two-decimals', '2026-03-10 11:00:00');
        $this->addResult('past-2', '2026-06-01 09:00:00');

        $card = $this->card($this->page());

        foreach (['愛情たっぷり・<wbr>甘えん坊タイプ', 'エネルギッシュ・<wbr>アスリートタイプ', '慎重派・<wbr>しっかり番犬タイプ'] as $label) {
            $this->assertStringContainsString('<td style="word-break: keep-all;">'.$label.'</td>', $card);
        }
        $this->assertSame(3, substr_count($card, '<wbr>'));
        // 表示名（types()）そのものは変えない
        $this->assertSame('愛情たっぷり・甘えん坊タイプ', PersonalityDiagnosisQuestions::types()['affectionate']);
    }

    public function test_タイプの文はエスケープして出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        // 表示名にない値（そのまま出す）でも、HTML として解釈させない
        $result->forceFill(['personality_type' => '<b>x</b>・y'])->saveQuietly();

        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_グラフの読み上げ用の説明は位置を言わない(): void
    {
        $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page();

        $this->assertStringContainsString('aria-label="レーダーチャート（点数は表のとおり）"', $html);
        $this->assertStringNotContainsString('点数は下の表のとおり', $html);
    }

    public function test_結果がなければ並びの枠も出さない(): void
    {
        $card = $this->card($this->page());

        $this->assertStringNotContainsString('data-personality-diagnosis-layout', $card);
        $this->assertStringNotContainsString('col-xl-', $card);
        $this->assertMatchesRegularExpression('/<div class="card-body">\s*<p class="mb-0 text-muted">（未回答）<\/p>\s*<\/div>/u', $card);
    }

    public function test_会員詳細の性格診断のリンクの飛び先とこの欄のidが一致する(): void
    {
        // 会員詳細（S-0305）のテンプレートの「性格診断」のリンクの飛び先（描いたときの href は ClientCountRenderingTest で確かめている）
        $template = file_get_contents(resource_path('views/clients/show.blade.php'));
        $this->assertSame(1, preg_match('/route\(\'trainees\.show\', \$trainee\) \}\}#([a-z-]+)"(?: style="word-break: keep-all; overflow-wrap: normal;")?\s*aria-label="\{\{ \$trainee->display_name \}\}の\{\{ __\(\'messages\.personality_diagnosis_name\'\) \}\}"/u', $template, $m));

        $this->assertSame('personality-diagnosis', $m[1]);
        $this->assertStringContainsString('id="'.$m[1].'"', $this->page());
    }

    // ---- 結果の削除（管理者だけ。2026-10 追加。2026-10-18 変更：S-0313 に移した。S-0313 の形は TraineePersonalityDiagnosisResultShowTest） ----

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => 'テスト', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    public function test_管理者にも各行の削除のボタンと確認モーダルを出さない(): void
    {
        $this->actingAsRole('admin');
        $this->addResult('past-2', '2026-03-10 11:00:00');
        $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page();
        $card = $this->card($html);

        // 最後の列の読み上げ用の見出しは「操作」のまま。中身は［詳細］だけ
        $this->assertStringContainsString('<th scope="col"><span class="visually-hidden">操作</span></th>', $card);
        $this->assertStringNotContainsString('btn-outline-danger', $card);
        $this->assertStringNotContainsString('の結果を削除', $html);
        $this->assertStringNotContainsString('data-personality-diagnosis-delete', $html);
        $this->assertStringNotContainsString('data-personality-diagnosis-answers', $html);
        $this->assertSame(2, substr_count($card, '">詳細</a>'));
    }

    public function test_管理者でなくても各行の詳細は出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page();

        $this->assertStringContainsString('href="'.route('trainee-personality-diagnosis-results.show', [$this->trainee, $result]).'"', $this->card($html));
        $this->assertStringNotContainsString('の結果を削除', $html);
        $this->assertStringNotContainsString('btn-outline-danger', $this->card($html));
    }

    public function test_いちばん新しい回を削除するとグラフの今回と前回がずれる(): void
    {
        $this->actingAsRole('admin');
        $this->addResult('past-3', '2025-12-01 10:00:00');
        $this->addResult('past-2', '2026-03-10 11:00:00');
        $newest = $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->delete(route('trainee-personality-diagnosis-results.destroy', [$this->trainee, $newest]))
            ->assertRedirect(route('trainees.show', $this->trainee));

        $html = $this->page();
        // 残った 2 件で決め直す：今回＝past-2、前回＝past-3（2 件なので初回は重ねない）
        $this->assertSame([
            $this->series('current', 'past-2', '2026/03/10'),
            $this->series('previous', 'past-3', '2025/12/01'),
        ], $this->radarData($html)['series']);
        $this->assertSame([
            '2026/03/10' => $this->expectedRow('past-2'),
            '2025/12/01' => $this->expectedRow('past-3'),
        ], $this->tableRows($this->card($html)));
        $this->assertStringContainsString('愛犬性格診断の結果を削除しました。', $html);
    }

    // ---- 結果の表の並び（新しい順。2026-10 変更） ----

    public function test_同じ日に2件あっても表のいちばん上がグラフの今回で並びはグラフと同じ基準(): void
    {
        // 同じ日（同じ日時）の 2 件は、後に作ったほう（id の大きいほう）を新しいとする
        $initial = $this->addResult('past-3', '2026-10-15 09:00:00');
        $previous = $this->addResult('past-2', '2026-10-15 09:00:00');
        $current = $this->addResult('past-1', '2026-10-16 09:00:00');

        $html = $this->page();

        $this->assertSame([$current->id, $previous->id, $initial->id], $this->tableRowIds($this->card($html)));
        // 表のいちばん上・2 番目・いちばん下が、グラフの今回・前回・初回と同じ結果（値と回答日）
        $this->assertSame([
            $this->series('current', 'past-1', '2026/10/16'),
            $this->series('previous', 'past-2', '2026/10/15'),
            $this->series('initial', 'past-3', '2026/10/15'),
        ], $this->radarData($html)['series']);
    }

    public function test_各行の詳細はその行の結果を開く(): void
    {
        $old = $this->addResult('past-3', '2025-12-01 10:00:00');
        $mid = $this->addResult('past-2', '2026-03-10 11:00:00');
        $new = $this->addResult('past-1', '2026-06-01 09:00:00');

        $card = $this->card($this->page());

        preg_match_all('/<tr>\s*<th scope="row" class="fw-normal text-nowrap">([0-9\/]+)<\/th>.*?<\/tr>/us', $card, $rows);
        $this->assertCount(3, $rows[0]);
        foreach ([[$new, '2026/06/01'], [$mid, '2026/03/10'], [$old, '2025/12/01']] as $i => [$result, $date]) {
            $this->assertSame($date, $rows[1][$i]);
            $this->assertStringContainsString('href="'.route('trainee-personality-diagnosis-results.show', [$this->trainee, $result]).'"', $rows[0][$i]);
            $this->assertStringContainsString('aria-label="'.$date.'の愛犬性格診断の詳細"', $rows[0][$i]);
        }
    }

    // ---- 愛犬性格診断の名前（2026-10-18 変更：正式名称に決まった。その前は「性格診断」） ----

    public function test_トレーニー詳細は愛犬性格診断の名前で出し性格診断は単独で出さない(): void
    {
        $this->actingAsRole('admin');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page();

        $this->assertStringContainsString('<h6 class="mb-0">愛犬性格診断</h6>', $html);
        $this->assertStringContainsString('aria-label="2026/06/01の愛犬性格診断の詳細"', $html);
        $this->assertOnlyFullPersonalityDiagnosisName($html);
        $this->assertNotNull($result);
    }

    // ---- 点数なし・判定なし（2026-10-18 追加） ----

    public function test_表の点数なしは横棒で読み上げは点数なし_判定なしは判定なし(): void
    {
        $this->addResult('all-na', '2026-06-01 09:00:00');

        $card = $this->card($this->page());
        $this->assertSame(1, preg_match('/<tr>\s*<th scope="row" class="fw-normal text-nowrap">2026\/06\/01<\/th>(.*?)<\/tr>/su', $card, $m));
        $row = $m[1];

        $this->assertStringContainsString('<td style="word-break: keep-all;">判定なし</td>', $row);
        $this->assertSame(7, substr_count($row, '<td class="text-end text-nowrap"><span aria-hidden="true">—</span><span class="visually-hidden">点数なし</span></td>'));
        $this->assertStringNotContainsString('0.0', $row);
    }

    public function test_今回に点数なしがあっても注記は出さずグラフには点数なしをnullのまま渡す(): void
    {
        $this->addResult('category-all-na', '2026-06-01 09:00:00');

        $html = $this->page();

        // 点数なしは表の「—」で分かるので、グラフの下に注記は出さない（2026-10-18 変更）
        $this->assertStringNotContainsString('点数がありません', $html);
        $this->assertStringNotContainsString('data-personality-radar-missing-note', $html);
        $this->assertStringContainsString('<td class="text-end text-nowrap"><span aria-hidden="true">—</span><span class="visually-hidden">点数なし</span></td>', $html);
        $values = $this->radarData($html)['series'][0]['values'];
        $this->assertNull($values[3]);
        $this->assertSame('2.0', $values[1]);
    }

    public function test_前回だけ点数なしでも注記は出さない(): void
    {
        $this->addResult('category-all-na', '2026-03-10 09:00:00');
        $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page();

        $this->assertStringNotContainsString('点数がありません', $html);
        // 前回の値には点数なし（null）が入る（点を描かないだけ）
        $this->assertNull($this->radarData($html)['series'][1]['values'][3]);
    }
}

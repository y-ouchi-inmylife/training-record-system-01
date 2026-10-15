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
 * トレーニー詳細（S-0309）の性格診断の欄（2026-10 追加。性格診断 段階4。設計書 S-0309「性格診断の欄」）。
 *
 * - 暮らしの様子の欄の下に、いつも出す（id="personality-diagnosis"・scroll-margin-top）。結果がなければ「（未回答）」
 * - 結果は新しい順。各回の見出しの行で開閉し、いちばん新しい回だけ最初から開いている
 * - 開いた回：7 カテゴリの点数の表（今回・前回。表示の値は保存の値から四捨五入）、50 問の回答（折りたたみ）
 *
 * 点数は固定データ（tests/fixtures/personality-diagnosis-cases.json）の past-1・past-2 を結果として入れ、
 * 表示の値が固定データの display と一致することを確かめる。
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

    /** 性格診断の欄（カードの始まりから計測値のモーダルの手前まで） */
    private function card(string $html): string
    {
        $start = strpos($html, '<div class="card mb-3" id="personality-diagnosis"');
        $this->assertNotFalse($start);
        $end = strpos($html, 'id="measurementModal-', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /** 1 つの回の点数の表の行（カテゴリ → [今回, 前回?]） */
    private function scoreRows(string $resultHtml): array
    {
        preg_match_all('/<th scope="row" class="fw-normal">([^<]+)<\/th>\s*<td class="text-end">([0-9.]+)<\/td>(?:\s*<td class="text-end text-muted">([0-9.]+)<\/td>)?/u', $resultHtml, $m, PREG_SET_ORDER);

        $rows = [];
        foreach ($m as $row) {
            $rows[$row[1]] = isset($row[3]) && $row[3] !== '' ? [$row[2], $row[3]] : [$row[2]];
        }

        return $rows;
    }

    /** 回の中身（collapse の中） */
    private function resultBody(string $html, TraineePersonalityDiagnosisResult $result): string
    {
        $start = strpos($html, 'id="personality-diagnosis-result-'.$result->id.'"');
        $this->assertNotFalse($start);
        $end = strpos($html, 'id="personality-diagnosis-result-'.$result->id.'-answers"', $start);

        return substr($html, $start, $end - $start);
    }

    public function test_暮らしの様子の欄の後ろにidとscroll_margin_topを付けて出す(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('<div class="card mb-3" id="personality-diagnosis" style="scroll-margin-top: 4.5rem;">', $html);
        $this->assertGreaterThan(strpos($html, 'id="intake-survey"'), strpos($html, 'id="personality-diagnosis"'));
        $this->assertMatchesRegularExpression('/<div class="card-header">\s*<h6 class="mb-0">性格診断<\/h6>\s*<\/div>/u', $this->card($html));
    }

    public function test_結果がなければ未回答とだけ出す(): void
    {
        $card = $this->card($this->page());

        $this->assertMatchesRegularExpression('/<div class="card-body">\s*<p class="mb-0 text-muted">（未回答）<\/p>\s*<\/div>/u', $card);
        $this->assertStringNotContainsString('data-personality-diagnosis-results', $card);
        $this->assertStringNotContainsString('<button', $card);
    }

    public function test_結果は新しい順でいちばん新しい回だけ開いている(): void
    {
        $old = $this->addResult('past-2', '2026-07-02 10:00:00');
        $new = $this->addResult('past-1', '2026-10-15 09:00:00');

        $card = $this->card($this->page());

        $newHeader = '/<button type="button" class="btn btn-link[^"]*"\s*data-bs-toggle="collapse" data-bs-target="#personality-diagnosis-result-'.$new->id.'"\s*'
            .'aria-expanded="true" aria-controls="personality-diagnosis-result-'.$new->id.'">\s*<span>2026\/10\/15<\/span>\s*<span class="fw-bold">繊細・デリケートタイプ<\/span>/u';
        $oldHeader = '/<button type="button" class="btn btn-link[^"]*"\s*data-bs-toggle="collapse" data-bs-target="#personality-diagnosis-result-'.$old->id.'"\s*'
            .'aria-expanded="false" aria-controls="personality-diagnosis-result-'.$old->id.'">\s*<span>2026\/07\/02<\/span>\s*<span class="fw-bold">慎重派・しっかり番犬タイプ<\/span>/u';
        $this->assertMatchesRegularExpression($newHeader, $card);
        $this->assertMatchesRegularExpression($oldHeader, $card);
        $this->assertLessThan(strpos($card, '2026/07/02'), strpos($card, '2026/10/15'));
        $this->assertStringContainsString('<div class="collapse show mt-3" id="personality-diagnosis-result-'.$new->id.'"', $card);
        $this->assertStringContainsString('<div class="collapse mt-3" id="personality-diagnosis-result-'.$old->id.'"', $card);
        // 操作は置かない
        $this->assertStringNotContainsString('<form', $card);
        $this->assertStringNotContainsString('案内を', $card);
    }

    public function test_点数の表は今回と前回の表示の値で最初の回は前回がない(): void
    {
        $old = $this->addResult('past-2', '2026-07-02 10:00:00');
        $new = $this->addResult('past-1', '2026-10-15 09:00:00');
        $past1 = $this->fixtureCase('past-1')['expected']['display'];
        $past2 = $this->fixtureCase('past-2')['expected']['display'];
        $categories = PersonalityDiagnosisQuestions::categories();

        $html = $this->page();

        $expectedNew = [];
        $expectedOld = [];
        foreach ($categories as $category => $label) {
            $expectedNew[$label] = [$past1[$category], $past2[$category]];
            $expectedOld[$label] = [$past2[$category]];
        }
        $this->assertSame($expectedNew, $this->scoreRows($this->resultBody($html, $new)));
        $this->assertStringContainsString('>前回</th>', $this->resultBody($html, $new));
        // いちばん古い回は「前回」の列を出さない
        $this->assertSame($expectedOld, $this->scoreRows($this->resultBody($html, $old)));
        $this->assertStringNotContainsString('>前回</th>', $this->resultBody($html, $old));
    }

    public function test_表示の値は保存の値から四捨五入し固定データのdisplayと一致する(): void
    {
        foreach (json_decode(file_get_contents(__DIR__.'/../fixtures/personality-diagnosis-cases.json'), true)['cases'] as $case) {
            $result = $this->addResult($case['name'], '2026-10-15 09:00:00');

            $this->assertSame($case['expected']['display'], $result->fresh()->displayScores(), $case['name']);
        }
    }

    public function test_50問の回答は折りたたみでカテゴリごとに設問と選んだ答えを出す(): void
    {
        $result = $this->addResult('past-1', '2026-10-15 09:00:00');
        $answers = $this->fixtureCase('past-1')['answers'];

        $card = $this->card($this->page());

        $this->assertMatchesRegularExpression('/data-bs-target="#personality-diagnosis-result-'.$result->id.'-answers"\s*aria-expanded="false" aria-controls="personality-diagnosis-result-'.$result->id.'-answers">50問の回答<\/button>/u', $card);
        $this->assertStringContainsString('<div class="collapse mt-2" id="personality-diagnosis-result-'.$result->id.'-answers" data-personality-diagnosis-answers>', $card);
        $answersHtml = substr($card, strpos($card, 'data-personality-diagnosis-answers'));
        foreach (PersonalityDiagnosisQuestions::categories() as $label) {
            $this->assertStringContainsString('>'.$label.'</p>', $answersHtml);
        }
        $this->assertSame(50, substr_count($answersHtml, '<dt class="col-sm-8 fw-normal">'));
        // 数字の答えと「未経験」（past-1 の Q01 は na、Q02 は 4）
        $this->assertSame('na', $answers['Q01']);
        $this->assertSame(4, $answers['Q02']);
        $this->assertMatchesRegularExpression('/<dt class="col-sm-8 fw-normal">①知らない人が家に入ってきた時に吠える・唸る<\/dt>\s*<dd class="col-sm-4 mb-1">未経験<\/dd>/u', $answersHtml);
        $this->assertMatchesRegularExpression('/<dt class="col-sm-8 fw-normal">②窓の外を誰かが通りかかっただけで、敏感に反応して吠える<\/dt>\s*<dd class="col-sm-4 mb-1">4：常にある<\/dd>/u', $answersHtml);
    }

    public function test_別のトレーニーの結果は出さない(): void
    {
        $other = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'ハル']);
        $this->addResult('past-3', '2026-10-15 09:00:00', $other);

        $card = $this->card($this->page());

        $this->assertStringContainsString('（未回答）', $card);
        $this->assertStringNotContainsString('聡明・スマート優等生タイプ', $card);
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

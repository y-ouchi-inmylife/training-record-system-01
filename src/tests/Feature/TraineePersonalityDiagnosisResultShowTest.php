<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\Trainer;
use App\Models\TrainingRecord;
use App\Support\PersonalityDiagnosisQuestions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 愛犬性格診断詳細（S-0313）と評価文の保存（2026-10-18 追加。要件定義書 6-16-13〜6-16-15、設計書 S-0313）。
 *
 * - 見出し・［« トレーニー詳細へ戻る］・結果（タイプ・この結果の時点のレーダーチャート・7 項目の点数）・評価文・50問の回答
 * - 評価文：なし・下書きはテキストエリアと［保存］（緑）。公開済みは表示だけと記録へのリンク。保存は 10,000 文字まで・
 *   空なら「なし」に戻る・保存した人と日時・操作の記録。公開済みは保存を受け付けない（409）
 * - ［トレーニング記録にして公開］（S-0313-M01。2026-10-18 追加）：欄の今の文を保存してから記録を作る。2 回目・公開済みは
 *   記録を増やさず作った記録の詳細へ。公開したあとは公開済み（表示だけ）
 * - 削除（管理者だけ。S-0313-M02。S-0309-M01 から移した）：結び付いた記録があるときの文。削除しても記録は残る
 * - 権限：トレーナー（一般・管理者）は表示・保存、システム管理者は 403、URL のトレーニーと結果が合わなければ 404、未ログインはログイン画面へ
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。点数は固定データを結果として入れる
 */
class TraineePersonalityDiagnosisResultShowTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
        $this->createAccessLogsTable();
        Schema::create('trainers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('role')->nullable();
        });
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();
        $this->createTrainingRecordsTableForPersonalityDiagnosis();

        DB::table('trainers')->insert(['id' => 200, 'name' => '佐藤', 'role' => 'staff']);
        $this->actingAsRole('staff');
        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        $this->trainee = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'モモ']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => '佐藤', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
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

    private function url(TraineePersonalityDiagnosisResult|int $result, ?Trainee $trainee = null): string
    {
        return route('trainee-personality-diagnosis-results.show', [$trainee ?? $this->trainee, $result]);
    }

    private function page(TraineePersonalityDiagnosisResult $result): string
    {
        return $this->get($this->url($result))->assertOk()->getContent();
    }

    /** 結果を結び付けたトレーニング記録を入れる（公開は次の段階なので、列を直接入れる） */
    private function linkRecord(TraineePersonalityDiagnosisResult $result, string $date = '2026-10-18'): int
    {
        return DB::table('training_records')->insertGetId([
            'client_id' => $this->client->id, 'training_date' => $date, 'trainer1_id' => 200,
            'record_content' => $result->evaluation_text, 'trainee_personality_diagnosis_result_id' => $result->id,
        ]);
    }

    /** 評価文のカード */
    private function evaluationCard(string $html): string
    {
        $start = strpos($html, 'data-personality-diagnosis-evaluation');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, 'data-personality-diagnosis-answers', $start) - $start);
    }

    /** 画面の下の「最終更新」の文（前後の空白を除く）。出ていなければ null */
    private function updatedLine(string $html): ?string
    {
        if (preg_match('/<div class="text-end text-muted small mb-3" data-evaluation-updated>\s*(.*?)\s*<\/div>/us', $html, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /** 評価文のカードの見出しの行（card-header の中） */
    private function evaluationHeader(string $card): string
    {
        $start = strpos($card, '<div class="card-header');
        $this->assertNotFalse($start);

        return substr($card, $start, strpos($card, '<div class="card-body">') - $start);
    }

    /** レーダーチャートの canvas の data-personality-radar（JSON を戻したもの） */
    private function radarData(string $html): array
    {
        $this->assertSame(1, preg_match_all('/<canvas id="personality-diagnosis-radar" role="img" aria-label="レーダーチャート（点数は表のとおり）"\s*data-personality-radar="([^"]*)"><\/canvas>/u', $html, $m));

        return json_decode(html_entity_decode($m[1][0], ENT_QUOTES), true);
    }

    private function update(TraineePersonalityDiagnosisResult $result, mixed $text)
    {
        return $this->from($this->url($result))->put(route('trainee-personality-diagnosis-results.update', [$this->trainee, $result]), ['evaluation_text' => $text]);
    }

    // ---- URL と表示 ----

    public function test_URLとルート名は設計書のとおり(): void
    {
        $path = url('/trainees/'.$this->trainee->id.'/personality-diagnosis-results/5');
        $this->assertSame($path, route('trainee-personality-diagnosis-results.show', [$this->trainee, 5]));
        $this->assertSame($path, route('trainee-personality-diagnosis-results.update', [$this->trainee, 5]));
        $this->assertSame($path, route('trainee-personality-diagnosis-results.destroy', [$this->trainee, 5]));
    }

    public function test_見出しと戻るボタンとタイプと7項目の点数を出す(): void
    {
        $result = $this->addResult('tie-possessiveness-fear', '2026-06-01 09:00:00');
        $display = $this->fixtureCase('tie-possessiveness-fear')['expected']['display'];

        $html = $this->page($result);

        $this->assertStringContainsString('<h2 class="mb-0">モモの愛犬性格診断（2026/06/01）</h2>', $html);
        $this->assertStringContainsString('<a href="'.route('trainees.show', $this->trainee).'" class="btn btn-outline-secondary">&laquo; トレーニー詳細へ戻る</a>', $html);
        $this->assertStringContainsString('<title>愛犬性格診断詳細', $html);
        // タイプは「・」のあとでだけ折り返す
        $this->assertStringContainsString('data-personality-diagnosis-type>愛情たっぷり・<wbr>甘えん坊タイプ</p>', $html);
        // 7 項目の点数（1 行の表）。見出しはカテゴリの順
        preg_match_all('/<th scope="col" class="fw-normal text-muted text-end text-nowrap px-2">(.*?)<\/th>/u', $html, $heads);
        $this->assertSame(array_values(PersonalityDiagnosisQuestions::categories()), $heads[1]);
        preg_match_all('/<td class="text-end text-nowrap px-2">([0-9.]+)<\/td>/u', $html, $cells);
        // 表の幅は中身に合わせる（2026-10-18 変更：右の列いっぱいに広がっていた）
        $this->assertStringContainsString('<table class="table table-sm small align-middle mb-0 w-auto" data-personality-diagnosis-scores>', $html);
        $this->assertSame(array_values($display), $cells[1]);
        // グラフの部品を読み込む
        $this->assertStringContainsString('personality-radar', $html);
        $this->assertStringContainsString('data-personality-radar-legend="personality-diagnosis-radar"', $html);
    }

    public function test_名前が未登録なら見出しは名前未登録(): void
    {
        $this->trainee->update(['name' => null]);
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->assertStringContainsString('<h2 class="mb-0">名前未登録の愛犬性格診断（2026/06/01）</h2>', $this->page($result));
    }

    public function test_点数なしは横棒で読み上げは点数なし_判定なしは判定なし(): void
    {
        $result = $this->addResult('all-na', '2026-06-01 09:00:00');

        $html = $this->page($result);

        $this->assertSame(7, substr_count($html, '<td class="text-end text-nowrap px-2"><span aria-hidden="true">—</span><span class="visually-hidden">点数なし</span></td>'));
        $this->assertStringContainsString('data-personality-diagnosis-type>判定なし</p>', $html);
        $this->assertSame([null, null, null, null, null, null, null], $this->radarData($html)['series'][0]['values']);
    }

    public function test_グラフはこの結果を今回にしそれより前の結果から前回と初回を取る(): void
    {
        $this->addResult('past-3', '2025-12-01 10:00:00');
        $this->addResult('past-2', '2026-03-10 11:00:00');
        $target = $this->addResult('past-1', '2026-06-01 09:00:00');
        // あとの結果は使わない
        $this->addResult('tie-possessiveness-fear', '2026-08-20 09:00:00');

        $series = $this->radarData($this->page($target))['series'];

        $this->assertSame(['current', 'previous', 'initial'], array_column($series, 'key'));
        $this->assertSame(['2026/06/01', '2026/03/10', '2025/12/01'], array_column($series, 'date'));
        $this->assertSame(array_values($this->fixtureCase('past-1')['expected']['display']), $series[0]['values']);
    }

    public function test_いちばん古い結果のグラフは今回だけ(): void
    {
        $first = $this->addResult('past-3', '2025-12-01 10:00:00');
        $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->assertSame(['2025/12/01'], array_column($this->radarData($this->page($first))['series'], 'date'));
    }

    public function test_50問の回答をカテゴリごとにこの画面に出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page($result);

        $this->assertStringContainsString('<h6 class="mb-0">50問の回答</h6>', $html);
        foreach (PersonalityDiagnosisQuestions::categories() as $label) {
            $this->assertStringContainsString('>'.$label.'</p>', $html);
        }
        $this->assertSame(50, substr_count($html, '<dt class="col-sm-8 fw-normal">'));
        $this->assertMatchesRegularExpression('/<dt class="col-sm-8 fw-normal">①知らない人が家に入ってきた時に吠える・唸る<\/dt>\s*<dd class="col-sm-4 mb-1">未経験<\/dd>/u', $html);
        $this->assertMatchesRegularExpression('/<dt class="col-sm-8 fw-normal">②窓の外を誰かが通りかかっただけで、敏感に反応して吠える<\/dt>\s*<dd class="col-sm-4 mb-1">4：常にある<\/dd>/u', $html);
        // Q11 は文末を直した文。モーダルにはしない
        $this->assertStringContainsString('本気で怒って喧嘩になる</dt>', $html);
        $this->assertStringNotContainsString('modal-dialog-scrollable', $html);
    }

    public function test_下書きには青の公開のボタンと確認のモーダルを出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '下書き']);

        $html = $this->page($result);

        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#personality-diagnosis-publish"\s*data-evaluation-publish\s*>トレーニング記録にして公開<\/button>/u', $html);
        // ［保存］の右
        $this->assertLessThan(strpos($html, 'data-evaluation-publish'), strpos($html, '>更新</button>'));
        $this->assertStringContainsString('<h5 class="modal-title" id="personality-diagnosis-publish-label">トレーニング記録にして公開</h5>', $html);
        $this->assertStringContainsString('<p class="mb-0">トレーニング記録を作り、会員様に公開します。公開すると、評価文はトレーニング記録で直すことになります。</p>', $html);
        $this->assertStringContainsString('action="'.route('trainee-personality-diagnosis-results.publish', [$this->trainee, $result]).'" class="d-inline m-0" data-evaluation-publish-form>', $html);
        $this->assertStringContainsString('<input type="hidden" name="evaluation_text" value="">', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-primary">公開する</button>', $html);
        $this->assertStringContainsString('<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>', $html);
    }

    public function test_評価文が空なら公開のボタンは押せない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page($result);

        $this->assertMatchesRegularExpression('/data-evaluation-publish\s+disabled>トレーニング記録にして公開<\/button>/u', $html);
    }

    public function test_公開済みには公開のボタンもモーダルも出さない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '公開した文']);
        $this->linkRecord($result);

        $html = $this->page($result);

        $this->assertStringNotContainsString('トレーニング記録にして公開', $html);
        $this->assertStringNotContainsString('data-personality-diagnosis-publish', $html);
    }

    // ---- 評価文 ----

    public function test_なしは灰のバッジと緑の更新と空のテキストエリアで最終更新は出さない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $card = $this->evaluationCard($this->page($result));

        $this->assertStringContainsString('<span class="badge bg-secondary" data-evaluation-status>なし</span>', $card);
        $this->assertStringContainsString('action="'.route('trainee-personality-diagnosis-results.update', [$this->trainee, $result]).'" novalidate id="evaluation-form" data-evaluation-form>', $card);
        $this->assertStringContainsString('name="_method" value="PUT"', $card);
        $this->assertMatchesRegularExpression('/<textarea class="form-control " id="evaluation_text" name="evaluation_text"\s*rows="12" maxlength="10000"><\/textarea>/u', $card);
        $this->assertStringContainsString('<label for="evaluation_text" class="visually-hidden">評価文</label>', $card);
        // ボタンの名前は［更新］（2026-10-18 変更：その前は［保存］）。［更新］はフォームの外（カードの見出しの行）にあり、
        // form 属性で評価文のフォームを送る
        $this->assertStringContainsString('<button type="submit" form="evaluation-form" class="btn btn-success">更新</button>', $card);
        $this->assertStringNotContainsString('>保存</button>', $card);
        // 見出しの行：「評価文」→ すぐ右にバッジ → 右に［更新］［トレーニング記録にして公開］（2026-10-18 変更：その前はテキストエリアの上の行）
        $header = $this->evaluationHeader($card);
        $this->assertStringContainsString('<div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">', $header);
        $this->assertLessThan(strpos($header, 'data-evaluation-status>'), strpos($header, '<h6 class="mb-0">評価文</h6>'));
        $this->assertLessThan(strpos($header, '>更新</button>'), strpos($header, 'data-evaluation-status>'));
        $this->assertLessThan(strpos($header, '>トレーニング記録にして公開</button>'), strpos($header, '>更新</button>'));
        $this->assertStringContainsString('<div class="d-flex flex-wrap gap-2 ms-auto" data-evaluation-buttons>', $header);
        // 大きさは通常（ほかのカードの見出しのボタンと同じ。btn-sm にしない）
        $this->assertStringNotContainsString('btn-sm', $header);
        // テキストエリアの上のボタンの行はなくした（ボタンは見出しの行だけ）
        $this->assertStringNotContainsString('<button', substr($card, strpos($card, '<div class="card-body">')));
        $this->assertStringNotContainsString('最終更新', $card);
        // 記録へのリンクは公開済みのときだけ
        $this->assertStringNotContainsString('data-evaluation-record-link', $card);
        // 一度も更新していなければ、画面の下にも出さない
        $this->assertNull($this->updatedLine($this->page($result)));
    }

    public function test_保存すると下書きになり保存した人と日時と操作の記録が残る(): void
    {
        Carbon::setTestNow('2026-10-18 14:30:00');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $response = $this->update($result, "落ち着いた性格です。\r\nお散歩が好きです。");

        $response->assertRedirect($this->url($result));
        $response->assertSessionHas('success', '評価文を更新しました。');
        $saved = $result->fresh();
        // 改行は LF にそろえる
        $this->assertSame("落ち着いた性格です。\nお散歩が好きです。", $saved->evaluation_text);
        $this->assertSame(200, (int) $saved->evaluation_updated_by);
        $this->assertSame('2026-10-18 14:30:00', $saved->evaluation_updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('下書き', $saved->evaluation_status_label);

        $logs = AccessLog::query()->get();
        $this->assertCount(1, $logs);
        $this->assertSame('edit_trainee_personality_diagnosis_evaluation', $logs[0]->action);
        $this->assertSame('Trainee', $logs[0]->target_type);
        $this->assertSame($this->trainee->id, (int) $logs[0]->target_id);
        $this->assertSame('愛犬性格診断評価文編集', AccessLog::actionLabels()[$logs[0]->action]);

        $html = $this->page($saved);
        $card = $this->evaluationCard($html);
        $this->assertStringContainsString('<span class="badge bg-warning text-dark" data-evaluation-status>下書き</span>', $card);
        $this->assertStringNotContainsString('data-evaluation-record-link', $card);
        $this->assertStringContainsString(">落ち着いた性格です。\nお散歩が好きです。</textarea>", $card);
        // 最終更新は評価文のカードの中ではなく、画面の下（50問の回答のあと）に右寄せ（S-0403 と同じ。2026-10-18 変更）
        $this->assertStringNotContainsString('最終更新', $card);
        $this->assertSame('最終更新: 2026/10/18 14:30 佐藤', $this->updatedLine($html));
        $this->assertStringContainsString('<div class="text-end text-muted small mb-3" data-evaluation-updated>', $html);
        $this->assertGreaterThan(strpos($html, 'data-personality-diagnosis-answers'), strpos($html, 'data-evaluation-updated'));
    }

    public function test_表示しただけでは操作の記録を残さない(): void
    {
        $this->page($this->addResult('past-1', '2026-06-01 09:00:00'));

        $this->assertSame(0, AccessLog::query()->count());
    }

    public function test_空で保存するとなしに戻る(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '下書き']);

        $this->update($result, '   ')->assertRedirect($this->url($result));

        $this->assertNull($result->fresh()->evaluation_text);
        $this->assertSame('なし', $result->fresh()->evaluation_status_label);
        // 保存した人と日時は残す（最後に保存した操作として）
        $this->assertSame(200, (int) $result->fresh()->evaluation_updated_by);
    }

    public function test_10000文字までは保存でき10001文字はエラーで保存しない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        // 改行（CRLF）は 1 文字と数える
        $this->update($result, str_repeat('あ', 9998)."\r\n".'い')->assertSessionHasNoErrors();
        $this->assertSame(10000, mb_strlen($result->fresh()->evaluation_text));

        $response = $this->update($result, str_repeat('う', 10001));
        $response->assertRedirect($this->url($result));
        $response->assertSessionHasErrors(['evaluation_text' => '評価文は10000文字以内で入力してください。']);
        $this->assertStringStartsWith('あ', $result->fresh()->evaluation_text);
    }

    public function test_入力エラーで戻ったときは入力した文と上部の案内と欄の下の文を出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->followingRedirects()->from($this->url($result))
            ->put(route('trainee-personality-diagnosis-results.update', [$this->trainee, $result]), ['evaluation_text' => str_repeat('う', 10001)])
            ->getContent();

        $this->assertStringContainsString('入力内容に誤りがあります。', $html);
        $this->assertStringContainsString('評価文は10000文字以内で入力してください。', $html);
        $this->assertMatchesRegularExpression('/<textarea class="form-control\s+is-invalid\s*" id="evaluation_text"/u', $html);
        $this->assertStringContainsString(str_repeat('う', 10001).'</textarea>', $html);
    }

    public function test_公開済みは表示だけで記録へのリンクを出し保存を受け付けない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => "公開した文\n2 行目", 'evaluation_updated_by' => 200, 'evaluation_updated_at' => '2026-10-17 09:00:00']);
        $recordId = $this->linkRecord($result, '2026-10-18');

        $card = $this->evaluationCard($this->page($result));

        $this->assertStringContainsString('<span class="badge bg-success" data-evaluation-status>公開済み</span>', $card);
        $this->assertStringContainsString("<div style=\"white-space: pre-wrap;\" data-evaluation-text>公開した文\n2 行目</div>", $card);
        $this->assertStringNotContainsString('<textarea', $card);
        $this->assertStringNotContainsString('<form', $card);
        $this->assertStringNotContainsString('>更新</button>', $card);
        // 見出しの行は「評価文」→ バッジ「公開済み」→「（トレーニング記録）」のリンク。ボタンは出さない
        $header = $this->evaluationHeader($card);
        $this->assertStringNotContainsString('data-evaluation-buttons', $card);
        $this->assertStringNotContainsString('<button', $header);
        $link = '<span class="small" data-evaluation-record-link>（<a href="'.route('training-records.show', $recordId).'">トレーニング記録</a>）</span>';
        $this->assertStringContainsString($link, $header);
        $this->assertLessThan(strpos($header, $link), strpos($header, 'data-evaluation-status>公開済み</span>'));
        // カードの中の「公開済み（{日付}のトレーニング記録）」と案内の一文は出さない（2026-10-18 変更）。日付も出さない
        $this->assertStringNotContainsString('data-evaluation-published', $card);
        $this->assertStringNotContainsString('直すときは', $card);
        $this->assertStringNotContainsString('公開済み（', $card);
        $this->assertStringNotContainsString('2026/10/18', $card);
        // 公開済みでも最終更新は出す（画面の下）
        $this->assertStringNotContainsString('最終更新', $card);
        $this->assertSame('最終更新: 2026/10/17 09:00 佐藤', $this->updatedLine($this->page($result)));

        $this->update($result, '書き換え')->assertStatus(409);
        $this->assertSame("公開した文\n2 行目", $result->fresh()->evaluation_text);
    }

    public function test_保存したトレーナーが削除されていれば最終更新は日時だけ(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '下書き', 'evaluation_updated_by' => 999, 'evaluation_updated_at' => '2026-10-17 09:00:00']);

        $this->assertSame('最終更新: 2026/10/17 09:00', $this->updatedLine($this->page($result)));
    }

    public function test_評価文はエスケープして出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '<b>x</b>']);
        $this->linkRecord($result);

        $this->assertStringNotContainsString('<b>x</b>', $this->page($result));
    }

    // ---- 公開（S-0313-M01。2026-10-18 追加。要件定義書 6-16-16） ----

    private function publish(TraineePersonalityDiagnosisResult $result, mixed $text, ?Trainee $trainee = null)
    {
        return $this->from($this->url($result, $trainee))
            ->post(route('trainee-personality-diagnosis-results.publish', [$trainee ?? $this->trainee, $result]), ['evaluation_text' => $text]);
    }

    public function test_URLとルート名は設計書のとおり_公開(): void
    {
        $this->assertSame(
            url('/trainees/'.$this->trainee->id.'/personality-diagnosis-results/5/publish'),
            route('trainee-personality-diagnosis-results.publish', [$this->trainee, 5])
        );
    }

    public function test_公開すると記録を1件作り中身は設計書のとおりで記録の詳細へ移る(): void
    {
        // 日付が変わった直後（アプリの時間帯＝日本時間）でも、公開した日になる
        Carbon::setTestNow(Carbon::parse('2026-10-19 00:10:00', 'Asia/Tokyo'));
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '古い下書き']);

        $response = $this->publish($result, "保存していない直し\r\n2 行目");

        $record = TrainingRecord::query()->sole();
        $response->assertRedirect(route('training-records.show', $record));
        $response->assertSessionHas('success', 'トレーニング記録を作り、会員様に公開しました。');
        $this->assertSame($this->client->id, (int) $record->client_id);
        $this->assertSame('2026-10-19', $record->training_date->format('Y-m-d'));
        $this->assertNull($record->training_time);
        $this->assertSame(200, (int) $record->trainer1_id);
        $this->assertNull($record->trainer2_id);
        $this->assertSame("保存していない直し\n2 行目", $record->record_content);
        $this->assertNull($record->impression);
        $this->assertSame(200, (int) $record->updated_by);
        $this->assertSame($result->id, (int) $record->trainee_personality_diagnosis_result_id);
        // 欄の今の文（保存していない直し）を保存してから公開する
        $saved = $result->fresh();
        $this->assertSame("保存していない直し\n2 行目", $saved->evaluation_text);
        $this->assertSame(200, (int) $saved->evaluation_updated_by);
        $this->assertSame('2026-10-19 00:10:00', $saved->evaluation_updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('公開済み', $saved->evaluation_status_label);
    }

    public function test_公開は記録の登録と同じ操作の記録を残す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->publish($result, '評価文');

        $logs = AccessLog::query()->get();
        $this->assertCount(1, $logs);
        $this->assertSame('create_training_record', $logs[0]->action);
        $this->assertSame('TrainingRecord', $logs[0]->target_type);
        $this->assertNull($logs[0]->target_id);
    }

    public function test_公開のあとS_0313は公開済みで評価文は表示だけ(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        Carbon::setTestNow('2026-10-18 10:00:00');

        $this->publish($result, '公開した文');

        $card = $this->evaluationCard($this->page($result));
        $this->assertStringContainsString('data-evaluation-status>公開済み</span>', $card);
        $this->assertStringContainsString('data-evaluation-text>公開した文</div>', $card);
        $this->assertStringNotContainsString('<textarea', $card);
        $record = TrainingRecord::query()->sole();
        $this->assertStringContainsString('（<a href="'.route('training-records.show', $record).'">トレーニング記録</a>）', $this->evaluationHeader($card));
    }

    public function test_評価文が空なら公開できない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '下書き']);

        $response = $this->publish($result, " \r\n ");

        $response->assertRedirect($this->url($result));
        $response->assertSessionHasErrors(['evaluation_text' => '評価文を入力してください。']);
        $this->assertSame(0, TrainingRecord::query()->count());
        // 保存もしない（下書きのまま）
        $this->assertSame('下書き', $result->fresh()->evaluation_text);
    }

    public function test_10001文字は公開できない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->publish($result, str_repeat('う', 10001))
            ->assertSessionHasErrors(['evaluation_text' => '評価文は10000文字以内で入力してください。']);
        $this->assertSame(0, TrainingRecord::query()->count());
    }

    public function test_2回送っても記録は1件で2回目は作った記録の詳細へ(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->publish($result, '1 回目');
        $record = TrainingRecord::query()->sole();
        $second = $this->publish($result, '2 回目');

        $second->assertRedirect(route('training-records.show', $record));
        $second->assertSessionHas('success', 'この愛犬性格診断は、すでにトレーニング記録にして公開しています。');
        $this->assertSame(1, TrainingRecord::query()->count());
        // 2 回目の文は使わない（評価文も記録も 1 回目のまま）
        $this->assertSame('1 回目', $result->fresh()->evaluation_text);
        $this->assertSame('1 回目', $record->fresh()->record_content);
    }

    public function test_公開済みの結果に空の文を送っても入力エラーにせず作った記録の詳細へ(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '公開した文']);
        $recordId = $this->linkRecord($result);

        $this->publish($result, '')->assertRedirect(route('training-records.show', $recordId))->assertSessionHasNoErrors();
        $this->assertSame(1, TrainingRecord::query()->count());
    }

    public function test_公開した記録を削除すると下書きに戻りもう一度公開できる(): void
    {
        $this->actingAsRole('admin');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->publish($result, '公開した文');
        $first = TrainingRecord::query()->sole();

        $this->delete(route('training-records.destroy', $first))->assertRedirect(route('clients.show', $this->client->id));

        $this->assertSame(0, TrainingRecord::query()->count());
        $this->assertSame('下書き', $result->fresh()->evaluation_status_label);
        $this->assertSame('公開した文', $result->fresh()->evaluation_text);
        $this->assertStringContainsString('トレーニング記録にして公開', $this->page($result->fresh()));

        $this->publish($result, '直して公開し直した文');
        $second = TrainingRecord::query()->sole();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('直して公開し直した文', $second->record_content);
        $this->assertSame($result->id, (int) $second->trainee_personality_diagnosis_result_id);
    }

    public function test_公開の権限は表示と同じ(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $other = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'ハナ']);

        // URL のトレーニーと結果が合わなければ 404
        $this->publish($result, '評価文', $other)->assertNotFound();
        // システム管理者は 403
        $this->actingAsRole('system_admin');
        $this->publish($result, '評価文')->assertForbidden();
        // ログインしていなければログイン画面へ
        auth('web')->logout();
        $this->publish($result, '評価文')->assertRedirect(route('login'));
        $this->assertSame(0, TrainingRecord::query()->count());
        // 一般のトレーナーは公開できる
        $this->actingAsRole('staff');
        $this->publish($result, '評価文');
        $this->assertSame(1, TrainingRecord::query()->count());
    }

    // ---- 削除（管理者だけ。S-0313-M02） ----

    public function test_管理者には赤の塗りの削除と確認モーダルを出す(): void
    {
        $this->actingAsRole('admin');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page($result);

        $this->assertStringContainsString('<button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#personality-diagnosis-delete">削除</button>', $html);
        $this->assertStringContainsString('<h5 class="modal-title" id="personality-diagnosis-delete-label">愛犬性格診断の結果の削除</h5>', $html);
        $this->assertStringContainsString('2026/06/01 の愛犬性格診断の結果を削除します。元に戻せません。', $html);
        $this->assertStringContainsString('action="'.route('trainee-personality-diagnosis-results.destroy', [$this->trainee, $result]).'"', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-danger">削除する</button>', $html);
        $this->assertStringContainsString('<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>', $html);
        // 結び付いた記録がなければ、記録の文は出さない
        $this->assertStringNotContainsString('data-personality-diagnosis-delete-linked', $html);
    }

    public function test_結び付いた記録があれば確認の文に記録の日付と残ることを足す(): void
    {
        $this->actingAsRole('admin');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->linkRecord($result, '2026-06-03');

        $this->assertStringContainsString('data-personality-diagnosis-delete-linked>2026/06/03のトレーニング記録に結び付いています。記録は残りますが、記録の中のグラフは消えます。</p>', $this->page($result));
    }

    public function test_管理者でなければ削除のボタンも確認モーダルも出さない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page($result);

        $this->assertStringNotContainsString('data-personality-diagnosis-delete', $html);
        $this->assertStringNotContainsString('btn-danger', $html);
    }

    public function test_削除するとトレーニー詳細に戻り結び付いた記録は残る(): void
    {
        $this->actingAsRole('admin');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $recordId = $this->linkRecord($result);

        $response = $this->delete(route('trainee-personality-diagnosis-results.destroy', [$this->trainee, $result]));

        $response->assertRedirect(route('trainees.show', $this->trainee));
        $response->assertSessionHas('success', '愛犬性格診断の結果を削除しました。');
        $this->assertFalse(TraineePersonalityDiagnosisResult::query()->whereKey($result->id)->exists());
        $record = TrainingRecord::query()->find($recordId);
        $this->assertNotNull($record);
        $this->assertNull($record->trainee_personality_diagnosis_result_id);
    }

    // ---- 権限 ----

    public function test_一般のトレーナーも見て保存できるが削除はできない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->get($this->url($result))->assertOk();
        $this->update($result, '下書き')->assertRedirect($this->url($result));
        $this->delete(route('trainee-personality-diagnosis-results.destroy', [$this->trainee, $result]))->assertForbidden();
        $this->assertTrue(TraineePersonalityDiagnosisResult::query()->whereKey($result->id)->exists());
    }

    public function test_システム管理者は403(): void
    {
        $this->actingAsRole('system_admin');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->get($this->url($result))->assertForbidden();
        $this->update($result, '下書き')->assertForbidden();
        $this->assertNull($result->fresh()->evaluation_text);
    }

    public function test_別のトレーニーの結果と存在しない結果は404(): void
    {
        $other = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'ハナ']);
        $othersResult = $this->addResult('past-1', '2026-06-01 09:00:00', $other);

        $this->get($this->url($othersResult))->assertNotFound();
        $this->put(route('trainee-personality-diagnosis-results.update', [$this->trainee, $othersResult]), ['evaluation_text' => '書き換え'])->assertNotFound();
        $this->get($this->url(999))->assertNotFound();
        $this->assertNull($othersResult->fresh()->evaluation_text);
        // 正しい組み合わせなら開ける
        $this->get($this->url($othersResult, $other))->assertOk();
    }

    public function test_ログインしていなければログイン画面へ(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        auth('web')->logout();

        $this->get($this->url($result))->assertRedirect(route('login'));
        $this->put(route('trainee-personality-diagnosis-results.update', [$this->trainee, $result]), ['evaluation_text' => '書き換え'])
            ->assertRedirect(route('login'));
        $this->assertNull($result->fresh()->evaluation_text);
    }
}

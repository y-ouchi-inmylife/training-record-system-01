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
use Tests\Concerns\AssertsPersonalityDiagnosisName;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 愛犬性格診断詳細（S-0313）とアドバイスの保存（2026-10-18 追加。要件定義書 6-16-13〜6-16-15、設計書 S-0313）。
 *
 * - 見出し・［« トレーニー詳細へ戻る］・結果（タイプ・この結果の時点のレーダーチャート・7 項目の点数）・アドバイス・50問の回答
 * - アドバイス：どの状態でもテキストエリアと［更新］（緑）。更新は 10,000 文字まで・空なら「なし」に戻る（公開済みは空にできない）・
 *   保存した人と日時・操作の記録。公開済みも直せる（完了メッセージで会員様に見えることを伝える）
 * - ［公開］（S-0313-M01）：欄の今の文を保存してから、公開した日時・トレーナーを入れる。トレーニング記録は作らない。
 *   2 回目は何もせず S-0313 へ（2026-10-18 変更：その前は公開で記録を作っていた）
 * - ［公開取消］（白抜きの赤。S-0313-M03。2026-10-18 追加）：公開した日時・トレーナーを空に戻す（アドバイスは残る）
 * - 削除（管理者だけ。S-0313-M02。S-0309-M01 から移した）：公開済みのときは会員様の画面からも消えることの文
 * - 権限：トレーナー（一般・管理者）は表示・保存、システム管理者は 403、URL のトレーニーと結果が合わなければ 404、未ログインはログイン画面へ
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。点数は固定データを結果として入れる
 */
class TraineePersonalityDiagnosisResultShowTest extends TestCase
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

    /** アドバイスを公開済みにする（公開した日時・トレーナーを直接入れる） */
    private function markPublished(TraineePersonalityDiagnosisResult $result, string $text = '公開した文', string $at = '2026-10-18 10:00:00'): void
    {
        $result->update(['evaluation_text' => $text, 'evaluation_published_at' => $at, 'evaluation_published_by' => 200]);
    }

    /** アドバイスのカード */
    private function evaluationCard(string $html): string
    {
        $start = strpos($html, 'data-personality-diagnosis-evaluation');
        $this->assertNotFalse($start);

        // アドバイスのカードの終わり（2026-10-19 変更：すぐ下にスタッフ用メモのカードを置いた）
        return substr($html, $start, strpos($html, 'data-personality-diagnosis-staff-memo', $start) - $start);
    }

    /** 画面の下の「最終更新」の文（前後の空白を除く）。出ていなければ null */
    private function updatedLine(string $html): ?string
    {
        if (preg_match('/<div class="text-end text-muted small mb-3" data-evaluation-updated>\s*(.*?)\s*<\/div>/us', $html, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /** アドバイスのカードの見出しの行（card-header の中） */
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
        // 2026-10-19 変更：会員様向けの文は「アドバイス」と呼ぶ（その前は「評価文」）。カードの見出し・入力欄の名前・確認のモーダル・
        // お知らせのどこにも「評価文」は出さない
        $this->assertStringNotContainsString('評価文', $html);
        $this->assertMatchesRegularExpression('/<h6 class="mb-0">アドバイス<\/h6>/u', $html);
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

    public function test_見出しの右に会員のリンクと会員の内部IDを出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page($result);

        // 2026-10-18 変更：トレーニー詳細（S-0309）の見出しの行と同じ形。内部ID は S-0305・S-0309・S-0403 と同じく会員の内部ID
        $this->assertMatchesRegularExpression(
            '/<div class="d-flex align-items-center gap-2 flex-wrap pb-2 border-bottom" data-personality-diagnosis-heading>\s*'
            .'<h2 class="mb-0">モモの愛犬性格診断（2026\/06\/01）<\/h2>\s*'
            .'<div class="d-flex align-items-baseline gap-2 ms-3">\s*<span class="text-muted small">会員<\/span>\s*'
            .'<a href="'.preg_quote(route('clients.show', $this->client), '/').'">山田 太郎<\/a>\s*<\/div>\s*'
            .'<div class="d-flex align-items-baseline gap-2 ms-3">\s*<span class="text-muted small">内部ID<\/span>\s*'
            .'<span class="font-monospace fs-5">0001<\/span>\s*<\/div>\s*<\/div>/u',
            $html
        );
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

    public function test_公開の確認のモーダルの名前は文のファイルの1か所から読む(): void
    {
        $this->overridePersonalityDiagnosisName('テスト診断');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '下書き']);

        $html = $this->page($result);

        $this->assertStringContainsString('<h5 class="modal-title" id="personality-diagnosis-publish-label">テスト診断の公開</h5>', $html);
        $this->assertStringContainsString('<p class="mb-0">会員のマイページにテスト診断を公開しますか？</p>', $html);
    }

    public function test_下書きには青の公開のボタンと確認のモーダルを出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '下書き']);

        $html = $this->page($result);

        // ボタンの名前は［公開］（2026-10-18 変更：その前は［トレーニング記録にして公開］）
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#personality-diagnosis-publish"\s*data-evaluation-publish\s*>公開<\/button>/u', $html);
        $this->assertStringNotContainsString('トレーニング記録にして公開', $html);
        // ［更新］の右
        $this->assertLessThan(strpos($html, 'data-evaluation-publish'), strpos($html, '>更新</button>'));
        // 確認のモーダル（M01）の見出しと文（2026-10-18 変更：ユーザーレビューで変えた）
        $this->assertStringContainsString('<h5 class="modal-title" id="personality-diagnosis-publish-label">愛犬性格診断の公開</h5>', $html);
        // 公開取消の確認（M03）と言い方をそろえた問いかけの 1 文（2026-10-18 変更：その前は「〜を公開します。よろしいですか？」）
        $this->assertStringContainsString('<p class="mb-0">会員のマイページに愛犬性格診断を公開しますか？</p>', $html);
        // 公開の確認モーダルの中だけで見る（2026-10-19 から、AI の下書きの確かめモーダル〔M04〕に「よろしいですか？」がある）
        $publishModal = substr($html, strpos($html, 'data-personality-diagnosis-publish>'));
        $publishModal = substr($publishModal, 0, strpos($publishModal, '</form>'));
        $this->assertStringNotContainsString('よろしいですか？', $publishModal);
        $this->assertStringNotContainsString('愛犬性格診断を公開します。', $html);
        // 前の文は出ない
        $this->assertStringNotContainsString('評価文の公開<', $html);
        $this->assertStringNotContainsString('会員様のマイページに公開します。', $html);
        $this->assertStringNotContainsString('公開したあとも、この画面で直せます。', $html);
        $this->assertStringContainsString('action="'.route('trainee-personality-diagnosis-results.publish', [$this->trainee, $result]).'" class="d-inline m-0" data-evaluation-publish-form>', $html);
        $this->assertStringContainsString('<input type="hidden" name="evaluation_text" value="">', $html);
        $this->assertStringContainsString('<button type="submit" class="btn btn-primary">公開する</button>', $html);
        $this->assertStringContainsString('<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>', $html);
        // 公開取消は出さない
        $this->assertStringNotContainsString('公開取消', $html);
        $this->assertStringNotContainsString('公開をやめる', $html);
        $this->assertStringNotContainsString('data-personality-diagnosis-unpublish', $html);
    }

    public function test_アドバイスが空なら公開のボタンは押せない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page($result);

        $this->assertMatchesRegularExpression('/data-evaluation-publish\s+disabled>公開<\/button>/u', $html);
    }

    public function test_公開済みには公開のボタンとモーダルを出さず公開を取り消すとM03を出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->markPublished($result);

        $html = $this->page($result);

        // ［公開］とモーダルは出さない（公開した日の data-evaluation-published-on とは別の属性）
        $this->assertDoesNotMatchRegularExpression('/data-evaluation-publish[\s>]/', $html);
        $this->assertStringNotContainsString('data-evaluation-publish-form>', $html);
        $this->assertStringNotContainsString('data-personality-diagnosis-publish', $html);
        // ［公開取消］は白抜きの赤。M03 の実行は赤の塗り（2026-10-18 変更：ユーザーレビューで［公開をやめる］を［公開取消］にし、M03 の文を変えた）
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#personality-diagnosis-unpublish"\s*data-evaluation-unpublish>公開取消<\/button>/u', $html);
        $this->assertStringContainsString('<h5 class="modal-title" id="personality-diagnosis-unpublish-label">愛犬性格診断の公開を取り消す</h5>', $html);
        $this->assertStringContainsString('<p class="mb-0">会員のマイページから愛犬性格診断の公開を取り消しますか？</p>', $html);
        $this->assertStringContainsString('action="'.route('trainee-personality-diagnosis-results.unpublish', [$this->trainee, $result]).'" class="d-inline m-0" data-evaluation-unpublish-form>', $html);
        // 実行ボタンは、ほかの取消の確認と同じ［取り消す］
        $this->assertStringContainsString('<button type="submit" class="btn btn-danger">取り消す</button>', $html);
        $this->assertStringContainsString('<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>', $html);
        // 前の文言は出ない
        $this->assertStringNotContainsString('公開をやめる', $html);
        $this->assertStringNotContainsString('会員様のマイページから評価文を外します。', $html);
    }

    public function test_公開取消の確認のモーダルの名前は文のファイルの1か所から読む(): void
    {
        $this->overridePersonalityDiagnosisName('テスト診断');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->markPublished($result);

        $html = $this->page($result);

        $this->assertStringContainsString('<h5 class="modal-title" id="personality-diagnosis-unpublish-label">テスト診断の公開を取り消す</h5>', $html);
        $this->assertStringContainsString('<p class="mb-0">会員のマイページからテスト診断の公開を取り消しますか？</p>', $html);
    }

    // ---- アドバイス ----

    public function test_なしは灰のバッジと緑の更新と空のテキストエリアで最終更新は出さない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $card = $this->evaluationCard($this->page($result));

        $this->assertStringContainsString('<span class="badge bg-secondary" data-evaluation-status>なし</span>', $card);
        $this->assertStringContainsString('action="'.route('trainee-personality-diagnosis-results.update', [$this->trainee, $result]).'" novalidate id="evaluation-form" data-evaluation-form>', $card);
        $this->assertStringContainsString('name="_method" value="PUT"', $card);
        $this->assertMatchesRegularExpression('/<textarea class="form-control " id="evaluation_text" name="evaluation_text"\s*rows="12" maxlength="10000"><\/textarea>/u', $card);
        $this->assertStringContainsString('<label for="evaluation_text" class="visually-hidden">アドバイス</label>', $card);
        // ボタンの名前は［更新］（2026-10-18 変更：その前は［保存］）。［更新］はフォームの外（カードの見出しの行）にあり、
        // form 属性でアドバイスのフォームを送る
        $this->assertStringContainsString('<button type="submit" form="evaluation-form" class="btn btn-success">更新</button>', $card);
        $this->assertStringNotContainsString('>保存</button>', $card);
        // 見出しの行：「アドバイス」→ すぐ右にバッジ → 右に［更新］［公開］（2026-10-18 変更：その前はテキストエリアの上の行）
        $header = $this->evaluationHeader($card);
        $this->assertStringContainsString('<div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">', $header);
        $this->assertLessThan(strpos($header, 'data-evaluation-status>'), strpos($header, '<h6 class="mb-0">アドバイス</h6>'));
        $this->assertLessThan(strpos($header, '>更新</button>'), strpos($header, 'data-evaluation-status>'));
        $this->assertLessThan(strpos($header, '>公開</button>'), strpos($header, '>更新</button>'));
        $this->assertStringContainsString('<div class="d-flex flex-wrap gap-2 ms-auto" data-evaluation-buttons>', $header);
        // 大きさは通常（ほかのカードの見出しのボタンと同じ。btn-sm にしない）
        $this->assertStringNotContainsString('btn-sm', $header);
        // テキストエリアの上のボタンの行はなくした（ボタンは見出しの行だけ）
        $this->assertStringNotContainsString('<button', substr($card, strpos($card, '<div class="card-body">')));
        $this->assertStringNotContainsString('最終更新', $card);
        // 公開した日は公開済みのときだけ
        $this->assertStringNotContainsString('data-evaluation-published-on', $card);
        // 一度も更新していなければ、画面の下にも出さない
        $this->assertNull($this->updatedLine($this->page($result)));
    }

    public function test_保存すると下書きになり保存した人と日時と操作の記録が残る(): void
    {
        Carbon::setTestNow('2026-10-18 14:30:00');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $response = $this->update($result, "落ち着いた性格です。\r\nお散歩が好きです。");

        $response->assertRedirect($this->url($result));
        $response->assertSessionHas('success', 'アドバイスを更新しました。');
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
        $this->assertSame('愛犬性格診断アドバイス編集', AccessLog::actionLabels()[$logs[0]->action]);

        $html = $this->page($saved);
        $card = $this->evaluationCard($html);
        $this->assertStringContainsString('<span class="badge bg-warning text-dark" data-evaluation-status>下書き</span>', $card);
        $this->assertStringNotContainsString('data-evaluation-record-link', $card);
        $this->assertStringContainsString(">落ち着いた性格です。\nお散歩が好きです。</textarea>", $card);
        // 最終更新はアドバイスのカードの中ではなく、画面の下（50問の回答のあと）に右寄せ（S-0403 と同じ。2026-10-18 変更）
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
        $response->assertSessionHasErrors(['evaluation_text' => 'アドバイスは10000文字以内で入力してください。']);
        $this->assertStringStartsWith('あ', $result->fresh()->evaluation_text);
    }

    public function test_入力エラーで戻ったときは入力した文と上部の案内と欄の下の文を出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->followingRedirects()->from($this->url($result))
            ->put(route('trainee-personality-diagnosis-results.update', [$this->trainee, $result]), ['evaluation_text' => str_repeat('う', 10001)])
            ->getContent();

        $this->assertStringContainsString('入力内容に誤りがあります。', $html);
        $this->assertStringContainsString('アドバイスは10000文字以内で入力してください。', $html);
        $this->assertMatchesRegularExpression('/<textarea class="form-control\s+is-invalid\s*" id="evaluation_text"/u', $html);
        $this->assertStringContainsString(str_repeat('う', 10001).'</textarea>', $html);
    }

    public function test_公開済みは見出しの行に公開した日と更新と公開取消を出しテキストエリアで直せる(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_updated_by' => 200, 'evaluation_updated_at' => '2026-10-17 09:00:00']);
        $this->markPublished($result, "公開した文\n2 行目", '2026-10-18 10:00:00');

        $html = $this->page($result);
        $card = $this->evaluationCard($html);
        $header = $this->evaluationHeader($card);

        // 見出しの行：「アドバイス」→ バッジ「公開済み」→ 公開した日 → 右に［更新］［公開取消］
        $this->assertStringContainsString('<span class="badge bg-success" data-evaluation-status>公開済み</span>', $header);
        $this->assertStringContainsString('<span class="text-muted small" data-evaluation-published-on>2026/10/18 公開</span>', $header);
        $this->assertLessThan(strpos($header, 'data-evaluation-published-on'), strpos($header, 'data-evaluation-status'));
        $this->assertLessThan(strpos($header, '>更新</button>'), strpos($header, 'data-evaluation-published-on'));
        $this->assertLessThan(strpos($header, '>公開取消</button>'), strpos($header, '>更新</button>'));
        $this->assertStringContainsString('<button type="submit" form="evaluation-form" class="btn btn-success">更新</button>', $header);
        // テキストエリアのまま直せる（2026-10-18 変更：その前は表示だけで、作った記録へのリンク）
        $this->assertStringContainsString(">公開した文\n2 行目</textarea>", $card);
        $this->assertStringNotContainsString('data-evaluation-text', $card);
        $this->assertStringNotContainsString('data-evaluation-record-link', $html);
        $this->assertStringNotContainsString('トレーニング記録', $card);
        // 最終更新は画面の下
        $this->assertSame('最終更新: 2026/10/17 09:00 佐藤', $this->updatedLine($html));
    }

    public function test_公開済みを更新すると会員様に見えることを伝え公開は続く(): void
    {
        Carbon::setTestNow('2026-10-19 08:00:00');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->markPublished($result);

        $response = $this->update($result, '直した文');

        $response->assertRedirect($this->url($result));
        $response->assertSessionHas('success', 'アドバイスを更新しました。会員様のマイページにも反映されています。');
        $saved = $result->fresh();
        $this->assertSame('直した文', $saved->evaluation_text);
        $this->assertSame('公開済み', $saved->evaluation_status_label);
        // 公開した日時は変わらない
        $this->assertSame('2026-10-18 10:00:00', $saved->evaluation_published_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19 08:00:00', $saved->evaluation_updated_at->format('Y-m-d H:i:s'));
    }

    public function test_公開済みは空にできない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->markPublished($result);

        $response = $this->update($result, " \r\n ");

        $response->assertRedirect($this->url($result));
        $response->assertSessionHasErrors(['evaluation_text' => '公開中のアドバイスは空にできません。公開を取り消してから消してください。']);
        $this->assertSame('公開した文', $result->fresh()->evaluation_text);
        $this->assertSame('公開済み', $result->fresh()->evaluation_status_label);
    }

    public function test_保存したトレーナーが削除されていれば最終更新は日時だけ(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '下書き', 'evaluation_updated_by' => 999, 'evaluation_updated_at' => '2026-10-17 09:00:00']);

        $this->assertSame('最終更新: 2026/10/17 09:00', $this->updatedLine($this->page($result)));
    }

    public function test_アドバイスはエスケープして出す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->markPublished($result, '<b>x</b>');

        $this->assertStringNotContainsString('<b>x</b>', $this->page($result));
    }

    // ---- 公開（S-0313-M01）・公開取消（S-0313-M03）。2026-10-18 追加。要件定義書 6-16-16 ----

    private function publish(TraineePersonalityDiagnosisResult $result, mixed $text, ?Trainee $trainee = null)
    {
        return $this->from($this->url($result, $trainee))
            ->post(route('trainee-personality-diagnosis-results.publish', [$trainee ?? $this->trainee, $result]), ['evaluation_text' => $text]);
    }

    private function unpublish(TraineePersonalityDiagnosisResult $result, ?Trainee $trainee = null)
    {
        return $this->from($this->url($result, $trainee))
            ->post(route('trainee-personality-diagnosis-results.unpublish', [$trainee ?? $this->trainee, $result]));
    }

    public function test_URLとルート名は設計書のとおり_公開と公開取消(): void
    {
        $base = url('/trainees/'.$this->trainee->id.'/personality-diagnosis-results/5');
        $this->assertSame($base.'/publish', route('trainee-personality-diagnosis-results.publish', [$this->trainee, 5]));
        $this->assertSame($base.'/unpublish', route('trainee-personality-diagnosis-results.unpublish', [$this->trainee, 5]));
    }

    public function test_公開すると公開した日時とトレーナーが入り記録は作らずS_0313に戻る(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-19 00:10:00', 'Asia/Tokyo'));
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '古い下書き']);

        $response = $this->publish($result, "保存していない直し\r\n2 行目");

        $response->assertRedirect($this->url($result));
        $response->assertSessionHas('success', 'アドバイスを公開しました。');
        $saved = $result->fresh();
        $this->assertSame('公開済み', $saved->evaluation_status_label);
        $this->assertSame('2026-10-19 00:10:00', $saved->evaluation_published_at->format('Y-m-d H:i:s'));
        $this->assertSame(200, (int) $saved->evaluation_published_by);
        $this->assertSame('佐藤', $saved->evaluationPublishedBy->name);
        // 欄の今の文（保存していない直し）を保存してから公開する
        $this->assertSame("保存していない直し\n2 行目", $saved->evaluation_text);
        $this->assertSame(200, (int) $saved->evaluation_updated_by);
        $this->assertSame('2026-10-19 00:10:00', $saved->evaluation_updated_at->format('Y-m-d H:i:s'));
        // トレーニング記録は作らない（2026-10-18 変更）
        $this->assertSame(0, TrainingRecord::query()->count());
    }

    public function test_公開と公開取消は操作の記録を残す(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->publish($result, 'アドバイス');
        $this->unpublish($result);

        $logs = AccessLog::query()->orderBy('id')->get();
        $this->assertSame(['publish_trainee_personality_diagnosis_evaluation', 'unpublish_trainee_personality_diagnosis_evaluation'], $logs->pluck('action')->all());
        foreach ($logs as $log) {
            $this->assertSame('Trainee', $log->target_type);
            $this->assertSame($this->trainee->id, (int) $log->target_id);
        }
        $this->assertSame('愛犬性格診断アドバイス公開', AccessLog::actionLabels()['publish_trainee_personality_diagnosis_evaluation']);
        // 表示名は「〜アドバイス公開取消」（2026-10-18 変更：その前は「〜評価文公開停止」）
        $this->assertSame('愛犬性格診断アドバイス公開取消', AccessLog::actionLabels()['unpublish_trainee_personality_diagnosis_evaluation']);
        $this->assertSame(0, AccessLog::query()->where('action', 'create_training_record')->count());
    }

    public function test_公開のあとS_0313は公開済みで直せる(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        Carbon::setTestNow('2026-10-18 10:00:00');

        $this->publish($result, '公開した文');

        $card = $this->evaluationCard($this->page($result));
        $this->assertStringContainsString('data-evaluation-status>公開済み</span>', $card);
        $this->assertStringContainsString('data-evaluation-published-on>2026/10/18 公開</span>', $card);
        $this->assertStringContainsString('>公開した文</textarea>', $card);
        $this->assertStringContainsString('>公開取消</button>', $card);
    }

    public function test_アドバイスが空なら公開できない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '下書き']);

        $response = $this->publish($result, " \r\n ");

        $response->assertRedirect($this->url($result));
        $response->assertSessionHasErrors(['evaluation_text' => 'アドバイスを入力してください。']);
        // 保存もしない（下書きのまま）
        $this->assertSame('下書き', $result->fresh()->evaluation_text);
        $this->assertNull($result->fresh()->evaluation_published_at);
    }

    public function test_10001文字は公開できない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->publish($result, str_repeat('う', 10001))
            ->assertSessionHasErrors(['evaluation_text' => 'アドバイスは10000文字以内で入力してください。']);
        $this->assertNull($result->fresh()->evaluation_published_at);
    }

    public function test_2回送ってもエラーにせず公開した日時も文も1回目のまま(): void
    {
        Carbon::setTestNow('2026-10-18 10:00:00');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $this->publish($result, '1 回目');
        Carbon::setTestNow('2026-10-18 10:05:00');
        $second = $this->publish($result, '');

        $second->assertRedirect($this->url($result));
        $second->assertSessionHasNoErrors();
        $second->assertSessionHas('success', 'このアドバイスは、すでに公開しています。');
        $saved = $result->fresh();
        $this->assertSame('1 回目', $saved->evaluation_text);
        $this->assertSame('2026-10-18 10:00:00', $saved->evaluation_published_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, TrainingRecord::query()->count());
    }

    public function test_公開を取り消すと下書きに戻りアドバイスは残る(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->markPublished($result);

        $response = $this->unpublish($result);

        $response->assertRedirect($this->url($result));
        // 完了メッセージは「{名前}の公開を取り消しました。」（2026-10-18 変更：その前は「評価文の公開をやめました。」）
        $response->assertSessionHas('success', '愛犬性格診断の公開を取り消しました。');
        $saved = $result->fresh();
        $this->assertSame('下書き', $saved->evaluation_status_label);
        $this->assertSame('公開した文', $saved->evaluation_text);
        $this->assertNull($saved->evaluation_published_at);
        $this->assertNull($saved->evaluation_published_by);
    }

    public function test_公開を取り消すときは欄の直しを保存しない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->markPublished($result);

        $this->from($this->url($result))->post(route('trainee-personality-diagnosis-results.unpublish', [$this->trainee, $result]), ['evaluation_text' => '保存していない直し']);

        $this->assertSame('公開した文', $result->fresh()->evaluation_text);
    }

    public function test_公開していないのに公開取消を送ってもエラーにしない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $result->update(['evaluation_text' => '下書き']);

        $response = $this->unpublish($result);

        $response->assertRedirect($this->url($result));
        $response->assertSessionHas('success', 'このアドバイスは公開していません。');
        $this->assertSame('下書き', $result->fresh()->evaluation_status_label);
    }

    public function test_公開取消のあともう一度公開すると公開した日時はそのときになる(): void
    {
        Carbon::setTestNow('2026-10-18 10:00:00');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->publish($result, '最初の文');
        $this->unpublish($result);

        Carbon::setTestNow('2026-10-20 15:00:00');
        $this->publish($result, '直して公開し直した文');

        $saved = $result->fresh();
        $this->assertSame('公開済み', $saved->evaluation_status_label);
        $this->assertSame('直して公開し直した文', $saved->evaluation_text);
        $this->assertSame('2026-10-20 15:00:00', $saved->evaluation_published_at->format('Y-m-d H:i:s'));
    }

    public function test_公開と公開取消の権限は表示と同じ(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $other = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'ハナ']);

        // URL のトレーニーと結果が合わなければ 404
        $this->publish($result, 'アドバイス', $other)->assertNotFound();
        $this->unpublish($result, $other)->assertNotFound();
        // システム管理者は 403
        $this->actingAsRole('system_admin');
        $this->publish($result, 'アドバイス')->assertForbidden();
        // ログインしていなければログイン画面へ
        auth('web')->logout();
        $this->publish($result, 'アドバイス')->assertRedirect(route('login'));
        $this->unpublish($result)->assertRedirect(route('login'));
        $this->assertNull($result->fresh()->evaluation_published_at);
        // 一般のトレーナーは公開・公開取消ができる
        $this->actingAsRole('staff');
        $this->publish($result, 'アドバイス');
        $this->assertSame('公開済み', $result->fresh()->evaluation_status_label);
        $this->unpublish($result);
        $this->assertSame('下書き', $result->fresh()->evaluation_status_label);
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
        // 公開済みでなければ、会員様の画面から消えることの文は出さない
        $this->assertStringNotContainsString('data-personality-diagnosis-delete-published', $html);
    }

    public function test_公開済みなら確認の文に会員様の画面から消えることを足す(): void
    {
        $this->actingAsRole('admin');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->markPublished($result);

        $html = $this->page($result);

        $this->assertStringContainsString('data-personality-diagnosis-delete-published>公開済みのアドバイスも、会員様のマイページから消えます。</p>', $html);
        $this->assertStringNotContainsString('結び付いています', $html);
    }

    public function test_管理者でなければ削除のボタンも確認モーダルも出さない(): void
    {
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');

        $html = $this->page($result);

        $this->assertStringNotContainsString('data-personality-diagnosis-delete', $html);
        $this->assertStringNotContainsString('btn-danger', $html);
    }

    public function test_削除するとトレーニー詳細に戻り公開済みのアドバイスも一緒に消える(): void
    {
        $this->actingAsRole('admin');
        $result = $this->addResult('past-1', '2026-06-01 09:00:00');
        $this->markPublished($result);

        $response = $this->delete(route('trainee-personality-diagnosis-results.destroy', [$this->trainee, $result]));

        $response->assertRedirect(route('trainees.show', $this->trainee));
        $response->assertSessionHas('success', '愛犬性格診断の結果を削除しました。');
        $this->assertFalse(TraineePersonalityDiagnosisResult::query()->whereKey($result->id)->exists());
        $this->assertSame(0, TrainingRecord::query()->count());
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

    // ---- ［AIで下書き］とスタッフ用メモ（2026-10-19 追加。設計書 S-0313） ----

    public function test_AIで下書きのボタンは更新の左で青の枠線(): void
    {
        $result = $this->addResult('past-1', '2026-10-15 10:30:00');

        $header = $this->evaluationHeader($this->evaluationCard($this->page($result)));

        $this->assertMatchesRegularExpression(
            '/<button type="button" class="btn btn-outline-primary" data-evaluation-draft\s*data-url="'.preg_quote(route('trainee-personality-diagnosis-results.evaluation-draft', [$this->trainee, $result]), '/').'">AIで下書き<\/button>/u',
            $header
        );
        $this->assertLessThan(strpos($header, '>更新</button>'), strpos($header, '>AIで下書き</button>'));
    }

    public function test_スタッフ用メモの欄はアドバイスの下で会員様には表示されないの文と緑の更新(): void
    {
        $result = $this->addResult('past-1', '2026-10-15 10:30:00');
        $result->update(['staff_memo' => 'メモの文']);

        $html = $this->page($result);

        $this->assertLessThan(strpos($html, 'data-personality-diagnosis-staff-memo'), strpos($html, 'data-personality-diagnosis-evaluation'));
        $this->assertLessThan(strpos($html, 'data-personality-diagnosis-answers'), strpos($html, 'data-personality-diagnosis-staff-memo'));
        $card = substr($html, strpos($html, 'data-personality-diagnosis-staff-memo'));
        $card = substr($card, 0, strpos($card, 'data-personality-diagnosis-answers'));
        $this->assertStringContainsString('<h6 class="mb-0">スタッフ用メモ</h6>', $card);
        $this->assertStringContainsString('<button type="submit" form="staff-memo-form" class="btn btn-success">更新</button>', $card);
        // 「（会員様には表示されません）」は見出しのすぐ右（見出しの行の中）。本文には出さない（2026-10-19 変更：その前は本文の上）。
        // 見た目はアドバイスの欄の「YYYY/MM/DD 公開」と同じ text-muted small
        $header = substr($card, 0, strpos($card, '<div class="card-body">'));
        $body = substr($card, strpos($card, '<div class="card-body">'));
        $this->assertMatchesRegularExpression(
            '/<div class="d-flex flex-wrap align-items-center gap-2">\s*<h6 class="mb-0">スタッフ用メモ<\/h6>\s*'
            .'<span class="text-muted small" id="staff-memo-note" data-staff-memo-note>（会員様には表示されません）<\/span>\s*<\/div>/u',
            $header
        );
        $this->assertStringNotContainsString('会員様には表示されません', $body);
        $this->assertSame(1, substr_count($card, '（会員様には表示されません）'));
        // 全角の括弧で括り、文の終わりの「。」は付けない（2026-10-19 変更：その前は「会員様には表示されません。」）
        $this->assertStringNotContainsString('会員様には表示されません。', $card);
        $this->assertStringContainsString('action="'.route('trainee-personality-diagnosis-results.staff-memo.update', [$this->trainee, $result]).'"', $card);
        // 入力欄の説明として、見出しの右の文を読み上げで結び付ける（aria-describedby）
        $this->assertMatchesRegularExpression('/<textarea class="form-control " id="staff_memo" name="staff_memo" aria-describedby="staff-memo-note"\s*rows="6" maxlength="10000">メモの文<\/textarea>/u', $card);
        // 公開・公開取消はない
        $this->assertStringNotContainsString('公開', $card);
    }

    public function test_AIの下書きの確かめのモーダルとお知らせの置き場所(): void
    {
        $result = $this->addResult('past-1', '2026-10-15 10:30:00');

        $html = $this->page($result);

        $modal = substr($html, strpos($html, 'data-evaluation-draft-confirm>'));
        $modal = substr($modal, 0, strpos($modal, '</div>', strpos($modal, 'modal-footer')) + 6);
        $this->assertStringContainsString('<h5 class="modal-title" id="personality-diagnosis-draft-confirm-label">AIで下書き</h5>', $modal);
        $this->assertStringContainsString('<p class="mb-0">アドバイスとスタッフ用メモを、AIの下書きで置き換えます。よろしいですか？</p>', $modal);
        // ［キャンセル］は灰色の塗り（2026-10-19 変更：この画面のほかの確認のモーダルの［キャンセル］とそろえた。その前は赤の枠線）
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル<\/button>\s*<button type="button" class="btn btn-primary" data-evaluation-draft-replace>置き換える<\/button>/u', $modal);
        $this->assertStringNotContainsString('btn-outline-danger', $modal);
        // お知らせはアドバイスのカードの上（最初は出さない）
        $this->assertStringContainsString('<div class="alert d-none" role="status" data-evaluation-draft-notice></div>', $html);
        $this->assertLessThan(strpos($html, 'data-personality-diagnosis-evaluation'), strpos($html, 'data-evaluation-draft-notice'));
        // ブラウザの confirm は使わない
        $this->assertStringNotContainsString('confirm(', $html);
        // 画面のスクリプトの文
        $this->assertStringContainsString("'作成中…'", $html);
        $this->assertStringContainsString('AIの下書きを入れました。内容を確かめて、アドバイスとスタッフ用メモをそれぞれ［更新］で保存してください。', $html);
        $this->assertStringContainsString('スタッフ用メモは作れませんでした。', $html);
        $this->assertStringContainsString('AIの下書きを作れませんでした。時間をおいてもう一度お試しください。', $html);
    }

    public function test_確かめのモーダルのキャンセルはどれも灰色の塗り(): void
    {
        $result = $this->addResult('past-1', '2026-10-15 10:30:00');
        $this->markPublished($result);
        $this->actingAsRole('admin');

        $html = $this->page($result);

        // 画面の確認のモーダル（削除〔管理者だけ〕・公開取消・AIで下書き）の［キャンセル］は、すべて同じ見た目
        preg_match_all('/<button type="button" class="([^"]*)" data-bs-dismiss="modal">キャンセル<\/button>/u', $html, $m);
        $this->assertGreaterThanOrEqual(3, count($m[1]));
        $this->assertSame(array_fill(0, count($m[1]), 'btn btn-secondary'), $m[1]);
    }

    // ---- 自由記述の質問（2026-10-19 追加。要件定義書 6-16-15、設計書 S-0313「自由記述の質問」。2026-10-19 変更：別のカードから、
    //      結果の欄の右の列の点数の表の下へ移した。同じ日に見出しをやめ、区切りの線と見えない名前にした） ----

    /** 自由記述の質問（data-personality-diagnosis-life の始まりから、結果の欄の閉じ〔アドバイスのお知らせ〕の手前まで） */
    private function lifeCard(string $html): string
    {
        $start = strpos($html, 'data-personality-diagnosis-life');
        $this->assertNotFalse($start);
        $card = substr($html, $start);

        return substr($card, 0, strpos($card, 'data-evaluation-draft-notice'));
    }

    public function test_自由記述の質問は結果の欄の右の列の点数の表の下にその診断の4問を出し空は未回答(): void
    {
        $other = $this->addResult('past-2', '2026-10-01 10:30:00');
        $other->update(['recent_events' => '別の診断の答え', 'wish' => '別の診断の悩み']);
        $result = $this->addResult('past-1', '2026-10-15 10:30:00');
        $result->update(['wish' => "引っ張らずに歩きたい\n吠えないでほしい", 'recent_events' => '', 'ideal_days' => null, 'health_concerns' => '膝のこと']);

        $html = $this->page($result);

        // 結果の欄の右の列（col-xl-8）の中、点数の表のあと。見出しは置かず、区切りの線のあとに 4 問（2026-10-19 変更：その前は
        // 「タイプ」のラベルと同じ見た目の見出しを置いていた）。4 問のまとまりには見えない名前「自由記述の質問」を付ける
        $this->assertSame(1, preg_match(
            '/<div class="col-12 col-xl-8">\s*<div class="text-muted small">タイプ<\/div>.*?data-personality-diagnosis-scores.*?<\/table>\s*<\/div>\s*'
            .'(?:\{\{--.*?--\}\}\s*)?<div class="border-bottom mt-3 mb-3" aria-hidden="true" data-life-divider><\/div>\s*'
            .'<div role="group" aria-label="自由記述の質問" data-personality-diagnosis-life>\s*<dl class="mb-0">\s*<dt class="fw-normal text-muted d-flex">/su',
            $html
        ));
        // 見出し（当時の呼び名・今の呼び名とも）は画面に出さない。別のカードの見出しにもしない
        $this->assertStringNotContainsString('暮らしの様子', $html);
        $this->assertStringNotContainsString('id="personality-diagnosis-life-label"', $html);
        $this->assertStringNotContainsString('>自由記述の質問<', $html);
        $this->assertSame(1, substr_count($html, 'data-life-divider'));
        $this->assertSame(1, substr_count($html, 'data-personality-diagnosis-life'));
        // 結果のカードの中（アドバイスのお知らせ・アドバイス・スタッフ用メモの前）。アドバイスとスタッフ用メモの欄は今のまま
        $this->assertLessThan(strpos($html, 'data-personality-diagnosis-life'), strpos($html, '<h6 class="mb-0">結果</h6>'));
        $this->assertLessThan(strpos($html, 'data-evaluation-draft-notice'), strpos($html, 'data-personality-diagnosis-life'));
        $this->assertLessThan(strpos($html, 'data-personality-diagnosis-staff-memo'), strpos($html, 'data-personality-diagnosis-evaluation'));
        $this->assertStringContainsString('<h6 class="mb-0">スタッフ用メモ</h6>', $html);

        $card = $this->lifeCard($html);
        $questions = PersonalityDiagnosisQuestions::lifeQuestions();
        $dt = fn (string $column) => '<dt class="fw-normal text-muted d-flex"><span class="me-1" aria-hidden="true">●<\/span><span>'.preg_quote($questions[$column]['question'], '/').'<\/span><\/dt>';
        $this->assertMatchesRegularExpression('/'.$dt('wish').'\s*<dd class="mb-3" style="white-space: pre-wrap;" data-life-answer="wish">引っ張らずに歩きたい\n吠えないでほしい<\/dd>/u', $card);
        $this->assertMatchesRegularExpression('/'.$dt('recent_events').'\s*<dd class="mb-3 text-muted" data-life-answer="recent_events">（未回答）<\/dd>/u', $card);
        $this->assertMatchesRegularExpression('/'.$dt('ideal_days').'\s*<dd class="mb-3 text-muted" data-life-answer="ideal_days">（未回答）<\/dd>/u', $card);
        $this->assertMatchesRegularExpression('/'.$dt('health_concerns').'\s*<dd class="mb-0" style="white-space: pre-wrap;" data-life-answer="health_concerns">膝のこと<\/dd>/u', $card);
        $this->assertSame(4, substr_count($card, '●'));
        // ほかの診断の答えは出さない。編集・削除の操作は置かない
        $this->assertStringNotContainsString('別の診断', $html);
        $this->assertStringNotContainsString('<button', $card);
        $this->assertStringNotContainsString('<form', $card);
    }

    public function test_自由記述の質問に答えていない診断は4問とも未回答(): void
    {
        $result = $this->addResult('past-1', '2026-10-15 10:30:00');

        $card = $this->lifeCard($this->page($result));

        $this->assertSame(4, substr_count($card, '>（未回答）</dd>'));
        $this->assertStringNotContainsString('pre-wrap', $card);
    }
}

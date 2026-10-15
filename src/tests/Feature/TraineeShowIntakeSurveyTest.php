<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyResponse;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * トレーニー詳細（S-0309）の暮らしの様子の欄（受付アンケートの回答）と、削除の確認の文言（2026-10 追加）。
 *
 * - 欄の見出しは「暮らしの様子」。状態の文字・日付は出さない（2026-10 変更。案内の状態は会員詳細のボタンで
 *   分かるため）。未発行・回答待ちのときは、本文に「（未回答）」とだけ出す
 * - 欄はカードに id="intake-survey" と scroll-margin-top を付ける（会員詳細の「暮らしの様子」のリンクの飛び先）
 * - 発行・表示・取消のボタン・取消モーダル・欄の中の完了メッセージは置かない（操作は会員詳細だけ）
 * - 削除の確認：計測値の文の後ろに、回答があれば回答の文、未回答の案内があれば案内の文をこの順で足し、
 *   最後を「このトレーニーを削除しますか？」で締める（どちらもないときは今までの文のまま）
 *
 * ミドルウェアは外さず、実際の HTTP で確かめる。
 */
class TraineeShowIntakeSurveyTest extends TestCase
{
    use CreatesClientCountTables;

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

        $admin = new Trainer(['name' => '管理太郎', 'role' => 'admin']);
        $admin->id = 100;
        $admin->exists = true;
        $this->actingAs($admin, 'web');

        $client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田']);
        $this->trainee = Trainee::query()->create(['client_id' => $client->id, 'name' => 'モモ']);
    }

    private function issueToken(?string $answeredAt = null): void
    {
        DB::table('trainee_intake_survey_tokens')->insert([
            'token' => bin2hex(random_bytes(16)), 'trainee_id' => $this->trainee->id,
            'answered_at' => $answeredAt, 'created_at' => '2026-10-15 09:00:00', 'updated_at' => '2026-10-15 09:00:00',
        ]);
    }

    private function addResponse(string $answeredAt = '2026-10-16 14:32:00', array $attributes = []): void
    {
        DB::table('trainee_intake_survey_responses')->insert(array_merge([
            'trainee_id' => $this->trainee->id, 'answered_at' => $answeredAt,
            'created_at' => $answeredAt, 'updated_at' => $answeredAt,
        ], $attributes));
    }

    /**
     * 受付アンケートの欄（カード）の HTML だけを取り出す。画面上部のトレーニーの情報（名前・犬種など）と
     * 見分けるため、確かめる範囲を欄の中に絞る。欄のすぐ後ろは計測値のモーダル
     */
    private function intakeCard(string $html): string
    {
        $start = strpos($html, 'id="intake-survey"');
        $this->assertNotFalse($start);
        $card = substr($html, $start);
        $end = strpos($card, 'id="measurementModal-');
        $this->assertNotFalse($end);

        return substr($card, 0, $end);
    }

    private function addMeasurements(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            DB::table('trainee_measurements')->insert([
                'trainee_id' => $this->trainee->id, 'measured_date' => "2026-10-0{$i}", 'measured_time' => '08:00:00', 'weight_kg' => 4.5,
            ]);
        }
    }

    /** 削除ボタンの onsubmit の confirm() に渡る文言 */
    private function deleteConfirmMessage(string $html): string
    {
        $this->assertSame(1, preg_match("/onsubmit=\"return confirm\\('([^']*)'\\)\">\\s*<input type=\"hidden\" name=\"_token\"[^>]*>\\s*<input type=\"hidden\" name=\"_method\" value=\"DELETE\">\\s*<button type=\"submit\" class=\"btn btn-danger\">削除<\\/button>/u", $html, $m));

        return $m[1];
    }

    public function test_回答済みなら見出しが暮らしの様子の欄にidとscroll_margin_topを付ける(): void
    {
        $this->addResponse();

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringContainsString('<div class="card mb-3" id="intake-survey" style="scroll-margin-top: 4.5rem;">', $html);
        $this->assertMatchesRegularExpression('/<div class="card-header">\s*<h6 class="mb-0">暮らしの様子<\/h6>\s*<\/div>/u', $html);
    }

    /** 欄（と画面全体）に操作のボタン・取消モーダルがないこと */
    private function assertNoOperations(string $html): void
    {
        $this->assertStringNotContainsString('>案内を発行<', $html);
        $this->assertStringNotContainsString('>案内を取消<', $html);
        $this->assertStringNotContainsString('案内を表示', $html);
        $this->assertStringNotContainsString('intakeSurveyCancelModal', $html);
        $this->assertStringNotContainsString(route('trainee-intake-survey-tokens.store', $this->trainee), $html);
    }

    /**
     * 回答がないときの欄：見出し「暮らしの様子」と、本文に「（未回答）」の 1 行だけ（2026-10 変更）。
     * 設問文・「●」・状態の文字・発行日・会員詳細への案内は出さない。id と scroll-margin-top は回答済みと同じ
     */
    private function assertUnansweredCard(string $html): void
    {
        $this->assertStringContainsString('<div class="card mb-3" id="intake-survey" style="scroll-margin-top: 4.5rem;">', $html);
        $card = $this->intakeCard($html);
        $this->assertMatchesRegularExpression('/<div class="card-header">\s*<h6 class="mb-0">暮らしの様子<\/h6>\s*<\/div>\s*<div class="card-body">\s*<p class="mb-0 text-muted">（未回答）<\/p>\s*<\/div>/u', $card);
        $this->assertSame(1, substr_count($card, '（未回答）'));
        $this->assertStringNotContainsString('data-intake-survey-answers', $card);
        $this->assertStringNotContainsString('●', $card);
        foreach (TraineeIntakeSurveyResponse::questions() as $question) {
            $this->assertStringNotContainsString($question['question'], $card);
        }
        // 「受付アンケート」は削除の確認の文（「発行済みの受付アンケート案内も…」）には出るため、欄の中で確かめる
        $this->assertStringNotContainsString('受付アンケート', $card);
        $this->assertStringNotContainsString('未発行', $html);
        $this->assertStringNotContainsString('受付アンケートの案内はまだ発行していません。', $html);
        $this->assertStringNotContainsString('回答待ち', $html);
        $this->assertStringNotContainsString('2026/10/15', $html);
        $this->assertStringNotContainsString('会員詳細から行えます', $html);
    }

    public function test_未発行なら見出しと未回答だけを出す(): void
    {
        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertUnansweredCard($html);
        $this->assertNoOperations($html);
    }

    public function test_回答待ちなら見出しと未回答だけを出す(): void
    {
        $this->issueToken();

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertUnansweredCard($html);
        $this->assertNoOperations($html);
    }

    public function test_回答済みなら状態の文字と回答日時を出さない(): void
    {
        $this->issueToken('2026-10-16 14:32:00');
        $this->addResponse();

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();
        $card = $this->intakeCard($html);

        $this->assertStringNotContainsString('受付アンケート', $card);
        $this->assertStringNotContainsString('回答済み', $card);
        $this->assertStringNotContainsString('2026/10/16', $card);
        $this->assertStringNotContainsString('14:32', $card);
        $this->assertStringNotContainsString('会員詳細から行えます', $card);
        // 本文は自由記述から始まる
        $this->assertMatchesRegularExpression('/<div class="card-body">\s*<dl class="mb-0" data-intake-survey-answers>/u', $card);
        $this->assertNoOperations($html);
    }

    public function test_欄の中に完了メッセージは出さない(): void
    {
        // 段階3a で使っていたキーが残っていても、欄の中には出さない（操作を会員詳細に寄せたため）
        $html = $this->withSession(['intake_survey_success' => '受付アンケートの案内を発行しました。'])
            ->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringNotContainsString('受付アンケートの案内を発行しました。', $html);
    }

    // ---- 削除の確認の文言 ----

    public function test_削除の確認_案内なし計測値なしは今までどおり(): void
    {
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_案内なし計測値ありは今までどおり(): void
    {
        $this->addMeasurements(2);
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('このトレーニーには 2 件の計測値が登録されています。トレーニーを削除すると計測値も一緒に削除されます。削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_案内あり計測値なし(): void
    {
        $this->issueToken();
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('発行済みの受付アンケート案内も使えなくなります。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_案内あり計測値あり(): void
    {
        $this->issueToken();
        $this->addMeasurements(3);
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('このトレーニーには 3 件の計測値が登録されています。トレーニーを削除すると計測値も一緒に削除されます。発行済みの受付アンケート案内も使えなくなります。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_回答済みの案内しかなければ案内の文は足さない(): void
    {
        $this->issueToken('2026-10-16 10:00:00');
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_回答あり計測値なし(): void
    {
        $this->issueToken('2026-10-16 14:32:00');
        $this->addResponse();
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('受付アンケートの回答も一緒に削除されます。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_回答あり計測値あり(): void
    {
        $this->issueToken('2026-10-16 14:32:00');
        $this->addResponse();
        $this->addMeasurements(2);
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('このトレーニーには 2 件の計測値が登録されています。トレーニーを削除すると計測値も一緒に削除されます。受付アンケートの回答も一緒に削除されます。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    public function test_削除の確認_回答と未回答の案内の両方があれば回答の文から足す(): void
    {
        // 第1段階では起きない組み合わせ（回答済みには発行しない）だが、文の順を確かめる
        $this->addResponse();
        $this->issueToken();
        $html = $this->get(route('trainees.show', $this->trainee))->getContent();

        $this->assertSame('受付アンケートの回答も一緒に削除されます。発行済みの受付アンケート案内も使えなくなります。このトレーニーを削除しますか？', $this->deleteConfirmMessage($html));
    }

    // ---- 回答の内容（2026-10 追加。設計書 S-0309「受付アンケートの欄」） ----

    public function test_回答済みでも基本情報の回答は欄に出さない(): void
    {
        // 基本情報の回答はトレーニーの基本情報に反映済みで、画面上部と重なるため出さない（2026-10 変更）
        $this->trainee->update(['breed' => '柴']);
        $this->addResponse('2026-10-16 14:32:00', [
            'name' => 'モモ', 'breed' => 'トイプードル', 'sex' => 'female',
            'birth_year' => 2022, 'birth_month' => 3, 'neuter_status' => 'unknown',
            'wish' => '留守番中に鳴き続けてしまうのを直したい',
        ]);

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();
        $card = $this->intakeCard($html);

        foreach (['名前', '犬種', '性別', '誕生日', '去勢・避妊'] as $label) {
            $this->assertStringNotContainsString('>'.$label.'<', $card, $label);
        }
        $this->assertStringNotContainsString('トイプードル', $card);
        $this->assertStringNotContainsString('女の子', $card);
        $this->assertStringNotContainsString('2022年3月生まれ', $card);
        $this->assertStringNotContainsString('data-intake-survey-basics', $card);
        // 画面上部のトレーニーの情報は今までどおり出る（欄の外）
        $this->assertStringContainsString('柴', substr($html, 0, strpos($html, 'id="intake-survey"')));
    }

    public function test_自由記述は設問文の先頭に読み上げない印を付け回答の対で改行を保持し答えなかった設問は未回答(): void
    {
        $this->addResponse('2026-10-16 14:32:00', [
            'wish' => "留守番中に鳴き続けてしまう\nのを直したい",
            'ideal_days' => 'ドッグランで遊びたい',
        ]);

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $questions = TraineeIntakeSurveyResponse::questions();
        $q = fn (string $column) => preg_quote($questions[$column]['question'], '/');
        // 設問文の先頭の「●」は回答画面（S-1413）と同じ作り（aria-hidden で読み上げない。d-flex でぶら下げ）
        $dt = fn (string $column) => '<dt class="fw-normal text-muted d-flex"><span class="me-1" aria-hidden="true">●<\/span><span>'.$q($column).'<\/span><\/dt>';
        $this->assertMatchesRegularExpression('/'.$dt('wish').'\s*<dd class="mb-3" style="white-space: pre-wrap;">留守番中に鳴き続けてしまう\nのを直したい<\/dd>/u', $html);
        $this->assertMatchesRegularExpression('/'.$dt('recent_events').'\s*<dd class="mb-3 text-muted">（未回答）<\/dd>/u', $html);
        $this->assertMatchesRegularExpression('/'.$dt('ideal_days').'\s*<dd class="mb-3" style="white-space: pre-wrap;">ドッグランで遊びたい<\/dd>/u', $html);
        $this->assertMatchesRegularExpression('/'.$dt('health_concerns').'\s*<dd class="mb-0 text-muted">（未回答）<\/dd>/u', $html);
        // 「●」は 4 問の設問文にだけ付ける（回答の行には付けない）。例文は出さない
        $this->assertSame(4, substr_count($html, '●'));
        $this->assertStringNotContainsString('例：', $html);
    }

    public function test_回答の内容は受付アンケートの欄の中に出し編集や削除の操作は置かない(): void
    {
        $this->addResponse('2026-10-16 14:32:00', ['breed' => 'トイプードル']);

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();
        $card = $this->intakeCard($html);

        $this->assertStringContainsString('data-intake-survey-answers', $card);
        $this->assertStringNotContainsString('<button', $card);
        $this->assertStringNotContainsString('<form', $card);
    }

    public function test_回答がなければ回答の内容は出さない(): void
    {
        $this->issueToken();

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-intake-survey-basics', $html);
        $this->assertStringNotContainsString('data-intake-survey-answers', $html);
        // 「（未回答）」は欄の本文の 1 行だけ
        $this->assertSame(1, substr_count($html, '（未回答）'));
    }

    public function test_回答が複数件あれば最新の1件を出す(): void
    {
        $this->addResponse('2026-10-16 14:32:00', ['wish' => '古い回答']);
        $this->addResponse('2026-10-18 09:05:00', ['wish' => '新しい回答']);

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringContainsString('>新しい回答</dd>', $html);
        $this->assertStringNotContainsString('古い回答', $html);
    }
}

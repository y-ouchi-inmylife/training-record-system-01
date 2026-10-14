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
 * トレーニー詳細（S-0309）の受付アンケートの欄と、削除の確認の文言（2026-10 追加）。
 *
 * - 欄はカードに id="intake-survey" と scroll-margin-top を付け、状態を見せるだけ（2026-10 変更：
 *   発行・表示・取消のボタン・取消モーダル・欄の中の完了メッセージは置かない。操作は会員詳細だけ）
 * - 未発行・回答待ちのときは「案内の発行・取消は会員詳細から行えます。」と会員詳細へのリンクを添える
 *   （回答済みのときは添えず、回答日時を出す）
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

    /** 回答した基本情報の表の 1 行（ラベルと値） */
    private function assertBasicRow(string $html, string $label, string $value, bool $unanswered = false): void
    {
        $class = $unanswered ? 'col-8 col-md-10 mb-1 text-muted' : 'col-8 col-md-10 mb-1';
        $this->assertMatchesRegularExpression(
            '/<dt class="col-4 col-md-2 fw-normal text-muted">'.preg_quote($label, '/').'<\/dt>\s*<dd class="'.$class.'">'.preg_quote($value, '/').'<\/dd>/u',
            $html
        );
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

    public function test_欄にidとscroll_margin_topを付ける(): void
    {
        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringContainsString('<div class="card mb-3" id="intake-survey" style="scroll-margin-top: 4.5rem;">', $html);
        $this->assertMatchesRegularExpression('/<div class="card-header">\s*<h6 class="mb-0">受付アンケート<\/h6>\s*<\/div>/u', $html);
    }

    /** 会員詳細で操作できることを添える文（「会員詳細」は S-0305 へのリンク） */
    private function assertGuideToClientDetail(string $html): void
    {
        $url = preg_quote(route('clients.show', $this->trainee->client_id), '/');
        $this->assertMatchesRegularExpression('/<p class="small text-muted mb-0">案内の発行・取消は<a href="'.$url.'">会員詳細<\/a>から行えます。<\/p>/u', $html);
    }

    /** 欄に操作のボタン・取消モーダル・欄の中の完了メッセージがないこと */
    private function assertNoOperations(string $html): void
    {
        $this->assertStringNotContainsString('>案内を発行<', $html);
        $this->assertStringNotContainsString('>案内を取消<', $html);
        $this->assertStringNotContainsString('案内を表示', $html);
        $this->assertStringNotContainsString('intakeSurveyCancelModal', $html);
        $this->assertStringNotContainsString(route('trainee-intake-survey-tokens.store', $this->trainee), $html);
    }

    public function test_未発行なら案内はまだ発行していませんと会員詳細への案内でボタンは出さない(): void
    {
        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringContainsString('受付アンケートの案内はまだ発行していません。', $html);
        $this->assertGuideToClientDetail($html);
        $this->assertNoOperations($html);
    }

    public function test_回答待ちなら発行日と会員詳細への案内でボタンも取消モーダルも出さない(): void
    {
        $this->issueToken();

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringContainsString('<p class="mb-1">回答待ち（2026/10/15 発行）</p>', $html);
        $this->assertGuideToClientDetail($html);
        $this->assertNoOperations($html);
    }

    public function test_回答済みなら回答日時を出し会員詳細への案内は出さない(): void
    {
        $this->issueToken('2026-10-16 14:32:00');
        $this->addResponse();

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringContainsString('回答済み（2026/10/16 14:32）', $html);
        $this->assertStringNotContainsString('案内の発行・取消は', $html);
        $this->assertStringNotContainsString('受付アンケートの案内はまだ発行していません。', $html);
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

    public function test_回答の内容を答えたとおりに出す(): void
    {
        // トレーニーの現在の値（犬種なし）と違う、答えたとおりの内容を出す
        $this->addResponse('2026-10-16 14:32:00', [
            'name' => 'モモ', 'breed' => 'トイプードル', 'sex' => 'female',
            'birth_year' => 2022, 'birth_month' => 3, 'neuter_status' => 'unknown',
            'wish' => '留守番中に鳴き続けてしまうのを直したい',
        ]);

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringContainsString('<p class="mb-3">回答済み（2026/10/16 14:32）</p>', $html);
        $this->assertBasicRow($html, '名前', 'モモ');
        $this->assertBasicRow($html, '犬種', 'トイプードル');
        $this->assertBasicRow($html, '性別', '女の子');
        // 誕生日は日付の部分だけ（年齢を付けない）
        $this->assertBasicRow($html, '誕生日', '2022年3月生まれ');
        $this->assertStringNotContainsString('2022年3月生まれ（', $html);
        // 去勢・避妊の「不明」はそのまま（2026-10 変更。当初は「分からない」〔dont_know〕を出していた）
        $this->assertBasicRow($html, '去勢・避妊', '不明');
    }

    public function test_性別の不明も出せる(): void
    {
        $this->addResponse('2026-10-16 14:32:00', ['name' => 'モモ', 'sex' => 'unknown']);

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertBasicRow($html, '性別', '不明');
    }

    public function test_答えなかった基本情報は未回答と出す(): void
    {
        $this->addResponse('2026-10-16 14:32:00', ['name' => 'モモ']);

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertBasicRow($html, '名前', 'モモ');
        foreach (['犬種', '性別', '誕生日', '去勢・避妊'] as $label) {
            $this->assertBasicRow($html, $label, '（未回答）', true);
        }
    }

    public function test_誕生日の日付の部分は年月日なら日付の形(): void
    {
        $this->addResponse('2026-10-16 14:32:00', ['birth_year' => 2020, 'birth_month' => 3, 'birth_day' => 15]);

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertBasicRow($html, '誕生日', '2020/03/15');
    }

    public function test_自由記述は設問文と回答の対で改行を保持し答えなかった設問は未回答(): void
    {
        $this->addResponse('2026-10-16 14:32:00', [
            'wish' => "留守番中に鳴き続けてしまう\nのを直したい",
            'ideal_days' => 'ドッグランで遊びたい',
        ]);

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $questions = TraineeIntakeSurveyResponse::questions();
        $q = fn (string $column) => preg_quote($questions[$column]['question'], '/');
        $this->assertMatchesRegularExpression('/<dt class="fw-normal text-muted">'.$q('wish').'<\/dt>\s*<dd class="mb-3" style="white-space: pre-wrap;">留守番中に鳴き続けてしまう\nのを直したい<\/dd>/u', $html);
        $this->assertMatchesRegularExpression('/<dt class="fw-normal text-muted">'.$q('recent_events').'<\/dt>\s*<dd class="mb-3 text-muted">（未回答）<\/dd>/u', $html);
        $this->assertMatchesRegularExpression('/<dt class="fw-normal text-muted">'.$q('ideal_days').'<\/dt>\s*<dd class="mb-3" style="white-space: pre-wrap;">ドッグランで遊びたい<\/dd>/u', $html);
        $this->assertMatchesRegularExpression('/<dt class="fw-normal text-muted">'.$q('health_concerns').'<\/dt>\s*<dd class="mb-0 text-muted">（未回答）<\/dd>/u', $html);
        // 例文は回答の表示には出さない
        $this->assertStringNotContainsString($questions['wish']['examples'][0], $html);
    }

    public function test_回答の内容は受付アンケートの欄の中に出し編集や削除の操作は置かない(): void
    {
        $this->addResponse('2026-10-16 14:32:00', ['breed' => 'トイプードル']);

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $card = substr($html, strpos($html, 'id="intake-survey"'));
        // 欄のすぐ後ろは計測値のモーダル（このモーダルには計測値の操作のボタンがあるため、そこで切る）
        $end = strpos($card, 'id="measurementModal-');
        $this->assertNotFalse($end);
        $card = substr($card, 0, $end);
        $this->assertStringContainsString('data-intake-survey-basics', $card);
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
        $this->assertStringNotContainsString('（未回答）', $html);
    }

    public function test_回答が複数件あれば最新の1件を出す(): void
    {
        $this->addResponse('2026-10-16 14:32:00', ['breed' => '古い回答']);
        $this->addResponse('2026-10-18 09:05:00', ['breed' => '新しい回答']);

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringContainsString('回答済み（2026/10/18 09:05）', $html);
        $this->assertBasicRow($html, '犬種', '新しい回答');
        $this->assertStringNotContainsString('古い回答', $html);
    }
}

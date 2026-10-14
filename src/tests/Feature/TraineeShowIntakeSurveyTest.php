<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Trainee;
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
 * - 削除の確認：未回答の案内があるときだけ、計測値の文の後ろに案内の文を足し、
 *   最後を「このトレーニーを削除しますか？」で締める（案内がないときは今までの文のまま）
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
}

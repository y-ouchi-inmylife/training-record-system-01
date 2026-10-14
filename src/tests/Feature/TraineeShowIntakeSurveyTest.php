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
 * - 欄はカードに id="intake-survey" と scroll-margin-top を付け、未発行・回答待ちで中身とボタンを出し分ける
 * - S-0309 から発行・取消したときの完了メッセージは、欄の中に出し、自動では消さない
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
        $this->assertStringContainsString('<h6 class="mb-0">受付アンケート</h6>', $html);
    }

    public function test_未発行なら案内はまだ発行していませんと案内を発行のボタン(): void
    {
        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringContainsString('受付アンケートの案内はまだ発行していません。', $html);
        $url = preg_quote(route('trainee-intake-survey-tokens.store', $this->trainee), '/');
        $this->assertMatchesRegularExpression('/<form method="POST" action="'.$url.'" class="d-inline m-0">\\s*<input type="hidden" name="_token"[^>]*>\\s*<input type="hidden" name="return_to" value="trainee">\\s*<button type="submit" class="btn btn-sm btn-outline-primary">案内を発行<\\/button>/u', $html);
        $this->assertStringNotContainsString('>案内を取消<', $html);
    }

    public function test_回答待ちなら発行日と案内を取消のボタンで案内を表示は出さない(): void
    {
        $this->issueToken();

        $html = $this->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $this->assertStringContainsString('<p class="mb-0">回答待ち（2026/10/15 発行）</p>', $html);
        $this->assertStringContainsString('data-intake-survey-trainee-id="'.$this->trainee->id.'">案内を取消</button>', $html);
        $this->assertStringNotContainsString('>案内を発行<', $html);
        $this->assertStringNotContainsString('案内を表示', $html);
        // 取消モーダルは S-0309 から開くと S-0309 に戻る
        $this->assertMatchesRegularExpression('/id="intakeSurveyCancelForm">\\s*<input type="hidden" name="_token"[^>]*>\\s*<input type="hidden" name="_method" value="DELETE">\\s*<input type="hidden" name="return_to" value="trainee">/u', $html);
    }

    public function test_発行のあとの完了メッセージは欄の中に出し自動では消さない(): void
    {
        $html = $this->withSession(['intake_survey_success' => '受付アンケートの案内を発行しました。'])
            ->get(route('trainees.show', $this->trainee))->assertOk()->getContent();

        $posCard = strpos($html, 'id="intake-survey"');
        $posMessage = strpos($html, '受付アンケートの案内を発行しました。');
        $this->assertNotFalse($posMessage);
        $this->assertGreaterThan($posCard, $posMessage);
        // 欄の中の完了メッセージは自動で消さない（上端のメッセージは data-auto-dismiss で 5 秒で消える）
        $this->assertMatchesRegularExpression('/<div class="alert alert-success alert-dismissible fade show" role="alert">\\s*受付アンケートの案内を発行しました。/u', $html);
        $this->assertSame(1, substr_count($html, '受付アンケートの案内を発行しました。'));
        $this->assertDoesNotMatchRegularExpression('/data-auto-dismiss>\\s*受付アンケートの案内を発行しました。/u', $html);
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

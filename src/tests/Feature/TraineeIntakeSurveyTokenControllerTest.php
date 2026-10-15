<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyToken;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 受付アンケート案内の発行・取消（POST／DELETE /trainees/{trainee}/intake-survey-tokens。2026-10 追加）。
 *
 * - 発行のあとは同じタブで S-0311 を開き、完了メッセージは載せない（2026-10 変更）。有効な案内が
 *   すでにあれば新しく作らず今ある案内の S-0311 を開く。回答済みなら 409
 * - 取消のあとは常に会員詳細に戻り、完了メッセージは上端の success（2026-10 変更。
 *   段階3a では return_to で S-0309 の #intake-survey にも戻していたが、操作を会員詳細に寄せた）
 * - アクセスログに create_／delete_trainee_intake_survey_token（target_type='Trainee'）が残る
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 */
class TraineeIntakeSurveyTokenControllerTest extends TestCase
{
    use CreatesClientCountTables;

    private Trainee $trainee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAccessLogsTable();
        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();

        $this->trainee = Trainee::query()->create(['client_id' => 7, 'name' => 'モモ']);
        $this->actingAsRole('staff');
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => 'テスト', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    private function insertToken(?string $answeredAt = null): int
    {
        return DB::table('trainee_intake_survey_tokens')->insertGetId([
            'token' => bin2hex(random_bytes(16)), 'trainee_id' => $this->trainee->id,
            'answered_at' => $answeredAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function lastLog(): AccessLog
    {
        return AccessLog::query()->orderByDesc('id')->firstOrFail();
    }

    // ---- 発行 ----

    private function surveyUrl(string $token): string
    {
        return route('client-portal.intake-survey.show', ['token' => $token]);
    }

    public function test_発行するとS0311を開き新しい案内のURLが出る(): void
    {
        $response = $this->post(route('trainee-intake-survey-tokens.store', $this->trainee));

        $response->assertRedirect(route('trainee-intake-survey-tokens.print', $this->trainee));
        $response->assertSessionMissing('success');

        $token = TraineeIntakeSurveyToken::query()->sole();
        $this->assertSame($this->trainee->id, (int) $token->trainee_id);
        $this->assertSame(200, (int) $token->created_by);
        $this->assertNull($token->answered_at);

        $html = $this->get(route('trainee-intake-survey-tokens.print', $this->trainee))->assertOk()->getContent();
        $this->assertStringContainsString(e($this->surveyUrl($token->token)), $html);
        // 完了メッセージは S-0311 に出さない
        $this->assertStringNotContainsString('発行しました', $html);
    }

    public function test_return_toにtraineeを送ってもS0311を開く(): void
    {
        $response = $this->post(route('trainee-intake-survey-tokens.store', $this->trainee), ['return_to' => 'trainee']);

        $response->assertRedirect(route('trainee-intake-survey-tokens.print', $this->trainee));
        $response->assertSessionMissing('success');
        $response->assertSessionMissing('intake_survey_success');
    }

    public function test_有効な案内があれば新しく作らず今ある案内のS0311を開く(): void
    {
        $tokenId = $this->insertToken();
        $token = TraineeIntakeSurveyToken::query()->findOrFail($tokenId)->token;

        $response = $this->post(route('trainee-intake-survey-tokens.store', $this->trainee));

        $response->assertRedirect(route('trainee-intake-survey-tokens.print', $this->trainee));
        $response->assertSessionMissing('success');
        $this->assertSame([$tokenId], TraineeIntakeSurveyToken::query()->pluck('id')->all());

        $html = $this->get(route('trainee-intake-survey-tokens.print', $this->trainee))->assertOk()->getContent();
        $this->assertStringContainsString(e($this->surveyUrl($token)), $html);
    }

    public function test_二重に送っても有効な案内は1つのまま(): void
    {
        $this->post(route('trainee-intake-survey-tokens.store', $this->trainee));
        $this->post(route('trainee-intake-survey-tokens.store', $this->trainee))
            ->assertRedirect(route('trainee-intake-survey-tokens.print', $this->trainee));

        $this->assertSame(1, TraineeIntakeSurveyToken::query()->count());
    }

    public function test_S0311の閉じるは会員詳細へのリンクで目印がある(): void
    {
        $this->post(route('trainee-intake-survey-tokens.store', $this->trainee));

        $html = $this->get(route('trainee-intake-survey-tokens.print', $this->trainee))->assertOk()->getContent();

        $this->assertStringContainsString('<a href="'.route('clients.show', 7).'" class="btn btn-outline-secondary btn-sm" data-close-tab>閉じる</a>', $html);
        $this->assertStringNotContainsString('history.back', $html);
        $this->assertStringNotContainsString('&laquo; 戻る', $html);
        $this->assertStringContainsString('close-tab', $html);
    }

    public function test_回答済みなら409で作らない(): void
    {
        $tokenId = $this->insertToken('2026-10-16 10:00:00');
        DB::table('trainee_intake_survey_responses')->insert([
            'trainee_id' => $this->trainee->id, 'trainee_intake_survey_token_id' => $tokenId,
            'answered_at' => '2026-10-16 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->post(route('trainee-intake-survey-tokens.store', $this->trainee))->assertStatus(409);
        $this->assertSame([$tokenId], TraineeIntakeSurveyToken::query()->pluck('id')->all());
    }

    public function test_発行はアクセスログにトレーニーへの操作として残る(): void
    {
        $this->post(route('trainee-intake-survey-tokens.store', $this->trainee));

        $log = $this->lastLog();
        $this->assertSame('create_trainee_intake_survey_token', $log->action);
        $this->assertSame('Trainee', $log->target_type);
        $this->assertSame($this->trainee->id, (int) $log->target_id);
        $this->assertSame('受付アンケート案内発行', AccessLog::actionLabels()[$log->action]);
    }

    // ---- 取消 ----

    public function test_取消は有効な案内だけを消し回答済みの案内は残す(): void
    {
        $answered = $this->insertToken('2026-10-16 10:00:00');
        $active = $this->insertToken();

        $response = $this->delete(route('trainee-intake-survey-tokens.destroy', $this->trainee));

        $response->assertRedirect(route('clients.show', 7));
        $response->assertSessionHas('success', '受付アンケートの案内を取り消しました。');
        $this->assertSame([$answered], TraineeIntakeSurveyToken::query()->pluck('id')->all());
        $this->assertFalse(TraineeIntakeSurveyToken::query()->whereKey($active)->exists());
    }

    public function test_取消もreturn_toにtraineeを送っても会員詳細に戻る(): void
    {
        $this->insertToken();

        $response = $this->delete(route('trainee-intake-survey-tokens.destroy', $this->trainee), ['return_to' => 'trainee']);

        $response->assertRedirect(route('clients.show', 7));
        $response->assertSessionHas('success', '受付アンケートの案内を取り消しました。');
    }

    public function test_有効な案内がなければ取消は409(): void
    {
        $this->insertToken('2026-10-16 10:00:00');

        $this->delete(route('trainee-intake-survey-tokens.destroy', $this->trainee))->assertStatus(409);
        $this->assertSame(1, TraineeIntakeSurveyToken::query()->count());
    }

    public function test_取消はアクセスログにトレーニーへの操作として残る(): void
    {
        $this->insertToken();
        $this->delete(route('trainee-intake-survey-tokens.destroy', $this->trainee));

        $log = $this->lastLog();
        $this->assertSame('delete_trainee_intake_survey_token', $log->action);
        $this->assertSame('Trainee', $log->target_type);
        $this->assertSame($this->trainee->id, (int) $log->target_id);
        $this->assertSame('受付アンケート案内取消', AccessLog::actionLabels()[$log->action]);
    }

    // ---- 権限 ----

    public function test_管理者も発行できる(): void
    {
        $this->actingAsRole('admin');

        $this->post(route('trainee-intake-survey-tokens.store', $this->trainee))
            ->assertRedirect(route('trainee-intake-survey-tokens.print', $this->trainee));
    }

    public function test_システム管理者は発行も取消も403(): void
    {
        $this->actingAsRole('system_admin');
        $this->insertToken();

        $this->post(route('trainee-intake-survey-tokens.store', $this->trainee))->assertForbidden();
        $this->delete(route('trainee-intake-survey-tokens.destroy', $this->trainee))->assertForbidden();
        $this->assertSame(1, TraineeIntakeSurveyToken::query()->count());
    }
}

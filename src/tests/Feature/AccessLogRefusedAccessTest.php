<?php

namespace Tests\Feature;

use App\Http\Controllers\ClientController;
use App\Models\AccessLog;
use App\Models\Client;
use App\Models\ClientUsage;
use App\Models\Trainer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 断ったアクセス・エラーになったアクセスをアクセスログに記録しない（2026-11 追加。要件定義書 6-8-9「記録しない送信」、
 * db-schema.md DS-0500 注記、api-design.md 2-3）。
 *
 * - 返事のステータスが 400 以上なら記録しない：権限・IP 制限の 403（PractitionersMiddleware・CheckIpRestriction）、
 *   コントローラーの abort() の 403・404・409、JSON の入力エラーの 422、処理の途中の例外の 500
 * - 成功した操作（表示・302 で戻る削除）は今までどおり記録する
 * - フラッシュ（errors・usage_confirmation_warning・error）で 302 で戻るものは AccessLogReturnedSubmissionTest・
 *   ClientUsageCapacityConfirmTest で確かめている
 *
 * ミドルウェア（auth・check-ip・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 */
class AccessLogRefusedAccessTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

    private int $traineeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createClientsTable();
        $this->createClientCountTables();
        $this->createTraineesTableForUsage();
        $this->createAccessLogsTable();
        $this->createSystemSettingsTable();
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $this->client = Client::query()->create(['internal_id' => '1', 'last_name' => '山田', 'first_name' => '太郎']);
        $this->traineeId = DB::table('trainees')->insertGetId(['client_id' => $this->client->id, 'name' => 'ポチ']);

        $this->actingAsRole('staff');
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => 'テスト', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    /** 記録された操作（action）の一覧 */
    private function loggedActions(): array
    {
        return AccessLog::query()->orderBy('id')->pluck('action')->all();
    }

    // ---- 記録しない（返事のステータスが 400 以上） ----

    public function test_403_一般トレーナーが会員を削除しようとして断られたら記録しない(): void
    {
        $this->delete(route('clients.destroy', $this->client))->assertForbidden();

        $this->assertSame([], $this->loggedActions());
        $this->assertDatabaseHas('clients', ['id' => $this->client->id]);
    }

    public function test_403_システム管理者が会員詳細を開こうとして断られたら記録しない(): void
    {
        $this->actingAsRole('system_admin');

        $this->get(route('clients.show', $this->client))->assertForbidden();

        $this->assertSame([], $this->loggedActions());
    }

    public function test_403_許可していないIPから会員詳細を開こうとして断られたら記録しない(): void
    {
        Schema::create('ip_whitelist', function ($table) {
            $table->id();
            $table->string('ip_address', 45);
            $table->string('description')->nullable();
            $table->timestamps();
        });
        DB::table('system_settings')->insert(['key' => 'enable_ip_restriction', 'value' => '1']);
        DB::table('ip_whitelist')->insert(['ip_address' => '10.0.0.1']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->get(route('clients.show', $this->client))
            ->assertForbidden();

        $this->assertSame([], $this->loggedActions());
    }

    public function test_404_トレーナー用写真がないのに削除しようとして断られたら記録しない(): void
    {
        $this->delete(route('trainee-trainer-photo.destroy', $this->traineeId))->assertNotFound();

        $this->assertSame([], $this->loggedActions());
    }

    public function test_409_取り消す案内がないのに取消しようとして断られたら記録しない(): void
    {
        $this->delete(route('trainee-intake-survey-tokens.destroy', $this->traineeId))->assertStatus(409);
        $this->delete(route('trainee-personality-diagnosis-tokens.destroy', $this->traineeId))->assertStatus(409);

        $this->assertSame([], $this->loggedActions());
    }

    public function test_422_JSONの入力エラーは記録しない(): void
    {
        $this->postJson(route('client-usages.store', $this->client), [])->assertStatus(422);

        $this->assertSame([], $this->loggedActions());
    }

    public function test_500_処理の途中で例外になったら記録しない(): void
    {
        // テストの中だけで、会員詳細のコントローラーに例外を起こさせる
        $this->partialMock(ClientController::class, fn ($mock) => $mock->shouldReceive('show')->andThrow(new \RuntimeException('テスト用の例外')));

        $this->get(route('clients.show', $this->client))->assertStatus(500);

        $this->assertSame([], $this->loggedActions());
    }

    // ---- 今までどおり記録する ----

    public function test_成功した表示と_302で戻る削除は記録する(): void
    {
        // 表示（会員詳細）。画面の描画は確かめない（ここでは記録の有無だけ）ため、コントローラーの返事を、描くと「ok」になる View に差し替える
        $view = \Mockery::mock(\Illuminate\View\View::class);
        $view->shouldReceive('render')->andReturn('ok');
        $this->partialMock(ClientController::class, fn ($mock) => $mock->shouldReceive('show')->andReturn($view));
        $this->get(route('clients.show', $this->client))->assertOk();

        // 302 で戻る削除（利用）
        $usage = ClientUsage::query()->create([
            'client_id' => $this->client->id, 'used_on' => '2026-11-01', 'start_time' => '08:00', 'end_time' => '19:00',
        ]);
        $this->delete(route('client-usages.destroy', $usage))->assertRedirect();

        $this->assertSame(['view_client', 'delete_client_usage'], $this->loggedActions());
    }

    public function test_断られたすぐあとの成功した操作は記録する(): void
    {
        $usage = ClientUsage::query()->create([
            'client_id' => $this->client->id, 'used_on' => '2026-11-01', 'start_time' => '08:00', 'end_time' => '19:00',
        ]);

        $this->delete(route('clients.destroy', $this->client))->assertForbidden();
        $this->delete(route('client-usages.destroy', $usage))->assertRedirect();

        $this->assertSame(['delete_client_usage'], $this->loggedActions());
    }
}

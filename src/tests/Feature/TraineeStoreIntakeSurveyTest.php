<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyToken;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * トレーニー登録（S-0308）の「受付アンケートの案内を発行する」（2026-10 追加。要件定義書 6-16-1）。
 *
 * - チェックが送られたときだけ、トレーニーの登録と同じトランザクションで案内を発行する
 * - 完了メッセージは発行したかどうかで変え、会員詳細に戻る（上端のメッセージ）
 * - アクセスログは create_trainee の 1 件だけ
 *
 * ミドルウェアは外さず、実際の HTTP で確かめる。
 */
class TraineeStoreIntakeSurveyTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

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
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();

        $staff = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $staff->id = 200;
        $staff->exists = true;
        $this->actingAs($staff, 'web');

        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田']);
    }

    public function test_チェックありで登録すると案内も発行する(): void
    {
        $response = $this->post(route('trainees.store', $this->client), ['name' => null, 'issue_intake_survey' => '1']);

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', 'トレーニーを登録し、受付アンケートの案内を発行しました。');

        $trainee = Trainee::query()->sole();
        $token = TraineeIntakeSurveyToken::query()->sole();
        $this->assertSame($trainee->id, (int) $token->trainee_id);
        $this->assertSame(200, (int) $token->created_by);
        $this->assertNull($token->answered_at);

        // アクセスログは create_trainee の 1 件だけ
        $this->assertSame(['create_trainee'], AccessLog::query()->pluck('action')->all());
    }

    public function test_チェックなしで登録すると案内は発行しない(): void
    {
        $response = $this->post(route('trainees.store', $this->client), ['name' => 'モモ']);

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', 'トレーニーを登録しました。');
        $this->assertSame(1, Trainee::query()->count());
        $this->assertSame(0, TraineeIntakeSurveyToken::query()->count());
    }

    public function test_入力エラーなら登録も発行もしない(): void
    {
        $response = $this->post(route('trainees.store', $this->client), ['name' => str_repeat('あ', 51), 'issue_intake_survey' => '1']);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Trainee::query()->count());
        $this->assertSame(0, TraineeIntakeSurveyToken::query()->count());
    }
}

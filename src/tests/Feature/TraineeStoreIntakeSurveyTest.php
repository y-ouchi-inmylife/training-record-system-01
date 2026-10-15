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
 * トレーニー登録（S-0308）では受付アンケート案内を発行しない（2026-10 変更。要件定義書 6-16-1）。
 *
 * - 案内の発行は会員詳細（S-0305）の［案内を発行］だけで行う。以前の「受付アンケートの案内を発行する」の
 *   チェックの値（issue_intake_survey）が送られてきても発行しない
 * - 登録後は会員詳細に戻り、完了メッセージは「トレーニーを登録しました。」
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

    public function test_登録しても案内は発行しない(): void
    {
        $response = $this->post(route('trainees.store', $this->client), ['name' => 'モモ']);

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', 'トレーニーを登録しました。');
        $this->assertSame(1, Trainee::query()->count());
        $this->assertSame(0, TraineeIntakeSurveyToken::query()->count());
        $this->assertSame(['create_trainee'], AccessLog::query()->pluck('action')->all());
    }

    public function test_以前のチェックの値を送っても案内は発行しない(): void
    {
        // 古い画面（チェックボックスがあったころ）から送られてきた場合も、値は使わない
        $response = $this->post(route('trainees.store', $this->client), ['name' => null, 'issue_intake_survey' => '1']);

        $response->assertRedirect(route('clients.show', $this->client));
        $response->assertSessionHas('success', 'トレーニーを登録しました。');
        $this->assertSame(1, Trainee::query()->count());
        $this->assertSame(0, TraineeIntakeSurveyToken::query()->count());
        $this->assertSame(['create_trainee'], AccessLog::query()->pluck('action')->all());
    }

    public function test_入力エラーなら登録しない(): void
    {
        $response = $this->post(route('trainees.store', $this->client), ['name' => str_repeat('あ', 51), 'issue_intake_survey' => '1']);

        $response->assertSessionHasErrors('name');
        $this->assertSame(0, Trainee::query()->count());
        $this->assertSame(0, TraineeIntakeSurveyToken::query()->count());
    }
}

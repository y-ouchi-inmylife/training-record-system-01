<?php

namespace Tests\Feature;

use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyToken;
use App\Models\Trainer;
use App\Services\TraineeIntakeSurveyTokenService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 受付アンケート案内の発行（TraineeIntakeSurveyTokenService::issue。2026-10 追加）。
 *
 * 注意：テストの DB（SQLite）では lockForUpdate() は何もしない（SQL に FOR UPDATE が付かない）。
 * そのため、ここで確かめられるのは「有効な案内があれば発行しない」という論理だけで、2 人が
 * ほぼ同時に発行したときの排他（MySQL の行ロック）はテストでは確かめられない。
 */
class TraineeIntakeSurveyTokenServiceTest extends TestCase
{
    use CreatesClientCountTables;

    private Trainee $trainee;

    private Trainer $trainer;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('name', 50)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();

        $this->trainee = Trainee::query()->create(['client_id' => 1, 'name' => 'モモ']);
        $this->trainer = new Trainer(['name' => '一般花子', 'role' => 'staff']);
        $this->trainer->id = 200;
    }

    private function service(): TraineeIntakeSurveyTokenService
    {
        return $this->app->make(TraineeIntakeSurveyTokenService::class);
    }

    public function test_未発行なら発行してトークンを作る(): void
    {
        $token = $this->service()->issue($this->trainee, $this->trainer);

        $this->assertNotNull($token);
        $row = TraineeIntakeSurveyToken::query()->sole();
        $this->assertSame($this->trainee->id, (int) $row->trainee_id);
        $this->assertSame(200, (int) $row->created_by);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{32}\z/', $row->token);
        $this->assertNull($row->answered_at);
    }

    public function test_有効な案内があれば発行せずnullを返す(): void
    {
        $first = $this->service()->issue($this->trainee, $this->trainer);

        $this->assertNull($this->service()->issue($this->trainee, $this->trainer));
        // 古い案内を消して作り直すこともしない
        $this->assertSame([$first->id], TraineeIntakeSurveyToken::query()->pluck('id')->all());
    }

    public function test_回答があれば発行せずnullを返す(): void
    {
        $tokenId = DB::table('trainee_intake_survey_tokens')->insertGetId([
            'token' => str_repeat('a', 32), 'trainee_id' => $this->trainee->id,
            'answered_at' => '2026-10-16 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('trainee_intake_survey_responses')->insert([
            'trainee_id' => $this->trainee->id, 'trainee_intake_survey_token_id' => $tokenId,
            'answered_at' => '2026-10-16 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull($this->service()->issue($this->trainee, $this->trainer));
        $this->assertSame(1, TraineeIntakeSurveyToken::query()->count());
    }

    public function test_回答のトークンが消えていても回答があれば発行しない(): void
    {
        // トークンが削除されても回答は残る（trainee_intake_survey_token_id が NULL。db-schema.md D-1100）
        DB::table('trainee_intake_survey_responses')->insert([
            'trainee_id' => $this->trainee->id, 'trainee_intake_survey_token_id' => null,
            'answered_at' => '2026-10-16 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull($this->service()->issue($this->trainee, $this->trainer));
        $this->assertSame(0, TraineeIntakeSurveyToken::query()->count());
    }

    public function test_別のトレーニーの回答は関係しない(): void
    {
        $other = Trainee::query()->create(['client_id' => 1, 'name' => 'ハル']);
        DB::table('trainee_intake_survey_responses')->insert([
            'trainee_id' => $other->id, 'answered_at' => '2026-10-16 10:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNotNull($this->service()->issue($this->trainee, $this->trainer));
    }

    public function test_回答のない回答済みのトークンは有効な案内として数えない(): void
    {
        // 回答済みのトークンには通常回答があるが、トークンの判定だけを分けて確かめる
        DB::table('trainee_intake_survey_tokens')->insert([
            'token' => str_repeat('a', 32), 'trainee_id' => $this->trainee->id,
            'answered_at' => '2026-10-16 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $token = $this->service()->issue($this->trainee, $this->trainer);

        $this->assertNotNull($token);
        $this->assertSame(2, TraineeIntakeSurveyToken::query()->count());
    }

    public function test_別のトレーニーの有効な案内は関係しない(): void
    {
        $other = Trainee::query()->create(['client_id' => 1, 'name' => 'ハル']);
        $this->service()->issue($other, $this->trainer);

        $this->assertNotNull($this->service()->issue($this->trainee, $this->trainer));
    }

    public function test_トレーニーの有効な案内のリレーションと状態(): void
    {
        $trainee = Trainee::query()->with(['latestIntakeSurveyResponse', 'activeIntakeSurveyToken'])->find($this->trainee->id);
        $this->assertSame(Trainee::INTAKE_NOT_ISSUED, $trainee->intake_survey_status);

        $this->service()->issue($this->trainee, $this->trainer);

        $trainee = Trainee::query()->with(['latestIntakeSurveyResponse', 'activeIntakeSurveyToken'])->find($this->trainee->id);
        $this->assertSame(Trainee::INTAKE_AWAITING, $trainee->intake_survey_status);
        $this->assertCount(1, $trainee->intakeSurveyTokens);
    }

    public function test_最新の回答のリレーションと回答済みの状態(): void
    {
        $this->service()->issue($this->trainee, $this->trainer);
        DB::table('trainee_intake_survey_responses')->insert([
            ['trainee_id' => $this->trainee->id, 'answered_at' => '2026-10-16 10:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('trainee_intake_survey_responses')->insert([
            ['trainee_id' => $this->trainee->id, 'answered_at' => '2026-10-18 09:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('trainee_intake_survey_responses')->insert([
            ['trainee_id' => $this->trainee->id, 'answered_at' => '2026-10-17 11:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 回答と有効な案内の両方があるときも、回答済みを先に判定する
        $trainee = Trainee::query()->with(['latestIntakeSurveyResponse', 'activeIntakeSurveyToken'])->find($this->trainee->id);
        $this->assertNotNull($trainee->activeIntakeSurveyToken);
        $this->assertSame('2026-10-18 09:00:00', $trainee->latestIntakeSurveyResponse->answered_at->format('Y-m-d H:i:s'));
        $this->assertSame(Trainee::INTAKE_ANSWERED, $trainee->intake_survey_status);
        $this->assertCount(3, $trainee->intakeSurveyResponses);
    }
}

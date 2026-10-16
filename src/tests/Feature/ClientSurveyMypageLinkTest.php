<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\TraineeIntakeSurveyResponse;
use App\Models\TraineePersonalityDiagnosisResult;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 受付アンケート（S-1413・S-1414）と性格診断（S-1415・S-1416）の回答完了の画面と使えない案内の画面の
 * ［マイページへ戻る］（2026-10 追加。App\Support\ClientMypageLink）。
 *
 * - 会員の認証（guard 'client'）でログインしていて、自分の愛犬の案内に回答したときだけ出す
 * - ログインなし・ほかの会員としてログイン・どの愛犬か分からない（存在しない URL）ときは出さない
 * - ログインなしでも今までどおり回答できる
 */
class ClientSurveyMypageLinkTest extends TestCase
{
    use CreatesClientCountTables;

    private const INTAKE = 'intaketoken000000000000000000001';

    private const PD = 'pdtoken0000000000000000000000001';

    private Client $owner;

    private Client $other;

    private int $traineeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClientsTable();
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
            $table->string('photo_path', 500)->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createTraineeIntakeSurveyTokensTable();
        $this->createTraineeIntakeSurveyResponsesTable();
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $this->owner = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎', 'email' => 'yamada@example.com']);
        $this->other = Client::query()->create(['internal_id' => '0002', 'last_name' => '佐藤', 'first_name' => '花子', 'email' => 'sato@example.com']);
        $this->traineeId = DB::table('trainees')->insertGetId(['client_id' => $this->owner->id, 'name' => 'モモ', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function issue(string $table, string $token, ?string $answeredAt = null): void
    {
        DB::table($table)->insert([
            'token' => $token, 'trainee_id' => $this->traineeId, 'answered_at' => $answeredAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function allZero(): array
    {
        $json = json_decode(file_get_contents(__DIR__.'/../fixtures/personality-diagnosis-cases.json'), true, 512, JSON_THROW_ON_ERROR);

        return collect($json['cases'])->firstWhere('name', 'all-zero')['answers'];
    }

    private function assertHasLink(string $html): void
    {
        $this->assertStringContainsString(
            '<a href="'.route('client-portal.dashboard').'" class="btn btn-outline-secondary">マイページへ戻る</a>',
            $html
        );
    }

    private function assertNoLink(string $html): void
    {
        $this->assertStringNotContainsString('マイページへ戻る', $html);
        $this->assertStringNotContainsString('data-client-mypage-back', $html);
    }

    // ---- 受付アンケート ----

    public function test_受付アンケート_自分の愛犬の案内にログインして回答したら完了の画面にマイページへ戻るを出す(): void
    {
        $this->issue('trainee_intake_survey_tokens', self::INTAKE);

        $response = $this->actingAs($this->owner, 'client')->followingRedirects()
            ->post('/client-portal/intake-survey/'.self::INTAKE, ['name' => 'モモ']);

        $response->assertOk()->assertSee('ご回答ありがとうございました');
        $this->assertHasLink($response->getContent());
    }

    public function test_受付アンケート_ログインなしでも回答でき完了の画面にマイページへ戻るは出さない(): void
    {
        $this->issue('trainee_intake_survey_tokens', self::INTAKE);

        $response = $this->followingRedirects()->post('/client-portal/intake-survey/'.self::INTAKE, ['name' => 'モモ']);

        $response->assertOk()->assertSee('ご回答ありがとうございました');
        $this->assertNoLink($response->getContent());
        $this->assertSame(1, TraineeIntakeSurveyResponse::query()->count());
    }

    public function test_受付アンケート_ほかの会員としてログインして回答したら出さない(): void
    {
        $this->issue('trainee_intake_survey_tokens', self::INTAKE);

        $response = $this->actingAs($this->other, 'client')->followingRedirects()
            ->post('/client-portal/intake-survey/'.self::INTAKE, ['name' => 'モモ']);

        $response->assertOk()->assertSee('ご回答ありがとうございました');
        $this->assertNoLink($response->getContent());
    }

    public function test_受付アンケート_完了の画面を直接開いたら出さない(): void
    {
        $this->assertNoLink($this->actingAs($this->owner, 'client')->get(route('client-portal.intake-survey.thanks'))->assertOk()->getContent());
    }

    public function test_受付アンケート_使えない案内の画面も同じ条件(): void
    {
        $this->issue('trainee_intake_survey_tokens', self::INTAKE, '2026-10-01 09:00:00');
        $url = '/client-portal/intake-survey/'.self::INTAKE;

        $owner = $this->actingAs($this->owner, 'client')->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('このアンケートはすでにご回答いただいています。', $owner);
        $this->assertHasLink($owner);

        $this->assertNoLink($this->actingAs($this->other, 'client')->get($url)->assertOk()->getContent());
        auth('client')->logout();
        $this->assertNoLink($this->get($url)->assertOk()->getContent());
        // 存在しない URL（どの愛犬か分からない）は、ログインしていても出さない
        $this->assertNoLink($this->actingAs($this->owner, 'client')->get('/client-portal/intake-survey/nosuchtoken00000000000000000000')->assertOk()->getContent());
    }

    // ---- 性格診断 ----

    public function test_性格診断_自分の愛犬の案内にログインして回答したら完了の画面にマイページへ戻るを出す(): void
    {
        $this->issue('trainee_personality_diagnosis_tokens', self::PD);

        $response = $this->actingAs($this->owner, 'client')->followingRedirects()
            ->post('/client-portal/personality-diagnosis/'.self::PD, ['answers' => $this->allZero()]);

        $response->assertOk()->assertSee('ご回答ありがとうございました');
        $this->assertHasLink($response->getContent());
    }

    public function test_性格診断_ログインなしでも回答でき完了の画面にマイページへ戻るは出さない(): void
    {
        $this->issue('trainee_personality_diagnosis_tokens', self::PD);

        $response = $this->followingRedirects()->post('/client-portal/personality-diagnosis/'.self::PD, ['answers' => $this->allZero()]);

        $response->assertOk()->assertSee('ご回答ありがとうございました');
        $this->assertNoLink($response->getContent());
        $this->assertSame(1, TraineePersonalityDiagnosisResult::query()->count());
    }

    public function test_性格診断_ほかの会員としてログインして回答したら出さない(): void
    {
        $this->issue('trainee_personality_diagnosis_tokens', self::PD);

        $response = $this->actingAs($this->other, 'client')->followingRedirects()
            ->post('/client-portal/personality-diagnosis/'.self::PD, ['answers' => $this->allZero()]);

        $response->assertOk()->assertSee('ご回答ありがとうございました');
        $this->assertNoLink($response->getContent());
    }

    public function test_性格診断_完了の画面を直接開いたら出さない(): void
    {
        $this->assertNoLink($this->actingAs($this->owner, 'client')->get(route('client-portal.personality-diagnosis.thanks'))->assertOk()->getContent());
    }

    public function test_性格診断_使えない案内の画面も同じ条件(): void
    {
        $this->issue('trainee_personality_diagnosis_tokens', self::PD, '2026-10-01 09:00:00');
        $url = '/client-portal/personality-diagnosis/'.self::PD;

        $owner = $this->actingAs($this->owner, 'client')->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('この性格診断はすでにご回答いただいています。', $owner);
        $this->assertHasLink($owner);

        $this->assertNoLink($this->actingAs($this->other, 'client')->get($url)->assertOk()->getContent());
        auth('client')->logout();
        $this->assertNoLink($this->get($url)->assertOk()->getContent());
        $this->assertNoLink($this->actingAs($this->owner, 'client')->get('/client-portal/personality-diagnosis/nosuchtoken0000000000000000000')->assertOk()->getContent());
    }
}

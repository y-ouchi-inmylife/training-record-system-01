<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\TraineePersonalityDiagnosisToken;
use App\Models\Trainer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 性格診断の結果の削除（DELETE /trainees/{trainee}/personality-diagnosis-results/{result}。2026-10 追加。
 * 要件定義書 6-16-14、api-design.md）。
 *
 * - 管理者だけ（トレーニーの削除と同じ確かめ方・同じ文言の 403）。システム管理者は practitioners で 403
 * - {result} は {trainee} の結果に限る（別のトレーニーの結果なら 404）
 * - その結果だけを物理削除する。ほかの結果と案内（回答済みのトークン）は残す
 * - 完了後はトレーニー詳細（フラグメントなし）に戻り、上端の success。アクセスログが 1 件残る
 *
 * ミドルウェア（auth・practitioners・LogAccess など）は外さず、実際の HTTP で確かめる。
 */
class TraineePersonalityDiagnosisResultControllerTest extends TestCase
{
    use CreatesClientCountTables;

    private Client $client;

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
            $table->timestamps();
            $table->unsignedBigInteger('updated_by')->nullable();
        });
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $this->actingAsRole('admin');
        $this->client = Client::query()->create(['internal_id' => '0001', 'last_name' => '山田', 'first_name' => '太郎']);
        $this->trainee = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'モモ']);
    }

    private function actingAsRole(string $role): void
    {
        $trainer = new Trainer(['name' => 'テスト', 'role' => $role]);
        $trainer->id = 200;
        $trainer->exists = true;
        $this->actingAs($trainer, 'web');
    }

    /** 回答済みの案内と、その案内で答えた結果を入れる（結果の id を返す） */
    private function insertAnswered(string $answeredAt, ?Trainee $trainee = null): int
    {
        $trainee ??= $this->trainee;
        $tokenId = DB::table('trainee_personality_diagnosis_tokens')->insertGetId([
            'token' => bin2hex(random_bytes(16)), 'trainee_id' => $trainee->id,
            'answered_at' => $answeredAt, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return TraineePersonalityDiagnosisResult::query()->create([
            'trainee_id' => $trainee->id, 'trainee_personality_diagnosis_token_id' => $tokenId,
            'answered_at' => $answeredAt, 'question_version' => 'v1', 'answers' => ['Q01' => 0],
            'score_people' => '0.00', 'score_dogs' => '0.00', 'score_possessiveness' => '0.00', 'score_separation' => '0.00',
            'score_fear' => '0.00', 'score_trainability' => '0.00', 'score_excitability' => '0.00', 'personality_type' => 'calm',
        ])->id;
    }

    private function url(int $resultId, ?Trainee $trainee = null): string
    {
        return route('trainee-personality-diagnosis-results.destroy', [$trainee ?? $this->trainee, $resultId]);
    }

    public function test_URLとルート名は設計書のとおり(): void
    {
        $this->assertSame(
            url('/trainees/'.$this->trainee->id.'/personality-diagnosis-results/5'),
            route('trainee-personality-diagnosis-results.destroy', [$this->trainee, 5])
        );
    }

    public function test_管理者はその結果だけを削除でき案内とほかの結果は残る(): void
    {
        $first = $this->insertAnswered('2026-03-10 09:00:00');
        $target = $this->insertAnswered('2026-06-01 09:00:00');
        $tokenIds = TraineePersonalityDiagnosisToken::query()->orderBy('id')->pluck('id')->all();

        $response = $this->delete($this->url($target));

        // フラグメントなしでトレーニー詳細に戻る
        $response->assertRedirect(route('trainees.show', $this->trainee));
        $this->assertStringNotContainsString('#', $response->headers->get('Location'));
        $response->assertSessionHas('success', '愛犬性格診断の結果を削除しました。');
        $this->assertSame([$first], TraineePersonalityDiagnosisResult::query()->pluck('id')->all());
        // 結果に使った案内（回答済みのトークン）は消さない
        $this->assertSame($tokenIds, TraineePersonalityDiagnosisToken::query()->orderBy('id')->pluck('id')->all());
        $this->assertCount(2, $tokenIds);

        $logs = AccessLog::query()->orderBy('id')->get()->all();
        $this->assertCount(1, $logs);
        $this->assertSame('delete_trainee_personality_diagnosis_result', $logs[0]->action);
        $this->assertSame('Trainee', $logs[0]->target_type);
        $this->assertSame($this->trainee->id, (int) $logs[0]->target_id);
        $this->assertSame('愛犬性格診断結果削除', AccessLog::actionLabels()[$logs[0]->action]);
    }

    public function test_管理者でなければ403で削除されない(): void
    {
        $this->actingAsRole('staff');
        $id = $this->insertAnswered('2026-06-01 09:00:00');

        $response = $this->delete($this->url($id));

        $response->assertForbidden();
        $response->assertSee('管理者のみ削除できます。');
        $this->assertTrue(TraineePersonalityDiagnosisResult::query()->whereKey($id)->exists());
    }

    public function test_システム管理者はトレーニーの削除と同じく403(): void
    {
        $this->actingAsRole('system_admin');
        $id = $this->insertAnswered('2026-06-01 09:00:00');

        $this->delete($this->url($id))->assertForbidden();
        // トレーニーの削除も同じく 403（同じ扱い）
        $this->delete(route('trainees.destroy', $this->trainee))->assertForbidden();
        $this->assertTrue(TraineePersonalityDiagnosisResult::query()->whereKey($id)->exists());
    }

    public function test_ログインしていなければログイン画面へ(): void
    {
        $id = $this->insertAnswered('2026-06-01 09:00:00');
        auth('web')->logout();

        $this->delete($this->url($id))->assertRedirect(route('login'));
        $this->assertTrue(TraineePersonalityDiagnosisResult::query()->whereKey($id)->exists());
    }

    public function test_別のトレーニーの結果なら404で削除されない(): void
    {
        $other = Trainee::query()->create(['client_id' => $this->client->id, 'name' => 'ハナ']);
        $othersResult = $this->insertAnswered('2026-06-01 09:00:00', $other);

        $this->delete($this->url($othersResult))->assertNotFound();
        $this->assertTrue(TraineePersonalityDiagnosisResult::query()->whereKey($othersResult)->exists());
    }

    public function test_存在しない結果なら404(): void
    {
        $this->delete($this->url(999))->assertNotFound();
    }
}

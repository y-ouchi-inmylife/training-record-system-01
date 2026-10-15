<?php

namespace Tests\Feature;

use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\TraineePersonalityDiagnosisToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 性格診断の案内（DS-1100）と結果（D-1200）のモデル（2026-10 追加。性格診断 段階1）。
 *
 * - トレーニーのリレーション（案内・有効な案内・結果〔新しい順〕・最新の結果）
 * - answers の配列への変換、7 カテゴリの平均の decimal
 * - トレーニーを消したら案内と結果も消える。案内を消したら結果の案内の列は NULL（外部キー。db-schema.md）
 */
class TraineePersonalityDiagnosisModelTest extends TestCase
{
    use CreatesClientCountTables;

    private Trainee $trainee;

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
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();

        $this->trainee = Trainee::query()->create(['client_id' => 1, 'name' => 'モモ']);
    }

    private function makeToken(?string $answeredAt = null, ?Trainee $trainee = null): TraineePersonalityDiagnosisToken
    {
        return TraineePersonalityDiagnosisToken::query()->create([
            'token' => bin2hex(random_bytes(16)),
            'trainee_id' => ($trainee ?? $this->trainee)->id,
            'answered_at' => $answeredAt,
            'created_by' => 200,
        ]);
    }

    /** @param array<string, int|string> $answers */
    private function makeResult(string $answeredAt, ?TraineePersonalityDiagnosisToken $token = null, array $answers = ['Q01' => 2, 'Q02' => 'na']): TraineePersonalityDiagnosisResult
    {
        return TraineePersonalityDiagnosisResult::query()->create([
            'trainee_id' => $this->trainee->id,
            'trainee_personality_diagnosis_token_id' => $token?->id,
            'answered_at' => $answeredAt,
            'question_version' => 'v1',
            'answers' => $answers,
            'score_people' => '2.00',
            'score_dogs' => '1.86',
            'score_possessiveness' => '1.00',
            'score_separation' => '1.43',
            'score_fear' => '2.67',
            'score_trainability' => '0.86',
            'score_excitability' => '1.00',
            'personality_type' => 'sensitive',
        ]);
    }

    public function test_有効な案内は回答されていないものだけ(): void
    {
        $this->makeToken('2026-10-01 10:00:00');
        $active = $this->makeToken();

        $this->assertSame($active->id, $this->trainee->activePersonalityDiagnosisToken->id);
        $this->assertCount(2, $this->trainee->personalityDiagnosisTokens);
        $this->assertTrue($active->isActive());
        $this->assertSame($this->trainee->id, $active->trainee->id);
    }

    public function test_回答済みの案内しかなければ有効な案内はない(): void
    {
        $this->makeToken('2026-10-01 10:00:00');

        $this->assertNull($this->trainee->activePersonalityDiagnosisToken);
    }

    public function test_結果は新しい順で最新の結果も取れる(): void
    {
        $old = $this->makeResult('2026-07-02 10:00:00');
        $new = $this->makeResult('2026-10-15 09:00:00');
        $middle = $this->makeResult('2026-09-01 12:00:00');

        $this->assertSame([$new->id, $middle->id, $old->id], $this->trainee->personalityDiagnosisResults->pluck('id')->all());
        $this->assertSame($new->id, $this->trainee->latestPersonalityDiagnosisResult->id);
    }

    public function test_結果がなければ最新の結果はない(): void
    {
        $this->assertNull($this->trainee->latestPersonalityDiagnosisResult);
        $this->assertCount(0, $this->trainee->personalityDiagnosisResults);
    }

    public function test_answersは配列で読み書きし平均は小数第2位の文字列で読む(): void
    {
        $token = $this->makeToken('2026-10-15 09:00:00');
        $this->makeResult('2026-10-15 09:00:00', $token, ['Q01' => 2, 'Q02' => 'na', 'Q03' => 0]);

        $result = TraineePersonalityDiagnosisResult::query()->sole();

        $this->assertSame(['Q01' => 2, 'Q02' => 'na', 'Q03' => 0], $result->answers);
        $this->assertSame('2.00', $result->score_people);
        $this->assertSame('1.86', $result->score_dogs);
        $this->assertSame('sensitive', $result->personality_type);
        $this->assertSame('v1', $result->question_version);
        $this->assertSame($token->id, $result->token->id);
        $this->assertSame('2026-10-15 09:00:00', $result->answered_at->format('Y-m-d H:i:s'));
    }

    public function test_トレーニーを消したら案内と結果も消える(): void
    {
        $other = Trainee::query()->create(['client_id' => 1, 'name' => 'ハル']);
        $this->makeToken(null, $other);
        $token = $this->makeToken('2026-10-15 09:00:00');
        $this->makeResult('2026-10-15 09:00:00', $token);

        $this->trainee->delete();

        $this->assertSame(0, TraineePersonalityDiagnosisResult::query()->count());
        $this->assertSame([$other->id], TraineePersonalityDiagnosisToken::query()->pluck('trainee_id')->all());
    }

    public function test_案内を消しても結果は残り案内の列はNULLになる(): void
    {
        $token = $this->makeToken('2026-10-15 09:00:00');
        $result = $this->makeResult('2026-10-15 09:00:00', $token);

        $token->delete();

        $fresh = $result->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->trainee_personality_diagnosis_token_id);
        $this->assertNull($fresh->token);
    }
}

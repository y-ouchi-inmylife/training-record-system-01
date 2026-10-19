<?php

namespace Tests\Feature;

use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\TraineePersonalityDiagnosisToken;
use App\Models\TrainingRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 性格診断の案内（DS-1100）と結果（D-1200）のモデル（2026-10 追加。性格診断 段階1）。
 *
 * - トレーニーのリレーション（案内・有効な案内・結果〔新しい順〕・最新の結果）
 * - answers の配列への変換、7 カテゴリの平均の decimal
 * - トレーニーを消したら案内と結果も消える。案内を消したら結果の案内の列は NULL（外部キー。db-schema.md）
 * - アドバイスの状態（なし・下書き・公開済み。公開した日時で決まる。2026-10-18 変更：その前は結び付いたトレーニング記録で決めていた）、
 *   指定した結果の時点のレーダーチャートの値（2026-10-18 追加。要件定義書 6-16-15・6-16-16）
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
        $this->createTrainingRecordsTableForPersonalityDiagnosis();

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

    // ---- アドバイスの状態と公開（2026-10-18 追加。2026-10-18 変更：公開した日時で決める） ----

    public function test_アドバイスの状態はアドバイスと公開した日時から決める(): void
    {
        $result = $this->makeResult('2026-06-01 09:00:00');
        $this->assertSame(TraineePersonalityDiagnosisResult::EVALUATION_NONE, $result->evaluation_status);
        $this->assertSame('なし', $result->evaluation_status_label);
        $this->assertSame('bg-secondary', $result->evaluation_status_badge_class);

        // 空白だけはなしのまま
        $result->update(['evaluation_text' => "  \n "]);
        $this->assertSame('なし', $result->fresh()->evaluation_status_label);

        $result->update(['evaluation_text' => '落ち着いた性格です。']);
        $this->assertSame(TraineePersonalityDiagnosisResult::EVALUATION_DRAFT, $result->fresh()->evaluation_status);
        $this->assertSame('下書き', $result->fresh()->evaluation_status_label);

        // 公開した日時があれば公開済み
        $result->update(['evaluation_published_at' => '2026-10-18 10:00:00', 'evaluation_published_by' => 200]);
        $this->assertSame(TraineePersonalityDiagnosisResult::EVALUATION_PUBLISHED, $result->fresh()->evaluation_status);
        $this->assertSame('公開済み', $result->fresh()->evaluation_status_label);
        $this->assertSame('bg-success', $result->fresh()->evaluation_status_badge_class);
        $this->assertSame('2026-10-18 10:00:00', $result->fresh()->evaluation_published_at->format('Y-m-d H:i:s'));

        // 公開を取り消すと（公開した日時を空に戻すと）下書きに戻る
        $result->update(['evaluation_published_at' => null, 'evaluation_published_by' => null]);
        $this->assertSame('下書き', $result->fresh()->evaluation_status_label);
    }

    public function test_アドバイスの状態は関連を読み込まずに決まる(): void
    {
        $this->makeResult('2026-06-01 09:00:00')->update(['evaluation_text' => '文', 'evaluation_published_at' => '2026-10-18 10:00:00']);
        $result = TraineePersonalityDiagnosisResult::query()->sole();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame('公開済み', $result->evaluation_status_label);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_結果とトレーニング記録の結び付きはもうない(): void
    {
        // 2026-10-18 変更：アドバイスを記録にしなくなったため、結び付けの関連と列を外した（000006）
        $this->assertFalse(method_exists(TraineePersonalityDiagnosisResult::class, 'trainingRecord'));
        $this->assertFalse(method_exists(TrainingRecord::class, 'personalityDiagnosisResult'));
        $this->assertFalse(method_exists(TrainingRecord::class, 'personalityDiagnosisSection'));
        $this->assertFalse(Schema::hasColumn('training_records', 'trainee_personality_diagnosis_result_id'));
        $this->assertNotContains('trainee_personality_diagnosis_result_id', (new TrainingRecord)->getFillable());
    }

    // ---- 指定した結果の時点のレーダーチャート（2026-10-18 追加） ----

    /** レーダーチャートの値の今回・前回・初回の回答日 */
    private static function seriesDates(?array $data): array
    {
        return collect($data['series'] ?? [])->mapWithKeys(fn (array $series) => [$series['key'] => $series['date']])->all();
    }

    public function test_指定した結果を今回にし前回と初回はそれより前の結果から取る(): void
    {
        $first = $this->makeResult('2025-12-01 10:00:00');
        $second = $this->makeResult('2026-03-10 11:00:00');
        $third = $this->makeResult('2026-06-01 09:00:00');
        $latest = $this->makeResult('2026-08-20 09:00:00');
        $trainee = $this->trainee->fresh();

        // 最新でない結果：それより新しい結果（2026/08/20）は使わない
        $this->assertSame(['current' => '2026/06/01', 'previous' => '2026/03/10', 'initial' => '2025/12/01'], self::seriesDates($trainee->personalityRadarDataAsOf($third)));
        // 前に 1 つだけ：初回は重ねない（前回と同じ回のため）
        $this->assertSame(['current' => '2026/03/10', 'previous' => '2025/12/01'], self::seriesDates($trainee->personalityRadarDataAsOf($second)));
        // いちばん古い結果：今回だけ
        $this->assertSame(['current' => '2025/12/01'], self::seriesDates($trainee->personalityRadarDataAsOf($first)));
        // 最新の結果：今の組み立て（personality_radar_data）と同じ
        $this->assertSame($trainee->personality_radar_data, $trainee->personalityRadarDataAsOf($latest));
        $this->assertSame(['current' => '2026/08/20', 'previous' => '2026/06/01', 'initial' => '2025/12/01'], self::seriesDates($trainee->personality_radar_data));
    }

    public function test_その時点のグラフも形と値は今の組み立てと同じ(): void
    {
        $older = $this->makeResult('2026-03-10 11:00:00');
        $target = $this->makeResult('2026-06-01 09:00:00');
        $target->update(['score_dogs' => null]);
        $this->makeResult('2026-08-20 09:00:00');

        $data = $this->trainee->fresh()->personalityRadarDataAsOf($target->fresh());

        $this->assertSame(['labels', 'keys', 'series'], array_keys($data));
        $this->assertSame(['people', 'dogs', 'possessiveness', 'separation', 'fear', 'trainability', 'excitability'], $data['keys']);
        // 表示の値（小数第 1 位）。点数なしは null のまま
        $this->assertSame(['2.0', null, '1.0', '1.4', '2.7', '0.9', '1.0'], $data['series'][0]['values']);
        $this->assertSame(array_values($older->displayScores()), $data['series'][1]['values']);
    }

    public function test_同じ日時の結果は後に作ったほうを新しいとしてその時点を決める(): void
    {
        $a = $this->makeResult('2026-06-01 09:00:00');
        $b = $this->makeResult('2026-06-01 09:00:00');
        $trainee = $this->trainee->fresh();

        // b（id が大きい）の時点：a が前回
        $this->assertSame([$b->id, $a->id], [$trainee->personalityDiagnosisResults[0]->id, $trainee->personalityDiagnosisResults[1]->id]);
        $this->assertCount(2, $trainee->personalityRadarDataAsOf($b)['series']);
        // a の時点：b は後なので使わない
        $this->assertCount(1, $trainee->personalityRadarDataAsOf($a)['series']);
    }

    public function test_別のトレーニーの結果を指定したらnull(): void
    {
        $other = Trainee::query()->create(['client_id' => 1, 'name' => 'ハナ']);
        $othersResult = TraineePersonalityDiagnosisResult::query()->create([
            'trainee_id' => $other->id, 'answered_at' => '2026-06-01 09:00:00', 'question_version' => 'v1', 'answers' => [],
        ]);
        $this->makeResult('2026-06-01 09:00:00');

        $this->assertNull($this->trainee->fresh()->personalityRadarDataAsOf($othersResult));
    }
}

<?php

namespace Tests\Feature;

use App\Support\PersonalityDiagnosisQuestions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesClientCountTables;
use Tests\TestCase;

/**
 * 保存済みの愛犬性格診断の結果の計算し直し（php artisan personality-diagnosis:rescore。2026-10-18 追加）。
 *
 * 全問「未経験」のカテゴリを 0、全問「未経験」のタイプを calm で保存していた結果（元の決まり）を、今の決まり
 * （点数なし・判定なし）に計算し直す。ほかの結果は変わらない
 */
class RescorePersonalityDiagnosisResultsTest extends TestCase
{
    use CreatesClientCountTables;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('trainees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->timestamps();
        });
        $this->createTraineePersonalityDiagnosisTokensTable();
        $this->createTraineePersonalityDiagnosisResultsTable();
        DB::table('trainees')->insert(['id' => 1, 'client_id' => 1]);
    }

    /** 固定データの 1 件を、元の決まり（GAS）の値で保存する。点数なしは 0.00、判定なしは calm */
    private function insertWithOldRule(string $caseName): int
    {
        $json = json_decode(file_get_contents(__DIR__.'/../fixtures/personality-diagnosis-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $case = collect($json['cases'])->firstWhere('name', $caseName);
        $row = [
            'trainee_id' => 1, 'answered_at' => '2026-10-01 09:00:00', 'question_version' => 'v1',
            'answers' => json_encode($case['answers']), 'personality_type' => $case['expected']['type'] ?? 'calm',
            'created_at' => now(), 'updated_at' => now(),
        ];
        foreach ($case['expected']['scores'] as $category => $score) {
            $row['score_'.$category] = $score ?? '0.00';
        }

        return DB::table('trainee_personality_diagnosis_results')->insertGetId($row);
    }

    private function row(int $id): array
    {
        return (array) DB::table('trainee_personality_diagnosis_results')->find($id);
    }

    public function test_全問未経験のカテゴリと判定なしだけを計算し直しほかは変えない(): void
    {
        $allNa = $this->insertWithOldRule('all-na');
        $categoryNa = $this->insertWithOldRule('category-all-na');
        $past = $this->insertWithOldRule('past-1');
        $before = $this->row($past);

        $this->artisan('personality-diagnosis:rescore')
            ->expectsOutputToContain('結果 3 件のうち、2 件が変わりました。')
            ->assertSuccessful();

        // 全問未経験：7 カテゴリとも点数なし、タイプは判定なし（元の決まりでは 0.00・calm）
        $row = $this->row($allNa);
        foreach (array_keys(PersonalityDiagnosisQuestions::categories()) as $category) {
            $this->assertNull($row['score_'.$category], $category);
        }
        $this->assertNull($row['personality_type']);
        // 分離不安だけ全問未経験：分離不安だけ点数なし。タイプは同じ（guardian）
        $row = $this->row($categoryNa);
        $this->assertNull($row['score_separation']);
        $this->assertEquals('2.00', $row['score_dogs']);
        $this->assertSame('guardian', $row['personality_type']);
        // 全問未経験のカテゴリがない結果は変わらない
        $this->assertSame($before, $this->row($past));
    }

    public function test_dry_runは件数を出すだけで更新せず2回目は0件(): void
    {
        $allNa = $this->insertWithOldRule('all-na');

        $this->artisan('personality-diagnosis:rescore', ['--dry-run' => true])
            ->expectsOutputToContain('結果 1 件のうち、1 件が変わります。')
            ->assertSuccessful();
        $this->assertSame('calm', $this->row($allNa)['personality_type']);

        $this->artisan('personality-diagnosis:rescore')->assertSuccessful();
        $this->artisan('personality-diagnosis:rescore')
            ->expectsOutputToContain('結果 1 件のうち、0 件が変わりました。')
            ->assertSuccessful();
    }
}

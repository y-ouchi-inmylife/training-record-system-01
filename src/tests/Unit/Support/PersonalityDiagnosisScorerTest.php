<?php

namespace Tests\Unit\Support;

use App\Support\PersonalityDiagnosisQuestions;
use App\Support\PersonalityDiagnosisScorer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 性格診断の採点とタイプの判定（要件定義書 6-16「性格診断の採点とタイプの判定」）のテスト。
 *
 * ケースは tests/fixtures/personality-diagnosis-cases.json。past-* の 4 件はトレーナーさんの過去の回答
 * （名前などを除いた回答だけ）で、期待値は当時の GAS の出力と一致することを確かめてある。ほかの 11 件は境目。
 */
class PersonalityDiagnosisScorerTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../fixtures/personality-diagnosis-cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function cases(): array
    {
        $cases = [];
        foreach (self::fixture()['cases'] as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_固定データの各ケース(array $case): void
    {
        $score = (new PersonalityDiagnosisScorer)->score($case['answers']);

        $this->assertSame($case['expected']['scores'], $score->scores, '保存の値（小数第2位）');
        $this->assertSame($case['expected']['display'], $score->display, '表示の値（小数第1位）');
        $this->assertSame($case['expected']['type'], $score->type, 'タイプ');
    }

    public function test_固定データは15件で設問の版とタイプの表示名が定義と一致する(): void
    {
        $fixture = self::fixture();

        $this->assertCount(15, $fixture['cases']);
        $this->assertSame(PersonalityDiagnosisQuestions::VERSION, $fixture['question_version']);
        $this->assertSame(PersonalityDiagnosisQuestions::types(), $fixture['type_codes']);
        foreach ($fixture['cases'] as $case) {
            $this->assertSame(array_keys(PersonalityDiagnosisQuestions::questions()), array_keys($case['answers']), $case['name']);
        }
    }

    public function test_保存する列の形に直せる(): void
    {
        $case = self::cases()['past-1'][0];

        $attributes = (new PersonalityDiagnosisScorer)->score($case['answers'])->toResultAttributes();

        $this->assertSame([
            'score_people' => '2.00',
            'score_dogs' => '1.86',
            'score_possessiveness' => '1.00',
            'score_separation' => '1.43',
            'score_fear' => '2.67',
            'score_trainability' => '0.86',
            'score_excitability' => '1.00',
            'personality_type' => 'sensitive',
        ], $attributes);
    }

    public function test_数字の文字列で送られた回答も整数と同じに採点する(): void
    {
        $case = self::cases()['past-2'][0];
        $strings = array_map(fn ($value) => (string) $value, $case['answers']);

        $score = (new PersonalityDiagnosisScorer)->score($strings);

        $this->assertSame($case['expected']['scores'], $score->scores);
        $this->assertSame($case['expected']['type'], $score->type);
    }

    public function test_設問がそろっていなければ採点しない(): void
    {
        $answers = self::cases()['all-zero'][0]['answers'];
        unset($answers['Q50']);

        $this->expectException(InvalidArgumentException::class);
        (new PersonalityDiagnosisScorer)->score($answers);
    }

    public function test_値が正しくなければ採点しない(): void
    {
        $answers = self::cases()['all-zero'][0]['answers'];
        $answers['Q01'] = 5;

        $this->expectException(InvalidArgumentException::class);
        (new PersonalityDiagnosisScorer)->score($answers);
    }
}

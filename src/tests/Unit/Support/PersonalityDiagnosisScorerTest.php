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

    // ---- 点数なし・判定なし（2026-10-18 変更：GAS では 0・calm。意図した違い） ----

    /** 全問を同じ値にした回答（上書きしたい設問だけ $overrides で渡す） */
    private static function answers(int|string $default, array $overrides = []): array
    {
        $answers = [];
        foreach (array_keys(PersonalityDiagnosisQuestions::questions()) as $id) {
            $answers[$id] = $default;
        }

        return array_replace($answers, $overrides);
    }

    /** そのカテゴリの全問を同じ値にする上書き */
    private static function category(string $category, int|string $value): array
    {
        return array_fill_keys(PersonalityDiagnosisQuestions::questionIdsByCategory()[$category], $value);
    }

    public function test_全問未経験のカテゴリは点数なしで0点の答えだけなら0点で混ざっていれば未経験を除いた平均(): void
    {
        $ids = PersonalityDiagnosisQuestions::questionIdsByCategory();
        $answers = self::answers(2, self::category('dogs', 'na') + self::category('fear', 0)
            + [$ids['people'][0] => 'na', $ids['people'][1] => 'na', $ids['people'][2] => 4]);

        $score = (new PersonalityDiagnosisScorer)->score($answers);

        $this->assertNull($score->scores['dogs']);
        $this->assertNull($score->display['dogs']);
        $this->assertSame('0.00', $score->scores['fear']);
        $this->assertSame('0.0', $score->display['fear']);
        // 対人：未経験 2 問を除いた 6 問（4 と 2 が 5 問）の平均 14 / 6 = 2.333…
        $this->assertSame('2.33', $score->scores['people']);
        $this->assertSame(['score_dogs' => null], array_intersect_key($score->toResultAttributes(), ['score_dogs' => true]));
    }

    public function test_点数なしのカテゴリはタイプの判定から外す(): void
    {
        // 対人が全問未経験（点数なし）、ほかは恐怖心がいちばん高い → 恐怖心（sensitive）。点数なしがいちばん高いことにはならない
        $answers = self::answers(1, self::category('people', 'na') + self::category('fear', 3));

        $score = (new PersonalityDiagnosisScorer)->score($answers);

        $this->assertNull($score->scores['people']);
        $this->assertSame('sensitive', $score->type);
    }

    public function test_点数なしを外した残りがすべて1点5未満ならcalm(): void
    {
        $answers = self::answers(1, self::category('people', 'na') + self::category('dogs', 'na'));

        $this->assertSame('calm', (new PersonalityDiagnosisScorer)->score($answers)->type);
    }

    public function test_全カテゴリが点数なしならタイプも判定なし(): void
    {
        $score = (new PersonalityDiagnosisScorer)->score(self::answers('na'));

        $this->assertSame(array_fill_keys(array_keys(PersonalityDiagnosisQuestions::categories()), null), $score->scores);
        $this->assertNull($score->type);
        $this->assertNull($score->toResultAttributes()['personality_type']);
    }

    public function test_GASと違うのは全問未経験のカテゴリがある固定データの2件だけ(): void
    {
        // all-na と category-all-na だけが点数なし（null）を含み、ほかの 13 件は GAS と同じ値のまま
        $withNull = [];
        foreach (self::fixture()['cases'] as $case) {
            if (in_array(null, $case['expected']['scores'], true) || $case['expected']['type'] === null) {
                $withNull[] = $case['name'];
            }
        }
        $this->assertSame(['all-na', 'category-all-na'], $withNull);
    }
}

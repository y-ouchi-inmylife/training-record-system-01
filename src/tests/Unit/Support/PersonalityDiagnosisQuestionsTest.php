<?php

namespace Tests\Unit\Support;

use App\Support\PersonalityDiagnosisQuestions;
use PHPUnit\Framework\TestCase;

/**
 * 性格診断の設問の定義（設問の版 v1。要件定義書 D-1200・db-schema.md 5-23〜5-25）のテスト。
 */
class PersonalityDiagnosisQuestionsTest extends TestCase
{
    public function test_設問は50問でIDはQ01からQ50の順(): void
    {
        $expected = array_map(fn (int $n) => sprintf('Q%02d', $n), range(1, 50));

        $this->assertSame($expected, array_keys(PersonalityDiagnosisQuestions::questions()));
        $this->assertSame('v1', PersonalityDiagnosisQuestions::VERSION);
    }

    public function test_7カテゴリの順と問数と設問の範囲(): void
    {
        $this->assertSame(
            ['people' => '対人', 'dogs' => '対犬', 'possessiveness' => '所有欲', 'separation' => '分離不安', 'fear' => '恐怖心', 'trainability' => '訓練性能', 'excitability' => '興奮性'],
            PersonalityDiagnosisQuestions::categories()
        );

        $ids = PersonalityDiagnosisQuestions::questionIdsByCategory();
        $this->assertSame(
            ['people' => 8, 'dogs' => 8, 'possessiveness' => 5, 'separation' => 7, 'fear' => 7, 'trainability' => 7, 'excitability' => 8],
            array_map('count', $ids)
        );
        // 範囲は連続している（対人 Q01〜Q08、対犬 Q09〜Q16 …）
        $this->assertSame(['Q01', 'Q08'], [$ids['people'][0], end($ids['people'])]);
        $this->assertSame(['Q09', 'Q16'], [$ids['dogs'][0], end($ids['dogs'])]);
        $this->assertSame(['Q17', 'Q21'], [$ids['possessiveness'][0], end($ids['possessiveness'])]);
        $this->assertSame(['Q22', 'Q28'], [$ids['separation'][0], end($ids['separation'])]);
        $this->assertSame(['Q29', 'Q35'], [$ids['fear'][0], end($ids['fear'])]);
        $this->assertSame(['Q36', 'Q42'], [$ids['trainability'][0], end($ids['trainability'])]);
        $this->assertSame(['Q43', 'Q50'], [$ids['excitability'][0], end($ids['excitability'])]);
    }

    public function test_設問の文は丸数字を含む今の文のまま(): void
    {
        $questions = PersonalityDiagnosisQuestions::questions();

        $this->assertSame('①知らない人が家に入ってきた時に吠える・唸る', $questions['Q01']['text']);
        $this->assertSame('① 遠くに犬を見つけただけで、グイグイ引っ張ったり吠えたりする', $questions['Q09']['text']);
        // Q11 は文末を確認中（要件定義書 D-1200）。今の文のまま
        $this->assertSame('③ドッグランなどで他の犬に追いかけられると、本気で怒って喧嘩になり', $questions['Q11']['text']);
        $this->assertSame('⑧ 散歩から帰ってきても、体力が有り余っていてすぐに遊びたがる', $questions['Q50']['text']);
        foreach ($questions as $id => $question) {
            $this->assertMatchesRegularExpression('/^[①-⑧]/u', $question['text'], $id);
        }
    }

    public function test_選択肢は6つで未経験はna(): void
    {
        $this->assertSame(
            ['0' => '0：全くない', '1' => '1：ほとんどない', '2' => '2：時々ある', '3' => '3：よくある', '4' => '4：常にある', 'na' => '未経験'],
            PersonalityDiagnosisQuestions::choices()
        );
        $this->assertSame('na', PersonalityDiagnosisQuestions::NOT_EXPERIENCED);
    }

    public function test_タイプは6つでカテゴリからタイプへの対応がある(): void
    {
        $this->assertSame(
            ['guardian', 'affectionate', 'sensitive', 'smart', 'energetic', 'calm'],
            array_keys(PersonalityDiagnosisQuestions::types())
        );
        $this->assertSame(array_keys(PersonalityDiagnosisQuestions::categories()), array_keys(PersonalityDiagnosisQuestions::typeByCategory()));
        foreach (PersonalityDiagnosisQuestions::typeByCategory() as $type) {
            $this->assertArrayHasKey($type, PersonalityDiagnosisQuestions::types());
        }
    }
}

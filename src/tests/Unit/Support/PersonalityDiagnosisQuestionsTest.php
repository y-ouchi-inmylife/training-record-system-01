<?php

namespace Tests\Unit\Support;

use App\Models\TraineePersonalityDiagnosisResult;
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
        // Q11 の文末は 2026-10-18 にトレーナーさんに確認し「喧嘩になる」に直した（要件定義書 D-1200）。版は v1 のまま
        $this->assertSame('③ドッグランなどで他の犬に追いかけられると、本気で怒って喧嘩になる', $questions['Q11']['text']);
        $this->assertSame('v1', PersonalityDiagnosisQuestions::VERSION);
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

    // ---- 自由記述の質問（4 問）（2026-10-19 に愛犬アンケートから移した。要件定義書 D-1200） ----

    public function test_自由記述の質問は4問で列名の順に並び例文は持たない(): void
    {
        $questions = PersonalityDiagnosisQuestions::lifeQuestions();

        $this->assertSame(['wish', 'recent_events', 'ideal_days', 'health_concerns'], array_keys($questions));
        $this->assertSame(['こうなったらいいな', '最近の様子', '理想の毎日', '体のこと'], array_column($questions, 'label'));
        foreach ($questions as $question) {
            // 例文は持たない（回答を例文に誘導しないため）
            $this->assertSame(['label', 'question'], array_keys($question));
        }
        $this->assertSame(2000, PersonalityDiagnosisQuestions::LIFE_ANSWER_MAX_LENGTH);
    }

    public function test_自由記述の質問の設問文は要件定義書のとおり(): void
    {
        $questions = PersonalityDiagnosisQuestions::lifeQuestions();

        $this->assertSame('日々の暮らしの中で、もっとこうなったらいいな、と感じることはありますか？', $questions['wish']['question']);
        $this->assertSame('最近の様子で、ふと気になったことや、印象に残っている出来事があれば教えてください。', $questions['recent_events']['question']);
        $this->assertSame('これから一緒に、どんな楽しいことをしたいですか？『理想の毎日』を聞かせてください。', $questions['ideal_days']['question']);
        $this->assertSame('体のことで気になることや、お医者様から言われていることがあれば教えてください。', $questions['health_concerns']['question']);
    }

    public function test_自由記述の質問の空の判定は結果のモデルで全角の空白や改行だけも空(): void
    {
        $result = fn (array $attributes) => new TraineePersonalityDiagnosisResult($attributes);

        $this->assertFalse($result([])->hasLifeAnswers());
        $this->assertFalse($result(['wish' => '', 'recent_events' => " \n\t", 'ideal_days' => '　　', 'health_concerns' => null])->hasLifeAnswers());
        $this->assertTrue($result(['health_concerns' => '　気になる'])->hasLifeAnswers());
        foreach (array_keys(PersonalityDiagnosisQuestions::lifeQuestions()) as $column) {
            $this->assertContains($column, (new TraineePersonalityDiagnosisResult)->getFillable());
        }
    }
}

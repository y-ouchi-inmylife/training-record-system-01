<?php

namespace Tests\Unit\Models;

use App\Models\TraineeIntakeSurveyResponse;
use Tests\TestCase;

/**
 * 受付アンケートの回答のモデル（D-1100。2026-10 追加）。
 *
 * 答えたとおりの表示（性別・去勢・避妊・誕生日。性別・去勢・避妊はトレーニーと同じ値・同じ対応表）と、
 * 自由記述の設問文と例文（要件定義書 D-1100）を確かめる。
 */
class TraineeIntakeSurveyResponseTest extends TestCase
{
    public function test_性別の表示はトレーニーと同じ対応表で不明もある(): void
    {
        // 値とラベルは Trainee::sexLabels() をそのまま使う（2026-10 変更。当初は男の子・女の子だけ）
        $this->assertSame('男の子', (new TraineeIntakeSurveyResponse(['sex' => 'male']))->sex_label);
        $this->assertSame('女の子', (new TraineeIntakeSurveyResponse(['sex' => 'female']))->sex_label);
        $this->assertSame('不明', (new TraineeIntakeSurveyResponse(['sex' => 'unknown']))->sex_label);
        $this->assertNull((new TraineeIntakeSurveyResponse)->sex_label);
    }

    public function test_去勢避妊の表示はトレーニーと同じ対応表(): void
    {
        // 値とラベルは Trainee::neuterStatusLabels() をそのまま使う（2026-10 変更。当初は「分からない」〔dont_know〕）
        $this->assertSame('している', (new TraineeIntakeSurveyResponse(['neuter_status' => 'neutered']))->neuter_status_label);
        $this->assertSame('していない', (new TraineeIntakeSurveyResponse(['neuter_status' => 'not_neutered']))->neuter_status_label);
        $this->assertSame('不明', (new TraineeIntakeSurveyResponse(['neuter_status' => 'unknown']))->neuter_status_label);
        $this->assertNull((new TraineeIntakeSurveyResponse)->neuter_status_label);
    }

    public function test_回答独自の対応表や読み替えは持たない(): void
    {
        $this->assertFalse(method_exists(TraineeIntakeSurveyResponse::class, 'sexLabels'));
        $this->assertFalse(method_exists(TraineeIntakeSurveyResponse::class, 'neuterStatusLabels'));
        $this->assertFalse(method_exists(TraineeIntakeSurveyResponse::class, 'neuterStatusForTrainee'));
    }

    public function test_誕生日の表示は年齢を付けない(): void
    {
        $full = new TraineeIntakeSurveyResponse(['birth_year' => 2020, 'birth_month' => 3, 'birth_day' => 15]);
        $yearMonth = new TraineeIntakeSurveyResponse(['birth_year' => 2020, 'birth_month' => 3]);
        $yearOnly = new TraineeIntakeSurveyResponse(['birth_year' => 2020]);

        $this->assertSame('2020/03/15', $full->birthday_label);
        $this->assertSame('2020年3月生まれ', $yearMonth->birthday_label);
        $this->assertSame('2020年生まれ', $yearOnly->birthday_label);
        $this->assertNull((new TraineeIntakeSurveyResponse)->birthday_label);
    }

    public function test_自由記述は4欄でカラム名の順に並ぶ(): void
    {
        $questions = TraineeIntakeSurveyResponse::questions();

        $this->assertSame(['wish', 'recent_events', 'ideal_days', 'health_concerns'], array_keys($questions));
        $this->assertSame(
            ['こうなったらいいな', '最近の様子', '理想の毎日', '体のこと'],
            array_column($questions, 'label')
        );
        foreach ($questions as $column => $question) {
            $this->assertContains($column, (new TraineeIntakeSurveyResponse)->getFillable());
            $this->assertNotSame('', $question['question']);
            $this->assertCount(2, $question['examples']);
        }
    }

    public function test_設問文と例文は要件定義書のとおり(): void
    {
        $wish = TraineeIntakeSurveyResponse::questions()['wish'];

        $this->assertSame('日々の暮らしの中で、もっとこうなったらいいな、と感じることはありますか？', $wish['question']);
        $this->assertSame('インターホンが鳴るたびに激しく吠えるのを、もう少し落ち着かせたい', $wish['examples'][0]);
        $this->assertSame(
            '健康診断で『左後ろ足のパテラ（膝蓋骨脱臼）がグレード2』と言われているので、足腰に負担をかけたくない',
            TraineeIntakeSurveyResponse::questions()['health_concerns']['examples'][1]
        );
    }

    public function test_トレーニーとトークンへのひも付け(): void
    {
        $response = new TraineeIntakeSurveyResponse;

        $this->assertSame('trainee_id', $response->trainee()->getForeignKeyName());
        $this->assertSame('trainee_intake_survey_token_id', $response->token()->getForeignKeyName());
    }
}

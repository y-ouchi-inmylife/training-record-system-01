<?php

namespace Tests\Unit\Models;

use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyResponse;
use App\Models\TraineeIntakeSurveyToken;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * トレーニーの受付アンケートの状態（要件定義書 6-16-8。2026-10 追加）。
 *
 * 判定の順は「回答あり → 回答済み、なければ有効な案内あり → 回答待ち、どちらもない → 未発行」。
 * 状態は latestIntakeSurveyResponse・activeIntakeSurveyToken（eager load したもの）から決まる。
 */
class TraineeIntakeSurveyStatusTest extends TestCase
{
    public function test_有効な案内がなければ未発行(): void
    {
        $trainee = new Trainee;
        $trainee->setRelation('activeIntakeSurveyToken', null);
        $trainee->setRelation('latestIntakeSurveyResponse', null);

        $this->assertSame(Trainee::INTAKE_NOT_ISSUED, $trainee->intake_survey_status);
    }

    public function test_有効な案内があれば回答待ち(): void
    {
        $token = new TraineeIntakeSurveyToken;
        $token->created_at = Carbon::parse('2026-10-15 09:30:00');

        $trainee = new Trainee;
        $trainee->setRelation('activeIntakeSurveyToken', $token);
        $trainee->setRelation('latestIntakeSurveyResponse', null);

        $this->assertSame(Trainee::INTAKE_AWAITING, $trainee->intake_survey_status);
    }

    public function test_回答があれば回答済み(): void
    {
        $response = new TraineeIntakeSurveyResponse;
        $response->answered_at = Carbon::parse('2026-10-16 14:32:00');

        $trainee = new Trainee;
        $trainee->setRelation('activeIntakeSurveyToken', null);
        $trainee->setRelation('latestIntakeSurveyResponse', $response);

        $this->assertSame(Trainee::INTAKE_ANSWERED, $trainee->intake_survey_status);
    }

    public function test_回答と有効な案内の両方があれば回答済みを先に判定する(): void
    {
        $token = new TraineeIntakeSurveyToken;
        $token->created_at = Carbon::parse('2026-10-15 09:30:00');
        $response = new TraineeIntakeSurveyResponse;
        $response->answered_at = Carbon::parse('2026-10-16 14:32:00');

        $trainee = new Trainee;
        $trainee->setRelation('activeIntakeSurveyToken', $token);
        $trainee->setRelation('latestIntakeSurveyResponse', $response);

        $this->assertSame(Trainee::INTAKE_ANSWERED, $trainee->intake_survey_status);
    }

    public function test_状態の定数(): void
    {
        $this->assertSame('not_issued', Trainee::INTAKE_NOT_ISSUED);
        $this->assertSame('awaiting', Trainee::INTAKE_AWAITING);
        $this->assertSame('answered', Trainee::INTAKE_ANSWERED);
    }

    public function test_トークンは回答日時が空なら有効(): void
    {
        $token = new TraineeIntakeSurveyToken;
        $this->assertTrue($token->isActive());

        $token->answered_at = Carbon::parse('2026-10-16 10:00:00');
        $this->assertFalse($token->isActive());
    }
}

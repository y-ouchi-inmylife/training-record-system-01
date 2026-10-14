<?php

namespace Tests\Unit\Models;

use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyToken;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * トレーニーの受付アンケートの状態（要件定義書 6-16-8。2026-10 追加）。
 *
 * 段階3では「有効な案内あり → 回答待ち、なし → 未発行」の 2 つ（回答済みは段階4で足す）。
 * 状態は activeIntakeSurveyToken（eager load したもの）から決まる。
 */
class TraineeIntakeSurveyStatusTest extends TestCase
{
    public function test_有効な案内がなければ未発行(): void
    {
        $trainee = new Trainee;
        $trainee->setRelation('activeIntakeSurveyToken', null);

        $this->assertSame(Trainee::INTAKE_NOT_ISSUED, $trainee->intake_survey_status);
        $this->assertSame('未発行', $trainee->intake_survey_status_label);
    }

    public function test_有効な案内があれば回答待ちで発行日を添える(): void
    {
        $token = new TraineeIntakeSurveyToken;
        $token->created_at = Carbon::parse('2026-10-15 09:30:00');

        $trainee = new Trainee;
        $trainee->setRelation('activeIntakeSurveyToken', $token);

        $this->assertSame(Trainee::INTAKE_AWAITING, $trainee->intake_survey_status);
        $this->assertSame('回答待ち（2026/10/15 発行）', $trainee->intake_survey_status_label);
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

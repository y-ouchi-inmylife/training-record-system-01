<?php

namespace Tests\Unit\Models;

use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyResponse;
use App\Models\TraineeIntakeSurveyToken;
use App\Models\TraineePersonalityDiagnosisToken;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * トレーニーの愛犬アンケートの状態（要件定義書 6-16-8。2026-10 追加）。
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

    /**
     * 2026-10-19 変更：回答したあとも案内を発行し直せるようにしたため、有効な案内（答え直しの案内）があれば、
     * 回答があっても「回答待ち」にする（その前は回答済みを先に判定していた）
     */
    public function test_回答と有効な案内の両方があれば回答待ちを先に判定する(): void
    {
        $token = new TraineeIntakeSurveyToken;
        $token->created_at = Carbon::parse('2026-10-15 09:30:00');
        $response = new TraineeIntakeSurveyResponse;
        $response->answered_at = Carbon::parse('2026-10-16 14:32:00');

        $trainee = new Trainee;
        $trainee->setRelation('activeIntakeSurveyToken', $token);
        $trainee->setRelation('latestIntakeSurveyResponse', $response);

        $this->assertSame(Trainee::INTAKE_AWAITING, $trainee->intake_survey_status);
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

    // ---- 会員詳細の「期限切れ」の行に出す種類（Trainee::expired_survey_kind。2026-10-19 追加） ----

    private function token(string $class, string $createdAt, ?string $expiresAt, ?string $answeredAt = null): object
    {
        $token = new $class;
        $token->created_at = Carbon::parse($createdAt);
        $token->expires_at = $expiresAt === null ? null : Carbon::parse($expiresAt);
        $token->answered_at = $answeredAt === null ? null : Carbon::parse($answeredAt);

        return $token;
    }

    private function trainee(?object $intake, ?object $personality, ?object $activeIntake = null, ?object $activePersonality = null): Trainee
    {
        $trainee = new Trainee;
        $trainee->setRelation('latestIntakeSurveyToken', $intake);
        $trainee->setRelation('latestPersonalityDiagnosisToken', $personality);
        $trainee->setRelation('activeIntakeSurveyToken', $activeIntake);
        $trainee->setRelation('activePersonalityDiagnosisToken', $activePersonality);

        return $trainee;
    }

    public function test_いちばん新しい案内が未回答で期限切れならその種類(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));

        $intake = $this->token(TraineeIntakeSurveyToken::class, '2026-10-19 10:00:00', '2026-10-26 23:59:59');
        $this->assertSame('intake', $this->trainee($intake, null)->expired_survey_kind);

        $personality = $this->token(TraineePersonalityDiagnosisToken::class, '2026-10-19 10:00:00', '2026-10-26 23:59:59');
        $this->assertSame('personality', $this->trainee(null, $personality)->expired_survey_kind);
    }

    public function test_2つの種類のうち新しい方で決まる(): void
    {
        $this->travelTo(Carbon::parse('2026-10-27 00:00:00'));
        $olderExpiredIntake = $this->token(TraineeIntakeSurveyToken::class, '2026-10-10 10:00:00', '2026-10-17 23:59:59');
        $newerAnsweredPersonality = $this->token(TraineePersonalityDiagnosisToken::class, '2026-10-19 10:00:00', '2026-10-26 23:59:59', '2026-10-20 10:00:00');
        $this->assertNull($this->trainee($olderExpiredIntake, $newerAnsweredPersonality)->expired_survey_kind);

        $olderAnsweredIntake = $this->token(TraineeIntakeSurveyToken::class, '2026-10-10 10:00:00', '2026-10-17 23:59:59', '2026-10-11 10:00:00');
        $newerExpiredPersonality = $this->token(TraineePersonalityDiagnosisToken::class, '2026-10-19 10:00:00', '2026-10-26 23:59:59');
        $this->assertSame('personality', $this->trainee($olderAnsweredIntake, $newerExpiredPersonality)->expired_survey_kind);
    }

    public function test_期限の日のうち_期限が空_案内なし_回答待ちありなら出さない(): void
    {
        $this->travelTo(Carbon::parse('2026-10-26 23:59:59'));
        $inTime = $this->token(TraineeIntakeSurveyToken::class, '2026-10-19 10:00:00', '2026-10-26 23:59:59');
        $this->assertNull($this->trainee($inTime, null)->expired_survey_kind);

        $this->travelTo(Carbon::parse('2030-01-01 00:00:00'));
        $noExpiry = $this->token(TraineeIntakeSurveyToken::class, '2026-10-01 10:00:00', null);
        $this->assertNull($this->trainee($noExpiry, null)->expired_survey_kind);
        $this->assertNull($this->trainee(null, null)->expired_survey_kind);

        // 回答待ちがあれば（「案内中」の行を出すので）出さない
        $expired = $this->token(TraineeIntakeSurveyToken::class, '2026-10-19 10:00:00', '2026-10-26 23:59:59');
        $active = $this->token(TraineePersonalityDiagnosisToken::class, '2029-12-31 10:00:00', '2030-01-07 23:59:59');
        $this->assertNull($this->trainee($expired, null, null, $active)->expired_survey_kind);
    }
}

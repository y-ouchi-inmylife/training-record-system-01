<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\TraineeIntakeSurveyToken;
use App\Models\TraineePersonalityDiagnosisToken;
use App\Models\Trainer;
use Illuminate\Support\Facades\DB;

/**
 * 愛犬アンケート・愛犬性格診断の案内に、マイページ登録の案内を付ける（2026-10-19 追加。要件定義書 6-16-18）。
 *
 * ユーザーレビューで、愛犬アンケートも愛犬性格診断もマイページに登録していなくても答えられるので、回答のついでにマイページの
 * 登録にも進んでもらえるようにした。会員詳細の［案内を発行］のモーダル（S-0305-M08）で「マイページ登録の案内も発行する」を
 * オンにして発行すると、案内の発行と同じトランザクションでマイページ登録の案内を用意し、お客様が最後に答える案内に結び付ける。
 * お客様が回答を終えた完了の画面で、そのマイページ登録の案内へ誘導する（App\Support\MypageRegistrationInvite）。
 * 送る文面（LINE・メール）とご案内の画面は変えない（リンクは 1 つのまま）。
 */
class SurveyMypageRegistrationService
{
    /** 新しくマイページ登録の案内を作った */
    public const CREATED = 'created';

    /** 期限内のマイページ登録の案内があったので、それを使った */
    public const REUSED = 'reused';

    public function __construct(private ClientEmailRegistrationTokenService $registrations) {}

    /**
     * マイページ登録の案内を付けられる会員か。マイページの状態が「未登録」（メールアドレスなし）か「登録待ち」（期限内・期限切れ。
     * メールアドレスを入れる前の段階）のときだけ。「利用中」（登録済み）と「登録待ち（初回設定）」（メールアドレスを入れて登録の途中）は付けない。
     * 呼び出し側は Client::loadStatusData()（または withStatusData）で状態の値を読んでおくこと
     */
    public static function canAttach(Client $client): bool
    {
        return in_array($client->status, [Client::STATUS_NO_EMAIL, Client::STATUS_AWAITING_EMAIL], true);
    }

    /**
     * 発行した案内にマイページ登録の案内を付ける。期限内のマイページ登録の案内があればそれを使い、なければ今の発行と同じ処理で作る。
     * 結び付けるのは、お客様が最後に答える案内（単独の案内はその案内、愛犬アンケート＆愛犬性格診断は愛犬性格診断の案内）。
     * 付けられない会員（canAttach が false）なら何もせず null。付けたら CREATED／REUSED
     */
    public function attach(TraineeIntakeSurveyToken|TraineePersonalityDiagnosisToken $surveyToken, Trainee $trainee, Trainer $practitioner): ?string
    {
        return DB::transaction(function () use ($surveyToken, $trainee, $practitioner) {
            $client = Client::query()->whereKey($trainee->client_id)->lockForUpdate()->firstOrFail();
            $client->loadStatusData();
            if (! self::canAttach($client)) {
                return null;
            }

            [$registration, $created] = $this->registrations->activeOrIssue($client, $practitioner->id);

            $target = $surveyToken instanceof TraineeIntakeSurveyToken && $surveyToken->isCombined()
                ? $surveyToken->personalityDiagnosisToken
                : $surveyToken;
            $target->update(['client_email_registration_token_id' => $registration->id]);

            return $created ? self::CREATED : self::REUSED;
        });
    }

    /** 発行の完了メッセージのあとに足す一言（付けなかったときは空） */
    public static function completionNote(?string $result): string
    {
        return match ($result) {
            self::CREATED => 'マイページ登録の案内も発行しました。',
            self::REUSED => 'マイページ登録の案内は、発行済みのものを使います。',
            default => '',
        };
    }
}

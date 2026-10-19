<?php

namespace App\Support;

use App\Models\TraineeIntakeSurveyToken;
use App\Models\TraineePersonalityDiagnosisToken;

/**
 * 回答を終えた完了の画面（S-1414・S-1416）に出す、マイページ登録へのお誘い（2026-10-19 追加。要件定義書 6-16-18）。
 *
 * 答え終わった案内に結び付けたマイページ登録の案内（client_email_registration_token_id）があり、まだ使えるときだけ、
 * そのマイページ登録の案内のリンク（メールアドレスを入れる画面 S-1406）を返す。完了の画面はトークンを持たないので、
 * 回答の送信のときにここで判定して、フラッシュで渡す。
 */
class MypageRegistrationInvite
{
    /**
     * お誘いのリンク。出さないときは null：
     * - マイページ登録の案内を結び付けていない（「マイページ登録の案内も発行する」をオフで発行した）
     * - そのマイページ登録の案内が期限切れ・使用済み・取消済み（行がない）
     * - 会員がもうマイページを使っている（利用中）・メールアドレスを入れて登録の途中
     * - 会員がログインして答えた（マイページから開いた。今の［マイページへ戻る］のまま）
     */
    public static function url(TraineeIntakeSurveyToken|TraineePersonalityDiagnosisToken|null $surveyToken): ?string
    {
        if ($surveyToken === null || $surveyToken->client_email_registration_token_id === null || auth('client')->check()) {
            return null;
        }
        $registration = $surveyToken->emailRegistrationToken;
        if ($registration === null || ! $registration->isValid()) {
            return null;
        }
        $client = $registration->client;
        if ($client === null || $client->password !== null || $client->email !== null) {
            return null;
        }

        return route('client-portal.email-registration.show', ['token' => $registration->token]);
    }
}

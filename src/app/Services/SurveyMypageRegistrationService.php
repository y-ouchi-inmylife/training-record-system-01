<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Trainee;
use App\Models\Trainer;
use Illuminate\Support\Facades\DB;

/**
 * 愛犬アンケート・愛犬性格診断の案内の発行と一緒に、マイページ登録の案内を発行する（2026-10-19 追加。要件定義書 6-16-18）。
 *
 * ユーザーレビューで、愛犬アンケートも愛犬性格診断もマイページに登録していなくても答えられるので、回答のついでにマイページの
 * 登録にも進んでもらえるようにした。会員詳細の［案内を発行］のモーダル（S-0305-M08）で「マイページ登録の案内も発行する」を
 * オンにして発行すると、案内の発行と同じトランザクションでマイページ登録の案内を作る。お客様が回答を終えた完了の画面では、
 * 会員に有効なマイページ登録の案内があればマイページの登録へ誘導する（App\Support\MypageRegistrationInvite）。
 *
 * 2026-10-19 変更（ユーザーレビュー 2 回目）：発行済みの会員（登録待ち）にもチェックボックスが出て、もう 1 件発行されるように
 * 見えて分かりにくかったため、有効な案内がない会員だけにした。その前は、期限内の案内があればそれを使い、案内に結び付けていた
 * （案内の表の client_email_registration_token_id。2026_10_19_000004 で外した）。
 */
class SurveyMypageRegistrationService
{
    public function __construct(private ClientEmailRegistrationTokenService $registrations) {}

    /**
     * マイページ登録の案内も発行できる会員か。マイページの状態が「未登録」（メールアドレスなし）か「登録待ち（期限切れ）」
     * （メールアドレスを入れる前の段階で、有効な〔期限内の〕案内がない）のときだけ。「登録待ち」（期限内の案内がある）・
     * 「登録待ち（初回設定）」・「利用中」では発行しない。
     * 呼び出し側は Client::loadStatusData()（または withStatusData）で状態の値を読んでおくこと
     */
    public static function canIssue(Client $client): bool
    {
        return $client->status === Client::STATUS_NO_EMAIL
            || ($client->status === Client::STATUS_AWAITING_EMAIL && $client->show_expired_note);
    }

    /**
     * 会員にマイページ登録の案内を発行する（今の発行と同じ処理。期限切れの案内があれば消して作り直す）。
     * 発行できない会員（canIssue が false）なら何もしない。発行したら true
     */
    public function issueFor(Trainee $trainee, Trainer $practitioner): bool
    {
        return DB::transaction(function () use ($trainee, $practitioner) {
            $client = Client::query()->whereKey($trainee->client_id)->lockForUpdate()->firstOrFail();
            $client->loadStatusData();
            if (! self::canIssue($client)) {
                return false;
            }

            [, $created] = $this->registrations->activeOrIssue($client, $practitioner->id);

            return $created;
        });
    }

    /** 発行の完了メッセージのあとに足す一言（発行しなかったときは空） */
    public static function completionNote(bool $issued): string
    {
        return $issued ? 'マイページ登録の案内も発行しました。' : '';
    }
}

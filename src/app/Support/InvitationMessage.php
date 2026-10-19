<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Trainee;
use Carbon\CarbonInterface;

/**
 * ご案内（マイページ登録 S-0307・愛犬アンケート S-0311・愛犬性格診断 S-0312・愛犬アンケート＆愛犬性格診断 S-0314。S-0314 は 2026-10-19 追加）の、LINE・メールで送る文面（2026-10-18 追加。
 * 設計書 screen-design.md S-0307「LINE・メールで送る文面」）。
 *
 * 画面に出す文と［文面をコピー］でコピーする文は、ここで組み立てた 1 つの文字列を両方に使う（食い違わないように）。
 * 文の中身は lang/ja/messages.php の invitation_message（トレーナーさんに確かめてもらって直すときは、そこだけを直す）。
 *
 * 並び：
 *   {氏名} 様
 *   （空行）
 *   {訓練所名}です。          ← 訓練所の名前（config('app.client_portal_company')）が空なら、この行を出さない
 *   {案内の 1 文}
 *   （空行）
 *   {本文}
 *   （空行）
 *   {URL}
 *   （空行）
 *   {有効期限の行｜1回かぎりの行}
 *   ご不明な点がございましたら、お気軽にお問い合わせください。
 */
class InvitationMessage
{
    /** 曜日（Carbon の dayOfWeek は 0＝日〜6＝土） */
    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    /** マイページ登録のご案内（有効期限あり） */
    public static function mypage(Client $client, string $url, CarbonInterface $expiresAt): string
    {
        return self::compose(
            $client,
            __('messages.invitation_message.mypage.intro'),
            __('messages.invitation_message.mypage.body'),
            $url,
            __('messages.invitation_message.expires', ['expires' => self::expiresLabel($expiresAt)]),
        );
    }

    /**
     * 愛犬アンケートのご案内。最後から 2 段目は、有効期限があれば「リンクの有効期限：…まで」（マイページ登録のご案内と同じ。
     * 2026-10-19 変更：案内に 1 週間の有効期限を付けた）。期限なし（期限を付ける前に発行した案内）なら今までどおり 1 回かぎりの文
     */
    public static function intakeSurvey(Trainee $trainee, string $url, ?CarbonInterface $expiresAt = null): string
    {
        return self::compose(
            $trainee->client,
            __('messages.invitation_message.intake.'.self::introKey($trainee), [
                'callName' => $trainee->client_call_name,
                'survey' => __('messages.intake_survey_name'),
            ]),
            __('messages.invitation_message.intake.body'),
            $url,
            self::expiresOrOnceLine($expiresAt),
        );
    }

    /** 愛犬性格診断のご案内（問題の数は今の設問の数から出す。最後から 2 段目は愛犬アンケートと同じ） */
    public static function personalityDiagnosis(Trainee $trainee, string $url, ?CarbonInterface $expiresAt = null): string
    {
        return self::compose(
            $trainee->client,
            __('messages.invitation_message.personality.'.self::introKey($trainee), [
                'callName' => $trainee->client_call_name,
                'diagnosis' => __('messages.personality_diagnosis_name'),
            ]),
            __('messages.invitation_message.personality.body', ['count' => count(PersonalityDiagnosisQuestions::questions())]),
            $url,
            self::expiresOrOnceLine($expiresAt),
        );
    }

    /**
     * 愛犬アンケート＆愛犬性格診断のご案内（2026-10-19 追加）。1 つのリンクで続けて答えてもらう。
     * 本文に愛犬性格診断の問題の数を入れる。最後から 2 段目は単独の案内と同じ（組み合わせは期限を付けてから作ったので、いつも期限の行）
     */
    public static function combined(Trainee $trainee, string $url, ?CarbonInterface $expiresAt = null): string
    {
        $names = [
            'survey' => __('messages.intake_survey_name'),
            'diagnosis' => __('messages.personality_diagnosis_name'),
        ];

        return self::compose(
            $trainee->client,
            __('messages.invitation_message.combined.'.self::introKey($trainee), ['callName' => $trainee->client_call_name, ...$names]),
            __('messages.invitation_message.combined.body', ['count' => count(PersonalityDiagnosisQuestions::questions()), ...$names]),
            $url,
            self::expiresOrOnceLine($expiresAt),
        );
    }

    /** 有効期限の行（期限があるとき）か、1 回かぎりの文（期限なしの案内。2026-10-19 より前に発行したもの） */
    private static function expiresOrOnceLine(?CarbonInterface $expiresAt): string
    {
        return $expiresAt !== null
            ? __('messages.invitation_message.expires', ['expires' => self::expiresLabel($expiresAt)])
            : __('messages.invitation_message.once');
    }

    /**
     * 案内の 1 文のキー。愛犬の名前が空なら「{呼び名}の」を付けない文（intro_unnamed）にする（2026-10-18 追加。
     * 名前が空のときの呼び名は「愛犬」なので、「愛犬の愛犬性格診断のご案内…」と「愛犬」が重なって読みにくいため）
     */
    private static function introKey(Trainee $trainee): string
    {
        return $trainee->has_name ? 'intro' : 'intro_unnamed';
    }

    /** 有効期限の表示（例：2026年10月21日（水）） */
    public static function expiresLabel(CarbonInterface $date): string
    {
        return $date->format('Y年n月j日').'（'.self::WEEKDAYS[$date->dayOfWeek].'）';
    }

    private static function compose(Client $client, string $intro, string $body, string $url, string $lastLine): string
    {
        $company = config('app.client_portal_company');
        $lines = [
            // 氏名は「姓 名」。名が空なら姓だけ（後ろの空白を残さない）
            __('messages.invitation_message.greeting', ['name' => trim($client->full_name)]),
            '',
        ];
        if (! blank($company)) {
            $lines[] = __('messages.invitation_message.from', ['company' => $company]);
        }
        array_push($lines, $intro, '', $body, '', $url, '', $lastLine, __('messages.invitation_message.closing'));

        return implode("\n", $lines);
    }
}

<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Controllers\MediaRecordController as TrainerMediaRecordController;
use App\Models\Trainee;
use App\Models\TraineePersonalityDiagnosisResult;
use App\Support\RecordTimeline;
use Illuminate\Contracts\View\View;

/**
 * クライアント閲覧機能（柱2）— ダッシュボードコントローラ
 *
 * ログイン中のクライアント自身のトレーニング記録を、セッションカード・
 * フィードとしてビューに渡す。段階③でトレーニーの体重推移グラフ用の
 * データも組み立てて渡す（設計書 §S-1402 の「体重推移」節、6-15-14）。
 *
 * 設計書 §8-1-1 の方針に従う：
 * - 既存クエリはそのまま（eager load / withCount / training_date 降順は不変）
 * - 記録ごとに入れ子の $sessions 構造を返す（前案の平坦化 $mediaItems は撤回）
 * - 署名付きサムネイル URL 等はコントローラで生成し、Blade からモデルの
 *   temporaryThumbnailUrl() を呼ばない
 * - 有効期限（$thumbnailExpiresAt）の算出はそのまま維持
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        $client = auth('client')->user();

        // 自分の記録を日付の新しい順（降順）で取得。
        // 一覧表示に使うリレーション（担当1・担当2）に加え、
        // メディアもカード内に埋め込むため mediaRecords を eager load（N+1回避）。
        // updatedBy はクライアント非表示のため意図的にロードしない。
        // 同じ日の記録は時刻の新しい順（時刻のない記録はその日のいちばん後ろ）。会員詳細（S-0305）と同じ並びにする
        // （2026-10-18 追加。公開済みの評価文を混ぜるときに、同じ日の並びを会員詳細とそろえるため）
        $records = $client
            ->trainingRecords()
            ->with(['trainer1', 'trainer2', 'mediaRecords'])
            ->withCount('mediaRecords')
            ->orderByDesc('training_date')
            ->orderByDesc('training_time')
            ->get();

        // 記録ごとに入れ子の $sessions 構造に組み替える（設計書 §8-1-1 変更1）。
        // 署名付きサムネイル URL は temporaryThumbnailUrl() を呼ぶ必要があるため、
        // 有効期限をここで算出しコントローラで一括生成する（Blade で呼ばない）。
        $thumbnailExpiresAt = now()->addMinutes(
            TrainerMediaRecordController::PLAY_URL_EXPIRES_MINUTES
        );

        // displayTitle は渡さない。お客様側の Blade は種別ラベル（写真／動画）を
        // alt / aria-label / data-display-title に載せる（設計書 S-1402 メディアギャラリー
        // 節参照。メディアの表示名・ファイル名はクライアント側に一切露出させない方針）。
        // typeLabel はコントローラで組み立てて渡す（Blade で `@php(...)` を使うと
        // 直前の `@php ... @endphp` ブロックとパースが衝突する事故があったため、
        // Blade 側の @php 使用を避けている）。photo / video の 2 分岐は DB CHECK 制約に対応。
        $sessions = $records->map(function ($rec) use ($thumbnailExpiresAt) {
            return [
                'record' => $rec,
                'media'  => $rec->mediaRecords->map(function ($m) use ($thumbnailExpiresAt) {
                    return [
                        'id'               => $m->id,
                        'type'             => $m->type,
                        'typeLabel'        => $m->type === 'photo' ? '写真' : '動画',
                        'thumbnailUrl'     => $m->temporaryThumbnailUrl($thumbnailExpiresAt),
                        'conversionStatus' => $m->conversion_status,
                    ];
                })->all(),
            ];
        });

        // 最新の記録のカード・これまでの記録には、自分のトレーニーの公開済みの愛犬性格診断の評価文も混ぜる
        // （2026-10-18 追加。要件定義書 6-15-6・6-16-16、設計書 S-1402「愛犬性格診断の評価を混ぜる」）。
        // 並びは会員詳細（S-0305）と同じ（RecordTimeline）。下書き・公開を取り消したものは入らない。
        // 「全 N 回」は記録の回数なので、評価文を数えない $recordCount を使う
        $timeline = RecordTimeline::merge(
            $sessions,
            TraineePersonalityDiagnosisResult::publishedEvaluationsOfClient($client->id),
        );
        $recordCount = $sessions->count();

        // 段階③：トレーニーごとの体重推移グラフ用データを組み立てる。
        // Blade には完成した配列を渡す（Blade 内での再計算・並べ替えは行わない）。
        $weightCharts = $this->buildWeightCharts($client);

        // ご連絡先の登録のお願い（2026-10-18 追加。設計書 S-1402「ご連絡先の登録のお願い」）。契約がある会員で、住所か電話番号が空のときだけ。
        // 足りないもの（'address'・'phone'）を渡し、文は足りないものに合わせる。入力が済めば出ない
        $contactNotice = $this->contactNotice($client);

        return view('client.dashboard', compact('timeline', 'recordCount', 'weightCharts', 'contactNotice'));
    }

    /**
     * 体重推移グラフ用のデータをトレーニーごとに組み立てる。
     *
     * 1 頭分の組み立てロジックは `Trainee::getWeightChartDataAttribute()` に集約
     * （**2026-09 変更**、トレーナー側 S-0309 と共用するため）。本メソッドは
     * トレーニーを回してアクセサを呼ぶだけの薄い形。返り値の形（トレーニー配列で
     * 各要素は `id / name / photoUrl / datasets`）は変更なし — Blade
     * （client/dashboard.blade.php）は無変更で動く。詳細な設計方針・データ形式・
     * タイムゾーン扱いは `Trainee::getWeightChartDataAttribute()` の docblock を参照。
     *
     * トレーニー 0 頭の会員では空配列を返し、Blade で `@if(!empty($weightCharts))` により
     * ブロックごと非表示になる（挙動変更なし）。
     */
    private function buildWeightCharts($client): array
    {
        // トレーニーは Trainee::trainees() で `id` 昇順（登録順）が既定。
        // アクセサ内で $this->measurements を触るため、ここで eager load しておく
        // （load しないと 1+N のクエリになる。単頭ケースは S-0309 側で別途
        // $trainee->load('measurements') 済み）。
        // personalityDiagnosisResults は性格診断（タイプ・レーダーチャート）に使う（2026-10 追加。要件定義書 6-15-19）。
        // トレーニーが何頭いてもクエリは 1 本。自分のトレーニーの結果だけ（$client->trainees() の絞り込みのまま）
        // latestIntakeSurveyResponse・activeIntakeSurveyToken・activePersonalityDiagnosisToken は、回答待ちの案内の
        // お知らせ（2026-10 追加。要件定義書 6-15-3）に使う。どれもトレーニーが何頭いてもクエリは 1 本
        $trainees = $client->trainees()->with([
            'measurements', 'personalityDiagnosisResults',
            'latestIntakeSurveyResponse', 'activeIntakeSurveyToken', 'activePersonalityDiagnosisToken',
        ])->get();

        // personality：結果がなければ null。あれば最新の結果のタイプ（表示名。判定なしなら「判定なし」）と、レーダーチャートの値
        // （今回・前回・初回。トレーナー側 S-0309 と同じ Trainee::personality_radar_data）。
        // 点数なしの注記は出さない（2026-10-18 変更：一度足したが、ユーザーレビューで要らないことになった）
        return $trainees->map(fn ($trainee) => $trainee->weight_chart_data + [
            'personality' => $trainee->personalityDiagnosisResults->isEmpty() ? null : [
                'type' => $trainee->personalityDiagnosisResults->first()->personality_type_label,
                'radar' => $trainee->personality_radar_data,
            ],
            'surveyNotices' => $this->surveyNotices($trainee),
            // 写真の下に出す 2 行（犬種（性別）と誕生日。値がない行は入らない。2026-10-18 追加。Trainee::client_profile_parts）
            'profileParts' => $trainee->client_profile_parts,
        ])->all();
    }

    /**
     * ご連絡先の登録のお願い（2026-10-18 追加。設計書 S-1402「ご連絡先の登録のお願い」）。
     *
     * 出すのは、契約が 1 件以上ある（有効期限切れ・回数を使い切ったものも含む）会員で、住所（都道府県・市区町村・町名番地のどれか）か
     * 電話番号が空のとき。マイページを開くたびに出す（閉じるボタンはない。入力が済めば出ない）。
     * text は足りないものだけを書いた文（lang/ja/messages.php の client_contact_notice）、url は登録情報の変更（S-1411）
     *
     * @return array{missing: list<string>, text: string, url: string}|null
     */
    private function contactNotice($client): ?array
    {
        if (! $client->hasContracts()) {
            return null;
        }
        $missing = $client->missingContactDetails();
        if ($missing === []) {
            return null;
        }

        return [
            'missing' => $missing,
            'text' => __('messages.client_contact_notice.'.(count($missing) === 2 ? 'both' : $missing[0])),
            'url' => route('client-portal.profile.edit'),
        ];
    }

    /**
     * 回答待ちの案内のお知らせ（2026-10 追加。設計書 S-1402「回答待ちの案内のお知らせ」）。
     * 並びは 愛犬アンケート → 性格診断。kind は文（lang/ja/messages.php の client_survey_notice）と見た目を、
     * url は印刷した案内と同じ回答の URL（トークンの URL）。
     *
     * 「回答待ち」の判定は、会員詳細（S-0305）で［案内を表示］［取消］を出す条件と同じ：
     * - 愛犬アンケート：Trainee::intake_survey_status が回答待ち（回答がなく、有効な案内〔未回答のトークン〕がある）
     * - 性格診断：有効な案内（Trainee::activePersonalityDiagnosisToken。未回答のトークン）がある
     * 取消した案内はトークンの行ごと消えるので、ここには出ない
     *
     * @return array<int, array{kind: string, url: string}>
     */
    private function surveyNotices($trainee): array
    {
        $notices = [];
        if ($trainee->intake_survey_status === Trainee::INTAKE_AWAITING) {
            $notices[] = [
                'kind' => 'intake',
                'url' => route('client-portal.intake-survey.show', ['token' => $trainee->activeIntakeSurveyToken->token]),
            ];
        }
        if ($trainee->activePersonalityDiagnosisToken !== null) {
            $notices[] = [
                'kind' => 'personality',
                'url' => route('client-portal.personality-diagnosis.show', ['token' => $trainee->activePersonalityDiagnosisToken->token]),
            ];
        }

        return $notices;
    }
}

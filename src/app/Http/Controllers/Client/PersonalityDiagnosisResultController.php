<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\TraineePersonalityDiagnosisResult;
use Illuminate\Contracts\View\View;

/**
 * クライアント閲覧機能（柱2）— 愛犬性格診断のアドバイスコントローラ（S-1418。2026-10-18 追加。要件定義書 6-15-21、api-design.md S-1418）
 *
 * ログイン中のクライアントが、トレーナーが公開した愛犬性格診断のアドバイスを、タイプとその結果だけのレーダーチャートと一緒に見る。
 * - 見られるのは、自分のトレーニーの公開済みのアドバイスだけ。下書き・なし・公開を取り消した・ほかのクライアントのものは 404
 *   （公開していないアドバイスがあることも分からないように。会員側の記録の詳細〔ほかの会員は 403〕と違い 404 にする）
 * - view には表示に使うものだけを渡す（点数・50 問の回答・アドバイスの状態・更新した人は渡さない）
 * - 表示はアクセスログに記録しない（会員側の操作は記録しない。記録の詳細 S-1404 と同じ）
 */
class PersonalityDiagnosisResultController extends Controller
{
    public function show(TraineePersonalityDiagnosisResult $personalityDiagnosisResult): View
    {
        $result = $personalityDiagnosisResult;
        $result->load(['trainee', 'evaluationPublishedBy']);
        if ($result->evaluation_published_at === null || $result->trainee->client_id !== auth('client')->id()) {
            abort(404);
        }

        $trainee = $result->trainee;

        return view('client.personality-diagnosis-results.show', [
            'callName' => $trainee->client_call_name,
            'answeredAt' => $result->answered_at,
            'publishedAt' => $result->evaluation_published_at,
            'publishedByName' => $result->evaluationPublishedBy?->name,
            'typeLabel' => $result->personality_type_label,
            'evaluationText' => $result->evaluation_text,
            // その結果だけ（2026-10-19 変更：前回・初回は重ねない。前の回と比べるのはマイページの愛犬のカードのグラフ。
            // その前は、その結果の時点の今回・前回・初回〔personalityRadarDataAsOf〕）。あとから新しい結果が増えても変わらない
            'radarData' => $trainee->personalityRadarDataOnly($result),
        ]);
    }
}

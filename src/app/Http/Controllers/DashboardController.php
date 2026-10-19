<?php

namespace App\Http\Controllers;

use App\Models\TraineePersonalityDiagnosisResult;
use App\Models\TrainingRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * ダッシュボード画面を表示
     */
    public function index(Request $request): View
    {
        // 最近のトレーニング記録（S-0201 セクション「最近のトレーニング記録」、2026-09 追加）。
        // 過去 1 週間（今日を含む 7 日間、未来日は含めない）を日付の新しい順で取得。
        // 詳細な設計方針は screen-design.md S-0201「設計方針」参照。
        $recentFrom = now()->startOfDay()->subDays(6);
        $recentTo   = now()->endOfDay();

        // 絞り込み：クエリパラメータ recent=primary のときのみ主担当（clients.primary_trainer_id）で絞る。
        // 空・不正な値は「すべて」として扱う（`=== 'primary'` の等価判定で分岐）。
        $recentFilter = $request->query('recent') === 'primary' ? 'primary' : 'all';

        $recentQuery = TrainingRecord::with(['client.trainees', 'trainer1', 'trainer2'])
            ->withCount('mediaRecords')
            ->whereBetween('training_date', [$recentFrom->toDateString(), $recentTo->toDateString()]);

        if ($recentFilter === 'primary') {
            // 会員の主担当 = ログイン中のトレーナー。担当1・担当2 で絞るのではない
            // （設計書 S-0201 設計方針「『主担当のみ』の基準」参照）。
            $recentQuery->whereHas('client', function ($q) {
                $q->where('primary_trainer_id', Auth::id());
            });
        }

        // 並びは S-0402 の既定に合わせる（training_date DESC, training_time DESC）。
        $recentRecords = $recentQuery
            ->orderBy('training_date', 'desc')
            ->orderBy('training_time', 'desc')
            ->get();

        // 過去 30 日の愛犬性格診断（S-0201。2026-10-20 追加：アドバイスの公開し忘れがひと目で分かるように）。
        // 期間は最近のトレーニング記録と同じ決め方（今日を含む 30 日間。今日の 29 日前の 0:00 から今日の終わりまで）。
        // 公開済みも含めてすべて。見せる範囲も最近のトレーニング記録と同じ（役割で変えない。recent の絞り込みは効かない）。
        // ページングなし。トレーニーと会員はまとめて読み込む
        $recentDiagnoses = TraineePersonalityDiagnosisResult::with('trainee.client')
            ->whereBetween('answered_at', [now()->startOfDay()->subDays(29), now()->endOfDay()])
            ->orderBy('answered_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();
        // 未公開（なし・下書き）の件数。状態は S-0313 のバッジと同じ判定（evaluation_status）で数える
        $unpublishedDiagnosisCount = $recentDiagnoses
            ->filter(fn (TraineePersonalityDiagnosisResult $result) => $result->evaluation_status !== TraineePersonalityDiagnosisResult::EVALUATION_PUBLISHED)
            ->count();

        return view('dashboard', compact('recentRecords', 'recentFilter', 'recentDiagnoses', 'unpublishedDiagnosisCount'));
    }
}

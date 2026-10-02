<?php

namespace App\Http\Controllers;

use App\Models\AccessLog;
use App\Models\Trainer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * トレーナー操作履歴管理コントローラー
 */
class AccessLogController extends Controller
{
    /**
     * トレーナー操作履歴一覧画面
     */
    public function index(Request $request): View
    {
        // 日付フィルタの相関チェック（開始日 ≦ 終了日）
        // 文言は lang/ja/validation.php の custom.date_to.after_or_equal に集約（設計書 §2-8）
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
        ]);

        $query = AccessLog::with('trainer')
            ->orderBy('created_at', 'desc');

        // トレーナーフィルター
        if ($request->filled('trainer_id')) {
            $query->where('trainer_id', $request->input('trainer_id'));
        }

        // 操作フィルター
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        // 期間フィルター
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $logs = $query->paginate(50)->withQueryString();
        $trainers = Trainer::orderBy('name')->get();

        return view('access-logs.index', compact('logs', 'trainers'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Trainer;
use App\Models\TrainingRecord;
use App\Models\LoginAttempt;
use App\Rules\StrongPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * トレーナーアカウント管理コントローラー
 */
class TrainerController extends Controller
{
    /**
     * 有効なトレーナー一覧を取得（API）
     */
    public function apiList(): JsonResponse
    {
        $trainers = Trainer::practitioners()
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($trainers);
    }

    /**
     * トレーナー一覧
     */
    public function index(): View
    {
        $trainers = Trainer::where('role', '!=', 'system_admin')
            ->withCount([
                'primaryClients',
                'trainingRecordsAsTrainer1',
                'trainingRecordsAsTrainer2',
            ])->orderBy('display_order')->orderBy('name')->get();

        $adminCount = $trainers->where('role', 'admin')->count();

        return view('trainers.index', compact('trainers', 'adminCount'));
    }

    /**
     * トレーナー登録画面
     */
    public function create(): View
    {
        return view('trainers.create');
    }

    /**
     * トレーナー登録処理
     */
    public function store(Request $request): RedirectResponse
    {
        // 文言は lang/ja/validation.php に集約（設計書 §2-8。login_id.regex は
        // custom.login_id.regex で、具体文言「ログインIDに使用できない文字が含まれています。」）。
        $validated = $request->validate([
            'login_id' => 'required|string|max:50|regex:/^[a-zA-Z0-9_]+$/|unique:trainers,login_id',
            'name' => 'required|string|max:100',
            'password' => ['required', 'string', 'confirmed', new StrongPassword()],
            'role' => 'required|in:admin,staff',
        ]);

        $validated['must_change_password'] = true;
        $validated['display_order'] = (Trainer::max('display_order') ?? 0) + 1;
        Trainer::create($validated);

        return redirect()->route('trainers.index')
            ->with('success', 'トレーナーを登録しました。初回ログイン時にパスワード変更が求められます。');
    }

    /**
     * トレーナー編集画面
     */
    public function edit(Trainer $trainer)
    {
        if ($trainer->isSystemAdmin()) {
            return redirect()->route('trainers.index')
                ->with('error', 'システム管理者アカウントは編集できません。');
        }

        // 自分自身は編集不可
        if ($trainer->id === auth()->id()) {
            return redirect()->route('trainers.index')
                ->with('error', '自分自身を編集することはできません。');
        }

        return view('trainers.edit', compact('trainer'));
    }

    /**
     * トレーナー更新処理
     */
    public function update(Request $request, Trainer $trainer): RedirectResponse
    {
        if ($trainer->isSystemAdmin()) {
            return redirect()->route('trainers.index')
                ->with('error', 'システム管理者アカウントは編集できません。');
        }

        // 自分自身は編集不可
        if ($trainer->id === auth()->id()) {
            return redirect()->route('trainers.index')
                ->with('error', '自分自身を編集することはできません。');
        }

        // 文言は lang/ja/validation.php に集約（設計書 §2-8）
        $validated = $request->validate([
            'login_id' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('trainers', 'login_id')->ignore($trainer->id),
            ],
            'name' => 'required|string|max:100',
            'role' => 'required|in:admin,staff',
        ]);

        // 最後の管理者を一般に変更することを防止
        if ($trainer->role === 'admin' && $validated['role'] === 'staff') {
            $adminCount = Trainer::where('role', 'admin')->count();
            if ($adminCount <= 1) {
                return redirect()->route('trainers.edit', $trainer)
                    ->with('error', '管理者は最低1名必要です。権限を変更できません。');
            }
        }

        $trainer->update([
            'login_id' => $validated['login_id'],
            'name' => $validated['name'],
            'role' => $validated['role'],
        ]);

        return redirect()->route('trainers.index')
            ->with('success', 'トレーナー情報を更新しました。');
    }

    /**
     * トレーナー削除
     */
    public function destroy(Trainer $trainer): RedirectResponse
    {
        // system_adminは削除できない
        if ($trainer->isSystemAdmin()) {
            return redirect()->route('trainers.index')
                ->with('error', 'システム管理者アカウントは削除できません。');
        }

        // 自分自身は削除不可
        if ($trainer->id === auth()->id()) {
            return redirect()->route('trainers.index')
                ->with('error', '自分自身を削除することはできません。');
        }

        // 管理トレーナーが1名の場合は削除不可
        if ($trainer->role === 'admin') {
            $adminCount = Trainer::where('role', 'admin')->count();
            if ($adminCount <= 1) {
                return redirect()->route('trainers.index')
                    ->with('error', '管理者は最低1名必要です。削除できません。');
            }
        }

        // クライアントの主担当トレーナーになっているかチェック
        $primaryClientsCount = Client::where('primary_trainer_id', $trainer->id)->count();
        if ($primaryClientsCount > 0) {
            return redirect()->route('trainers.index')
                ->with('error', "{$trainer->name} は {$primaryClientsCount} 人の会員の主担当トレーナーです。先に主担当を変更してから削除してください。");
        }

        // トレーニング記録の担当者になっているかチェック（担当者1・担当者2）
        $recordsCount = TrainingRecord::where('trainer1_id', $trainer->id)
            ->orWhere('trainer2_id', $trainer->id)
            ->count();
        if ($recordsCount > 0) {
            return redirect()->route('trainers.index')
                ->with('error', "{$trainer->name} は {$recordsCount} 件のトレーニング記録の担当者です。削除できません。");
        }

        $trainer->delete();

        return redirect()->route('trainers.index')
            ->with('success', "{$trainer->name} を削除しました。");
    }

    /**
     * アカウントロック解除
     */
    public function unlock(Trainer $trainer): RedirectResponse
    {
        if (!$trainer->is_locked) {
            return redirect()->route('trainers.index')
                ->with('error', 'このアカウントはロックされていません。');
        }

        $trainer->update(['is_locked' => false]);

        // 失敗履歴をクリア
        LoginAttempt::where('trainer_id', $trainer->id)
            ->where('success', false)
            ->delete();

        return redirect()->route('trainers.index')
            ->with('success', $trainer->name . ' のアカウントロックを解除しました。');
    }

    /**
     * アカウント有効/無効の切り替え
     */
    public function toggleActive(Trainer $trainer): RedirectResponse
    {
        // system_adminは無効化できない
        if ($trainer->isSystemAdmin()) {
            return redirect()->route('trainers.index')
                ->with('error', 'システム管理者アカウントは無効化できません。');
        }

        // 自分自身は無効化できない
        if ($trainer->id === auth()->id()) {
            return redirect()->route('trainers.index')
                ->with('error', '自分自身のアカウントを無効化することはできません。');
        }

        // 最後の管理者を無効化することを防止
        if ($trainer->role === 'admin' && $trainer->is_active) {
            $activeAdminCount = Trainer::where('role', 'admin')
                ->where('is_active', true)
                ->count();
            if ($activeAdminCount <= 1) {
                return redirect()->route('trainers.index')
                    ->with('error', '管理者は最低1名必要です。無効化できません。');
            }
        }

        $trainer->update(['is_active' => !$trainer->is_active]);
        $status = $trainer->is_active ? '有効化' : '無効化';

        return redirect()->route('trainers.index')
            ->with('success', $trainer->name . ' のアカウントを' . $status . 'しました。');
    }

    /**
     * パスワードリセット画面
     */
    public function showResetPassword(Trainer $trainer): View|RedirectResponse
    {
        // system_adminはパスワードをリセットできない
        if ($trainer->isSystemAdmin()) {
            return redirect()->route('trainers.index')
                ->with('error', 'システム管理者アカウントはパスワードをリセットできません。');
        }

        return view('trainers.reset-password', compact('trainer'));
    }

    /**
     * パスワードリセット処理
     */
    public function resetPassword(Request $request, Trainer $trainer): RedirectResponse
    {
        // system_adminはパスワードをリセットできない
        if ($trainer->isSystemAdmin()) {
            return redirect()->route('trainers.index')
                ->with('error', 'システム管理者アカウントはパスワードをリセットできません。');
        }

        // 文言は lang/ja/validation.php に集約（設計書 §2-8）。
        // 送信項目の名前を new_password / new_password_confirmation にして、他のパスワード変更
        // 画面（プロフィール・強制パスワード変更・会員側）と揃える（段階 3-4、設計書 S-0804）。
        // これで attributes.new_password = '新しいパスワード' が引かれ、画面ラベル「新しい
        // パスワード」と文言が揃う。
        $validated = $request->validate([
            'new_password' => ['required', 'string', 'confirmed', new StrongPassword()],
        ]);

        $trainer->update([
            'password' => $validated['new_password'],
            'must_change_password' => true,
        ]);

        return redirect()->route('trainers.index')
            ->with('success', $trainer->name . ' のパスワードをリセットしました。次回ログイン時にパスワード変更が求められます。');
    }

    /**
     * 表示順を1つ上に移動
     */
    public function moveUp(Trainer $trainer): RedirectResponse
    {
        if ($trainer->isSystemAdmin()) {
            return redirect()->route('trainers.index');
        }

        return $this->swapAdjacent($trainer, -1);
    }

    /**
     * 表示順を1つ下に移動
     */
    public function moveDown(Trainer $trainer): RedirectResponse
    {
        if ($trainer->isSystemAdmin()) {
            return redirect()->route('trainers.index');
        }

        return $this->swapAdjacent($trainer, 1);
    }

    /**
     * 一覧上の隣接トレーナーと表示順を入れ替え、display_orderを1,2,3...に振り直す
     *
     * @param int $offset -1=上に移動、+1=下に移動
     */
    private function swapAdjacent(Trainer $trainer, int $offset): RedirectResponse
    {
        // indexと同じソート順で全トレーナーを取得（一覧上の位置を特定するため）
        $trainers = Trainer::where('role', '!=', 'system_admin')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->values();

        $index = $trainers->search(fn ($c) => $c->id === $trainer->id);
        $targetIndex = $index + $offset;

        // 対象が見つからない、もしくは移動先が範囲外なら何もしない
        if ($index === false || $targetIndex < 0 || $targetIndex >= $trainers->count()) {
            return redirect()->route('trainers.index');
        }

        // 一覧上で位置を入れ替え
        $reordered = $trainers->all();
        [$reordered[$index], $reordered[$targetIndex]] = [$reordered[$targetIndex], $reordered[$index]];

        // display_orderを 1, 2, 3... と振り直し（重複や歯抜けを解消）
        foreach ($reordered as $i => $c) {
            $c->display_order = $i + 1;
            $c->save();
        }

        return redirect()->route('trainers.index')
            ->with('success', '表示順を変更しました。');
    }
}

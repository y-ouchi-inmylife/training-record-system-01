<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\Trainer;
use App\Models\LoginAttempt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** 連続失敗でロックするまでの回数 */
    private const MAX_ATTEMPTS = 5;

    /**
     * ログイン画面を表示
     */
    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    /**
     * ログイン処理
     */
    public function login(Request $request): RedirectResponse
    {
        $request->validate([
            'login_id' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string'],
        ]);

        $userId = $request->input('login_id');
        $password = $request->input('password');
        $ipAddress = $request->ip();

        // トレーナーを検索
        $trainer = Trainer::where('login_id', $userId)->first();

        // アカウント無効化チェック
        if ($trainer && !$trainer->is_active) {
            $this->recordLoginAttempt($trainer->id, $userId, $ipAddress, false);

            return back()
                ->withInput($request->only('login_id'))
                ->withErrors(['login' => 'このアカウントは無効化されています。管理者にお問い合わせください。']);
        }

        // アカウントロック中かチェック
        if ($trainer && $trainer->is_locked) {
            $this->recordLoginAttempt($trainer->id, $userId, $ipAddress, false);

            return back()
                ->withInput($request->only('login_id'))
                ->withErrors(['login' => 'アカウントがロックされています。管理者に連絡してください。']);
        }

        // 認証チェック
        if ($trainer && Hash::check($password, $trainer->password)) {
            // ログイン成功
            $this->recordLoginAttempt($trainer->id, $userId, $ipAddress, true);

            // 最終ログイン日時を記録
            $trainer->update(['last_login_at' => now()]);

            // トレーナー操作履歴に記録
            AccessLog::create([
                'trainer_id' => $trainer->id,
                'action' => 'login',
                'ip_address' => $ipAddress,
                'user_agent' => substr($request->userAgent() ?? '', 0, 500),
            ]);

            Auth::login($trainer);
            $request->session()->regenerate();

            // システム管理者は音声ファイル一覧（S-0701）、それ以外はダッシュボード（S-0101）へ
            $home = $trainer->isSystemAdmin() ? '/usage-stats' : '/dashboard';

            return redirect()->intended($home);
        }

        // ログイン失敗
        $this->recordLoginAttempt($trainer?->id, $userId, $ipAddress, false);

        // 連続失敗回数を確認してロック
        if ($trainer) {
            $this->checkAndLockAccount($trainer);
        }

        return back()
            ->withInput($request->only('login_id'))
            ->withErrors(['login' => 'ログインIDまたはパスワードが正しくありません。']);
    }

    /**
     * ログイン試行を記録
     */
    private function recordLoginAttempt(
        ?int $trainerId,
        string $loginIdInput,
        ?string $ipAddress,
        bool $success,
    ): void {
        LoginAttempt::create([
            'trainer_id' => $trainerId,
            'login_id_input' => $loginIdInput,
            'ip_address' => $ipAddress,
            'attempted_at' => now(),
            'success' => $success,
        ]);
    }

    /**
     * 連続失敗回数を確認し、上限に達したらアカウントをロック
     */
    private function checkAndLockAccount(Trainer $trainer): void
    {
        // 直近の連続失敗回数を取得（最後の成功以降の失敗数）
        $lastSuccess = LoginAttempt::where('trainer_id', $trainer->id)
            ->where('success', true)
            ->orderByDesc('attempted_at')
            ->value('attempted_at');

        $query = LoginAttempt::where('trainer_id', $trainer->id)
            ->where('success', false);

        if ($lastSuccess) {
            $query->where('attempted_at', '>', $lastSuccess);
        }

        $failCount = $query->count();

        if ($failCount >= self::MAX_ATTEMPTS) {
            $trainer->update(['is_locked' => true]);
        }
    }
}

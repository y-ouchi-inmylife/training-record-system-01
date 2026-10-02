<?php

namespace App\Http\Controllers;

use App\Rules\StrongPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * マイプロフィール管理コントローラー
 */
class ProfileController extends Controller
{
    /**
     * プロフィール編集画面を表示
     */
    public function edit(): View
    {
        return view('profile.edit', [
            'trainer' => Auth::user(),
        ]);
    }

    /**
     * プロフィール情報を更新
     */
    public function update(Request $request): RedirectResponse
    {
        // 文言は lang/ja/validation.php に集約（設計書 §2-8）
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        Auth::user()->update($validated);

        return redirect()->route('profile.edit')
            ->with('success', 'プロフィールを更新しました。');
    }

    /**
     * 自分のパスワード変更画面を表示。
     */
    public function editPassword(): View
    {
        return view('profile.password');
    }

    /**
     * パスワードを変更
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        // 文言は lang/ja/validation.php に集約（設計書 §2-8）。
        // current_password の照合は Laravel 標準の current_password ルールに任せる
        // （段階 3-5 で Hash::check の自前実装から置き換えた。失敗の文言は
        //  lang/ja/validation.php の current_password「現在のパスワードが正しくありません。」）。
        // defaults.guard は web（config/auth.php）で、トレーナーの認証も web ガードを使うため、
        // ルールにガード名を付けない（current_password:web でも等価）。
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'new_password' => ['required', 'string', 'confirmed', new StrongPassword()],
        ]);

        Auth::user()->update([
            'password' => $validated['new_password'],
        ]);

        return redirect()->route('profile.edit')
            ->with('success', 'パスワードを変更しました。');
    }
}

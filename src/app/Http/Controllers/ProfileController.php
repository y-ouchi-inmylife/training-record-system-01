<?php

namespace App\Http\Controllers;

use App\Rules\StrongPassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
        // 文言は lang/ja/validation.php に集約（設計書 §2-8。
        // confirmed の新案「:attribute（確認）が一致しません。」で、
        // 画面ラベルと一致する「新しいパスワード（確認）が一致しません。」が出る。
        // current_password の照合は本メソッド末尾の Hash::check で自前で行う（本コミットでは
        // 変更しない。Laravel 標準の current_password ルールへの置き換えは段階 3 で検討）。
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => ['required', 'string', 'confirmed', new StrongPassword()],
        ]);

        // 現在のパスワードを照合
        if (!Hash::check($validated['current_password'], Auth::user()->password)) {
            return redirect()->route('profile.password.edit')
                ->withErrors(['current_password' => '現在のパスワードが正しくありません。'])
                ->withInput();
        }

        Auth::user()->update([
            'password' => $validated['new_password'],
        ]);

        return redirect()->route('profile.edit')
            ->with('success', 'パスワードを変更しました。');
    }
}

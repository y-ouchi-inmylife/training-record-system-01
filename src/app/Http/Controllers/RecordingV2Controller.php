<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;

class RecordingV2Controller extends Controller
{
    /**
     * 録音開始画面
     */
    public function index()
    {
        // クライアント選択はSelect2のAJAX（/api/clients/search）経由で動的に検索する
        return view('recording-v2.index');
    }

    /**
     * 録音実行画面へ遷移（POST → セッション保存 → /session へリダイレクト）
     *
     * PRG パターンを採用し、Ctrl+R 再読み込みで 405 エラーになる問題を回避する。
     */
    public function start(Request $request)
    {
        // クライアントIDのバリデーション（必須）
        // 業務方針: 音声記録は必ずクライアントに紐付ける（飛び込みケース未想定）
        // 文言は lang/ja/validation.php に集約（§2-8。段階 5-2）：
        //   client_id.required → custom.client_id.required「会員を選択してください。」
        //   client_id.exists   → 標準の exists ＋ attributes.client_id「選択された会員が存在しません。」
        $request->validate([
            'client_id' => 'required|exists:clients,id',
        ]);

        // セッションに client_id を保存して /session にリダイレクト（PRG パターン）
        $request->session()->put('recording_v2.client_id', $request->client_id);

        return redirect()->route('recording-v2.session');
    }

    /**
     * 録音実行画面の表示（GET）
     *
     * セッションに保存された client_id を元に表示する。
     * Ctrl+R 等での再表示にも対応する GET エンドポイント。
     */
    public function session(Request $request)
    {
        $clientId = $request->session()->get('recording_v2.client_id');

        if (!$clientId) {
            return redirect()->route('recording-v2.index')
                ->with('error', '会員を選択してください');
        }

        $client = Client::find($clientId);
        if (!$client) {
            return redirect()->route('recording-v2.index')
                ->with('error', '指定された会員が見つかりません');
        }

        return view('recording-v2.session', compact('client'));
    }
}

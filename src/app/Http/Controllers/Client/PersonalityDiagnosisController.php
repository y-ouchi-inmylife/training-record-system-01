<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * 性格診断の回答画面（S-1415、GET /client-portal/personality-diagnosis/{token}。2026-10 追加）。
 *
 * **仮の受け口**（性格診断 段階2）：性格診断の案内（S-0312）の QR コード・URL に、お客様の回答の画面の URL が要るため、
 * ルート（名前・URL は設計書の本番のもの）だけを先に定義し、今はどのトークンでも「ご利用いただけません」の画面を返す。
 * 回答の画面・送信・回答完了（S-1416）は段階3で作り、このコントローラの中身を差し替える
 * （受付アンケートを作ったときの段階分けと同じやり方）。
 */
class PersonalityDiagnosisController extends Controller
{
    /**
     * 仮：トークンを確かめず、使えない URL の画面を返す（段階3で、トークンの検証と回答の画面に差し替える）
     */
    public function show(string $token): View
    {
        return view('client.personality-diagnosis.unavailable', [
            'message' => 'この性格診断はご利用いただけません。お手数ですがトレーナーにお問い合わせください。',
        ]);
    }
}

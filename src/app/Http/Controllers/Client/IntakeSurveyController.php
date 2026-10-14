<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * 受付アンケートの回答（S-1413・S-1414、要件定義書 6-15-17）。お客様が受付アンケート案内の URL
 * （受付アンケート用 URL）から、ログインせずに開く。
 *
 * 2026-10（段階3b）：S-0311 受付アンケート案内画面に載せる URL を route() で正しく作るため
 * （会員側のホストが付くため）、ルートだけを先に定義し、中身は仮の画面にしている。トークンの照合・
 * 回答の画面・保存は段階4で作る。段階3と段階4は両方そろってから本番に出す（それまでは本番に反映しない）。
 */
class IntakeSurveyController extends Controller
{
    /**
     * 受付アンケート回答画面（GET /client-portal/intake-survey/{token}）。
     * 段階3b では仮の画面を返すだけで、トークンは照合しない
     */
    public function show(string $token): View
    {
        return view('client.intake-survey.coming-soon');
    }
}

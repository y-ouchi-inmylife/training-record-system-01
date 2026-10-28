<?php

namespace App\Http\Controllers;

use App\Models\ClientContract;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 契約一覧（S-0315。2026-10-28 追加。要件定義書 6-17-8）。
 *
 * 会員をまたいで契約を 1 件ずつ出す、見るだけの画面（支払いの状況などとの突き合わせ用）。
 * 契約・利用の登録・編集・削除は今までどおり会員詳細（S-0305）で行うので、ここは index だけ
 * （登録・編集・削除は ClientContractController）。参照なのでアクセスログは記録しない。
 * 検索・並べ替え・ページ送りの作りは会員一覧（ClientController::index）に合わせる。
 */
class ContractController extends Controller
{
    /**
     * 契約一覧画面
     */
    public function index(Request $request): View
    {
        // 契約日の範囲の相関チェック（開始日 ≦ 終了日）。文言は会員一覧と同じ lang/ja/validation.php の custom.date_to.after_or_equal
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        // 検索・並べ替えに会員の列を使うため、会員を結び付ける（選ぶ列は契約だけ）。
        // 表示用の会員は with でまとめて読み込み、利用の件数（その契約に割り当てた利用。未割り当ては数えない）は withCount で数える（N+1 を避ける）
        $query = ClientContract::query()
            ->select('client_contracts.*')
            ->join('clients', 'clients.id', '=', 'client_contracts.client_id')
            ->with('client')
            ->withCount('usages');

        // 内部ID検索（部分一致。会員一覧と同じ）
        if ($request->filled('internal_id')) {
            $query->where('clients.internal_id', 'like', '%'.$request->input('internal_id').'%');
        }

        // 名前検索（姓名・かなの部分一致。会員一覧と同じ）
        if ($request->filled('keyword')) {
            $keyword = $request->input('keyword');
            $query->where(function ($q) use ($keyword) {
                $q->where('clients.last_name', 'like', "%{$keyword}%")
                    ->orWhere('clients.first_name', 'like', "%{$keyword}%")
                    ->orWhere('clients.last_name_kana', 'like', "%{$keyword}%")
                    ->orWhere('clients.first_name_kana', 'like', "%{$keyword}%");
            });
        }

        // 契約日の範囲（日付どうしで比べる。操作履歴の一覧と同じ whereDate）
        if ($request->filled('date_from')) {
            $query->whereDate('client_contracts.contracted_on', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('client_contracts.contracted_on', '<=', $request->input('date_to'));
        }

        // 並べ替え。既定は契約日の新しい順（設計書 S-0315「並べ替え」）。
        // sort が未指定・不正な値のときは、direction の指定にかかわらず昇降も含めて既定に戻す（会員一覧と同じ）
        $sortBy = $request->input('sort');
        $allowedSorts = ['contracted_on', 'internal_id', 'last_name'];
        if (in_array($sortBy, $allowedSorts, true)) {
            $sortDir = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        } else {
            $sortBy = 'contracted_on';
            $sortDir = 'desc';
        }
        if ($sortBy === 'internal_id') {
            // internal_id は文字列型だが数値のみを格納するため、数値として比較する（会員一覧と同じ）
            $query->orderByRaw('CAST(clients.internal_id AS UNSIGNED) '.$sortDir);
        } elseif ($sortBy === 'last_name') {
            // 日本語照合順序で姓順に並べる（会員一覧と同じ）
            $query->orderByRaw('clients.last_name COLLATE utf8mb4_ja_0900_as_cs '.$sortDir);
        } else {
            $query->orderBy('client_contracts.contracted_on', $sortDir);
        }
        // 第2キー：同じ並びの中は契約の id で固定し、ページをまたいだ重複・欠落を防ぐ
        $query->orderBy('client_contracts.id', $sortDir);

        $contracts = $query->paginate(20)->withQueryString();

        // 確定した並び順（実効値）をビューに渡す。検索フォームの hidden と列見出しの ▲▼ に使う
        return view('contracts.index', compact('contracts', 'sortBy', 'sortDir'));
    }
}

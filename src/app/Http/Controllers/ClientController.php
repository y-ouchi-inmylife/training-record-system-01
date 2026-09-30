<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Models\Trainer;
use App\Models\TrainingRecord;
use App\Services\ClientInternalIdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ClientController extends Controller
{
    /**
     * クライアント一覧・検索画面（S-0304）
     */
    public function index(Request $request): View
    {
        // 日付フィルタの相関チェック（開始日 ≦ 終了日）
        $request->validate(
            [
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
            ],
            [
                'date_to.after_or_equal' => '開始日は終了日以前の日付を指定してください',
            ]
        );

        // trainees は一覧の「トレーニー」列の表示（Client::trainees_label アクセサ）で使う。
        // paginate(20) の 20 行分をまとめて 1 クエリで取ることで N+1 を回避（設計書
        // S-0304 設計方針「N+1 対策」参照）。
        $query = Client::with(['primaryTrainer', 'trainees'])
            ->withStatusData()
            ->addSelect([
                'last_training_date' => TrainingRecord::select('training_date')
                    ->whereColumn('client_id', 'clients.id')
                    ->orderBy('training_date', 'desc')
                    ->orderBy('training_time', 'desc')
                    ->limit(1),
            ]);

        // 内部ID検索（部分一致）
        if ($request->filled('internal_id')) {
            $query->where('internal_id', 'like', '%'.$request->input('internal_id').'%');
        }

        // 名前検索（姓名・かなの部分一致）
        if ($request->filled('keyword')) {
            $keyword = $request->input('keyword');
            $query->where(function ($q) use ($keyword) {
                $q->where('last_name', 'like', "%{$keyword}%")
                    ->orWhere('first_name', 'like', "%{$keyword}%")
                    ->orWhere('last_name_kana', 'like', "%{$keyword}%")
                    ->orWhere('first_name_kana', 'like', "%{$keyword}%");
            });
        }

        // 主担当トレーナーフィルター
        if ($request->filled('primary_trainer_id')) {
            $query->where('primary_trainer_id', $request->input('primary_trainer_id'));
        }

        // 最終記録日の期間指定（最新のトレーニング記録日付で絞り込み）
        if ($request->filled('date_from')) {
            $query->whereRaw(
                '(SELECT MAX(cr.training_date) FROM training_records cr WHERE cr.client_id = clients.id) >= ?',
                [$request->input('date_from')]
            );
        }
        if ($request->filled('date_to')) {
            $query->whereRaw(
                '(SELECT MAX(cr.training_date) FROM training_records cr WHERE cr.client_id = clients.id) <= ?',
                [$request->input('date_to')]
            );
        }

        // ソート
        // 既定は初回日の新しい順（設計書 S-0304 設計方針「並び順」参照）
        $sortBy = $request->input('sort', 'initial_consultation_date');
        $sortDir = $request->input('direction', 'desc');
        // かな列は 2026-09 に UI から撤去したため、ソートキーからも除外する。
        // created_at も 2026-09 に既定を初回日へ変えた際に除外した。
        // 古いブックマーク（?sort=last_name_kana / ?sort=created_at）は allowedSorts 判定で
        // 既定値（initial_consultation_date）にフォールバックし、エラーにはならない。
        $allowedSorts = ['internal_id', 'last_name', 'initial_consultation_date'];
        if (! in_array($sortBy, $allowedSorts)) {
            $sortBy = 'initial_consultation_date';
        }
        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';
        // internal_id は文字列型だが数値のみを格納するため、数値として比較する
        if ($sortBy === 'internal_id') {
            $query->orderByRaw('CAST(internal_id AS UNSIGNED) '.$sortDir);
        } elseif ($sortBy === 'last_name') {
            // 日本語照合順序で姓順に並べる
            $query->orderByRaw('last_name COLLATE utf8mb4_ja_0900_as_cs '.$sortDir);
        } else {
            $query->orderBy($sortBy, $sortDir);
        }
        // 第2キー：同値時の並びを固定し、ページをまたいだ重複・欠落を防ぐ（設計書 S-0304 設計方針「並び順」参照）
        $query->orderBy('clients.id', $sortDir);

        $clients = $query->paginate(20)->withQueryString();
        $trainers = Trainer::practitioners()->orderBy('display_order')->orderBy('name')->get();

        // 確定した並び順（実効値）をビューに渡す。検索フォームの hidden で並び順を保持するために使う
        return view('clients.index', compact('clients', 'trainers', 'sortBy', 'sortDir'));
    }

    /**
     * 会員登録画面（S-0301 ステップ形式ウィザード）
     */
    public function create(): View
    {
        $trainers = Trainer::practitioners()->orderBy('display_order')->orderBy('name')->get();

        return view('clients.create', compact('trainers'));
    }

    /**
     * クライアント登録処理
     */
    public function store(ClientRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $validated['updated_by'] = auth()->id();

        // トランザクション内で内部IDを採番（競合回避）
        $client = DB::transaction(function () use ($validated) {
            $validated['internal_id'] = (string) (new ClientInternalIdService)->generateNext();

            return Client::create($validated);
        });

        return redirect()
            ->route('clients.show', $client)
            ->with('success', '会員を登録しました。');
    }

    /**
     * クライアント詳細画面（S-0305）
     */
    public function show(Client $client): View
    {
        // trainees は id 昇順（Client::trainees() の既定）で並び、
        // 各 trainee の measurements を eager load することで、
        // トレーニーカードの「最終計測」表示で N+1 を起こさない。
        // measurements は Trainee::measurements() で計測日時降順に並ぶため、
        // Trainee::latest_measurement アクセサが `first()` で最新 1 件を取り出せる
        // （設計書 S-0305 セクション3「最終計測」参照）。
        $client->load(['primaryTrainer', 'trainingRecords' => function ($query) {
            $query->with(['trainer1', 'trainer2'])
                ->withCount('mediaRecords')
                ->orderBy('training_date', 'desc')
                ->orderBy('training_time', 'desc');
        }, 'trainees.measurements']);

        // 状態バッジ（4 状態＋期限切れ）判定に必要な派生値を先読みする。
        // 状態別ボタン（「登録案内を発行」／「登録案内を表示」／「登録案内を取消」／
        // 「登録を削除」）の出し分けもこの派生値と `status` アクセサから判断する。
        // 「登録を削除」（旧「メールアドレスを削除」）は初回設定待ち・利用中の両方で出る
        // （S-0305 の設計書参照）。
        $client->loadStatusData();

        $trainers = Trainer::practitioners()->orderBy('display_order')->orderBy('name')->get();

        return view('clients.show', compact('client', 'trainers'));
    }

    /**
     * 会員編集画面
     */
    public function edit(Client $client): View
    {
        $trainers = Trainer::practitioners()->orderBy('display_order')->orderBy('name')->get();

        return view('clients.edit', compact('client', 'trainers'));
    }

    /**
     * クライアント更新処理
     */
    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        // ClientRequest が基本項目を先に自動検証。
        // internal_id は update 固有の追加ルールなのでコントローラ側で個別に検証する。
        $request->validate(
            ['internal_id' => 'required|numeric|unique:clients,internal_id,'.$client->id],
            $this->internalIdMessages()
        );

        $validated = $request->validated();
        $validated['internal_id'] = $request->input('internal_id');
        $validated['updated_by'] = auth()->id();
        $client->update($validated);

        return redirect()
            ->route('clients.show', $client)
            ->with('success', '会員情報を更新しました。');
    }

    /**
     * クライアント削除処理（管理トレーナーのみ）
     */
    public function destroy(Client $client): RedirectResponse
    {
        // 管理トレーナーのみ削除可能
        if (! auth()->user()->isAdmin()) {
            abort(403, '管理者のみ削除できます。');
        }

        // トレーニング記録が存在する場合は削除不可
        if ($client->trainingRecords()->exists()) {
            return redirect()
                ->route('clients.show', $client)
                ->with('error', 'この会員にはトレーニング記録が登録されているため削除できません。');
        }

        // トレーニーが登録されている場合も削除不可（設計書 6-3-5、段階① で追加）
        if ($client->trainees()->exists()) {
            return redirect()
                ->route('clients.show', $client)
                ->with('error', 'この会員にはトレーニーが登録されているため削除できません。');
        }

        $client->delete();

        return redirect()
            ->route('clients.index')
            ->with('success', '会員を削除しました。');
    }

    /**
     * バリデーションルール
     */
    /**
     * クライアント検索API（Select2用）
     */
    public function apiSearch(Request $request): JsonResponse
    {
        // ID指定の場合（バリデーションエラー後の復元用）
        if ($request->filled('id')) {
            $client = Client::find($request->input('id'));
            if ($client) {
                return response()->json([
                    'results' => [['id' => $client->id, 'text' => $client->internal_id.' '.$client->display_name]],
                ]);
            }

            return response()->json(['results' => []]);
        }

        $query = $request->input('q', '');

        $clients = Client::where(function ($q) use ($query) {
            $q->where('internal_id', 'like', "%{$query}%")
                ->orWhere('last_name', 'like', "%{$query}%")
                ->orWhere('first_name', 'like', "%{$query}%")
                ->orWhere('last_name_kana', 'like', "%{$query}%")
                ->orWhere('first_name_kana', 'like', "%{$query}%");
        })
            ->orderBy('internal_id')
            ->limit(20)
            ->get();

        return response()->json([
            'results' => $clients->map(function ($client) {
                return [
                    'id' => $client->id,
                    'text' => $client->internal_id.' '.$client->display_name,
                ];
            }),
        ]);
    }

    /**
     * 内部IDのバリデーションメッセージ
     */
    private function internalIdMessages(): array
    {
        return [
            'internal_id.required' => '内部IDを入力してください。',
            'internal_id.numeric' => '内部IDは数値で入力してください。',
            'internal_id.unique' => 'この内部IDは既に使用されています。',
        ];
    }
}

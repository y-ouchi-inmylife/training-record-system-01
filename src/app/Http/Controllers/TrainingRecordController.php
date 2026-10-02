<?php

namespace App\Http\Controllers;

use App\Http\Controllers\MediaRecordController;
use App\Models\AudioRecord;
use App\Models\Client;
use App\Models\MediaRecord;
use App\Models\Trainer;
use App\Models\TrainingRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TrainingRecordController extends Controller
{
    /**
     * トレーニング記録検索・一覧画面（S-0402）
     */
    public function index(Request $request): View
    {
        // 日付フィルタの相関チェック（開始日 ≦ 終了日）
        // 文言は lang/ja/validation.php の custom.date_to.after_or_equal に集約（設計書 §2-8）
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
        ]);

        // client.trainees は S-0402 のトレーニー列の表示（Client::trainees_label アクセサ）で使う。
        // paginate(20) の 20 行分をまとめて 1 クエリで取ることで N+1 を回避（設計書
        // S-0402 設計方針「N+1 対策」参照）。
        $query = TrainingRecord::with(['client.trainees', 'trainer1', 'trainer2'])
            ->withCount('mediaRecords');

        // 内部ID（部分一致）
        if ($request->filled('internal_id')) {
            $internalId = $request->input('internal_id');
            $query->whereHas('client', function ($q) use ($internalId) {
                $q->where('internal_id', 'like', "%{$internalId}%");
            });
        }

        // 氏名検索（姓名・かな 4カラムを横断的に部分一致）
        if ($request->filled('name')) {
            $name = $request->input('name');
            $query->whereHas('client', function ($q) use ($name) {
                $q->where(function ($sub) use ($name) {
                    $sub->where('last_name', 'like', "%{$name}%")
                        ->orWhere('first_name', 'like', "%{$name}%")
                        ->orWhere('last_name_kana', 'like', "%{$name}%")
                        ->orWhere('first_name_kana', 'like', "%{$name}%");
                });
            });
        }

        // 日付（期間指定）
        if ($request->filled('date_from')) {
            $query->where('training_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('training_date', '<=', $request->input('date_to'));
        }

        // 担当者フィルター（担当者1または担当者2に含まれていれば表示）
        if ($request->filled('trainer_id')) {
            $trainerId = $request->input('trainer_id');
            $query->where(function ($q) use ($trainerId) {
                $q->where('trainer1_id', $trainerId)
                  ->orWhere('trainer2_id', $trainerId);
            });
        }

        // キーワード検索（トレーニング記録・所感の全文検索）
        if ($request->filled('keyword')) {
            $keyword = $request->input('keyword');
            $query->where(function ($q) use ($keyword) {
                $q->where('record_content', 'like', "%{$keyword}%")
                  ->orWhere('impression', 'like', "%{$keyword}%");
            });
        }

        // ソート
        $sortBy = $request->input('sort', 'training_date');
        $sortDir = $request->input('direction', 'desc');
        $allowedSorts = ['training_date', 'internal_id', 'client_name', 'created_at'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'training_date';
        }
        $sortDir = $sortDir === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'internal_id') {
            // クライアントの内部IDで数値ソート（文字列型だが数値のみ格納）
            $query->leftJoin('clients', 'training_records.client_id', '=', 'clients.id')
                ->select('training_records.*')
                ->orderByRaw('CAST(clients.internal_id AS UNSIGNED) ' . $sortDir);
        } elseif ($sortBy === 'client_name') {
            // 日本語照合順序で姓順に並べる
            $query->leftJoin('clients', 'training_records.client_id', '=', 'clients.id')
                ->select('training_records.*')
                ->orderByRaw("clients.last_name COLLATE utf8mb4_ja_0900_as_cs " . $sortDir);
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        // 2次ソート: 日付が同じレコードは時刻で並び替える（NULLはMySQLのDESCで最後、ASCで最初）
        if ($sortBy !== 'training_date') {
            $query->orderBy('training_date', 'desc');
        }
        $query->orderBy('training_time', $sortDir);

        $records = $query->paginate(20)->withQueryString();
        $trainers = Trainer::practitioners()->orderBy('display_order')->orderBy('name')->get();

        return view('training-records.index', compact('records', 'trainers'));
    }

    /**
     * トレーニング記録登録画面（S-0401 登録モード）
     *
     * 業務方針: トレーニング記録は必ずクライアント詳細から登録する設計（自由選択モード廃止）。
     * ?client_id= 未指定・不存在クライアントの場合はクライアント一覧へリダイレクト。
     */
    public function create(Request $request): View|RedirectResponse
    {
        // クライアント詳細画面や録音画面からの遷移時はパラメータを受け取る
        $selectedClientId = $request->input('client_id');

        if (!$selectedClientId) {
            return redirect()->route('clients.index')
                ->with('error', 'トレーニング記録を登録する会員を選択してください');
        }

        $selectedClient = Client::find($selectedClientId);
        if (!$selectedClient) {
            return redirect()->route('clients.index')
                ->with('error', '指定された会員が見つかりません');
        }

        $trainers = Trainer::practitioners()->orderBy('display_order')->orderBy('name')->get();

        $audioRecordId = $request->input('audio_record_id');

        // メディアセクションの初期データ：
        // サーバー検証エラーで戻ったとき（セッションに古い入力があるとき）は、
        // 送られてきた media_record_ids の順にメディアを並べ直して渡す。初めて
        // 開いたとき（古い入力なし）は空。判定を old('media_record_ids') の有無で
        // はなく hasOldInput() で行う理由は、メディアを全部外して送った場合に
        // media_record_ids が送信されず old() が null になるため（全部外した状態
        // として空で戻す必要がある。設計書 S-0401「エラーで戻ったときの入力値の
        // 保持」参照）。
        $mediaInitial = $request->session()->hasOldInput()
            ? $this->buildMediaInitial((array) old('media_record_ids', []))
            : [];

        return view('training-records.create', compact(
            'trainers', 'selectedClientId', 'selectedClient', 'audioRecordId', 'mediaInitial'
        ));
    }

    /**
     * トレーニング記録登録処理
     */
    public function store(Request $request): RedirectResponse
    {
        // 既存ルールに media_record_ids（多対多紐づけ）を追加。配列順を sort_order として保存する
        $validated = $request->validate(array_merge($this->validationRules(), [
            'media_record_ids'   => 'nullable|array',
            'media_record_ids.*' => 'integer|distinct|exists:media_records,id',
        ]));

        $mediaIds = $validated['media_record_ids'] ?? [];
        unset($validated['media_record_ids']);

        $validated['updated_by'] = auth()->id();

        try {
            $created = DB::transaction(function () use ($validated, $mediaIds) {
                $record = TrainingRecord::create($validated);

                // 登録と同時にメディア紐づけを作成。配列順を sort_order（0始まり連番）として保存
                if (!empty($mediaIds)) {
                    $pivotData = collect($mediaIds)
                        ->mapWithKeys(fn ($id, $idx) => [(int) $id => ['sort_order' => $idx]])
                        ->all();
                    $record->mediaRecords()->sync($pivotData);
                }

                return $record;
            });
        } catch (\Exception $e) {
            \Log::error('トレーニング記録登録エラー', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return back()
                ->withInput()
                ->with('error', 'トレーニング記録の登録に失敗しました。');
        }

        return redirect()
            ->route('training-records.show', $created)
            ->with('success', 'トレーニング記録を登録しました。');
    }

    /**
     * トレーニング記録詳細画面
     */
    public function show(TrainingRecord $trainingRecord): View
    {
        // mediaRecords は belongsToMany 側で orderByPivot('sort_order') 済みのため、
        // sort_order 昇順で取得される（詳細画面メディアセクションの閲覧用）
        $trainingRecord->load([
            'client', 'trainer1', 'trainer2',
            'mediaRecords',
        ]);

        // 詳細画面メディアセクション用の表示データ（presigned サムネイル URL を含む）。
        $thumbnailExpiresAt = now()->addMinutes(MediaRecordController::PLAY_URL_EXPIRES_MINUTES);
        // displayTitle / hasTitle は渡さない。S-0403 詳細画面のメディアカードは
        // 種別ラベル（写真／動画）を alt / data-display-title / aria-label に載せる
        // （設計書 S-0403 セクション2 メディア参照。メディアの表示名・ファイル名は
        // 記録側の画面に露出させない方針）。
        // typeLabel はコントローラで組み立てて渡す（Blade 側で `@php(...)` の
        // インライン形式を使うと直前の `@php ... @endphp` ブロックとパースが
        // 衝突する事故があったため、Blade 側の @php 使用を避けている）。
        // photo / video の 2 分岐は DB の CHECK 制約と MediaRecord::TYPE_* 定数に
        // 対応（他の値は入り得ない）。
        $mediaItems = $trainingRecord->mediaRecords->map(function ($m) use ($thumbnailExpiresAt) {
            return [
                'id'               => $m->id,
                'type'             => $m->type,
                'typeLabel'        => $m->type === 'photo' ? '写真' : '動画',
                'thumbnailUrl'     => $m->temporaryThumbnailUrl($thumbnailExpiresAt),
                'conversionStatus' => $m->conversion_status,
            ];
        })->values()->all();

        return view('training-records.show', compact('trainingRecord', 'mediaItems'));
    }

    /**
     * トレーニング記録編集画面（S-0404 編集モード）
     */
    public function edit(Request $request, TrainingRecord $trainingRecord): View
    {
        // mediaRecords は belongsToMany 側で orderByPivot('sort_order') 済みのため、
        // sort_order 昇順で取得される（編集画面メディアセクションの初期表示用）
        $trainingRecord->load(['client', 'mediaRecords']);

        // メディアセクションの初期データ：
        // サーバー検証エラーで戻ったとき（セッションに古い入力があるとき）は、
        // 送られてきた media_record_ids の順にメディアを並べ直して渡す（利用者が
        // 編集画面で追加・削除した状態を保つため）。初めて開いたとき（古い入力なし）
        // は既存の紐づけから作る。判定を hasOldInput() で行う理由と、$mediaInitial
        // の 1 件ずつの形（キー）は buildMediaInitial() のコメントを参照。
        $mediaInitial = $request->session()->hasOldInput()
            ? $this->buildMediaInitial((array) old('media_record_ids', []))
            : $this->buildMediaInitial($trainingRecord->mediaRecords->pluck('id')->all());

        $trainers = Trainer::practitioners()->orderBy('display_order')->orderBy('name')->get();

        return view('training-records.edit', compact(
            'trainingRecord', 'trainers', 'mediaInitial'
        ));
    }

    /**
     * トレーニング記録更新処理
     */
    public function update(Request $request, TrainingRecord $trainingRecord): RedirectResponse
    {
        // 既存ルールに media_record_ids（多対多紐づけ）を追加。store には足さない（編集時のみ受け付け）
        $validated = $request->validate(array_merge($this->validationRules(), [
            'media_record_ids'   => 'nullable|array',
            'media_record_ids.*' => 'integer|distinct|exists:media_records,id',
        ]));

        $mediaIds = $validated['media_record_ids'] ?? [];
        unset($validated['media_record_ids']);

        $validated['updated_by'] = auth()->id();

        try {
            DB::transaction(function () use ($validated, $trainingRecord, $mediaIds) {
                $trainingRecord->update($validated);

                // 配列順を sort_order（0始まり連番）にマッピングして総入れ替え。
                // sync は detach/attach/update を1セットで行うため、複合UNIQUE と衝突しない。
                $pivotData = collect($mediaIds)
                    ->mapWithKeys(fn ($id, $idx) => [(int) $id => ['sort_order' => $idx]])
                    ->all();
                $trainingRecord->mediaRecords()->sync($pivotData);

                // メディアだけ変更（本体無変更）でも親 updated_at を更新する
                // （D-0600 注記：紐づけ変更は親 training_records の更新に寄せる方針）
                $trainingRecord->touch();
            });
        } catch (\Exception $e) {
            \Log::error('トレーニング記録更新エラー', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return back()
                ->withInput()
                ->with('error', 'トレーニング記録の更新に失敗しました。');
        }

        return redirect()
            ->route('training-records.show', $trainingRecord)
            ->with('success', 'トレーニング記録を更新しました。');
    }

    /**
     * トレーニング記録削除処理（管理トレーナーのみ）
     */
    public function destroy(TrainingRecord $trainingRecord): RedirectResponse
    {
        if (!auth()->user()->isAdmin()) {
            abort(403, '管理者のみ削除できます。');
        }

        $clientId = $trainingRecord->client_id;
        $trainingRecord->delete();

        return redirect()
            ->route('clients.show', $clientId)
            ->with('success', 'トレーニング記録を削除しました。');
    }

    /**
     * 録音画面からトレーニング記録を自動登録（API）
     */
    public function autoCreate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audio_record_id' => 'required|exists:audio_records,id',
            'client_id' => 'required|exists:clients,id',
            'training_date' => 'required|date',
            'training_time' => 'nullable|date_format:H:i',
            'trainer1_id' => 'required|exists:trainers,id',
            'trainer2_id' => 'nullable|exists:trainers,id|different:trainer1_id',
        ]);

        try {
            $record = DB::transaction(function () use ($validated) {
                $audioRecord = AudioRecord::findOrFail($validated['audio_record_id']);

                $record = TrainingRecord::create([
                    'client_id' => $validated['client_id'],
                    'training_date' => $validated['training_date'],
                    'training_time' => $validated['training_time'] ?? null,
                    'trainer1_id' => $validated['trainer1_id'],
                    'trainer2_id' => $validated['trainer2_id'] ?? null,
                    'record_content' => $audioRecord->summary_text,
                ]);

                return $record;
            });

            return response()->json([
                'success' => true,
                'training_record_id' => $record->id,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'トレーニング記録の作成に失敗しました: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 紐づけ候補メディア一覧（GET /api/training-records/available-media）
     *
     * S-0404-M01（編集）および S-0401-M02（登録）メディア追加モーダルが、メディア候補を
     * 取得するための内部API。training_record_id 指定時はその記録に「まだ紐づいていない」
     * メディアのみを候補とし、未指定時（登録画面）は全件を候補とする。
     * 登録者フィルタと24件ページネーションは MediaRecordController::index() と同方針。
     */
    public function availableMedia(Request $request): JsonResponse
    {
        $user = Auth::user();

        // 登録画面では training_record_id 未指定。指定時は実在チェック（無ければ 404）
        $trainingRecordId = $request->query('training_record_id');
        $trainingRecord = $trainingRecordId
            ? TrainingRecord::findOrFail($trainingRecordId)
            : null;

        $query = MediaRecord::with(['trainer'])
            ->orderBy('created_at', 'desc');

        // 紐づけ済み除外は trainingRecord 指定時のみ。未指定（登録画面）は全件が候補
        if ($trainingRecord) {
            $query->whereNotIn('id', function ($q) use ($trainingRecord) {
                $q->select('media_record_id')
                  ->from('media_record_training_record')
                  ->where('training_record_id', $trainingRecord->id);
            });
        }

        // 登録者フィルタ（既存メディア一覧と同型: 'all'=全件、id指定、未指定=自分）
        $trainerId = $request->query('trainer_id');
        if ($trainerId === 'all') {
            // フィルタなし
        } elseif ($trainerId) {
            $query->where('trainer_id', $trainerId);
        } else {
            $query->where('trainer_id', $user->id);
        }

        $paginator = $query->paginate(MediaRecordController::INDEX_PER_PAGE);

        // 各要素のメタ情報（mediaModalData と同形・5c フロントが lookup 不要で直接使える形）
        $thumbnailExpiresAt = now()->addMinutes(MediaRecordController::PLAY_URL_EXPIRES_MINUTES);
        $items = $paginator->getCollection()->map(function ($m) use ($thumbnailExpiresAt) {
            return [
                'id' => $m->id,
                'type' => $m->type,
                'mime_type' => $m->mime_type,
                'conversion_status' => $m->conversion_status,
                'thumbnail_status' => $m->thumbnail_status,
                'thumbnail_url' => $m->temporaryThumbnailUrl($thumbnailExpiresAt),
                'display_title' => $m->display_title,
                'original_filename' => $m->original_filename,
                'created_at' => $m->created_at->format('Y/m/d H:i'),
                'trainer_name' => $m->trainer?->name,
            ];
        })->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * メディアセクションの初期データ（mediaSelection.items と同形）を作る。
     *
     * $ids に入っていない ID は除き、$ids の順に並べる（利用者がドラッグで並べた
     * 順を保つ）。送られた ID は書き換えられる可能性があるため、`whereIn` で DB に
     * 実在するメディアだけに絞る（存在しない ID は黙って除く）。候補の絞り込み
     * 条件は、本クラスの `availableMedia()` と同じく「登録者フィルタのみ・会員で
     * の絞り込みは行わない」方針に揃える（候補として出せる最大の集合＝全件）。
     *
     * 返す要素のキーは `mediaSelection.items` に流し込める形：
     *   `{id, type, thumbnailUrl, conversionStatus}`
     * （Blade の `@json($mediaInitial ?? [])` に渡し、JS が camelCase を期待する
     * ため、availableMedia の snake_case 系とは別の形）。
     *
     * create()・edit() の両方から呼び、エラーで戻ったとき（session->hasOldInput）
     * と初回表示を同じヘルパで組み立てる。
     */
    private function buildMediaInitial(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $thumbnailExpiresAt = now()->addMinutes(MediaRecordController::PLAY_URL_EXPIRES_MINUTES);
        $media = MediaRecord::whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)
            ->map(fn ($id) => $media[(int) $id] ?? null)
            ->filter()
            ->map(function (MediaRecord $m) use ($thumbnailExpiresAt) {
                return [
                    'id' => $m->id,
                    'type' => $m->type,
                    'thumbnailUrl' => $m->temporaryThumbnailUrl($thumbnailExpiresAt),
                    'conversionStatus' => $m->conversion_status,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * バリデーションルール
     */
    private function validationRules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'training_date' => 'required|date',
            'training_time' => 'nullable|date_format:H:i',
            'trainer1_id' => 'required|exists:trainers,id',
            'trainer2_id' => 'nullable|exists:trainers,id|different:trainer1_id',
            'record_content' => 'nullable|string',
            'impression' => 'nullable|string',
        ];
    }
}

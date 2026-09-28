<?php

namespace App\Http\Controllers;

use App\Jobs\SummarizeJob;
use App\Jobs\TranscribeAudioJob;
use App\Models\AudioRecord;
use App\Models\Trainer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 文字起こし・要約管理コントローラー
 */
class AudioRecordController extends Controller
{
    /**
     * 音声管理一覧画面
     *
     * トレーナーフィルタ（GETパラメータ trainer_id）で表示を切り替え
     * デフォルト: ログイン中のトレーナー自身の記録のみ表示
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = AudioRecord::with(['trainer', 'client'])->orderBy('created_at', 'desc');

        // トレーナーフィルタ
        $trainerId = $request->query('trainer_id');
        if ($trainerId === 'all') {
            // フィルタなし（全員表示）
        } elseif ($trainerId) {
            $query->where('trainer_id', $trainerId);
        } else {
            // デフォルト: 自分の記録のみ
            $query->where('trainer_id', $user->id);
        }

        $audioRecords = $query->paginate(5)->appends($request->query());

        // プルダウン用トレーナー一覧（system_adminを除外）
        $trainers = Trainer::practitioners()
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        $selectedTrainerId = $trainerId ?? $user->id;

        return view('audio.index', compact('audioRecords', 'trainers', 'selectedTrainerId'));
    }

    /**
     * 音声ファイルの手動アップロード処理（S-0504 専用フォームから）
     *
     * 録音画面（S-0502）からの自動アップロードは別エンドポイント
     * POST /audio-records/recording（recordingStore、次ステップで実装）に分離する。
     */
    public function uploadStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'file' => [
                'required',
                'file',
                'max:' . (AudioRecord::MAX_FILE_SIZE / 1024), // KB単位
            ],
        ], [
            'client_id.required' => '会員を選択してください。',
            'client_id.exists' => '選択された会員が存在しません。',
            'file.required' => '音声ファイルを選択してください。',
            'file.file' => '有効なファイルをアップロードしてください。',
            'file.max' => '100MBを超えるファイルは登録できません。',
            'file.uploaded' => 'ファイルのアップロードに失敗しました。ファイルサイズが大きすぎる可能性があります。',
        ]);

        $uploadedFile = $request->file('file');

        // 拡張子チェック
        $extension = strtolower($uploadedFile->getClientOriginalExtension());
        if (!in_array($extension, AudioRecord::ALLOWED_EXTENSIONS)) {
            $allowedList = implode(', ', AudioRecord::ALLOWED_EXTENSIONS);
            return redirect()->back()
                ->withErrors(['file' => "対応形式: {$allowedList}"])
                ->withInput();
        }

        $user = Auth::user();
        $originalName = $uploadedFile->getClientOriginalName();

        // 保存先: storage/app/audio/{trainer_id}/
        $directory = 'audio/' . $user->id;
        $storedPath = $uploadedFile->store($directory);

        AudioRecord::create([
            'trainer_id' => $user->id,
            'client_id' => $validated['client_id'],
            // タイトル: アップロードファイル名から拡張子を除いた値
            'title' => pathinfo($originalName, PATHINFO_FILENAME),
            'source' => AudioRecord::SOURCE_UPLOAD,
            'file_name' => $originalName,
            'file_path' => $storedPath,
            'status' => AudioRecord::STATUS_UNPROCESSED,
            'file_size' => $uploadedFile->getSize(),
        ]);

        return redirect()->route('audio-records.index')
            ->with('success', '音声ファイルをアップロードしました。');
    }

    /**
     * 録音実行画面（S-0502）からの音声アップロード処理（Ajax 専用）
     *
     * 手動アップロード（S-0504）は別エンドポイント
     * POST /audio-records/upload（uploadStore）。
     */
    public function recordingStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'file' => [
                'required',
                'file',
                'max:' . (AudioRecord::MAX_FILE_SIZE / 1024), // KB単位
            ],
        ], [
            'client_id.required' => '会員を選択してください。',
            'client_id.exists' => '選択された会員が存在しません。',
            'file.required' => '音声ファイルを選択してください。',
            'file.file' => '有効なファイルをアップロードしてください。',
            'file.max' => '100MBを超えるファイルは登録できません。',
            'file.uploaded' => 'ファイルのアップロードに失敗しました。ファイルサイズが大きすぎる可能性があります。',
        ]);

        $uploadedFile = $request->file('file');

        // 拡張子チェック（録音が生成する形式が許可内であることの保証）
        $extension = strtolower($uploadedFile->getClientOriginalExtension());
        if (!in_array($extension, AudioRecord::ALLOWED_EXTENSIONS)) {
            $allowedList = implode(', ', AudioRecord::ALLOWED_EXTENSIONS);
            return response()->json([
                'error' => ['file' => ["対応形式: {$allowedList}"]],
            ], 422);
        }

        $user = Auth::user();
        $originalName = $uploadedFile->getClientOriginalName();

        // 保存先: storage/app/audio/{trainer_id}/
        $directory = 'audio/' . $user->id;
        $storedPath = $uploadedFile->store($directory);

        $audioRecord = AudioRecord::create([
            'trainer_id' => $user->id,
            'client_id' => $validated['client_id'],
            // タイトル: 「YYYYMMDD_HHMM_ログインID」
            'title' => now()->format('Ymd_Hi') . '_' . $user->login_id,
            'source' => AudioRecord::SOURCE_RECORDING,
            'file_name' => $originalName,
            'file_path' => $storedPath,
            'status' => AudioRecord::STATUS_UNPROCESSED,
            'file_size' => $uploadedFile->getSize(),
        ]);

        return response()->json(['data' => $audioRecord], 201);
    }

    /**
     * 音声ファイルのアップロード画面を表示（S-0504）
     */
    public function uploadCreate()
    {
        return view('audio.upload-create');
    }

    /**
     * テキストから要約作成画面を表示
     */
    public function textPasteCreate()
    {
        $defaultTitle = now()->format('Ymd_Hi');

        return view('audio.text-paste-create', compact('defaultTitle'));
    }

    /**
     * テキスト貼り付けレコードを保存
     */
    public function textPasteStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'title' => 'required|string|max:255',
            'transcription_text' => 'required|string',
        ], [
            'client_id.required' => '会員を選択してください。',
            'client_id.exists' => '選択された会員が存在しません。',
            'title.required' => '表示名を入力してください。',
            'title.max' => '表示名は255文字以内で入力してください。',
            'transcription_text.required' => '文字起こしテキストを入力してください。',
        ]);

        AudioRecord::create([
            'trainer_id' => Auth::id(),
            'client_id' => $validated['client_id'],
            'title' => $validated['title'],
            'source' => AudioRecord::SOURCE_TEXT_PASTE,
            'file_path' => null,
            'status' => AudioRecord::STATUS_TRANSCRIBED,
            'transcription_text' => $validated['transcription_text'],
        ]);

        return redirect()->route('audio-records.index')
            ->with('success', 'テキストを保存しました。');
    }

    /**
     * 音声ファイルの詳細取得（Ajax用）
     */
    public function show(AudioRecord $audioRecord)
    {

        return response()->json([
            'data' => [
                'title' => $audioRecord->title,
                'transcription_text' => $audioRecord->transcription_text,
                'summary_text' => $audioRecord->summary_text,
                'can_delete' => $audioRecord->canDelete(),
                'has_audio_file' => !empty($audioRecord->file_path),
                'delete_audio_url' => !empty($audioRecord->file_path) ? route('audio-records.delete-audio', $audioRecord) : null,
            ],
        ]);
    }

    /**
     * 音声ファイルの削除
     */
    public function destroy(AudioRecord $audioRecord): RedirectResponse
    {

        if (!$audioRecord->canDelete()) {
            return redirect()->route('audio-records.index')
                ->with('error', '処理中の音声ファイルは削除できません。');
        }

        // サーバー上のファイルを削除
        if ($audioRecord->file_path && Storage::exists($audioRecord->file_path)) {
            Storage::delete($audioRecord->file_path);
        }

        $audioRecord->delete();

        return redirect()->route('audio-records.index')
            ->with('success', '音声ファイルを削除しました。');
    }

    /**
     * 音声ファイルのみ削除（文字起こし・要約は残す）
     */
    public function deleteAudioOnly(AudioRecord $audioRecord): RedirectResponse
    {

        if (!$audioRecord->canDelete()) {
            return redirect()->route('audio-records.index')
                ->with('error', '処理中の音声ファイルは削除できません。');
        }

        // 音声ファイルを削除
        if ($audioRecord->file_path && Storage::exists($audioRecord->file_path)) {
            Storage::delete($audioRecord->file_path);
        }

        // file_path を NULL に更新（レコードは残す）
        $audioRecord->update(['file_path' => null]);

        return redirect()->route('audio-records.index')
            ->with('success', '音声ファイルを削除しました。文字起こし・要約は保持されています。');
    }

    /**
     * 音声記録の保存（表示名・文字起こし・要約をまとめて更新）
     */
    public function update(Request $request, AudioRecord $audioRecord): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'transcription_text' => 'nullable|string',
            'summary_text' => 'nullable|string',
        ], [
            'title.required' => '表示名を入力してください。',
            'title.max' => '表示名は255文字以内で入力してください。',
        ]);

        $audioRecord->update([
            'title' => $validated['title'],
            'transcription_text' => $validated['transcription_text'] ?? null,
            'summary_text' => $validated['summary_text'] ?? null,
        ]);

        return redirect()->route('audio-records.index')
            ->with('success', '音声記録を保存しました。');
    }

    /**
     * 文字起こし処理を実行する（同期実行）
     */
    public function transcribe(AudioRecord $audioRecord): JsonResponse
    {

        if (!$audioRecord->canTranscribe()) {
            return response()->json([
                'error' => ['message' => 'この音声ファイルは現在処理できません。ステータス: ' . $audioRecord->status_label],
            ], 409);
        }

        // APIキーの事前チェック
        if (empty(config('openai.api_key'))) {
            return response()->json([
                'error' => ['message' => 'OpenAI APIキーが設定されていません。管理者に連絡してください。'],
            ], 400);
        }

        try {
            $audioRecord->update(['status' => AudioRecord::STATUS_TRANSCRIBING]);

            // 同期実行: dispatch完了時点で文字起こし処理が完了している
            TranscribeAudioJob::dispatch($audioRecord->id);

            // 最新のステータスを取得
            $audioRecord->refresh();

            return response()->json([
                'data' => [
                    'id' => $audioRecord->id,
                    'status' => $audioRecord->status,
                    'message' => '文字起こしが完了しました。',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('文字起こしエラー', [
                'audio_record_id' => $audioRecord->id,
                'error' => $e->getMessage(),
            ]);

            // ジョブ内でエラーステータスに更新されていない場合の安全策
            $audioRecord->refresh();
            if ($audioRecord->status === AudioRecord::STATUS_TRANSCRIBING) {
                $audioRecord->update(['status' => AudioRecord::STATUS_ERROR]);
            }

            return response()->json([
                'error' => ['message' => '文字起こし中にエラーが発生しました: ' . $e->getMessage()],
            ], 500);
        }
    }

    /**
     * 要約処理を実行する（同期実行）
     */
    public function summarize(AudioRecord $audioRecord): JsonResponse
    {

        if (!$audioRecord->canSummarize()) {
            return response()->json([
                'error' => ['message' => 'この音声ファイルは要約できません。ステータス: ' . $audioRecord->status_label],
            ], 409);
        }

        // APIキーの事前チェック
        if (empty(config('services.anthropic.api_key'))) {
            return response()->json([
                'error' => ['message' => 'Anthropic APIキーが設定されていません。管理者に連絡してください。'],
            ], 400);
        }

        try {
            $audioRecord->update(['status' => AudioRecord::STATUS_SUMMARIZING]);

            // 同期実行: dispatch完了時点で要約処理が完了している
            SummarizeJob::dispatch($audioRecord->id);

            // 最新のステータスを取得
            $audioRecord->refresh();

            return response()->json([
                'data' => [
                    'id' => $audioRecord->id,
                    'status' => $audioRecord->status,
                    'message' => '要約が完了しました。',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('要約エラー', [
                'audio_record_id' => $audioRecord->id,
                'error' => $e->getMessage(),
            ]);

            $audioRecord->refresh();
            if ($audioRecord->status === AudioRecord::STATUS_SUMMARIZING) {
                $audioRecord->update(['status' => AudioRecord::STATUS_ERROR]);
            }

            return response()->json([
                'error' => ['message' => '要約中にエラーが発生しました: ' . $e->getMessage()],
            ], 500);
        }
    }

    /**
     * 要約テキストを取得（録音→トレーニング記録連携用API）
     */
    public function getSummary(AudioRecord $audioRecord): JsonResponse
    {

        return response()->json([
            'summary_text' => $audioRecord->summary_text,
        ]);
    }

    /**
     * 要約済みファイル一覧を取得（トレーニング記録への取り込み用API）
     *
     * 業務方針: 指定クライアントに紐付く要約のみ返す（誤取り込み防止）
     */
    public function summaries(Request $request): JsonResponse
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
        ], [
            'client_id.required' => '会員を指定してください。',
            'client_id.exists' => '選択された会員が存在しません。',
        ]);

        $query = AudioRecord::where('trainer_id', Auth::id())
            ->where('client_id', $request->client_id)
            ->where('status', AudioRecord::STATUS_COMPLETED)
            ->whereNotNull('summary_text')
            ->orderBy('created_at', 'desc');

        // タイトルで検索
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('title', 'like', "%{$search}%");
        }

        $audioRecords = $query->get(['id', 'client_id', 'title', 'source', 'file_name', 'created_at', 'summary_text']);

        return response()->json([
            'success' => true,
            'data' => $audioRecords,
        ]);
    }

    /**
     * 音声ファイルの再生（ストリーミング配信）
     */
    public function play(AudioRecord $audioRecord)
    {

        if (empty($audioRecord->file_path) || !Storage::exists($audioRecord->file_path)) {
            abort(404, '音声ファイルが見つかりません。');
        }

        $path = Storage::path($audioRecord->file_path);
        $extension = strtolower(pathinfo($audioRecord->file_name, PATHINFO_EXTENSION));
        $mimeTypes = [
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'wav' => 'audio/wav',
            'mp4' => 'audio/mp4',
            'webm' => 'audio/webm',
        ];

        return response()->file($path, [
            'Content-Type' => $mimeTypes[$extension] ?? 'audio/mpeg',
        ]);
    }

    /**
     * アクセス権チェック（現在は未使用）
     *
     * トレーナーフィルタ機能の導入に伴い、全トレーナーが全レコードを
     * 閲覧・操作可能になったため、各アクションからの呼び出しを削除。
     * 将来的にアクセス制御を再導入する場合に備えてメソッドは残している。
     */
    private function authorizeAccess(AudioRecord $audioRecord): void
    {
        $user = Auth::user();

        if ($user->role === 'staff' && $audioRecord->trainer_id !== $user->id) {
            abort(403, 'この音声ファイルへのアクセス権がありません。');
        }
    }

}

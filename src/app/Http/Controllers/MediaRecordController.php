<?php

namespace App\Http\Controllers;

use App\Jobs\ConvertMediaJob;
use App\Jobs\GenerateThumbnailJob;
use App\Models\MediaRecord;
use App\Models\Trainer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * メディア管理コントローラー
 *
 * メディアは署名付きURLでオブジェクトストレージへ直アップロードする方式を採る。
 * 本コントローラは「署名発行（uploadUrl）」と「アップロード完了後のレコード作成（store）」
 * を提供する。
 */
class MediaRecordController extends Controller
{
    // アップロード署名付きURLの有効期限（分）
    const UPLOAD_URL_EXPIRES_MINUTES = 15;

    // 再生（GET）署名付きURLの有効期限（分）
    // 将来のクライアント向け公開ではより短くする可能性があるため、UPLOAD と独立して持つ
    const PLAY_URL_EXPIRES_MINUTES = 15;

    // storage_key の形式 media/YYYYMM/{uuid}.{ext}（store 時の形式検証用）
    const STORAGE_KEY_PATTERN = '#^media/\d{6}/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[a-z0-9]+$#';

    // 一覧の1ページあたり件数（グリッド表示向け）
    const INDEX_PER_PAGE = 24;

    /**
     * メディア一覧画面（GET /media-records）
     *
     * 登録者フィルタ（trainer_id クエリ）で絞り込み、登録日時降順で一覧表示する。
     * 'all' は全件、整数値はそのトレーナーのみ、未指定はログイン中トレーナーをデフォルト。
     */
    public function index(Request $request): View
    {
        $user = Auth::user();

        // 詳細モーダルで登録者名を表示するため、N+1回避の eager load を入れる
        $query = MediaRecord::with(['trainer'])
            ->orderBy('created_at', 'desc');

        // 登録者フィルタ（既存の音声記録一覧と同型）
        $trainerId = $request->query('trainer_id');
        if ($trainerId === 'all') {
            // フィルタなし（NULL含む全件）
        } elseif ($trainerId) {
            $query->where('trainer_id', $trainerId);
        } else {
            // デフォルト: 自分が登録したもののみ
            $query->where('trainer_id', $user->id);
        }

        $mediaRecords = $query->paginate(self::INDEX_PER_PAGE)->appends($request->query());

        // 登録者プルダウン用トレーナー一覧（system_admin を除外）
        $trainers = Trainer::practitioners()
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        $selectedTrainerId = $trainerId ?? $user->id;

        // 詳細モーダル用のメタ情報辞書（id → 表示用フィールドの連想配列）
        // ビューでJSに JSON で渡し、カードクリック時にidで lookup する。
        // 一覧カードのサムネイル表示にも使用するため thumbnail_url を同梱する。
        $thumbnailExpiresAt = now()->addMinutes(self::PLAY_URL_EXPIRES_MINUTES);
        $mediaModalData = $mediaRecords->getCollection()->mapWithKeys(function ($m) use ($thumbnailExpiresAt) {
            // サムネイル presigned URL は thumbnail_status=done のものだけ発行（24件分、SDK 内 HMAC のみで高速）
            $thumbnailUrl = $m->temporaryThumbnailUrl($thumbnailExpiresAt);

            return [$m->id => [
                'type' => $m->type,
                'mime_type' => $m->mime_type,
                // 詳細モーダルの表示判定（done/not_required なら play を呼ぶ）に使う
                'conversion_status' => $m->conversion_status,
                // 一覧カードのサムネイル表示判定に使う
                'thumbnail_status' => $m->thumbnail_status,
                'thumbnail_url' => $thumbnailUrl,
                'display_title' => $m->display_title,
                // 編集フォーム用に「raw な title（NULL あり得る）」を別途持つ
                'title_raw' => $m->title,
                'original_filename' => $m->original_filename,
                'created_at' => $m->created_at->format('Y/m/d H:i'),
                'trainer_name' => $m->trainer?->name,
            ]];
        });

        return view('media-records.index', compact('mediaRecords', 'trainers', 'selectedTrainerId', 'mediaModalData'));
    }

    /**
     * 署名付きURL発行（POST /api/media-records/upload-url）
     *
     * 申請値（mime_type / file_size）で形式・サイズを事前検証し、不適合は 422 で拒否。
     * 適合時は storage_key を採番して presigned PUT URL を発行する。
     */
    public function uploadUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'original_filename' => 'required|string|max:255',
            // mime_type は受け取るが採用しない（ブラウザの file.type は heic 等で空文字や image/heif に
            // なるばらつきがあり信頼できないため、サーバは original_filename の拡張子から決定する）
            'mime_type' => 'nullable|string',
            'file_size' => 'required|integer|min:1',
        ], [
            'original_filename.required' => '元ファイル名を指定してください。',
            'original_filename.max' => '元ファイル名は255文字以内で指定してください。',
            'file_size.required' => 'ファイルサイズを指定してください。',
            'file_size.integer' => 'ファイルサイズは整数で指定してください。',
            'file_size.min' => 'ファイルサイズは1バイト以上で指定してください。',
        ]);

        // 拡張子から正規の mime_type を決定（クライアントの mime_type は採用しない）
        $mimeType = MediaRecord::resolveMimeFromFilename($validated['original_filename']);
        if ($mimeType === null) {
            return response()->json([
                'error' => ['original_filename' => ['対応形式は写真(jpeg/png/heic)・動画(mp4/mov)のみです。']],
            ], 422);
        }

        // 形式 → 種別判定（決定した mime_type は EXTENSION_TO_MIME 由来なので常に許可リスト内）
        $type = MediaRecord::resolveTypeFromMime($mimeType);

        // サイズ上限チェック（種別ごとに上限が異なる）
        $maxSize = MediaRecord::maxSizeForType($type);
        if ($validated['file_size'] > $maxSize) {
            $limitLabel = $type === MediaRecord::TYPE_PHOTO ? '20MB' : '1GB';
            return response()->json([
                'error' => ['file_size' => ["ファイルサイズは{$limitLabel}以下にしてください。"]],
            ], 422);
        }

        // storage_key 採番: media/YYYYMM/{uuid}.{ext}（拡張子は mime から正規化、.jpeg → jpg）
        $extension = MediaRecord::extensionForMime($mimeType);
        $storageKey = sprintf('media/%s/%s.%s', now()->format('Ym'), (string) Str::uuid(), $extension);

        // 署名付きPUT URL発行（有効期限はクラス定数で管理）
        $expiresAt = now()->addMinutes(self::UPLOAD_URL_EXPIRES_MINUTES);
        $signed = Storage::disk(MediaRecord::STORAGE_DISK)->temporaryUploadUrl($storageKey, $expiresAt);

        return response()->json([
            'data' => [
                'upload_url' => $signed['url'],
                'storage_key' => $storageKey,
            ],
        ]);
    }

    /**
     * メディアレコード作成（POST /api/media-records）
     *
     * 直アップロード完了後にブラウザから呼ばれる。
     * storage_key は upload-url で発行された形式に合致するかを正規表現で検証する
     * （形式検証のみ。ファイル実体の存在確認は今フェーズでは行わない）。
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'storage_key' => 'required|string',
            'original_filename' => 'required|string|max:255',
            // mime_type は受け取るが採用しない（uploadUrl と同じ理由。詳細はそちらのコメント参照）
            'mime_type' => 'nullable|string',
            'file_size' => 'required|integer|min:1',
            'title' => 'nullable|string|max:255',
        ], [
            'storage_key.required' => '保存キーを指定してください。',
            'original_filename.required' => '元ファイル名を指定してください。',
            'original_filename.max' => '元ファイル名は255文字以内で指定してください。',
            'file_size.required' => 'ファイルサイズを指定してください。',
            'file_size.integer' => 'ファイルサイズは整数で指定してください。',
            'file_size.min' => 'ファイルサイズは1バイト以上で指定してください。',
            'title.max' => '表示名は255文字以内で入力してください。',
        ]);

        // storage_key の形式検証（upload-url で発行した形式と一致するか）
        if (!preg_match(self::STORAGE_KEY_PATTERN, $validated['storage_key'])) {
            return response()->json([
                'error' => ['storage_key' => ['保存キーの形式が不正です。']],
            ], 422);
        }

        // 拡張子から正規の mime_type を決定（クライアントの mime_type は採用しない）
        $mimeType = MediaRecord::resolveMimeFromFilename($validated['original_filename']);
        if ($mimeType === null) {
            return response()->json([
                'error' => ['original_filename' => ['対応形式は写真(jpeg/png/heic)・動画(mp4/mov)のみです。']],
            ], 422);
        }

        // 種別確定（決定した mime_type は EXTENSION_TO_MIME 由来なので常に許可リスト内）
        $type = MediaRecord::resolveTypeFromMime($mimeType);

        // 表示用変換の要否を判定（ブラウザ表示可能＝変換不要、それ以外＝変換必要）
        // 変換不要時は display_path に original_path と同値をセット（原本がそのまま表示用）
        // 変換必要時は display_path は NULL のままで、conversion_status = pending として
        // 変換ジョブの起動を待つ（変換ロジック本体は2b以降のフェーズ）
        $isDisplayable = MediaRecord::isBrowserDisplayable($mimeType);
        $conversionStatus = $isDisplayable
            ? MediaRecord::CONVERSION_NOT_REQUIRED
            : MediaRecord::CONVERSION_PENDING;
        $displayPath = $isDisplayable ? $validated['storage_key'] : null;

        // サムネイルは全メディア（jpeg/png/mp4/heic/mov）が生成対象のため、登録時は常に pending。
        // DB default も 'pending' だが、CONVERSION_* と同じく意図を明示するため定数でセットする。
        // サムネイル生成ロジックは3b以降のフェーズ。
        $mediaRecord = MediaRecord::create([
            'trainer_id' => Auth::id(),
            'type' => $type,
            'title' => $validated['title'] ?? null,
            'original_filename' => $validated['original_filename'],
            'original_path' => $validated['storage_key'],
            'display_path' => $displayPath,
            'mime_type' => $mimeType,
            'file_size' => $validated['file_size'],
            'conversion_status' => $conversionStatus,
            'thumbnail_status' => MediaRecord::THUMBNAIL_PENDING,
        ]);

        return response()->json(['data' => $mediaRecord], 201);
    }

    /**
     * メディア更新（PUT /media-records/{id}）
     *
     * 表示名（title）のみ変更可能。
     * 種別・ファイル・元ファイル名・mime_type は変更しない（ファイル差し替えは別フロー）。
     */
    public function update(Request $request, MediaRecord $mediaRecord): JsonResponse
    {
        // 文言は lang/ja/validation.php に集約（§2-8。段階 5-3a）：
        //   title.max → 標準 ＋ attributes.title「表示名は255文字以内で入力してください。」
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
        ]);

        $mediaRecord->update([
            'title' => $validated['title'] ?? null,
        ]);

        return response()->json(['data' => $mediaRecord->fresh()]);
    }

    /**
     * メディア削除（DELETE /media-records/{id}）
     *
     * ファイル先削除 → 成功してからレコード削除（既存音声と同型）。
     * original_path / display_path / thumbnail_path の3キーから NULL を除き、重複
     * （変換不要な jpeg/png/mp4 は original==display）を除いたユニーク集合を一括削除する。
     * sakura ディスクは config で throw=>true のため、ファイル削除失敗は例外で500に。
     * その場合レコードは残り「DB ↔ ストレージ整合」の不変条件を保つ（孤児ファイル防止優先、
     * ユーザーは再度の削除で再試行可能）。
     * S3 互換 API の DELETE は冪等のため、存在確認チェックは不要（過剰防御を避ける）。
     */
    public function destroy(MediaRecord $mediaRecord): JsonResponse
    {
        $keysToDelete = array_values(array_unique(array_filter([
            $mediaRecord->original_path,
            $mediaRecord->display_path,
            $mediaRecord->thumbnail_path,
        ])));

        // original_path は NOT NULL なので通常空にならないが、念のためのガード
        if (!empty($keysToDelete)) {
            Storage::disk(MediaRecord::STORAGE_DISK)->delete($keysToDelete);
        }
        $mediaRecord->delete();

        return response()->json(['success' => true]);
    }

    /**
     * 再生（GET /api/media-records/{id}/play）
     *
     * 表示用ファイル（display_path）への presigned GET URL を発行して返す。
     * クライアント（ブラウザ）はこのURLへ直接アクセスして再生・表示する。
     * 存在確認は行わない（store 時の方針Bと整合。孤児レコードはオブジェクトストレージが 403/404 を返す）。
     *
     * display_path が NULL（変換待ち・変換中・変換失敗）は表示用ファイルが未生成のため
     * 409 を返す。呼び出し側は conversion_status に応じて「変換中」「変換失敗」を表示する。
     */
    public function play(MediaRecord $mediaRecord): JsonResponse
    {
        if ($mediaRecord->display_path === null) {
            return response()->json([
                'error' => '表示用ファイルが未生成です。',
                'conversion_status' => $mediaRecord->conversion_status,
            ], 409);
        }

        $expiresAt = now()->addMinutes(self::PLAY_URL_EXPIRES_MINUTES);
        $url = Storage::disk(MediaRecord::STORAGE_DISK)->temporaryUrl($mediaRecord->display_path, $expiresAt);

        return response()->json([
            'data' => [
                'url' => $url,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    /**
     * 表示用変換を起動（POST /api/media-records/{id}/convert）
     *
     * 写真（heic/heif）を jpeg に、動画（mov）を mp4 に変換する。
     * conversion_status が pending のときのみ実行可能。
     * 開発は QUEUE_CONNECTION=sync で同期実行のため、レスポンス時点で done/error に遷移している。
     * type 別の振り分けは ConvertMediaJob 側で行う。
     */
    public function convert(MediaRecord $mediaRecord): JsonResponse
    {
        if ($mediaRecord->conversion_status !== MediaRecord::CONVERSION_PENDING) {
            return response()->json([
                'error' => '変換待ち状態ではないため処理できません。',
                'conversion_status' => $mediaRecord->conversion_status,
            ], 409);
        }

        try {
            $mediaRecord->update(['conversion_status' => MediaRecord::CONVERSION_PROCESSING]);

            // 同期実行: dispatch完了時点で変換処理が完了している
            ConvertMediaJob::dispatch($mediaRecord->id);

            $mediaRecord->refresh();

            return response()->json(['data' => $mediaRecord]);
        } catch (\Throwable $e) {
            Log::error('メディア変換エラー', [
                'media_record_id' => $mediaRecord->id,
                'error' => $e->getMessage(),
            ]);

            // ジョブ内でエラーステータスに更新されていない場合の安全策（音声と同型）
            $mediaRecord->refresh();
            if ($mediaRecord->conversion_status === MediaRecord::CONVERSION_PROCESSING) {
                $mediaRecord->update(['conversion_status' => MediaRecord::CONVERSION_ERROR]);
            }

            // 例外メッセージは外部プロセス由来で非UTF-8（CP932 等）になりうるため JSON に
            // 直接含めない。固定文言＋ログ突合用の media_record_id のみ返す。
            // 詳細は Log::error 側のログとサーバログから追跡する。
            return response()->json([
                'error' => '表示用変換に失敗しました。',
                'media_record_id' => $mediaRecord->id,
            ], 500);
        }
    }

    /**
     * サムネイル生成を起動（POST /api/media-records/{id}/generate-thumbnail）
     *
     * 原本から 200x200 のサムネイルを生成する。thumbnail_status が pending のときのみ実行可能。
     * 開発は QUEUE_CONNECTION=sync で同期実行のため、レスポンス時点で done/error に遷移している。
     * type 別の振り分けは GenerateThumbnailJob 側で行う（API は全メディアを受け付ける）。
     */
    public function generateThumbnail(MediaRecord $mediaRecord): JsonResponse
    {
        if ($mediaRecord->thumbnail_status !== MediaRecord::THUMBNAIL_PENDING) {
            return response()->json([
                'error' => 'サムネイル生成待ち状態ではないため処理できません。',
                'thumbnail_status' => $mediaRecord->thumbnail_status,
            ], 409);
        }

        try {
            $mediaRecord->update(['thumbnail_status' => MediaRecord::THUMBNAIL_PROCESSING]);

            // 同期実行: dispatch完了時点でサムネイル生成処理が完了している
            GenerateThumbnailJob::dispatch($mediaRecord->id);

            $mediaRecord->refresh();

            // 段2 のため、登録モーダルの呼び出し側が再フェッチ不要で
            // 仮紐づけ表示に使えるよう、thumbnail_url と display_title を含めて返す。
            // QUEUE_CONNECTION=sync のため、ここで thumbnail_status=done が確定済み。
            $thumbnailUrl = $mediaRecord->temporaryThumbnailUrl(
                now()->addMinutes(self::PLAY_URL_EXPIRES_MINUTES)
            );
            return response()->json([
                'data' => array_merge($mediaRecord->toArray(), [
                    'thumbnail_url' => $thumbnailUrl,
                    'display_title' => $mediaRecord->display_title,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('サムネイル生成エラー', [
                'media_record_id' => $mediaRecord->id,
                'error' => $e->getMessage(),
            ]);

            // ジョブ内でエラーステータスに更新されていない場合の安全策（convert と同型）
            $mediaRecord->refresh();
            if ($mediaRecord->thumbnail_status === MediaRecord::THUMBNAIL_PROCESSING) {
                $mediaRecord->update(['thumbnail_status' => MediaRecord::THUMBNAIL_ERROR]);
            }

            // convert と同じく、例外メッセージは外部プロセス由来で非UTF-8 になりうるため
            // JSON に直接含めない。固定文言＋ログ突合用の media_record_id のみ返す。
            return response()->json([
                'error' => 'サムネイル生成に失敗しました。',
                'media_record_id' => $mediaRecord->id,
            ], 500);
        }
    }
}

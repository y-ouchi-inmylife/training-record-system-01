<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Trainee;
use Illuminate\Http\File;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * クライアント閲覧機能（柱2）— トレーニー写真コントローラ
 *
 * 会員がダッシュボード（S-1402）から自分のトレーニーの写真を登録・差し替え・削除する
 * （要件定義書 6-15-15、screen-design.md S-1402「トレーニー写真」設計方針参照）。
 *
 * サーバ経由の multipart POST 方式（署名付き URL の直アップロードは使わない）。
 * 画像は magick で 400x400\> にリサイズし JPEG に統一して保存する（原寸・サムネイルは持たない）。
 * 変換ロジックは既存の MediaThumbnailService と同じ流儀：Process ファサードで CLI 呼び出し、
 * stderr は UTF-8 化してログのみ、例外は UTF-8 確定の固定文言、一時ファイルは finally で削除。
 * 今回はアップロード時 1 回だけの処理のためジョブキューは使わず、リクエスト内で同期実行する。
 */
class TraineePhotoController extends Controller
{
    // アップロードされた写真の一時ファイル置き場（storage/app 配下は git 管理外）
    private const TMP_DIR = 'tmp/trainee-photo';

    // リサイズ後 JPEG の品質（既存メディアと揃える）
    private const JPEG_QUALITY = 85;

    // 表示用の最大寸法（長辺 400px）。設計書 S-1402「トレーニー写真」設計方針参照
    private const MAX_DIMENSION = 400;

    // アップロード上限（20MB）。既存の MediaRecord::MAX_PHOTO_SIZE に揃える。
    // Laravel の max ルールはキロバイト単位で指定する（20MB = 20480KB）
    private const MAX_SIZE_KB = 20480;

    /**
     * トレーニー写真をアップロード（新規登録・差し替え）する。
     *
     * バリデーションで image ルールは使わない：Laravel の image ルールは
     * Symfony の MIME 判定に依存し、HEIC を弾く可能性がある（HEIC は Symfony の
     * MimeTypes マップに存在しないため fallback で通らないケースがある）。
     * 既存の MediaRecordController が「クライアントの mime_type を信用せず拡張子で
     * 判定する」方針を採っている理由と同じ。mimes ルールは拡張子ベースの判定にも
     * フォールバックするため、HEIC/HEIF も含めて安全に扱える。
     */
    public function store(Request $request, Trainee $trainee): RedirectResponse
    {
        // 所有権の検証：既存の Client\TrainingRecordController::show と同型
        // （client_id 直接照合。403 で止める）
        if ($trainee->client_id !== auth('client')->id()) {
            abort(403);
        }

        // required / mimes / max の文言は lang/ja/validation.php に集約
        // （設計書 §2-8。custom.photo.required / custom.photo.mimes / custom.photo.max）。
        // photo.file は「アップロードそのものの失敗」で入力エラーの範囲外（§2-7「対象外」）
        // のため、本コントローラ内に残す。
        $validated = $request->validate([
            'photo' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,heic,heif',
                'max:' . self::MAX_SIZE_KB,
            ],
        ], [
            'photo.file' => '写真ファイルの取得に失敗しました。もう一度お試しください。',
        ]);

        $tmpDir = storage_path('app/' . self::TMP_DIR);
        FileFacade::ensureDirectoryExists($tmpDir);

        $tmpId = (string) Str::uuid();
        $uploaded = $request->file('photo');
        // 元拡張子は原本判定に必要（magick が拡張子を見てデコーダを選ぶ）。
        // getClientOriginalExtension は信頼できないため extension()（実ファイル解析ベース）を使う…
        // が、HEIC ではこちらも空文字を返すケースがあるため、両方を試して空でない方を採る。
        $originalExt = strtolower($uploaded->extension() ?: $uploaded->getClientOriginalExtension() ?: 'bin');
        $tmpIn = $tmpDir . DIRECTORY_SEPARATOR . $tmpId . '.' . $originalExt;
        $tmpOut = $tmpDir . DIRECTORY_SEPARATOR . $tmpId . '.jpg';

        try {
            // アップロードされた multipart ファイルを一時ディレクトリに移動する。
            // move は UploadedFile の finfo と拡張子を保つため、後続の magick で
            // 拡張子から適切なデコーダを選べる。
            $uploaded->move($tmpDir, $tmpId . '.' . $originalExt);

            // magick でリサイズ・JPEG 変換
            //   -auto-orient: EXIF orientation を焼き込み（iPhone の縦撮り写真の横倒しを防ぐ）
            //   -resize 400x400\>: 長辺 400px（\> は「指定より大きい場合だけ縮小」を意味する
            //                      ImageMagick の geometry 修飾子。小さい画像は拡大しない）
            //   -quality 85: 既存メディア（サムネイル・変換）と同じ画質
            $magickPath = (string) config('media.magick_path', 'magick');
            $result = Process::timeout(300)->run([
                $magickPath,
                $tmpIn,
                '-auto-orient',
                '-resize', self::MAX_DIMENSION . 'x' . self::MAX_DIMENSION . '>',
                '-quality', (string) self::JPEG_QUALITY,
                $tmpOut,
            ]);

            // UTF-8 対策（既存の MediaThumbnailService と同型）：
            // Windows 開発の cmd.exe は CP932 で日本語エラーを返す。stderr を生で
            // 例外に載せず、UTF-8 化してログに記録、例外文言は UTF-8 固定にする。
            if ($result->failed()) {
                Log::error('TraineePhotoController: magick 実行失敗', [
                    'exit_code' => $result->exitCode(),
                    'stderr' => $this->toUtf8($result->errorOutput()),
                    'trainee_id' => $trainee->id,
                ]);
                throw new \RuntimeException(
                    "写真の変換に失敗しました（exit code {$result->exitCode()}）。詳細はサーバログを確認してください。"
                );
            }

            if (!FileFacade::exists($tmpOut)) {
                throw new \RuntimeException('変換後の写真ファイルが生成されませんでした。');
            }

            // ストレージキー採番: trainees/YYYYMM/{uuid}.jpg（設計書 D-0700 注記の形式）
            $storageKey = sprintf('trainees/%s/%s.jpg', now()->format('Ym'), (string) Str::uuid());
            $dir = pathinfo($storageKey, PATHINFO_DIRNAME);
            $basename = pathinfo($storageKey, PATHINFO_BASENAME);

            // 順序：新ファイル保存 → DB 更新 → 旧ファイル削除（設計書の指示どおり）
            Storage::disk(Trainee::STORAGE_DISK)
                ->putFileAs($dir, new File($tmpOut), $basename);

            $oldPath = $trainee->photo_path;
            // updated_by は触らない（会員操作のため、トレーナー更新の追跡カラムを汚さない）
            $trainee->update(['photo_path' => $storageKey]);

            // 旧ファイル削除（差し替え時のみ）。
            // 失敗時はログに残して処理を継続する：DB は新しいキーに更新済みで
            // 会員から見ると「写真は差し替わっている」状態のため、ここで例外を伝播すると
            // ユーザに矛盾したエラーが出る。孤児ファイルは残るが、後日運用側で
            // 掃除できる（既存のさくらのクラウド手順書の記述と同じ考え方）。
            if ($oldPath !== null && $oldPath !== $storageKey) {
                try {
                    Storage::disk(Trainee::STORAGE_DISK)->delete($oldPath);
                } catch (\Throwable $e) {
                    Log::warning('TraineePhotoController: 旧写真ファイルの削除に失敗（孤児ファイル発生の可能性）', [
                        'trainee_id' => $trainee->id,
                        'old_path' => $oldPath,
                        'new_path' => $storageKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return redirect()
                ->route('client-portal.dashboard')
                ->with('success', '写真を登録しました。');
        } catch (\Throwable $e) {
            // magick 失敗など：一時ファイルの掃除は finally に任せて、
            // ユーザ向けにはお客様向けの文言でフォームに戻る。
            Log::error('TraineePhotoController: 写真アップロード処理でエラー', [
                'trainee_id' => $trainee->id,
                'error' => $e->getMessage(),
            ]);
            // 入力エラー以外の失敗（変換・保存失敗）はフォームの上に出す（設計書 §2-7。
            // 段階 4-4 で photo キーから専用の form キーに移した）。該当トレーニーのカードに
            // だけエラーを出すため、_trainee_id を old() 経由で復元できるよう withInput() を付ける
            // （ビュー側で old('_trainee_id') === $chart['id'] を見て絞り込む）。
            return redirect()
                ->route('client-portal.dashboard')
                ->withInput()
                ->withErrors(['form' => '写真の登録に失敗しました。時間をおいて試してみてください。']);
        } finally {
            // 一時ファイルは成功・失敗どちらでも必ず削除
            FileFacade::delete([$tmpIn, $tmpOut]);
        }
    }

    /**
     * トレーニー写真を削除する。
     *
     * 順序：ファイル先削除 → DB カラム NULL 化（既存の MediaRecordController::destroy と同型）。
     * ファイル削除失敗時は例外→500 に持ち上げ、DB は元のキーのまま残す（孤児ファイル防止優先、
     * ユーザは再度の削除で再試行可能）。差し替え時の旧ファイル削除失敗（store 側）は
     * 「新写真が既に反映されている」ため無視するが、こちらは削除操作そのものが失敗している
     * ため隠さない。
     */
    public function destroy(Trainee $trainee): RedirectResponse
    {
        // 所有権の検証
        if ($trainee->client_id !== auth('client')->id()) {
            abort(403);
        }

        // 写真未登録は 404（削除対象が存在しない）
        if ($trainee->photo_path === null) {
            abort(404);
        }

        // media ディスクは throw=>true 設定のため、削除失敗は例外で 500 に。
        // 例外の場合は DB カラムを NULL 化しない（孤児ファイル防止）。
        Storage::disk(Trainee::STORAGE_DISK)->delete($trainee->photo_path);
        // updated_by は触らない（会員操作のため）
        $trainee->update(['photo_path' => null]);

        return redirect()
            ->route('client-portal.dashboard')
            ->with('success', '写真を削除しました。');
    }

    /**
     * 外部プロセスの stdout/stderr を UTF-8 化する
     *
     * MediaThumbnailService / MediaConversionService の同名メソッドと同一実装。
     * Windows の cmd.exe が CP932 で返す日本語エラーを、Log や JSON で安全に扱えるよう
     * UTF-8 に変換する。3 つ目の Service（本コントローラ）が加わったため、
     * 将来は trait 化を検討する（既存の TODO コメントと合わせて）。
     */
    private function toUtf8(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $converted = mb_convert_encoding($s, 'UTF-8', 'UTF-8,SJIS-win,CP932,SJIS,EUC-JP');
        return $converted === false ? '' : $converted;
    }
}

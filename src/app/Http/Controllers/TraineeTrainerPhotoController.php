<?php

namespace App\Http\Controllers;

use App\Models\MediaRecord;
use App\Models\Trainee;
use App\Services\TraineePhotoImageService;
use Illuminate\Http\File;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * トレーナー用写真の設定・外す（S-0309 / S-0309-M02。要件定義書 6-16-20。2026-11 追加）。
 *
 * トレーナーがメディア一覧（ライブラリ）から写真を 1 枚選び、そのトレーニーの「トレーナー用写真」にする。
 * 選んだメディアの表示用の画像（display_path）から、会員写真と同じ形式の画像（長辺 400px の JPEG 1 枚）を作って
 * トレーニーに持たせる（コピー方式。元のメディアの ID は持たない。設計書 db-schema.md D-0700
 * 「トレーナー用写真の扱い」、api-design.md 4-1-16「S-0309 トレーニー詳細画面」）。
 *
 * - 一般トレーナーも管理者も操作できる（practitioners グループ。管理者の判定はしない）
 * - トレーナー用写真は会員側に一切出さない（このコントローラは会員側から呼ばれない）
 * - 処理は会員写真（Client\TraineePhotoController）と同じく、キューを使わずリクエストの中で行う
 * - アクセスログは LogAccess の ACTION_MAP（create_trainee_trainer_photo / delete_trainee_trainer_photo）
 */
class TraineeTrainerPhotoController extends Controller
{
    // 選んだメディアの画像を取り出す一時ファイル置き場（storage/app 配下は git 管理外）。
    // 会員写真の tmp/trainee-photo とは分ける（用途ごとにフォルダを分ける既存の方針）
    private const TMP_DIR = 'tmp/trainee-trainer-photo';

    /**
     * トレーナー用写真を設定する（新規・選び直し）。POST /trainees/{trainee}/trainer-photo
     *
     * 順番：新しいファイルを保存 → DB を更新（updated_by も）→ 古いファイルを消す。
     * DB の更新に失敗したら新しいファイルを消す。古いファイルの削除に失敗してもエラーにせずログに残す。
     */
    public function store(Request $request, Trainee $trainee): RedirectResponse
    {
        // 文言は lang/ja/validation.php に集約（§2-8。custom.media_record_id）。
        // エラーで戻ったら S-0309-M02 を開き直すため、hidden の _trainer_photo_modal を old() に残す（withInput）
        $validated = $request->validate([
            'media_record_id' => ['required', 'integer'],
        ]);

        // 選べるメディア：写真で、表示用の画像があるもの（変換が不要か、変換が終わったもの）。
        // 存在しない・動画・変換が終わっていない・変換に失敗したものは同じ文言で断る
        // （設計書 S-0309-M02「バリデーションチェック」）
        $media = MediaRecord::query()->find($validated['media_record_id']);
        if (! $media || ! self::isSelectable($media)) {
            throw ValidationException::withMessages([
                'media_record_id' => __('validation.custom.media_record_id.selectable'),
            ]);
        }

        $tmpDir = storage_path('app/'.self::TMP_DIR);
        FileFacade::ensureDirectoryExists($tmpDir);
        $tmpId = (string) Str::uuid();
        // magick は拡張子を見てデコーダを選ぶため、表示用の画像のキーの拡張子を引き継ぐ（jpg / png）
        $ext = strtolower(pathinfo($media->display_path, PATHINFO_EXTENSION) ?: 'jpg');
        $tmpIn = $tmpDir.DIRECTORY_SEPARATOR.$tmpId.'.'.$ext;
        $tmpOut = $tmpDir.DIRECTORY_SEPARATOR.$tmpId.'.jpg';

        $newKey = null;
        try {
            // 表示用の画像を一時ファイルに取り出す（media ディスクは throw=>true。失敗は例外）
            $stream = Storage::disk(MediaRecord::STORAGE_DISK)->readStream($media->display_path);
            if ($stream === null || $stream === false) {
                throw new \RuntimeException('表示用の画像を読み込めませんでした。');
            }
            $out = fopen($tmpIn, 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }

            // 会員写真と同じ形式（長辺 400px・品質 85 の JPEG）にする
            app(TraineePhotoImageService::class)->makeJpeg($tmpIn, $tmpOut, 'TraineeTrainerPhotoController', [
                'trainee_id' => $trainee->id,
                'media_record_id' => $media->id,
            ]);

            // キー：trainee-trainer-photos/YYYYMM/{uuid}.jpg（設計書 D-0700「トレーナー用写真の扱い」）
            $newKey = sprintf('%s/%s/%s.jpg', Trainee::TRAINER_PHOTO_DIR, now()->format('Ym'), (string) Str::uuid());
            Storage::disk(Trainee::STORAGE_DISK)
                ->putFileAs(pathinfo($newKey, PATHINFO_DIRNAME), new File($tmpOut), pathinfo($newKey, PATHINFO_BASENAME));

            $oldKey = $trainee->trainer_photo_path;
            try {
                // fillable に入れていないので forceFill で保存する（会員側のフォームから書き換えられないように）
                $trainee->forceFill([
                    'trainer_photo_path' => $newKey,
                    'updated_by' => auth()->id(),
                ])->save();
            } catch (\Throwable $e) {
                // DB の更新に失敗したら、保存した新しいファイルを消す（孤児ファイルを残さない）
                $this->deleteQuietly($newKey, $trainee->id);
                throw $e;
            }

            // 古いファイルを消す（選び直しのとき）。失敗してもエラーにせずログに残す
            // （DB は新しいキーになっていて、画面では差し替わっているため）
            if ($oldKey !== null && $oldKey !== $newKey) {
                $this->deleteQuietly($oldKey, $trainee->id);
            }
        } catch (\Throwable $e) {
            Log::error('TraineeTrainerPhotoController: トレーナー用写真の設定でエラー', [
                'trainee_id' => $trainee->id,
                'media_record_id' => $media->id,
                'error' => $e->getMessage(),
            ]);

            // 入力エラー以外の失敗は form のキーで、S-0309-M02 の本文の上に出す（§2-7）。
            // withInput で _trainer_photo_modal を残し、モーダルを開き直す
            return redirect()
                ->route('trainees.show', $trainee)
                ->withInput()
                ->withErrors(['form' => 'トレーナー用写真の設定に失敗しました。時間をおいて試してみてください。']);
        } finally {
            // 一時ファイルは成功・失敗どちらでも必ず削除
            FileFacade::delete([$tmpIn, $tmpOut]);
        }

        return redirect()
            ->route('trainees.show', $trainee)
            ->with('success', 'トレーナー用写真を設定しました。');
    }

    /**
     * トレーナー用写真を外す。DELETE /trainees/{trainee}/trainer-photo
     *
     * 順番：DB を NULL に戻す（updated_by も）→ ファイルを消す（削除の失敗はログのみ）。
     * 会員写真と元のメディアには触らない。
     */
    public function destroy(Trainee $trainee): RedirectResponse
    {
        // 外すものがないときは 404（設計書 api-design.md）
        $key = $trainee->trainer_photo_path;
        if ($key === null || $key === '') {
            abort(404);
        }

        $trainee->forceFill([
            'trainer_photo_path' => null,
            'updated_by' => auth()->id(),
        ])->save();

        $this->deleteQuietly($key, $trainee->id);

        return redirect()
            ->route('trainees.show', $trainee)
            ->with('success', 'トレーナー用写真を外しました。');
    }

    /**
     * トレーナー用写真に選べるメディアか（写真で、表示用の画像があるもの）。
     * 候補の API（available-media の type=photo）と同じ条件
     */
    public static function isSelectable(MediaRecord $media): bool
    {
        return $media->type === MediaRecord::TYPE_PHOTO
            && in_array($media->conversion_status, [MediaRecord::CONVERSION_NOT_REQUIRED, MediaRecord::CONVERSION_DONE], true)
            && ! empty($media->display_path);
    }

    /**
     * ストレージのファイルを消す。失敗しても例外を外に出さず、警告のログに残す
     */
    private function deleteQuietly(string $key, int $traineeId): void
    {
        try {
            Storage::disk(Trainee::STORAGE_DISK)->delete($key);
        } catch (\Throwable $e) {
            Log::warning('TraineeTrainerPhotoController: トレーナー用写真ファイルの削除に失敗（孤児ファイル発生の可能性）', [
                'trainee_id' => $traineeId,
                'path' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

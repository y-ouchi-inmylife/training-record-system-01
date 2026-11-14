<?php

namespace App\Services;

use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * トレーニーの写真の画像を作る（長辺 400px の JPEG 1 枚）。
 *
 * 会員写真（Client\TraineePhotoController。要件定義書 6-15-15）の変換処理を切り出したもの。
 * トレーナー用の写真（要件定義書 6-16-20）も同じ形式で作るため、ここにまとめる
 * （設計書 db-schema.md D-0700「トレーナー用の写真の扱い」：会員写真と同じ形式）。
 *
 * 変換ロジックは既存の MediaThumbnailService と同じ流儀：Process ファサードで CLI 呼び出し、
 * stderr は UTF-8 化してログのみ、例外は UTF-8 確定の固定文言。
 * 一時ファイルの用意と削除は呼び出し側の責務（入力・出力のパスを渡す）。
 */
class TraineePhotoImageService
{
    // リサイズ後 JPEG の品質（既存メディアと揃える）
    public const JPEG_QUALITY = 85;

    // 表示用の最大寸法（長辺 400px）。設計書 S-1402「トレーニー写真」設計方針参照
    public const MAX_DIMENSION = 400;

    /**
     * $inputPath の画像を、長辺 400px（小さい画像は拡大しない）の JPEG にして $outputPath に書く。
     *
     * 失敗したら RuntimeException を投げる（文言は UTF-8 の固定文言。詳細はログ）。
     *
     * @param  string  $logLabel  ログの先頭に付ける呼び出し元の名前（例：'TraineePhotoController'）
     * @param  array<string, mixed>  $logContext  ログに足す情報（例：['trainee_id' => 1]）
     */
    public function makeJpeg(string $inputPath, string $outputPath, string $logLabel, array $logContext = []): void
    {
        // magick でリサイズ・JPEG 変換
        //   -auto-orient: EXIF orientation を焼き込み（iPhone の縦撮り写真の横倒しを防ぐ）
        //   -resize 400x400\>: 長辺 400px（\> は「指定より大きい場合だけ縮小」を意味する
        //                      ImageMagick の geometry 修飾子。小さい画像は拡大しない）
        //   -quality 85: 既存メディア（サムネイル・変換）と同じ画質
        $magickPath = (string) config('media.magick_path', 'magick');
        $result = Process::timeout(300)->run([
            $magickPath,
            $inputPath,
            '-auto-orient',
            '-resize', self::MAX_DIMENSION.'x'.self::MAX_DIMENSION.'>',
            '-quality', (string) self::JPEG_QUALITY,
            $outputPath,
        ]);

        // UTF-8 対策（既存の MediaThumbnailService と同型）：
        // Windows 開発の cmd.exe は CP932 で日本語エラーを返す。stderr を生で
        // 例外に載せず、UTF-8 化してログに記録、例外文言は UTF-8 固定にする。
        if ($result->failed()) {
            Log::error($logLabel.': magick 実行失敗', [
                'exit_code' => $result->exitCode(),
                'stderr' => $this->toUtf8($result->errorOutput()),
            ] + $logContext);
            throw new \RuntimeException(
                "写真の変換に失敗しました（exit code {$result->exitCode()}）。詳細はサーバログを確認してください。"
            );
        }

        if (! FileFacade::exists($outputPath)) {
            throw new \RuntimeException('変換後の写真ファイルが生成されませんでした。');
        }
    }

    /**
     * 外部プロセスの stdout/stderr を UTF-8 化する
     *
     * MediaThumbnailService / MediaConversionService の同名メソッドと同一実装。
     * Windows の cmd.exe が CP932 で返す日本語エラーを、Log や JSON で安全に扱えるよう
     * UTF-8 に変換する（もとは Client\TraineePhotoController にあった）。
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

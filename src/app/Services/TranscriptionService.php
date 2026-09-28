<?php

namespace App\Services;

use App\Exceptions\TranscriptionInputTooLargeException;
use Illuminate\Support\Facades\File as FileFacade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

/**
 * 文字起こしサービス（Whisper API）
 *
 * 将来的にサーバーローカル処理への切り替えを考慮し、
 * API呼び出しをService層で抽象化している。
 *
 */
class TranscriptionService
{
    // 文字起こし API に送る 1 リクエストの上限バイト数。
    // Whisper の 25MB 制限に余裕を持たせ、1MB=1,000,000 でも 1,048,576 でも下回るよう 24,000,000 バイトとする。
    // 変換するかどうかの閾値と、変換後の上限チェックの両方に同じ値を使う。
    private const MAX_API_UPLOAD_BYTES = 24_000_000;

    // FFmpeg 実行タイムアウト（秒）。MediaConversionService の動画変換と同じ値。
    private const FFMPEG_PROCESS_TIMEOUT = 600;

    // 一時ファイル置き場（storage/app 配下は git 管理外）。
    // 用途ごとにフォルダを分ける（tmp/conversion・tmp/thumbnail・tmp/trainee-photo と同じ方針）。
    private const TMP_DIR = 'tmp/transcription';

    /**
     * 音声ファイルを文字起こしする
     *
     * @param string $filePath Storage上のファイルパス
     * @return array{text: string, duration: float|null}
     * @throws TranscriptionInputTooLargeException 変換後も上限を超える場合
     * @throws \RuntimeException API呼び出しや変換に失敗した場合
     */
    public function transcribe(string $filePath): array
    {
        $absolutePath = Storage::path($filePath);

        if (!file_exists($absolutePath)) {
            throw new \RuntimeException("音声ファイルが見つかりません: {$filePath}");
        }

        $tmpPath = null;
        $sendPath = $absolutePath;

        try {
            // 上限を超えるファイルだけ、送信前にサーバー側で小さく変換する。
            // 短いファイルはこれまでどおりそのまま送る（動きを変えない）。
            if (filesize($absolutePath) > self::MAX_API_UPLOAD_BYTES) {
                $tmpPath = $this->compressForApi($absolutePath);
                if (filesize($tmpPath) > self::MAX_API_UPLOAD_BYTES) {
                    throw new TranscriptionInputTooLargeException(
                        '録音が長すぎるため、文字起こしできません。音声記録一覧から録音をダウンロードして、外部のツールで文字起こししてください。'
                    );
                }
                $sendPath = $tmpPath;
            }

            $response = OpenAI::audio()->transcribe([
                'model' => 'whisper-1',
                'file' => fopen($sendPath, 'r'),
                'language' => 'ja',
                'response_format' => 'verbose_json',
            ]);

            return [
                'text' => $response->text,
                'duration' => $response->duration ?? null,
            ];
        } finally {
            // 変換ファイルは成功・失敗にかかわらず必ず削除。元の録音ファイルは触らない。
            if ($tmpPath !== null && FileFacade::exists($tmpPath)) {
                FileFacade::delete($tmpPath);
            }
        }
    }

    /**
     * 元ファイルをモノラル・16kHz・Opus 24 kbps の Ogg に変換して、
     * 一時ファイルの絶対パスを返す。
     *
     * Whisper は内部で 16kHz に落として認識するため、
     * ここでのダウンサンプルによる精度低下はほぼない。
     *
     * @throws \RuntimeException 変換失敗時
     */
    private function compressForApi(string $sourceAbsolutePath): string
    {
        $tmpDir = storage_path('app/' . self::TMP_DIR);
        FileFacade::ensureDirectoryExists($tmpDir);
        $tmpOut = $tmpDir . DIRECTORY_SEPARATOR . (string) Str::uuid() . '.ogg';

        // ffmpeg のパスは config 経由（Windows 開発ではフルパス必須、Linux 本番は 'ffmpeg' で PATH 解決）
        $ffmpegPath = (string) config('media.ffmpeg_path', 'ffmpeg');
        $sourceSize = filesize($sourceAbsolutePath);
        $startTime = microtime(true);

        $result = Process::timeout(self::FFMPEG_PROCESS_TIMEOUT)->run([
            $ffmpegPath,
            '-y',
            '-i', $sourceAbsolutePath,
            '-vn',
            '-ac', '1',
            '-ar', '16000',
            '-c:a', 'libopus',
            '-b:a', '24k',
            // -vbr off: ビットレートを 24 kbps に固定する。
            // libopus は既定で可変ビットレート（VBR）のため、音の内容によって
            // 実際のビットレートが上下し、変換後のファイルサイズが読めない
            // （試験用ファイルで約 38 kbps 相当になった実測がある）。
            // 変換の目的は API の 1 リクエスト上限（25MB）に確実に収めることなので、
            // どんな音でも計算どおりのサイズになるよう CBR を明示する。
            '-vbr', 'off',
            $tmpOut,
        ]);
        $elapsed = microtime(true) - $startTime;

        // MediaConversionService と同じ UTF-8 対策: stderr はログのみ、例外は固定文言。
        if ($result->failed()) {
            Log::error('TranscriptionService: ffmpeg 変換失敗', [
                'exit_code' => $result->exitCode(),
                'stderr' => $this->toUtf8($result->errorOutput()),
                'source' => $sourceAbsolutePath,
            ]);
            if (FileFacade::exists($tmpOut)) {
                FileFacade::delete($tmpOut);
            }
            throw new \RuntimeException(
                "音声の変換に失敗しました（exit code {$result->exitCode()}）。詳細はサーバログを確認してください。"
            );
        }

        if (!FileFacade::exists($tmpOut)) {
            throw new \RuntimeException('変換後ファイルが生成されませんでした。');
        }

        Log::info('TranscriptionService: 音声変換完了', [
            'source' => $sourceAbsolutePath,
            'source_size_bytes' => $sourceSize,
            'converted_size_bytes' => filesize($tmpOut),
            'elapsed_seconds' => round($elapsed, 2),
        ]);

        return $tmpOut;
    }

    /**
     * 外部プロセスの stdout/stderr を UTF-8 化する
     *
     * MediaConversionService::toUtf8 と同じ実装。
     * 3 つ目の使用箇所となるため、次に増えたら trait 化を検討する。
     *
     * Windows の cmd.exe は CP932（Shift-JIS）で日本語エラーを返すため、そのまま
     * Log や JSON に渡すと Malformed UTF-8 で連鎖事故になる。Windows系の代表的な
     * エンコーディングからの推定変換を行い、UTF-8 として安全な文字列を返す。
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

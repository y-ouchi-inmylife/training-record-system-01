<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

/**
 * ログに書く直前にメールアドレスを伏せ字にする仕組み。
 *
 * メールの送信の失敗などで、SMTP サーバーのエラー文言には送信先のメアドが
 * 含まれることがある（例：「Expected response code 250 but got 550 … for
 * user@example.com」）。`$e->getMessage()` や `'exception' => $e` をそのまま
 * ログに書くと、そのメアドがログに残る。受け止めていない例外も Laravel が
 * 自動でログに書くので同じことが起こる。これを 1 か所でまとめて伏せ字にする。
 *
 * 使い方：`config/logging.php` のチャンネルに `tap` で登録する。
 *   'daily' => [
 *       ...
 *       'tap' => [App\Logging\MaskSensitiveData::class],
 *   ],
 *
 * 対象：
 *  - LogRecord の `message`（1 文のテキスト）
 *  - LogRecord の `context`・`extra`（配列。入れ子を含めて再帰的に）
 *  - context の中の `Throwable`：そのままだと `(string)$e` でスタックトレースと
 *    一緒にメアドが書き出されるので、クラス名・伏せ字した文言・ファイル・行・
 *    伏せ字したスタックトレース（文字列）の配列に置き換える。
 *
 * 置き換え：`***@domain.example`（ローカル部を伏せ、ドメインは残す）。
 * どのメールのサービスで失敗したかは調べられるようにする。
 */
final class MaskSensitiveData
{
    /**
     * メールアドレスを拾う正規表現。
     *
     * ローカル部: 英数字・ドット・+-_%、@ 以降: 英数字・ドット・ハイフンで、
     * 最低 1 つのドット＋トップレベル（英字 2 文字以上）を必須にする。
     * 過剰に拾いすぎてログが読めなくなることがないよう、一般的な形だけを拾う。
     */
    private const EMAIL_REGEX = '/[A-Za-z0-9._%+\-]+@([A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,})/';

    /**
     * Laravel の tap エントリポイント。
     * 受け取った Logger の裏の Monolog に、process() を processor として差し込む。
     */
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor([$this, 'process']);
    }

    /**
     * Monolog の processor。書き込む直前に LogRecord を加工する。
     */
    public function process(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->maskString($record->message),
            context: $this->maskArray($record->context),
            extra: $this->maskArray($record->extra),
        );
    }

    public function maskString(string $value): string
    {
        return (string) preg_replace(self::EMAIL_REGEX, '***@$1', $value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function maskArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = $this->maskValue($value);
        }

        return $result;
    }

    public function maskValue(mixed $value): mixed
    {
        if ($value instanceof Throwable) {
            return $this->maskThrowable($value);
        }
        if (is_string($value)) {
            return $this->maskString($value);
        }
        if (is_array($value)) {
            return $this->maskArray($value);
        }

        return $value;
    }

    /**
     * 例外を、原因の調査に必要な情報（クラス名・どこで起きたか）を残しつつ、
     * 文言とスタックトレースのメアドを伏せ字にした配列に置き換える。
     * 前の例外（getPrevious）があれば、同じく扱って `previous` に添える。
     *
     * @return array<string, mixed>
     */
    private function maskThrowable(Throwable $e): array
    {
        $data = [
            'class' => get_class($e),
            'message' => $this->maskString($e->getMessage()),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $this->maskString($e->getTraceAsString()),
        ];

        if ($previous = $e->getPrevious()) {
            $data['previous'] = $this->maskThrowable($previous);
        }

        return $data;
    }
}

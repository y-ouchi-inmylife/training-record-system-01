<?php

namespace Tests\Unit\Logging;

use App\Logging\MaskSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * app/Logging/MaskSensitiveData の Unit テスト。
 *
 * ログに書く直前にメールアドレスを `***@ドメイン` に伏せ字にする仕組み。
 * message・context（入れ子を含む）・例外（Throwable）の文言／スタックトレースを対象にする。
 * ドメインは残して「どのメールのサービスで失敗したか」は追えるようにしつつ、
 * メールアドレスでない文字列（URL・日本語の文・数字など）は変えない。
 */
class MaskSensitiveDataTest extends TestCase
{
    private function makeRecord(string $message, array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Error,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    public function test_messageのメールアドレスが伏せ字になる(): void
    {
        $processor = new MaskSensitiveData();
        $record = $this->makeRecord('送信失敗: user@example.com に送れませんでした');

        $after = $processor->process($record);

        $this->assertSame('送信失敗: ***@example.com に送れませんでした', $after->message);
    }

    public function test_複数のメールアドレスがすべて伏せ字になる(): void
    {
        $processor = new MaskSensitiveData();
        $record = $this->makeRecord('from foo+bar@example.co.jp to bar.baz-99@sub.example.com');

        $after = $processor->process($record);

        $this->assertSame('from ***@example.co.jp to ***@sub.example.com', $after->message);
    }

    public function test_contextの文字列と入れ子の配列のメールが伏せ字になる(): void
    {
        $processor = new MaskSensitiveData();
        $record = $this->makeRecord('error', [
            'client_id' => 42,
            'email' => 'alice@example.com',
            'raw' => 'SMTP: 550 bob@example.net is unknown',
            'nested' => [
                'inner' => 'carol@example.org に連絡',
                'deeper' => [
                    'dave@example.com',
                ],
            ],
        ]);

        $after = $processor->process($record);

        $this->assertSame(42, $after->context['client_id']);
        $this->assertSame('***@example.com', $after->context['email']);
        $this->assertSame('SMTP: 550 ***@example.net is unknown', $after->context['raw']);
        $this->assertSame('***@example.org に連絡', $after->context['nested']['inner']);
        $this->assertSame('***@example.com', $after->context['nested']['deeper'][0]);
    }

    public function test_contextの例外の文言とスタックトレースのメアドが伏せ字になりクラス名とファイル行が残る(): void
    {
        $processor = new MaskSensitiveData();

        // 実際に発生させた例外にスタックトレースを持たせる
        try {
            throw new RuntimeException('SMTP: user@example.com に送れませんでした');
        } catch (RuntimeException $e) {
            $exception = $e;
        }

        $record = $this->makeRecord('mail failed', ['exception' => $exception]);
        $after = $processor->process($record);

        $this->assertIsArray($after->context['exception']);
        $this->assertSame(RuntimeException::class, $after->context['exception']['class']);
        $this->assertSame('SMTP: ***@example.com に送れませんでした', $after->context['exception']['message']);
        $this->assertSame($exception->getFile(), $after->context['exception']['file']);
        $this->assertSame($exception->getLine(), $after->context['exception']['line']);
        $this->assertIsString($after->context['exception']['trace']);
        // 元のメアドが trace からも消えていること
        $this->assertStringNotContainsString('user@example.com', $after->context['exception']['trace']);
    }

    public function test_前の例外もたどって伏せ字になる(): void
    {
        $processor = new MaskSensitiveData();

        try {
            try {
                throw new RuntimeException('原因: inner@example.com');
            } catch (RuntimeException $inner) {
                throw new RuntimeException('外側: outer@example.net', 0, $inner);
            }
        } catch (RuntimeException $e) {
            $exception = $e;
        }

        $record = $this->makeRecord('nested fail', ['exception' => $exception]);
        $after = $processor->process($record);

        $this->assertSame('外側: ***@example.net', $after->context['exception']['message']);
        $this->assertArrayHasKey('previous', $after->context['exception']);
        $this->assertSame('原因: ***@example.com', $after->context['exception']['previous']['message']);
        $this->assertSame(RuntimeException::class, $after->context['exception']['previous']['class']);
    }

    public function test_メールアドレスでない文字列は変わらない(): void
    {
        $processor = new MaskSensitiveData();
        $record = $this->makeRecord('データベースへの接続に失敗しました', [
            'url' => 'https://example.com/clients/42?token=abc123',
            'ja' => '山田太郎さんの処理に失敗',
            'number' => 1234567890,
            'id' => 'client-42',
            'exit_code' => 1,
            'path' => '/var/www/app/storage/logs/laravel.log',
            // @ を含むが TLD がない形は拾わない（ユーザー名@ホスト名〔ドットなし〕など）
            'ssh' => 'ubuntu@sakura-prod-01',
            // メンション風（@ の後がドットなし）
            'mention' => '@admin さん',
        ]);

        $after = $processor->process($record);

        $this->assertSame('データベースへの接続に失敗しました', $after->message);
        $this->assertSame('https://example.com/clients/42?token=abc123', $after->context['url']);
        $this->assertSame('山田太郎さんの処理に失敗', $after->context['ja']);
        $this->assertSame(1234567890, $after->context['number']);
        $this->assertSame('client-42', $after->context['id']);
        $this->assertSame(1, $after->context['exit_code']);
        $this->assertSame('/var/www/app/storage/logs/laravel.log', $after->context['path']);
        $this->assertSame('ubuntu@sakura-prod-01', $after->context['ssh']);
        $this->assertSame('@admin さん', $after->context['mention']);
    }

    public function test_extraの中のメアドも伏せ字になる(): void
    {
        $processor = new MaskSensitiveData();
        $record = $this->makeRecord('msg', [], ['request_id' => 'xyz', 'actor' => 'alice@example.com']);

        $after = $processor->process($record);

        $this->assertSame('xyz', $after->extra['request_id']);
        $this->assertSame('***@example.com', $after->extra['actor']);
    }
}

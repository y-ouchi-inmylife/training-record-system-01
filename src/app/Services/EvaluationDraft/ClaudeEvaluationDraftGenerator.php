<?php

namespace App\Services\EvaluationDraft;

use Anthropic\Client;
use App\Models\TraineePersonalityDiagnosisResult;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;

/**
 * Claude（Anthropic）でアドバイスとスタッフ用メモを自動生成する（2026-10-19 追加。EvaluationDraftGenerator の実装。2026-10-19 変更：呼び名を「下書き」から変えた）。
 *
 * 音声の要約（App\Services\SummarizationService）と同じ作り：anthropic-ai/sdk、キー・モデルは config('services.anthropic.*')
 * （ANTHROPIC_API_KEY・ANTHROPIC_MODEL）。違うところ：
 * - 待ち時間の上限は 60 秒（その場で呼んで画面で待つため）。SDK 0.6.0 の timeout の指定は HTTP の呼び出しに使われないので、
 *   時間切れを付けた Guzzle を transporter として渡す
 * - 再試行しない（maxRetries 0。費用のため。SDK の既定は 2 回まで再試行する）
 * - 出す長さの上限（maxTokens）は 3000（お客様用 800 字＋スタッフ用 300 字の日本語が切れずに収まる量）
 * - 書式の決まり（PersonalityEvaluationPrompt::FORMAT_RULES。Markdown の記号を使わない）を system に入れ、指示文はユーザーの発言で渡す
 *   （2026-10-19 追加：AI が Markdown の記号で書き、会員様の画面に記号が出てしまうため）
 * - ログ（laravel.log）には、結果の id・モデル・入力と出力のトークン数・かかった時間・止まった理由だけを残す。
 *   送った文・返ってきた文・名前は残さない。失敗のときはエラーの内容を残す
 * - 2026-10-20 変更：3 社（Claude・OpenAI・Gemini）を比べるため、ボタンを出すかの判定（isConfigured）を足し、ログに AI の名前を足し、
 *   エラーの内容から API キーを伏せるようにした（EvaluationDraftProviders。ほかの 2 社の実装と同じ決まり）
 */
class ClaudeEvaluationDraftGenerator implements EvaluationDraftGenerator
{
    /** 出す長さの上限（トークン） */
    public const MAX_TOKENS = 3000;

    /** 待ち時間の上限（秒） */
    public const TIMEOUT_SECONDS = 60;

    /** AI の名前（ログ・EvaluationDraftProviders。2026-10-20 追加） */
    public const NAME = 'claude';

    /** キーとモデルの設定がそろっているか（そろっていなければ S-0313 にボタンを出さない。2026-10-20 追加） */
    public static function isConfigured(): bool
    {
        return filled(config('services.anthropic.api_key')) && filled(config('services.anthropic.model'));
    }

    /** @param  ClientInterface|null  $transporter  HTTP の送り手（テストで差し替える。null なら時間切れを付けた Guzzle） */
    public function __construct(private readonly ?ClientInterface $transporter = null) {}

    public function generate(TraineePersonalityDiagnosisResult $result): EvaluationDraft
    {
        $apiKey = config('services.anthropic.api_key');
        $model = (string) config('services.anthropic.model');
        if (empty($apiKey)) {
            Log::error('アドバイスのAI自動生成：Anthropic の API キーが設定されていません', ['result_id' => $result->id, 'provider' => self::NAME]);
            throw new EvaluationDraftFailedException('Anthropic API key is not set.');
        }

        $prompt = PersonalityEvaluationPrompt::render($result);
        $started = microtime(true);
        try {
            $client = new Client(apiKey: $apiKey, requestOptions: [
                'maxRetries' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'transporter' => $this->transporter ?? new GuzzleClient(['timeout' => self::TIMEOUT_SECONDS, 'connect_timeout' => 10]),
            ]);
            $response = $client->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages: [['role' => 'user', 'content' => $prompt]],
                model: $model,
                system: PersonalityEvaluationPrompt::FORMAT_RULES,
            );
        } catch (\Throwable $e) {
            Log::error('アドバイスのAI自動生成：呼び出しに失敗しました', [
                'result_id' => $result->id,
                'provider' => self::NAME,
                'model' => $model,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => str_replace((string) $apiKey, '[REDACTED]', get_class($e).': '.$e->getMessage()),
            ]);
            throw new EvaluationDraftFailedException('AI の呼び出しに失敗しました。', previous: $e);
        }

        $text = '';
        foreach ($response->content as $block) {
            $text .= $block->text ?? '';
        }
        Log::info('アドバイスのAI自動生成：作成しました', [
            'result_id' => $result->id,
            'provider' => self::NAME,
            'model' => $model,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'stop_reason' => $response->stopReason,
        ]);

        try {
            return EvaluationDraft::fromResponse($text);
        } catch (EvaluationDraftFailedException $e) {
            Log::error('アドバイスのAI自動生成：返事が空でした', ['result_id' => $result->id, 'provider' => self::NAME, 'model' => $model]);
            throw $e;
        }
    }
}

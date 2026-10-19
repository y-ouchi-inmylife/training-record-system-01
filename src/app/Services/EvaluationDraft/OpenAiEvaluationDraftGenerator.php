<?php

namespace App\Services\EvaluationDraft;

use App\Models\TraineePersonalityDiagnosisResult;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Log;
use OpenAI;
use Psr\Http\Client\ClientInterface;

/**
 * OpenAI でアドバイスとスタッフ用メモを自動生成する（2026-10-20 追加。EvaluationDraftGenerator の実装。architecture.md 2-5-1）。
 *
 * Claude の実装（ClaudeEvaluationDraftGenerator）と同じ決まりにそろえる：
 * - 書式の決まり（PersonalityEvaluationPrompt::FORMAT_RULES）を system の発言、指示文（データを差し込んだもの）を user の発言で渡す
 * - 待ち時間の上限は 60 秒（時間切れを付けた Guzzle を HTTP クライアントとして渡す。文字起こしの OPENAI_REQUEST_TIMEOUT には左右されない）。
 *   再試行しない（openai-php は再試行しない）
 * - 出す長さの上限は max_completion_tokens 3000（Claude の MAX_TOKENS と同じ）
 * - ログ（laravel.log）には、結果の id・AI の名前・モデル・入力と出力のトークン数・かかった時間・止まった理由だけを残す。
 *   送った文・返ってきた文・名前・API キーは残さない。失敗のときはエラーの内容を API キーを伏せて残す
 *
 * キーは文字起こしと同じ OPENAI_API_KEY（config('openai.api_key')。openai-php/laravel の設定）、
 * 文を書くモデルは OPENAI_EVALUATION_MODEL（config('services.openai.evaluation_model')。既定の値は持たない）
 */
class OpenAiEvaluationDraftGenerator implements EvaluationDraftGenerator
{
    /** AI の名前（ログ・EvaluationDraftProviders） */
    public const NAME = 'openai';

    /** 出す長さの上限（トークン。Claude と同じ） */
    public const MAX_TOKENS = 3000;

    /** 待ち時間の上限（秒） */
    public const TIMEOUT_SECONDS = 60;

    /** @param  ClientInterface|null  $httpClient  HTTP の送り手（テストで差し替える。null なら時間切れを付けた Guzzle） */
    public function __construct(private readonly ?ClientInterface $httpClient = null) {}

    /** キーとモデルの設定がそろっているか（そろっていなければ S-0313 にボタンを出さない） */
    public static function isConfigured(): bool
    {
        return filled(config('openai.api_key')) && filled(config('services.openai.evaluation_model'));
    }

    public function generate(TraineePersonalityDiagnosisResult $result): EvaluationDraft
    {
        $apiKey = (string) config('openai.api_key');
        $model = (string) config('services.openai.evaluation_model');
        if (! self::isConfigured()) {
            Log::error('アドバイスのAI自動生成：キーかモデルが設定されていません', ['result_id' => $result->id, 'provider' => self::NAME]);
            throw new EvaluationDraftFailedException('OpenAI key or model is not set.');
        }

        $prompt = PersonalityEvaluationPrompt::render($result);
        $started = microtime(true);
        try {
            $factory = OpenAI::factory()
                ->withApiKey($apiKey)
                ->withHttpClient($this->httpClient ?? new GuzzleClient(['timeout' => self::TIMEOUT_SECONDS, 'connect_timeout' => 10]));
            if (filled(config('openai.organization'))) {
                $factory = $factory->withOrganization((string) config('openai.organization'));
            }
            if (filled(config('openai.project'))) {
                $factory = $factory->withProject((string) config('openai.project'));
            }
            if (filled(config('openai.base_uri'))) {
                $factory = $factory->withBaseUri((string) config('openai.base_uri'));
            }
            $response = $factory->make()->chat()->create([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => PersonalityEvaluationPrompt::FORMAT_RULES],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_completion_tokens' => self::MAX_TOKENS,
            ]);
        } catch (\Throwable $e) {
            Log::error('アドバイスのAI自動生成：呼び出しに失敗しました', [
                'result_id' => $result->id,
                'provider' => self::NAME,
                'model' => $model,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => str_replace($apiKey, '[REDACTED]', get_class($e).': '.$e->getMessage()),
            ]);
            throw new EvaluationDraftFailedException('AI の呼び出しに失敗しました。', previous: $e);
        }

        $choice = $response->choices[0] ?? null;
        $text = (string) ($choice?->message->content ?? '');
        Log::info('アドバイスのAI自動生成：作成しました', [
            'result_id' => $result->id,
            'provider' => self::NAME,
            'model' => $model,
            'input_tokens' => $response->usage?->promptTokens,
            'output_tokens' => $response->usage?->completionTokens,
            'reasoning_tokens' => $response->usage?->completionTokensDetails?->reasoningTokens,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'stop_reason' => $choice?->finishReason,
        ]);

        try {
            return EvaluationDraft::fromResponse($text);
        } catch (EvaluationDraftFailedException $e) {
            Log::error('アドバイスのAI自動生成：返事が空でした', ['result_id' => $result->id, 'provider' => self::NAME, 'model' => $model]);
            throw $e;
        }
    }
}

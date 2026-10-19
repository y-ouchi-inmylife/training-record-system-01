<?php

namespace App\Services\EvaluationDraft;

use App\Models\TraineePersonalityDiagnosisResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gemini（Google）でアドバイスとスタッフ用メモを自動生成する（2026-10-20 追加。EvaluationDraftGenerator の実装。architecture.md 2-5-1）。
 *
 * SDK は入れず、Laravel の HTTP クライアントで generateContent を呼ぶ。Claude の実装（ClaudeEvaluationDraftGenerator）と同じ決まりにそろえる：
 * - 書式の決まり（PersonalityEvaluationPrompt::FORMAT_RULES）を systemInstruction、指示文（データを差し込んだもの）を contents（role: user）で渡す
 * - API キーは URL に入れず、ヘッダー x-goog-api-key で送る（URL がログに残っても漏れないように）
 * - 待ち時間の上限は 60 秒。再試行しない（Laravel の HTTP クライアントの retry を付けない）
 * - 出す長さの上限は generationConfig.maxOutputTokens に PersonalityEvaluationPrompt::MAX_OUTPUT_TOKENS（3 社で同じ値。考えた分も含まれる。
 *   2026-10-20 変更：その前はここに 3000）
 * - ログ（laravel.log）には、結果の id・AI の名前・モデル・入力と出力のトークン数・かかった時間・止まった理由だけを残す。
 *   送った文・返ってきた文・名前・API キーは残さない。失敗のときはエラーの内容を API キーを伏せて残す
 *
 * キーは GEMINI_API_KEY（config('services.gemini.api_key')）、モデルは GEMINI_MODEL（config('services.gemini.model')。既定の値は持たない）
 */
class GeminiEvaluationDraftGenerator implements EvaluationDraftGenerator
{
    /** AI の名前（ログ・EvaluationDraftProviders） */
    public const NAME = 'gemini';

    /** 待ち時間の上限（秒） */
    public const TIMEOUT_SECONDS = 60;

    /**
     * 最後まで書き終えたときの止まった理由（2026-10-20 追加。EvaluationDraft::ensureCompleted）。Gemini の API の資料の FinishReason のうち
     * STOP（自然な止まり方、または指定した停止の文字列）。MAX_TOKENS・SAFETY・RECITATION・CONTINUATION などは失敗
     */
    public const COMPLETED_STOP_REASONS = ['STOP'];

    /** generateContent の URL の頭（モデルの名前と :generateContent を後ろに付ける）。キーは付けない */
    public const ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** キーとモデルの設定がそろっているか（そろっていなければ S-0313 にボタンを出さない） */
    public static function isConfigured(): bool
    {
        return filled(config('services.gemini.api_key')) && filled(config('services.gemini.model'));
    }

    public function generate(TraineePersonalityDiagnosisResult $result): EvaluationDraft
    {
        $apiKey = (string) config('services.gemini.api_key');
        $model = (string) config('services.gemini.model');
        if (! self::isConfigured()) {
            Log::error('アドバイスのAI自動生成：キーかモデルが設定されていません', ['result_id' => $result->id, 'provider' => self::NAME]);
            throw new EvaluationDraftFailedException('Gemini key or model is not set.');
        }

        $prompt = PersonalityEvaluationPrompt::render($result);
        $started = microtime(true);
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(10)
                ->post(self::ENDPOINT_BASE.rawurlencode($model).':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => PersonalityEvaluationPrompt::FORMAT_RULES]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['maxOutputTokens' => PersonalityEvaluationPrompt::MAX_OUTPUT_TOKENS],
                ])
                ->throw();
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

        $candidate = $response->json('candidates.0') ?? [];
        // 考えた過程（thought: true）の部分は除き、答えの文だけをつなぐ
        $text = collect($candidate['content']['parts'] ?? [])
            ->reject(fn ($part) => ($part['thought'] ?? false) === true)
            ->map(fn ($part) => (string) ($part['text'] ?? ''))
            ->implode('');
        $stopReason = $candidate['finishReason'] ?? null;
        $logContext = [
            'result_id' => $result->id,
            'provider' => self::NAME,
            'model' => $model,
            'input_tokens' => $response->json('usageMetadata.promptTokenCount'),
            'output_tokens' => $response->json('usageMetadata.candidatesTokenCount'),
            'thoughts_tokens' => $response->json('usageMetadata.thoughtsTokenCount'),
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'stop_reason' => $stopReason,
        ];
        // 最後まで書き終えていなければ失敗（2026-10-20 追加。答えを「#####」で分ける前に確かめる）
        EvaluationDraft::ensureCompleted(is_string($stopReason) ? $stopReason : null, self::COMPLETED_STOP_REASONS, $logContext);
        Log::info('アドバイスのAI自動生成：作成しました', $logContext);

        try {
            return EvaluationDraft::fromResponse($text);
        } catch (EvaluationDraftFailedException $e) {
            Log::error('アドバイスのAI自動生成：返事が空でした', ['result_id' => $result->id, 'provider' => self::NAME, 'model' => $model]);
            throw $e;
        }
    }
}

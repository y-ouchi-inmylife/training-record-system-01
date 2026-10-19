<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        // 愛犬性格診断のアドバイスの AI の自動生成（S-0313 の［AIで自動生成（OpenAI）］）で文を書くモデル（2026-10-20 追加）。
        // 既定の値は持たない（空ならボタンを出さない）。キーは文字起こしと同じ OPENAI_API_KEY（config/openai.php）
        'evaluation_model' => env('OPENAI_EVALUATION_MODEL'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    ],

    // 愛犬性格診断のアドバイスの AI の自動生成（S-0313 の［AIで自動生成（Gemini）］。2026-10-20 追加）。
    // キーは URL に入れずヘッダー x-goog-api-key で送る（App\Services\EvaluationDraft\GeminiEvaluationDraftGenerator）。
    // モデルの既定の値は持たない（キーかモデルが空ならボタンを出さない）。
    // 2026-10-20 変更：AI の会社を選ぶ evaluation_draft.driver（EVALUATION_DRAFT_DRIVER）は外した（押したボタンで選ぶ。EvaluationDraftProviders）
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL'),
    ],
];

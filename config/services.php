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

'gemini' => [
    'key' => env('GEMINI_API_KEY'),
    'url' => 'https://generativelanguage.googleapis.com/v1beta/models/' . env('GEMINI_MODEL', 'gemini-2.5-flash') . ':generateContent',
    'timeout_seconds' => env('GEMINI_TIMEOUT_SECONDS', 90),
    'connect_timeout_seconds' => env('GEMINI_CONNECT_TIMEOUT_SECONDS', 15),
    'request_retries' => env('GEMINI_REQUEST_RETRIES', 0),
    'max_output_tokens' => env('GEMINI_MAX_OUTPUT_TOKENS', 1024),
    'booking_chunk_size' => env('GEMINI_BOOKING_CHUNK_SIZE', 5),
    'stock_piece_fetch_limit' => env('GEMINI_STOCK_PIECE_FETCH_LIMIT', 10),
    'booking_include_history' => env('GEMINI_BOOKING_INCLUDE_HISTORY', false),
    'enable_compact_retry_prompt' => env('GEMINI_ENABLE_COMPACT_RETRY_PROMPT', false),
],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];

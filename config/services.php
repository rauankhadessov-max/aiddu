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
        'key' => env('OPENAI_API_KEY'),

        // В Azure OpenAI это не название модели, а имя развёртывания,
        // которое задаёт администратор ресурса.
        'model' => env('OPENAI_MODEL', 'gpt-5.6-terra'),

        // Полный адрес Responses API. По умолчанию — OpenAI напрямую.
        // Для Azure: https://<ресурс>.cognitiveservices.azure.com/openai/responses?api-version=<версия>
        'responses_url' => env('OPENAI_RESPONSES_URL', 'https://api.openai.com/v1/responses'),

        // Способ передачи ключа: bearer (OpenAI) или api-key (Azure).
        'auth_header' => env('OPENAI_AUTH_HEADER', 'bearer'),
    ],



];

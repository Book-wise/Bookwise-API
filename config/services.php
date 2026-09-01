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

    'woocommerce' => [
        'consumer_key' => env('WC_CONSUMER_KEY'),
        'consumer_secret' => env('WC_CONSUMER_SECRET'),
        'webhook_secret' => env('WC_WEBHOOK_SECRET'),
        'store_url' => env('WC_STORE_URL'),
    ],

    'carlitox' => [
        'webhook_url' => env('CARLITOX_WEBHOOK_URL'),
    ],

    'frontend' => [
        // Base URL of the frontend, used to build email-verification links
        // (FRONTEND_URL/verify-email?token=...) delivered via carlitox.
        // Blank value makes the verification job fail loudly (R2.4/D1).
        'url' => env('FRONTEND_URL'),
    ],

];

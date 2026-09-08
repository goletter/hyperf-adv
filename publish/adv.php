<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // 平台数字别名：AdvFactory::make(0, $token)
    'platforms' => [
        0 => 'facebook',
        1 => 'tiktok',
        3 => 'google',
    ],

    'facebook' => [
        'api_version' => 'v24.0',
        // OAuth 应用授权（dialog/oauth + callback）
        // 'app_id' => env('FACEBOOK_APP_ID', ''),
        // 'app_secret' => env('FACEBOOK_APP_SECRET', ''),
        // 'redirect_uri' => env('FACEBOOK_REDIRECT_URI', ''),
        // 'scopes' => 'ads_management,ads_read,business_management,email,public_profile',
        // 可选：覆盖账户默认 business_info
        // 'default_business_info' => [
        //     'business_country_code' => 'US',
        //     'business_street' => '...',
        //     'business_city' => '...',
        //     'business_state' => '...',
        //     'business_zip' => '...',
        // ],
    ],

    'tiktok' => [
        'base_uri' => 'https://business-api.tiktok.com',
        // OAuth 应用授权（portal/auth + auth_code 换 token）
        // 'app_id' => env('TIKTOK_APP_ID', ''),
        // 'secret' => env('TIKTOK_APP_SECRET', ''),
        // 'redirect_uri' => env('TIKTOK_REDIRECT_URI', ''),
    ],

    'google' => [
        'api_version' => 'v19',
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN', ''),
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID', ''),
        'base_uri' => 'https://googleads.googleapis.com',
        // OAuth2 应用授权（accounts.google.com + oauth2/token）
        // 'client_id' => env('GOOGLE_ADS_CLIENT_ID', ''),
        // 'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET', ''),
        // 'redirect_uri' => env('GOOGLE_ADS_REDIRECT_URI', ''),
        // 'scopes' => ['https://www.googleapis.com/auth/adwords'],
    ],
];

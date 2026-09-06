<?php

declare(strict_types=1);

return [
    // 平台数字别名：AdvFactory::make(1, $token)
    'platforms' => [
        1 => 'facebook',
        2 => 'google',
        3 => 'tiktok',
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
    ],

    'google' => [
        'api_version' => 'v19',
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN', ''),
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID', ''),
        'base_uri' => 'https://googleads.googleapis.com',
    ],
];

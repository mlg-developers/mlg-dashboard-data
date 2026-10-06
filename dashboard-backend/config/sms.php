<?php

return [
    'api_key'    => env('SMS_API_KEY', ''),
    'api_secret' => env('SMS_API_SECRET', ''),
    'sender_id'  => env('SMS_SENDER_ID', 'MLOGANZILA'),
    'base_url'   => env('SMS_BASE_URL', 'https://messaging.kilakona.co.tz/api/v1/vendor/message'),
];

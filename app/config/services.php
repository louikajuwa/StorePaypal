<?php

return [
    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret'    => env('PAYPAL_SECRET'),
        'base_url'  => env('PAYPAL_BASE_URL', 'https://api-m.sandbox.paypal.com'),
    ],
    'realtime' => [
        'url' => env('REALTIME_SERVER_URL', 'http://node:3000'),
        'secret' => env('REALTIME_API_SECRET', 'realtime-secret-key'),
    ],
];

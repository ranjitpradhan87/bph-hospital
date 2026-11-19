<?php

/*
 | Online payment for the booking advance.
 |  - "razorpay": real payments. Needs the key id/secret (and webhook secret) from the Razorpay dashboard.
 |  - "simulate": no money moves; the booking page shows a "Simulate payment" button. Refused in production.
 */
return [
    'driver' => env('PAYMENT_DRIVER', env('APP_ENV') === 'production' ? 'razorpay' : 'simulate'),

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        'api' => env('RAZORPAY_API', 'https://api.razorpay.com/v1'),
    ],
];

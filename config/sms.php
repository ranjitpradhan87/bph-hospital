<?php

/*
 | Text messages. "log" records the message in the sms_messages table without sending (local development);
 | "msg91" sends through MSG91's Flow API. Indian carriers only deliver DLT-registered templates, so each
 | message below has a MSG91 template id whose variables (var1, var2, ...) follow the order in "vars".
 */
return [
    'driver' => env('SMS_DRIVER', 'log'),

    'msg91' => [
        'auth_key' => env('MSG91_AUTH_KEY'),
        'endpoint' => env('MSG91_ENDPOINT', 'https://control.msg91.com/api/v5/flow/'),
    ],

    'templates' => [
        'booking_confirmed' => [
            'msg91_template_id' => env('MSG91_TEMPLATE_BOOKING'),
            'vars' => ['hospital', 'number', 'doctor', 'when', 'payment'],
            'body' => '{hospital}: Your appointment {number} with {doctor} on {when} is confirmed. {payment}',
        ],
        'account_created' => [
            'msg91_template_id' => env('MSG91_TEMPLATE_ACCOUNT'),
            'vars' => ['hospital', 'mobile', 'link'],
            'body' => '{hospital}: Your patient account is ready. Sign in with {mobile}. Set your password here (valid 7 days): {link}',
        ],
    ],
];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Stripe, Mailgun, Mandrill, and others. This file provides a sane
    | default location for this type of information, allowing packages
    | to have a conventional place to find your various credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
    ],

    'mandrill' => [
        'secret' => env('MANDRILL_SECRET'),
    ],

    'ses' => [
        'key'    => env('SES_KEY'),
        'secret' => env('SES_SECRET'),
        'region' => 'us-east-1',
    ],

    'stripe' => [
        'model'  => App\User::class,
        'key'    => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
    ],

    'weibo' => [
        'AppId'       => env('WEIBO_APP_ID'),
        'AppSecret'   => env('WEIBO_APP_SECRET'),
        'CallbackUrl' => env('WEIBO_CALLBACK_URL'),
    ],

    'qq' => [
        'AppId'       => env('QQ_APP_ID'),
        'AppSecret'   => env('QQ_APP_SECRET'),
        'CallbackUrl' => env('QQ_CALLBACK_URL'),
    ],

    'weixin' => [
        'AppId'       => env('WEIXIN_APP_ID'),
        'AppSecret'   => env('WEIXIN_APP_SECRET'),
        'CallbackUrl' => env('WEIXIN_CALLBACK_URL'),
    ],

];

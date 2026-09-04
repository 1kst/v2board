<?php

/*
 * Three-brand runtime configuration.
 *
 * Keep credentials in the server environment, never in this repository.  Each
 * value falls back to the legacy v2board configuration while site 1 is being
 * migrated, so existing single-site installations continue to work.
 */
return [
    1 => [
        'key' => env('SITE_1_KEY'),
        'name' => env('SITE_1_NAME'),
        'url' => env('SITE_1_URL'),
        'email_template' => env('SITE_1_EMAIL_TEMPLATE', 'site1'),
        'email_host' => env('SITE_1_EMAIL_HOST'),
        'email_port' => env('SITE_1_EMAIL_PORT'),
        'email_encryption' => env('SITE_1_EMAIL_ENCRYPTION'),
        'email_username' => env('SITE_1_EMAIL_USERNAME'),
        'email_password' => env('SITE_1_EMAIL_PASSWORD'),
        'email_from_address' => env('SITE_1_EMAIL_FROM_ADDRESS'),
    ],
    2 => [
        'key' => env('SITE_2_KEY'),
        'name' => env('SITE_2_NAME'),
        'url' => env('SITE_2_URL'),
        'email_template' => env('SITE_2_EMAIL_TEMPLATE', 'site2'),
        'email_host' => env('SITE_2_EMAIL_HOST'),
        'email_port' => env('SITE_2_EMAIL_PORT'),
        'email_encryption' => env('SITE_2_EMAIL_ENCRYPTION'),
        'email_username' => env('SITE_2_EMAIL_USERNAME'),
        'email_password' => env('SITE_2_EMAIL_PASSWORD'),
        'email_from_address' => env('SITE_2_EMAIL_FROM_ADDRESS'),
    ],
    3 => [
        'key' => env('SITE_3_KEY'),
        'name' => env('SITE_3_NAME'),
        'url' => env('SITE_3_URL'),
        'email_template' => env('SITE_3_EMAIL_TEMPLATE', 'site3'),
        'email_host' => env('SITE_3_EMAIL_HOST'),
        'email_port' => env('SITE_3_EMAIL_PORT'),
        'email_encryption' => env('SITE_3_EMAIL_ENCRYPTION'),
        'email_username' => env('SITE_3_EMAIL_USERNAME'),
        'email_password' => env('SITE_3_EMAIL_PASSWORD'),
        'email_from_address' => env('SITE_3_EMAIL_FROM_ADDRESS'),
    ],
];

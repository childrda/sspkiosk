<?php

return [

    'enabled' => filter_var(
        env('AD_ENABLED', false),
        FILTER_VALIDATE_BOOL
    ),

    'hosts' => array_values(array_filter(
        array_map(
            'trim',
            explode(',', (string) env('AD_HOSTS', ''))
        )
    )),

    'port' => (int) env('AD_PORT', 636),

    'base_dn' => env('AD_BASE_DN'),

    'student_ou' => env('AD_STUDENT_OU'),

    'username' => env('AD_USERNAME'),

    'password' => env('AD_PASSWORD'),

    'use_ssl' => true,

    'timeout' => (int) env('AD_TIMEOUT', 10),

];

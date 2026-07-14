<?php

return [

    'allowed_networks' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('KIOSK_ALLOWED_NETWORKS', ''))
    ))),

    'hmac_tolerance_seconds' => (int) env('KIOSK_HMAC_TOLERANCE_SECONDS', 300),

    'heartbeat_interval_seconds' => (int) env('KIOSK_HEARTBEAT_INTERVAL_SECONDS', 60),

    'heartbeat_expires_after_seconds' => (int) env('KIOSK_HEARTBEAT_EXPIRES_AFTER_SECONDS', 300),

    'require_active_heartbeat' => filter_var(
        env('KIOSK_REQUIRE_ACTIVE_HEARTBEAT', false),
        FILTER_VALIDATE_BOOL
    ),

    'enable_mtls' => filter_var(env('KIOSK_ENABLE_MTLS', false), FILTER_VALIDATE_BOOL),

    'enable_client_certificate_check' => filter_var(
        env('KIOSK_ENABLE_CLIENT_CERTIFICATE_CHECK', false),
        FILTER_VALIDATE_BOOL
    ),

    'enrollment_code_expires_minutes' => (int) env('KIOSK_ENROLLMENT_CODE_EXPIRES_MINUTES', 60),

    'registration_session_kiosk_key' => env('REGISTRATION_KIOSK_SESSION_KEY', 'ssp_registration_kiosk_id'),

    'reset_session_student_key' => env('KIOSK_RESET_SESSION_STUDENT_KEY', 'ssp_reset_student_id'),

    'reset_session_questions_key' => env('KIOSK_RESET_SESSION_QUESTIONS_KEY', 'ssp_reset_presented_questions'),

    'reset_session_photo_key' => env('KIOSK_RESET_SESSION_PHOTO_KEY', 'ssp_reset_photo_id'),

    'reset_session_challenge_score_key' => env('KIOSK_RESET_SESSION_CHALLENGE_SCORE_KEY', 'ssp_reset_challenge_score'),

    'active_reset_request_session_key' => env('KIOSK_ACTIVE_RESET_REQUEST_SESSION_KEY', 'ssp_active_reset_request_id'),

    'status_poll_interval_seconds' => (int) env('KIOSK_STATUS_POLL_INTERVAL_SECONDS', 5),

    /*
    | Staleness of last_seen_at is only meaningful during school hours.
    | Outside this window, ChromeOS sleep is expected and kiosks show as "Asleep".
    */
    'staleness_window' => [
        'start' => env('KIOSK_STALENESS_WINDOW_START', '07:00'),
        'end' => env('KIOSK_STALENESS_WINDOW_END', '16:00'),
        'timezone' => env('KIOSK_STALENESS_TIMEZONE', 'America/New_York'),
    ],

];

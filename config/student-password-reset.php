<?php

return [

    'reset_password_mode' => env('RESET_PASSWORD_MODE', 'temporary_generated'),

    'reset_request_expiration_minutes' => (int) env('RESET_REQUEST_EXPIRATION_MINUTES', 10),

    'temp_password_display_seconds' => (int) env('TEMP_PASSWORD_DISPLAY_SECONDS', 90),

    'pending_password' => [
        'display_seconds' => (int) env('PENDING_PASSWORD_DISPLAY_SECONDS', 90),
        'copy_notice_enabled' => filter_var(env('PENDING_PASSWORD_COPY_NOTICE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'encryption_enabled' => filter_var(env('PENDING_PASSWORD_ENCRYPTION_ENABLED', true), FILTER_VALIDATE_BOOL),
        'delete_on_approval' => filter_var(env('DELETE_PENDING_PASSWORD_ON_APPROVAL', true), FILTER_VALIDATE_BOOL),
        'delete_on_denial' => filter_var(env('DELETE_PENDING_PASSWORD_ON_DENIAL', true), FILTER_VALIDATE_BOOL),
        'delete_on_expiration' => filter_var(env('DELETE_PENDING_PASSWORD_ON_EXPIRATION', true), FILTER_VALIDATE_BOOL),
        'delete_on_google_failure' => filter_var(env('DELETE_PENDING_PASSWORD_ON_GOOGLE_FAILURE', true), FILTER_VALIDATE_BOOL),
        'retain_on_google_failure' => filter_var(env('RETAIN_PENDING_PASSWORD_ON_GOOGLE_FAILURE', false), FILTER_VALIDATE_BOOL),
    ],

    'google_force_change_at_next_login' => [
        'temporary_generated' => filter_var(env('GOOGLE_FORCE_CHANGE_AT_NEXT_LOGIN_TEMPORARY', true), FILTER_VALIDATE_BOOL),
        'student_selected' => filter_var(env('GOOGLE_FORCE_CHANGE_AT_NEXT_LOGIN_STUDENT_SELECTED', false), FILTER_VALIDATE_BOOL),
    ],

    'password_policy' => [
        'min_length' => (int) env('PASSWORD_MIN_LENGTH', 12),
        'require_uppercase' => filter_var(env('PASSWORD_REQUIRE_UPPERCASE', true), FILTER_VALIDATE_BOOL),
        'require_lowercase' => filter_var(env('PASSWORD_REQUIRE_LOWERCASE', true), FILTER_VALIDATE_BOOL),
        'require_number' => filter_var(env('PASSWORD_REQUIRE_NUMBER', true), FILTER_VALIDATE_BOOL),
        'require_symbol' => filter_var(env('PASSWORD_REQUIRE_SYMBOL', false), FILTER_VALIDATE_BOOL),
        'prevent_email_parts' => filter_var(env('PASSWORD_PREVENT_EMAIL_PARTS', true), FILTER_VALIDATE_BOOL),
        'prevent_name_parts' => filter_var(env('PASSWORD_PREVENT_NAME_PARTS', true), FILTER_VALIDATE_BOOL),
    ],

    'pending_temp_password_notice' => env(
        'PENDING_TEMP_PASSWORD_NOTICE',
        'Write down this temporary password. It will not work yet. If technology staff approve your request, this will become your temporary Google password. You will be required to change it when you sign in.'
    ),

    'student_selected_submitted_notice' => env(
        'STUDENT_SELECTED_SUBMITTED_NOTICE',
        'Your request has been submitted. If approved, your Google password will be changed to the password you entered.'
    ),

    'photo_retention_days' => (int) env('PHOTO_RETENTION_DAYS', 90),

    'photo_storage_disk' => env('PHOTO_STORAGE_DISK', 'local'),

    'registration_photo_max_kilobytes' => (int) env('REGISTRATION_PHOTO_MAX_KILOBYTES', 5120),

    'max_failed_attempts_per_student' => (int) env('MAX_FAILED_ATTEMPTS_PER_STUDENT', 3),

    'max_failed_attempts_per_kiosk' => (int) env('MAX_FAILED_ATTEMPTS_PER_KIOSK', 10),

    'lockout_minutes' => (int) env('LOCKOUT_MINUTES', 30),

    'challenge_questions_to_ask' => (int) env('CHALLENGE_QUESTIONS_TO_ASK', 3),

    'challenge_questions_required_correct' => (int) env('CHALLENGE_QUESTIONS_REQUIRED_CORRECT', 3),

    'max_challenge_questions_per_student' => (int) env('MAX_CHALLENGE_QUESTIONS_PER_STUDENT', 10),

    'min_challenge_questions_per_student' => (int) env('MIN_CHALLENGE_QUESTIONS_PER_STUDENT', 3),

    'challenge_answer_case_insensitive' => filter_var(
        env('CHALLENGE_ANSWER_CASE_INSENSITIVE', true),
        FILTER_VALIDATE_BOOL
    ),

    'slack_approval_required' => filter_var(env('SLACK_APPROVAL_REQUIRED', true), FILTER_VALIDATE_BOOL),

    'office_verification_allowed' => filter_var(
        env('OFFICE_VERIFICATION_ALLOWED', true),
        FILTER_VALIDATE_BOOL
    ),

    'office_verification_expires_hours' => (int) env('OFFICE_VERIFICATION_EXPIRES_HOURS', 48),

    'office_verification_max_queue_depth' => (int) env('OFFICE_VERIFICATION_MAX_QUEUE_DEPTH', 25),

    'label_printing_enabled' => filter_var(env('LABEL_PRINTING_ENABLED', false), FILTER_VALIDATE_BOOL),

    /*
    | Maximum number of replacement revisions after the initial credential (revision 1).
    | Blocks endless policy-rejection loops; staff must reconcile manually beyond this.
    */
    'max_replacement_revisions' => (int) env('PASSWORD_MAX_REPLACEMENT_REVISIONS', 3),

    'registration_requires_kiosk' => filter_var(
        env('REGISTRATION_REQUIRES_KIOSK', false),
        FILTER_VALIDATE_BOOL
    ),

    'reset_requires_kiosk' => filter_var(env('RESET_REQUIRES_KIOSK', true), FILTER_VALIDATE_BOOL),

    'allow_student_id_lookup' => filter_var(env('ALLOW_STUDENT_ID_LOOKUP', true), FILTER_VALIDATE_BOOL),

    'allow_email_lookup' => filter_var(env('ALLOW_EMAIL_LOOKUP', true), FILTER_VALIDATE_BOOL),

    'registration_session_key' => env('REGISTRATION_SESSION_KEY', 'ssp_registration_student_id'),

    'reset_notice' => env(
        'RESET_NOTICE',
        'A photo will be taken and sent to school technology staff for review. They must approve your request before your password can be reset.'
    ),

    'reset_lookup_failure_message' => env(
        'RESET_LOOKUP_FAILURE_MESSAGE',
        'We could not start a password reset request with that information. If you are registered for kiosk assistance, check your entry and try again.'
    ),

    'temp_password_display_notice' => env(
        'TEMP_PASSWORD_DISPLAY_NOTICE',
        'This password is shown once. Sign in to Google, then you will be asked to create a new password.'
    ),

    'temp_password_unavailable_message' => env(
        'TEMP_PASSWORD_UNAVAILABLE_MESSAGE',
        'Your temporary password is not available on this kiosk. Please restart the reset process or see technology staff.'
    ),

    'reset_challenge_failure_message' => env(
        'RESET_CHALLENGE_FAILURE_MESSAGE',
        'We could not verify your answers. Please try again later or see technology staff in the office.'
    ),

    'registration_notice' => env(
        'REGISTRATION_NOTICE',
        'This system is used to request password assistance. Your photo may be captured and reviewed by school technology staff to verify your identity and protect your account. Misuse of this system may result in disciplinary action.'
    ),

    'temp_password' => [
        'word_list' => env('TEMP_PASSWORD_WORD_LIST', 'default'),
        'format' => env('TEMP_PASSWORD_FORMAT', 'word-word-4digits-word'),
        'min_length' => (int) env('TEMP_PASSWORD_MIN_LENGTH', 14),
    ],

    'features' => [
        'enable_temp_password_printing' => filter_var(
            env('ENABLE_TEMP_PASSWORD_PRINTING', false),
            FILTER_VALIDATE_BOOL
        ),
        'enable_sis_integration' => filter_var(env('ENABLE_SIS_INTEGRATION', false), FILTER_VALIDATE_BOOL),
        'enable_photo_retention_cleanup' => filter_var(
            env('ENABLE_PHOTO_RETENTION_CLEANUP', true),
            FILTER_VALIDATE_BOOL
        ),
    ],

];

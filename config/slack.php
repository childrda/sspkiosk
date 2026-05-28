<?php

return [

    'bot_token' => env('SLACK_BOT_TOKEN'),

    'signing_secret' => env('SLACK_SIGNING_SECRET'),

    'reset_channel_id' => env('SLACK_RESET_CHANNEL_ID'),

    'approver_usergroup_id' => env('SLACK_APPROVER_USERGROUP_ID'),

    'signature_tolerance_seconds' => (int) env('SLACK_SIGNATURE_TOLERANCE_SECONDS', 300),

    'photo_url_ttl_minutes' => (int) env('SLACK_PHOTO_URL_TTL_MINUTES', 15),

];

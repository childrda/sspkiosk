<?php

use App\Http\Controllers\SlackInteractionController;
use App\Http\Controllers\SlackPhotoController;
use App\Http\Middleware\VerifySlackSignature;
use Illuminate\Support\Facades\Route;

Route::get('/slack/photos/{studentPhoto}', [SlackPhotoController::class, 'show'])
    ->middleware(['web', 'signed', 'throttle:slack-photo'])
    ->name('slack.photos.show');

Route::post('/slack/interactions', [SlackInteractionController::class, 'handle'])
    ->middleware([VerifySlackSignature::class, 'throttle:slack-interactions'])
    ->name('slack.interactions');

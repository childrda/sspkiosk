<?php

namespace App\Services\Slack;

use App\Models\StudentPhoto;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class SlackPhotoUrlService
{
    public function canEmbedInMessageBlocks(): bool
    {
        return str_starts_with(strtolower((string) config('app.url')), 'https://');
    }

    public function temporarySignedUrl(?StudentPhoto $photo): ?string
    {
        if ($photo === null || ! $this->canEmbedInMessageBlocks()) {
            return null;
        }

        if (! $this->photoExists($photo)) {
            return null;
        }

        $ttlMinutes = (int) config('slack.photo_url_ttl_minutes', 15);

        return URL::temporarySignedRoute(
            'slack.photos.show',
            now()->addMinutes(max(1, $ttlMinutes)),
            ['studentPhoto' => $photo],
        );
    }

    private function photoExists(StudentPhoto $photo): bool
    {
        $disk = (string) config('student-password-reset.photo_storage_disk');

        return Storage::disk($disk)->exists($photo->storage_path);
    }
}

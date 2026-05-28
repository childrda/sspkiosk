<?php

namespace App\Http\Controllers;

use App\Models\StudentPhoto;
use App\Services\StudentPhotoStorageService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SlackPhotoController extends Controller
{
    public function __construct(
        private readonly StudentPhotoStorageService $photoStorage,
    ) {}

    /**
     * Time-limited signed URL for Slack image blocks (no session auth).
     */
    public function show(StudentPhoto $studentPhoto): StreamedResponse
    {
        $storagePath = (string) $studentPhoto->storage_path;

        abort_unless(
            $this->photoStorage->isSafeStoragePath($storagePath),
            404,
        );

        $disk = (string) config('student-password-reset.photo_storage_disk');

        abort_unless(Storage::disk($disk)->exists($storagePath), 404);

        return Storage::disk($disk)->response(
            $storagePath,
            basename($storagePath),
            [
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Type' => $this->guessContentType($studentPhoto->storage_path),
            ],
        );
    }

    private function guessContentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}

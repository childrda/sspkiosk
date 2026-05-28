<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentPhoto;
use App\Enums\StudentPhotoType;
use App\Services\Slack\SlackPhotoUrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SlackPhotoRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_slack_photo_url_serves_image(): void
    {
        Storage::fake('local');
        URL::forceRootUrl('https://kiosk.test');
        config(['app.url' => 'https://kiosk.test']);

        $student = Student::factory()->registered()->create();
        $photo = StudentPhoto::query()->create([
            'student_id' => $student->id,
            'type' => StudentPhotoType::ResetRequest,
            'storage_path' => 'student-photos/'.$student->id.'/reset.jpg',
            'metadata' => [],
        ]);
        Storage::disk('local')->put($photo->storage_path, 'fake-image-bytes');

        $url = app(SlackPhotoUrlService::class)->temporarySignedUrl($photo);

        $this->assertNotNull($url);

        $this->get($url)->assertOk();
    }

    public function test_unsigned_slack_photo_url_is_rejected(): void
    {
        Storage::fake('local');
        URL::forceRootUrl('https://kiosk.test');
        config(['app.url' => 'https://kiosk.test']);

        $student = Student::factory()->registered()->create();
        $photo = StudentPhoto::query()->create([
            'student_id' => $student->id,
            'type' => StudentPhotoType::ResetRequest,
            'storage_path' => 'student-photos/'.$student->id.'/reset.jpg',
            'metadata' => [],
        ]);
        Storage::disk('local')->put($photo->storage_path, 'fake-image-bytes');

        $this->get(route('slack.photos.show', $photo))->assertForbidden();
    }
}

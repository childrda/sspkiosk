<?php

namespace Tests\Feature;

use App\Enums\KioskStatus;
use App\Models\AuditLog;
use App\Models\Kiosk;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Services\AdminKioskService;
use App\Services\KioskCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsKioskRequests;
use Tests\TestCase;

class KioskArchiveTest extends TestCase
{
    use RefreshDatabase;
    use SignsKioskRequests;

    private function adminUser(): User
    {
        return User::factory()->admin()->create();
    }

    private function enrolledKiosk(array $attributes = []): array
    {
        $credentials = app(KioskCredentialService::class);
        $secret = $credentials->generateSecret();

        $kiosk = Kiosk::factory()->create(array_merge([
            'secret_hash' => $credentials->encryptSecret($secret),
            'last_seen_at' => now(),
        ], $attributes));

        return [$kiosk, $secret];
    }

    public function test_archiving_kiosk_with_reset_history_succeeds(): void
    {
        $admin = $this->adminUser();
        $kiosk = Kiosk::factory()->create();
        PasswordResetRequest::factory()->create(['kiosk_id' => $kiosk->id]);

        $this->actingAs($admin)
            ->delete(route('admin.kiosks.destroy', $kiosk))
            ->assertRedirect(route('admin.kiosks.index'));

        $this->assertTrue(Kiosk::onlyTrashed()->whereKey($kiosk->id)->exists());
    }

    public function test_archived_kiosk_is_excluded_from_default_index_and_shown_under_archived_filter(): void
    {
        $admin = $this->adminUser();
        $active = Kiosk::factory()->create(['name' => 'Active Kiosk']);
        $archived = Kiosk::factory()->create(['name' => 'Archived Kiosk']);

        app(AdminKioskService::class)->archive($archived, $admin->id);

        $this->actingAs($admin)
            ->get(route('admin.kiosks.index'))
            ->assertOk()
            ->assertSee('Active Kiosk')
            ->assertDontSee('Archived Kiosk');

        $this->actingAs($admin)
            ->get(route('admin.kiosks.index', ['archived' => 1]))
            ->assertOk()
            ->assertSee('Archived Kiosk')
            ->assertDontSee('Active Kiosk');
    }

    public function test_password_reset_request_with_archived_kiosk_renders_admin_request_show(): void
    {
        $admin = $this->adminUser();
        $kiosk = Kiosk::factory()->create(['name' => 'Library Kiosk']);
        $request = PasswordResetRequest::factory()->create(['kiosk_id' => $kiosk->id]);

        app(AdminKioskService::class)->archive($kiosk, $admin->id);

        $this->assertSame('Library Kiosk', $request->fresh()->kiosk->name);

        $this->actingAs($admin)
            ->get(route('admin.requests.show', $request))
            ->assertOk()
            ->assertSee('Library Kiosk');
    }

    public function test_archive_nulls_secret_hash_and_heartbeat_is_rejected(): void
    {
        config(['kiosk.allowed_networks' => ['127.0.0.1']]);

        $admin = $this->adminUser();
        [$kiosk, $secret] = $this->enrolledKiosk();

        app(AdminKioskService::class)->archive($kiosk, $admin->id);

        $archived = Kiosk::withTrashed()->findOrFail($kiosk->id);
        $this->assertNull($archived->secret_hash);
        $this->assertNull($archived->enrolled_at);

        $body = json_encode(['device_fingerprint' => 'fp-test']);
        $headers = $this->kioskAuthHeaders($kiosk, $secret, 'POST', '/kiosk/heartbeat', [], $body);

        $this->postSignedKioskRequest($headers, $body)
            ->assertUnauthorized()
            ->assertJsonPath('reason', 'unknown_kiosk');
    }

    public function test_restore_brings_kiosk_back_as_disabled(): void
    {
        $admin = $this->adminUser();
        $kiosk = Kiosk::factory()->create(['status' => KioskStatus::Active]);

        app(AdminKioskService::class)->archive($kiosk, $admin->id);

        $this->actingAs($admin)
            ->post(route('admin.kiosks.restore', $kiosk))
            ->assertRedirect(route('admin.kiosks.show', $kiosk));

        $kiosk->refresh();
        $this->assertFalse($kiosk->trashed());
        $this->assertSame(KioskStatus::Disabled, $kiosk->status);
        $this->assertTrue(
            AuditLog::query()->where('action', 'admin.kiosk.restored')->exists(),
        );
    }

    public function test_non_admin_cannot_archive_kiosk(): void
    {
        $user = User::factory()->create();
        $kiosk = Kiosk::factory()->create();

        $this->actingAs($user)
            ->delete(route('admin.kiosks.destroy', $kiosk))
            ->assertForbidden();
    }
}

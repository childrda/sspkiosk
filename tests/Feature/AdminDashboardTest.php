<?php

namespace Tests\Feature;

use App\Enums\KioskStatus;
use App\Enums\PasswordResetRequestStatus;
use App\Models\AuditLog;
use App\Models\Kiosk;
use App\Models\PasswordResetRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->admin()->create([
            'email' => 'admin@district.example',
            'password' => 'Secure-Password-12',
        ]);
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    public function test_non_admin_user_cannot_access_dashboard(): void
    {
        $user = User::factory()->create(['password' => 'Secure-Password-12']);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_admin_can_view_dashboard_and_requests(): void
    {
        $admin = $this->adminUser();
        PasswordResetRequest::factory()->create([
            'status' => PasswordResetRequestStatus::Pending,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Dashboard');

        $this->actingAs($admin)
            ->get(route('admin.requests.index', ['status' => PasswordResetRequestStatus::Pending->value]))
            ->assertOk();
    }

    public function test_admin_can_create_and_disable_kiosk(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.kiosks.store'), [
                'name' => 'Library Kiosk',
                'school' => 'Main Campus',
                'location' => 'Library',
                'allowed_ip' => '10.10.20.15',
            ])
            ->assertRedirect();

        $kiosk = Kiosk::query()->where('name', 'Library Kiosk')->first();
        $this->assertNotNull($kiosk);
        $this->assertSame(KioskStatus::Active, $kiosk->status);

        $this->actingAs($admin)
            ->post(route('admin.kiosks.disable', $kiosk))
            ->assertRedirect(route('admin.kiosks.show', $kiosk));

        $this->assertSame(KioskStatus::Disabled, $kiosk->fresh()->status);
        $this->assertTrue(
            AuditLog::query()->where('action', 'admin.kiosk.disabled')->exists(),
        );
    }

    public function test_admin_can_rotate_kiosk_secret(): void
    {
        $admin = $this->adminUser();
        $kiosk = Kiosk::factory()->enrolled()->create(['allowed_ip' => '10.0.0.1']);

        $this->actingAs($admin)
            ->post(route('admin.kiosks.rotate-secret', $kiosk))
            ->assertRedirect()
            ->assertSessionHas('kiosk_secret');

        $this->assertTrue(
            AuditLog::query()->where('action', 'admin.kiosk.secret_rotated')->exists(),
        );
    }

    public function test_admin_can_archive_kiosk_without_reset_history(): void
    {
        $admin = $this->adminUser();
        $kiosk = Kiosk::factory()->create(['allowed_ip' => '10.0.0.1']);

        $this->actingAs($admin)
            ->delete(route('admin.kiosks.destroy', $kiosk))
            ->assertRedirect(route('admin.kiosks.index'))
            ->assertSessionHas('status');

        $this->assertNull(Kiosk::query()->find($kiosk->id));
        $this->assertNotNull(Kiosk::withTrashed()->find($kiosk->id));
        $this->assertTrue(
            AuditLog::query()->where('action', 'admin.kiosk.archived')->exists(),
        );
    }

    public function test_admin_can_archive_kiosk_with_reset_history(): void
    {
        $admin = $this->adminUser();
        $kiosk = Kiosk::factory()->create(['allowed_ip' => '10.0.0.1']);
        PasswordResetRequest::factory()->create(['kiosk_id' => $kiosk->id]);

        $this->actingAs($admin)
            ->delete(route('admin.kiosks.destroy', $kiosk))
            ->assertRedirect(route('admin.kiosks.index'))
            ->assertSessionHas('status');

        $this->assertNull(Kiosk::query()->find($kiosk->id));
        $this->assertNotNull(Kiosk::withTrashed()->find($kiosk->id));
        $this->assertSame(1, PasswordResetRequest::query()->where('kiosk_id', $kiosk->id)->count());
    }

    public function test_admin_can_disable_student_reset_eligibility(): void
    {
        $admin = $this->adminUser();
        $student = Student::factory()->registered()->create(['reset_enabled' => true]);

        $this->actingAs($admin)
            ->post(route('admin.students.disable-reset', $student))
            ->assertRedirect(route('admin.students.show', $student));

        $this->assertFalse($student->fresh()->reset_enabled);
    }

    public function test_admin_can_search_students_and_view_audit_log(): void
    {
        $admin = $this->adminUser();
        $student = Student::factory()->registered()->create([
            'email' => 'unique.student@students.example.org',
        ]);

        AuditLog::query()->create([
            'actor_type' => \App\Enums\AuditActorType::System,
            'actor_id' => null,
            'action' => 'test.system.event',
            'target_type' => 'student',
            'target_id' => (string) $student->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'metadata' => null,
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.students.index', ['q' => 'unique.student']))
            ->assertOk()
            ->assertSee($student->email);

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['action' => 'test.system']))
            ->assertOk()
            ->assertSee('test.system.event');
    }

    public function test_admin_login_rejects_non_admin_account(): void
    {
        User::factory()->create([
            'email' => 'teacher@district.example',
            'password' => 'Secure-Password-12',
            'is_admin' => false,
        ]);

        $this->post(route('admin.login.submit'), [
            'email' => 'teacher@district.example',
            'password' => 'Secure-Password-12',
        ])->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('email');
    }
}

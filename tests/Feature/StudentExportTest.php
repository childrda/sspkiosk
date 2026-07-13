<?php

namespace Tests\Feature;

use App\Enums\StudentPhotoType;
use App\Models\AuditLog;
use App\Models\Student;
use App\Models\StudentChallengeQuestion;
use App\Models\StudentPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class StudentExportTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_export_returns_csv_with_expected_header_and_one_row_per_student(): void
    {
        $admin = $this->adminUser();
        $student = Student::factory()->registered()->create([
            'email' => 'alex@students.example.org',
            'name' => 'Alex Johnson',
            'school' => 'LHS',
            'grade' => '10',
            'reset_enabled' => true,
        ]);

        StudentChallengeQuestion::query()->create([
            'student_id' => $student->id,
            'question_text' => 'Favorite color?',
            'answer_hash' => bcrypt('blue'),
        ]);
        StudentChallengeQuestion::query()->create([
            'student_id' => $student->id,
            'question_text' => 'First pet?',
            'answer_hash' => bcrypt('rex'),
        ]);
        StudentPhoto::query()->create([
            'student_id' => $student->id,
            'type' => StudentPhotoType::Registration,
            'storage_path' => 'student-photos/'.$student->id.'/reg.jpg',
            'metadata' => [],
        ]);

        $response = $this->actingAs($admin)->get(route('admin.students.export'));

        $response->assertOk();
        $response->assertHeader('content-disposition');

        $content = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($content))));

        $this->assertSame(
            'email,name,school,grade,org_unit_path,registered_at,questions_count,has_registration_photo,reset_enabled,reset_requests_count,last_reset_at',
            $lines[0],
        );
        $this->assertStringContainsString('alex@students.example.org', $lines[1]);
        $this->assertStringContainsString('Alex Johnson', $lines[1]);
        $this->assertCount(2, $lines);

        $this->assertTrue(
            AuditLog::query()->where('action', 'admin.students.exported')->exists(),
        );
    }

    public function test_students_export_route_resolves_before_student_model_binding(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->get(route('admin.students.export'))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_roster_diff_correctly_buckets_fixture_roster_including_case_mismatched_emails(): void
    {
        $admin = $this->adminUser();

        Student::factory()->registered()->create([
            'email' => 'alex@students.example.org',
            'name' => 'Alex Johnson',
        ]);
        Student::factory()->registered()->create([
            'email' => 'withdrawn@students.example.org',
            'name' => 'Withdrawn Student',
        ]);

        $csv = implode("\n", [
            'Email,Name',
            'ALEX@students.example.org,Alex Johnson',
            'newkid@students.example.org,New Kid',
        ]);

        $file = UploadedFile::fake()->createWithContent('roster.csv', $csv);

        $this->actingAs($admin)
            ->post(route('admin.students.roster-compare.run'), ['roster' => $file])
            ->assertOk()
            ->assertSee('newkid@students.example.org')
            ->assertSee('withdrawn@students.example.org')
            ->assertSee('In both:</strong> 1', false)
            ->assertSessionMissing('roster_comparison');

        $this->assertTrue(
            AuditLog::query()->where('action', 'admin.students.roster_compared')->exists(),
        );
    }

    public function test_uploading_csv_without_email_column_returns_clear_validation_error(): void
    {
        $admin = $this->adminUser();
        $file = UploadedFile::fake()->createWithContent('roster.csv', "Student ID,Name\n123,Alex");

        $this->actingAs($admin)
            ->post(route('admin.students.roster-compare.run'), ['roster' => $file])
            ->assertSessionHasErrors('roster');

        $errors = session('errors')->get('roster');
        $this->assertStringContainsString('email column', $errors[0]);
        $this->assertStringContainsString('Student ID', $errors[0]);
    }

    public function test_non_admin_cannot_export_or_compare_roster(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('roster.csv', "email\na@example.org");

        $this->actingAs($user)->get(route('admin.students.export'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.students.roster-compare.run'), ['roster' => $file])->assertForbidden();
    }
}

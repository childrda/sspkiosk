<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->json('directory_results')->nullable()->after('google_error_message');
            $table->string('password_mode')->nullable()->after('reset_mode');
            $table->string('password_origin')->nullable()->after('password_mode');
            $table->boolean('force_change_at_next_login')->nullable()->after('password_origin');
            $table->boolean('superseded_student_selected_password')->default(false)->after('force_change_at_next_login');
            $table->boolean('retry_available')->default(false)->after('superseded_student_selected_password');
        });

        // Approximate backfill for historical rows from current configuration/mode fields.
        $tempForce = filter_var(env('GOOGLE_FORCE_CHANGE_AT_NEXT_LOGIN_TEMPORARY', true), FILTER_VALIDATE_BOOL);
        $studentForce = filter_var(env('GOOGLE_FORCE_CHANGE_AT_NEXT_LOGIN_STUDENT_SELECTED', false), FILTER_VALIDATE_BOOL);

        DB::table('password_reset_requests')->orderBy('id')->chunkById(100, function ($rows) use ($tempForce, $studentForce): void {
            foreach ($rows as $row) {
                $type = $row->pending_password_type;
                $mode = $row->reset_mode;

                $origin = match ($type) {
                    'student_selected' => 'student_selected',
                    'temporary_generated' => $row->office_verified_at !== null
                        ? 'office_generated_temporary'
                        : 'temporary_generated',
                    default => $mode === 'student_selected_pending_approval'
                        ? 'student_selected'
                        : 'temporary_generated',
                };

                $force = match ($origin) {
                    'student_selected' => $studentForce,
                    'office_generated_temporary' => true,
                    default => $tempForce || $row->pending_password_printed_at !== null,
                };

                $directoryResults = null;
                if ($row->google_reset_attempted_at !== null || $row->google_reset_success !== null) {
                    $googleStatus = $row->google_reset_success === true
                        ? 'success'
                        : ($row->google_reset_success === false ? 'failed' : 'pending');

                    $directoryResults = json_encode([
                        'planned_directories' => ['google'],
                        'required_directories' => ['google'],
                        'results' => [
                            'google' => [
                                'status' => $googleStatus,
                                'reason' => $googleStatus === 'failed' ? 'unexpected_error' : null,
                                'retry_mode' => 'none',
                                'attempts' => $row->google_reset_attempted_at !== null ? 1 : 0,
                                'last_attempt_at' => $row->google_reset_attempted_at,
                                'processing_started_at' => null,
                                'completed_at' => $googleStatus === 'success' ? $row->google_reset_attempted_at : null,
                            ],
                        ],
                    ]);
                }

                DB::table('password_reset_requests')->where('id', $row->id)->update([
                    'password_mode' => $mode,
                    'password_origin' => $origin,
                    'force_change_at_next_login' => $force,
                    'directory_results' => $directoryResults,
                    'retry_available' => false,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->dropColumn([
                'directory_results',
                'password_mode',
                'password_origin',
                'force_change_at_next_login',
                'superseded_student_selected_password',
                'retry_available',
            ]);
        });
    }
};

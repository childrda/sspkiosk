<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('password_reset_request_id')->constrained('password_reset_requests')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('password_mode')->nullable();
            $table->string('password_origin')->nullable();
            $table->boolean('force_change_at_next_login')->nullable();
            $table->text('encrypted_pending_password')->nullable();
            $table->timestamp('pending_password_expires_at')->nullable();
            $table->timestamp('pending_password_created_at')->nullable();
            $table->timestamp('pending_password_displayed_at')->nullable();
            $table->timestamp('pending_password_printed_at')->nullable();
            $table->timestamp('pending_password_deleted_at')->nullable();
            $table->string('pending_password_type')->nullable();
            $table->json('directory_results')->nullable();
            $table->boolean('retry_available')->default(false);
            $table->string('status')->default('active');
            $table->timestamp('superseded_at')->nullable();
            // MySQL-compatible "one active revision" guard (null when superseded/terminal).
            $table->unsignedBigInteger('active_for_request_id')->nullable();
            $table->timestamps();

            $table->unique(['password_reset_request_id', 'revision_number']);
            $table->unique('active_for_request_id');
        });

        $requests = DB::table('password_reset_requests')->orderBy('id')->get();

        foreach ($requests as $row) {
            $isTerminal = in_array($row->status, ['completed', 'denied', 'expired', 'failed', 'cancelled'], true);
            $hasCredential = $row->encrypted_pending_password !== null
                || $row->directory_results !== null
                || $row->password_mode !== null
                || $row->password_origin !== null;

            if (! $hasCredential && $row->status === 'pending' && $row->encrypted_pending_password === null) {
                // Still create revision 1 for every request per prompt backfill.
            }

            $status = match (true) {
                $row->status === 'completed' => 'completed',
                in_array($row->status, ['failed', 'expired', 'denied'], true) => 'failed',
                default => 'active',
            };

            if ($isTerminal && $status === 'active') {
                $status = 'failed';
            }

            DB::table('password_reset_revisions')->insert([
                'password_reset_request_id' => $row->id,
                'revision_number' => 1,
                'password_mode' => $row->password_mode,
                'password_origin' => $row->password_origin,
                'force_change_at_next_login' => $row->force_change_at_next_login,
                'encrypted_pending_password' => $row->encrypted_pending_password,
                'pending_password_expires_at' => $row->pending_password_expires_at,
                'pending_password_created_at' => $row->pending_password_created_at,
                'pending_password_displayed_at' => $row->pending_password_displayed_at,
                'pending_password_printed_at' => $row->pending_password_printed_at,
                'pending_password_deleted_at' => $row->pending_password_deleted_at,
                'pending_password_type' => $row->pending_password_type,
                'directory_results' => $row->directory_results,
                'retry_available' => (bool) $row->retry_available,
                'status' => $status,
                'superseded_at' => $status === 'active' ? null : ($row->updated_at ?? now()),
                'active_for_request_id' => $status === 'active' ? $row->id : null,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_revisions');
    }
};

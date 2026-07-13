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
            $table->timestamp('escalated_at')->nullable()->after('denial_reason');
            $table->string('escalated_by_slack_user_id')->nullable()->after('escalated_at');
            $table->timestamp('office_verified_at')->nullable()->after('escalated_by_slack_user_id');
            $table->foreignId('office_verified_by_user_id')->nullable()->after('office_verified_at')
                ->constrained('users')->nullOnDelete();
            $table->text('office_verification_notes')->nullable()->after('office_verified_by_user_id');
            $table->timestamp('office_verification_expires_at')->nullable()->after('office_verification_notes');
        });

        $expiresHours = 48;

        $rows = DB::table('password_reset_requests')
            ->where('status', 'needs_office_verification')
            ->where('denial_reason', 'Escalated for office verification')
            ->get(['id', 'denied_at', 'denied_by_slack_user_id', 'updated_at']);

        foreach ($rows as $row) {
            $escalatedAt = $row->denied_at ?? $row->updated_at ?? now();

            DB::table('password_reset_requests')
                ->where('id', $row->id)
                ->update([
                    'escalated_at' => $escalatedAt,
                    'escalated_by_slack_user_id' => $row->denied_by_slack_user_id,
                    'office_verification_expires_at' => \Illuminate\Support\Carbon::parse($escalatedAt)->addHours($expiresHours),
                    'denied_at' => null,
                    'denied_by_slack_user_id' => null,
                    'denial_reason' => null,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('password_reset_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_verified_by_user_id');
            $table->dropColumn([
                'escalated_at',
                'escalated_by_slack_user_id',
                'office_verified_at',
                'office_verification_notes',
                'office_verification_expires_at',
            ]);
        });
    }
};

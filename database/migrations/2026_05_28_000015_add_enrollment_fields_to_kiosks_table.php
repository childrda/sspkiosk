<?php

use App\Enums\KioskEnrollmentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->timestamp('enrolled_at')->nullable()->after('secret_hash');
            $table->string('enrollment_type')->nullable()->after('enrolled_at');
        });

        DB::table('kiosks')
            ->whereNotNull('secret_hash')
            ->where('secret_hash', '!=', '')
            ->update([
                'enrolled_at' => DB::raw('created_at'),
                'enrollment_type' => KioskEnrollmentType::DeviceAgent->value,
            ]);

        DB::table('kiosks')
            ->whereNull('enrolled_at')
            ->where(function ($query): void {
                $query->whereNotNull('last_seen_at')
                    ->orWhereExists(function ($subquery): void {
                        $subquery->select(DB::raw(1))
                            ->from('password_reset_requests')
                            ->whereColumn('password_reset_requests.kiosk_id', 'kiosks.id');
                    });
            })
            ->update([
                'enrolled_at' => DB::raw('COALESCE(last_seen_at, created_at)'),
                'enrollment_type' => KioskEnrollmentType::Browser->value,
            ]);
    }

    public function down(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->dropColumn(['enrolled_at', 'enrollment_type']);
        });
    }
};

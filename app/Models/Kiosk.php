<?php

namespace App\Models;

use App\Enums\KioskEnrollmentType;
use App\Enums\KioskStatus;
use Database\Factories\KioskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Kiosk extends Model
{
    /** @use HasFactory<KioskFactory> */
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'kiosk_uuid',
        'name',
        'school',
        'location',
        'status',
        'allowed_ip',
        'allowed_subnet',
        'secret_hash',
        'enrolled_at',
        'enrollment_type',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'kiosk_uuid' => 'string',
            'status' => KioskStatus::class,
            'enrolled_at' => 'datetime',
            'enrollment_type' => KioskEnrollmentType::class,
            'last_seen_at' => 'datetime',
        ];
    }

    public function enrollmentCodes(): HasMany
    {
        return $this->hasMany(KioskEnrollmentCode::class);
    }

    public function passwordResetRequests(): HasMany
    {
        return $this->hasMany(PasswordResetRequest::class);
    }

    public function usedNonces(): HasMany
    {
        return $this->hasMany(UsedNonce::class);
    }

    public function isActive(): bool
    {
        return $this->status === KioskStatus::Active;
    }
}

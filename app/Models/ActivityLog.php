<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ACTIVITY LOG — one row per notable action anywhere in the app,
 * written only by App\Services\ActivityLogService::record() (never
 * created directly elsewhere, same convention as ScheduleAuditLog /
 * NotificationService). Backs the Settings > Activity Log tab
 * (Administrator-only — see ActivityLogController).
 */
class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The record this log entry is about (a Faculty, Section, etc.) —
     * subject_type/subject_id already store a plain polymorphic pair
     * (see ActivityLogService::record()), this just gives callers a
     * normal Eloquent relation instead of hand-rolling the lookup
     * every time (e.g. SchedulingController's Recent Activity feed).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
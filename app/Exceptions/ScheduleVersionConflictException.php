<?php

namespace App\Exceptions;

/**
 * CONCURRENCY HARDENING — Optimistic Concurrency Control.
 *
 * Thrown from INSIDE a locked DB::transaction() (see
 * ScheduleConflictService::checkSectionVersion()) the moment a
 * caller-submitted `expected_schedule_version` no longer matches the
 * Section's CURRENT `schedule_version`, read under a row lock. This
 * means another request already committed a change to this Section's
 * schedule since the caller's data was loaded/generated.
 *
 * Rolls the transaction back with no partial write — never a silent
 * overwrite — and is caught by the controller to return HTTP 409 with
 * code SCHEDULE_VERSION_CONFLICT and the current version, so the
 * frontend can prompt the user to refresh and retry.
 *
 * ACTOR-AWARE (bug fix — "false 'another user' conflict") — $updatedBy
 * carries WHO committed the version bump that caused this mismatch
 * (Section::schedule_version_updated_by at the moment of the locked
 * read). Without this, every version conflict got reported to the
 * user as "another user changed this schedule" even when it was
 * their OWN other tab/request/retry that raced and won — the exact
 * same distinction useSchedulePolling.js's polling tick already makes
 * correctly (comparing schedule_version_updated_by against the
 * current user) was missing entirely from this, the actual
 * server-authoritative conflict path. Callers should compare
 * $updatedBy against the requesting user's id before choosing their
 * message/notification, the same way the polling composable does.
 */
class ScheduleVersionConflictException extends \RuntimeException
{
    public function __construct(
        public readonly int $currentVersion,
        ?int $submittedVersion = null,
        public readonly ?int $updatedBy = null,
    ) {
        parent::__construct(
            "Schedule version conflict: submitted version {$submittedVersion} does not match current version {$currentVersion}."
        );
    }
}
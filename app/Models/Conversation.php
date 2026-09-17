<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * IN-SYSTEM MESSAGING — a direct 1-to-1 thread between two users.
 *
 * Conversations are never created from the UI as a separate step:
 * ChatController::open() lazily finds-or-creates the pair's thread the
 * first time one of them opens the other's chat.
 */
class Conversation extends Model
{
    protected $fillable = [
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    /** @return HasMany<ChatMessage> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /** @return BelongsToMany<User> */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    /**
     * The existing thread between these two users, or a new one.
     *
     * Wrapped in a transaction because two users opening each other's
     * chat at the same instant would otherwise each create a thread —
     * the unique (conversation_id, user_id) index can't catch that on
     * its own since the pair spans two rows.
     */
    public static function betweenUsers(int $userId, int $otherUserId): self
    {
        return DB::transaction(function () use ($userId, $otherUserId) {
            $existing = static::query()
                ->whereHas('participants', fn ($query) => $query->where('users.id', $userId))
                ->whereHas('participants', fn ($query) => $query->where('users.id', $otherUserId))
                ->withCount('participants')
                ->orderBy('id')
                ->get()
                ->firstWhere('participants_count', 2);

            if ($existing) {
                return $existing;
            }

            $conversation = static::create();
            $conversation->participants()->attach([$userId, $otherUserId]);

            return $conversation;
        });
    }

    /**
     * Count of messages this user hasn't seen yet — messages from the
     * other participant newer than their last_read_at watermark.
     */
    public function unreadCountFor(int $userId): int
    {
        $lastReadAt = $this->participants
            ->firstWhere('id', $userId)?->pivot?->last_read_at;

        return $this->messages()
            ->where('sender_id', '!=', $userId)
            ->when($lastReadAt, fn ($query) => $query->where('created_at', '>', $lastReadAt))
            ->count();
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IN-SYSTEM MESSAGING — one chat bubble.
 *
 * Plain text only, by design: the body is rendered as text in
 * ChatWidget.vue (never v-html), so nothing a user types can inject
 * markup into another user's session.
 */
class ChatMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'edited_at',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, ChatMessage> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, ChatMessage> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function isUnsent(): bool
    {
        return $this->deleted_at !== null;
    }
}
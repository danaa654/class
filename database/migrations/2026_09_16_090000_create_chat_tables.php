<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IN-SYSTEM MESSAGING (chatbox).
 *
 * Direct 1-to-1 conversations between system users (Administrator,
 * Registrar, Dean, OIC, Assistant Dean). Faculty are data records, not
 * logins, so they are never participants here — schedules still reach
 * them through the Reports module's "Send via Email" action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            // Denormalised so the contacts list can order by recency
            // without a correlated subquery on chat_messages.
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Watermark for the unread badge — everything in the
            // conversation newer than this is unread for this user.
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'conversation_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // The sender. Cascades on delete: removing a user removes
            // their messages rather than leaving orphan bubbles.
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            // Serves both the "messages since id X" poll and the
            // newest-first initial load.
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
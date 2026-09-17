<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IN-SYSTEM MESSAGING — edit and unsend.
 *
 * edited_at: set whenever the sender overwrites body; no prior version
 * is kept, so ChatMessage::body is always the current text and
 * edited_at is purely a "(edited)" display flag.
 *
 * deleted_at: soft-delete for "unsend". The row and body are kept (this
 * app treats communication like Section changes — always auditable,
 * see ScheduleAuditLog) but the frontend renders a placeholder instead
 * of the body whenever this is set. Deliberately NOT Laravel's
 * SoftDeletes trait: that trait hides the row from every query by
 * default, but an unsent message must still show its placeholder in
 * the thread, so this column is checked explicitly in ChatController
 * instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('body');
            $table->timestamp('deleted_at')->nullable()->after('edited_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'deleted_at']);
        });
    }
};
<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * IN-SYSTEM MESSAGING (chatbox) — polling API.
 *
 * Mirrors the notification system's approach deliberately: short-poll
 * over plain JSON endpoints rather than websockets, so the system keeps
 * running on a stock XAMPP/shared-hosting PHP setup with no extra
 * daemon (Reverb/Pusher) to keep alive during the defense.
 *
 * Every endpoint is scoped to the authenticated user: you can only read
 * or post to a conversation you are a participant of, and that is
 * re-checked server-side on every request — the frontend's contact list
 * is a convenience, never the boundary.
 */
class ChatController extends Controller
{
    /** Newest messages returned on first open of a thread. */
    private const HISTORY_LIMIT = 50;

    /** How long after sending a message can still be edited. */
    private const EDIT_WINDOW_MINUTES = 15;

    /** How long after sending a message can still be unsent. */
    private const UNSEND_WINDOW_MINUTES = 60;

    /**
     * Everyone this user can message — all other Active system users —
     * with their existing thread's last message and unread count.
     *
     * Faculty are intentionally absent: they are data records, not
     * logins (see the Faculty module), so there is nobody on the other
     * end of such a thread.
     */
    public function contacts(Request $request): JsonResponse
    {
        $user = $request->user();

        $others = User::query()
            ->where('id', '!=', $user->id)
            ->where('status', 'Active')
            ->with('college:id,name')
            ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'profile_photo_path', 'college_id']);

        // One query for every thread this user is in, rather than one
        // per contact — with ~5-10 system users this stays trivial.
        $conversations = Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
            ->with(['participants', 'messages' => fn ($query) => $query->latest('id')->limit(1)])
            ->get();

        $byOtherUserId = [];
        foreach ($conversations as $conversation) {
            $otherId = $conversation->participants->firstWhere('id', '!=', $user->id)?->id;
            if ($otherId) {
                $byOtherUserId[$otherId] = $conversation;
            }
        }

        $contacts = $others->map(function (User $other) use ($byOtherUserId, $user) {
            $conversation = $byOtherUserId[$other->id] ?? null;
            $lastMessage = $conversation?->messages->first();

            return [
                'user' => $this->userPayload($other),
                'conversation_id' => $conversation?->id,
                'unread_count' => $conversation ? $conversation->unreadCountFor($user->id) : 0,
                'last_message' => $lastMessage ? [
                    'body' => str($lastMessage->body)->limit(60)->value(),
                    'is_mine' => $lastMessage->sender_id === $user->id,
                    'created_at' => $lastMessage->created_at->toIso8601String(),
                ] : null,
                'last_message_at' => $conversation?->last_message_at?->toIso8601String(),
            ];
        })
            // Threads with recent activity float to the top; everyone
            // else falls back to alphabetical.
            ->sort(function (array $a, array $b) {
                $recency = ($b['last_message_at'] ?? '') <=> ($a['last_message_at'] ?? '');

                return $recency !== 0
                    ? $recency
                    : strcasecmp($a['user']['full_name'], $b['user']['full_name']);
            })
            ->values();

        return response()->json(['contacts' => $contacts]);
    }

    /**
     * Total unread messages across all threads — the badge on the
     * floating chat button. Kept to a single aggregate query because
     * this is the thing that gets polled on every open tab.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->unreadCountFor($request->user()->id),
        ]);
    }

    /**
     * Open (find-or-create) the thread with another user and return its
     * recent history. Opening a thread is what marks it read.
     */
    public function open(Request $request, User $user): JsonResponse
    {
        $me = $request->user();

        abort_if($user->id === $me->id, 422, 'You cannot start a conversation with yourself.');
        abort_if($user->status !== 'Active', 422, 'That user is not active.');

        $conversation = Conversation::betweenUsers($me->id, $user->id);

        $messages = $conversation->messages()
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        $this->markRead($conversation, $me->id);

        return response()->json([
            'conversation_id' => $conversation->id,
            'participant' => $this->userPayload($user),
            'messages' => $messages->map(fn (ChatMessage $message) => $this->messagePayload($message, $me->id)),
            'unread_count' => $this->unreadCountFor($me->id),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Poll for changes in an open thread: brand-new messages
     * (`id > after_id`) PLUS any earlier message edited or unsent
     * since the client's last poll (`updated_at > since`), so an edit
     * or unsend shows up live for the other participant instead of
     * only on next reopen. The client upserts by id rather than
     * blindly appending, since this can return messages it already
     * has.
     */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        $this->authorizeParticipant($conversation, $me->id);

        $afterId = (int) $request->query('after_id', 0);
        $since = $request->query('since');

        $messages = $conversation->messages()
            ->when($afterId > 0, function ($query) use ($afterId, $since) {
                $query->where(function ($inner) use ($afterId, $since) {
                    $inner->where('id', '>', $afterId);
                    if ($since) {
                        $inner->orWhere('updated_at', '>', $since);
                    }
                });
            })
            ->when($afterId === 0, fn ($query) => $query->latest('id')->limit(self::HISTORY_LIMIT))
            ->orderBy('id')
            ->get();

        if ($messages->contains(fn (ChatMessage $message) => $message->sender_id !== $me->id && $message->id > $afterId)) {
            $this->markRead($conversation, $me->id);
        }

        return response()->json([
            'messages' => $messages->map(fn (ChatMessage $message) => $this->messagePayload($message, $me->id)),
            'unread_count' => $this->unreadCountFor($me->id),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Post a message. The insert and the conversation's
     * last_message_at bump share one transaction so the contacts list
     * can never order by a timestamp whose message failed to save.
     */
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        $this->authorizeParticipant($conversation, $me->id);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = DB::transaction(function () use ($conversation, $me, $validated) {
            $message = $conversation->messages()->create([
                'sender_id' => $me->id,
                'body' => trim($validated['body']),
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });

        // Sending is also reading — otherwise your own reply would sit
        // in your unread count until the next poll cleared it.
        $this->markRead($conversation, $me->id);

        return response()->json([
            'message' => $this->messagePayload($message, $me->id),
        ], 201);
    }

    /**
     * Edit a message's body. Sender-only, and only within
     * EDIT_WINDOW_MINUTES of sending — an unsent message can't be
     * edited (unsend is meant to be the last word on it).
     */
    public function update(Request $request, ChatMessage $message): JsonResponse
    {
        $me = $request->user();
        $this->authorizeParticipant($message->conversation, $me->id);

        abort_if($message->sender_id !== $me->id, 403, 'You can only edit your own messages.');
        abort_if($message->isUnsent(), 422, 'This message was unsent and can no longer be edited.');
        abort_if(
            $message->created_at->diffInMinutes(now()) > self::EDIT_WINDOW_MINUTES,
            422,
            'This message is too old to edit (limit: '.self::EDIT_WINDOW_MINUTES.' minutes after sending).'
        );

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message->forceFill([
            'body' => trim($validated['body']),
            'edited_at' => now(),
        ])->save();

        return response()->json([
            'message' => $this->messagePayload($message, $me->id),
        ]);
    }

    /**
     * "Unsend" a message — soft delete. Sender-only, within
     * UNSEND_WINDOW_MINUTES of sending. The row and body are kept (see
     * the migration's docblock) so the placeholder can still be
     * rendered in place, but body is never returned to the frontend
     * once unsent.
     */
    public function destroy(Request $request, ChatMessage $message): JsonResponse
    {
        $me = $request->user();
        $this->authorizeParticipant($message->conversation, $me->id);

        abort_if($message->sender_id !== $me->id, 403, 'You can only unsend your own messages.');
        abort_if($message->isUnsent(), 422, 'This message was already unsent.');
        abort_if(
            $message->created_at->diffInMinutes(now()) > self::UNSEND_WINDOW_MINUTES,
            422,
            'This message is too old to unsend (limit: '.self::UNSEND_WINDOW_MINUTES.' minutes after sending).'
        );

        $message->forceFill(['deleted_at' => now()])->save();

        return response()->json([
            'message' => $this->messagePayload($message, $me->id),
        ]);
    }

    /** Explicit mark-read, used when the widget regains focus. */
    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        $this->authorizeParticipant($conversation, $me->id);
        $this->markRead($conversation, $me->id);

        return response()->json(['unread_count' => $this->unreadCountFor($me->id)]);
    }

    /**
     * Hard membership check. Route-model binding will happily resolve
     * any conversation id typed into the URL, so this — not the UI — is
     * what keeps threads private.
     */
    private function authorizeParticipant(Conversation $conversation, int $userId): void
    {
        $isParticipant = $conversation->participants()
            ->where('users.id', $userId)
            ->exists();

        if (! $isParticipant) {
            throw new AccessDeniedHttpException('You are not a participant of this conversation.');
        }
    }

    private function markRead(Conversation $conversation, int $userId): void
    {
        $conversation->participants()->updateExistingPivot($userId, [
            'last_read_at' => now(),
        ]);
    }

    /**
     * Unread across every thread, in one query: messages not sent by
     * this user, newer than this user's watermark on that thread.
     */
    private function unreadCountFor(int $userId): int
    {
        return ChatMessage::query()
            ->join('conversation_participants', 'conversation_participants.conversation_id', '=', 'chat_messages.conversation_id')
            ->where('conversation_participants.user_id', $userId)
            ->where('chat_messages.sender_id', '!=', $userId)
            ->where(function ($query) {
                $query->whereNull('conversation_participants.last_read_at')
                    ->orWhereColumn('chat_messages.created_at', '>', 'conversation_participants.last_read_at');
            })
            ->count();
    }

    /** @return array<string, mixed> */
    private function messagePayload(ChatMessage $message, int $viewerId): array
    {
        $isMine = $message->sender_id === $viewerId;
        $ageMinutes = $message->created_at->diffInMinutes(now());
        $isUnsent = $message->isUnsent();

        return [
            'id' => $message->id,
            // Never send the real body of an unsent message — the
            // placeholder text is decided client-side, but the source
            // text itself must not reach the browser at all.
            'body' => $isUnsent ? null : $message->body,
            'sender_id' => $message->sender_id,
            'is_mine' => $isMine,
            'created_at' => $message->created_at->toIso8601String(),
            'edited_at' => $message->edited_at?->toIso8601String(),
            'is_unsent' => $isUnsent,
            // UI affordance flags — still re-checked server-side on
            // the actual edit/unsend request, this only decides
            // whether to show the buttons at all.
            'can_edit' => $isMine && ! $isUnsent && $ageMinutes <= self::EDIT_WINDOW_MINUTES,
            'can_unsend' => $isMine && ! $isUnsent && $ageMinutes <= self::UNSEND_WINDOW_MINUTES,
        ];
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'initials' => strtoupper(mb_substr($user->first_name ?? $user->name ?? '', 0, 1).mb_substr($user->last_name ?? '', 0, 1)),
            'profile_photo_url' => $user->profile_photo_url,
            'role' => $user->getRoleNames()->first(),
            'college' => $user->college?->name,
        ];
    }
}
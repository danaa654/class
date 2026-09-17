<script setup>
/**
 * IN-SYSTEM MESSAGING (chatbox).
 *
 * A floating, layout-level widget so messaging follows the user across
 * every page without an Inertia visit — mounted once in AppLayout, kept
 * out of the page components entirely.
 *
 * Transport is short-polling over the /chat/* JSON API, deliberately
 * matching NotificationBell rather than introducing websockets: no
 * extra daemon to keep alive on XAMPP or on the deployment box.
 * Poll cadence is tiered so an idle tab stays cheap:
 *   - badge only, every 15s, always;
 *   - open thread, every 4s, and only for messages newer than the last
 *     id already rendered.
 */
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';

const page = usePage();

const BADGE_POLL_MS = 15000;
const THREAD_POLL_MS = 4000;

const isOpen = ref(false);
// 'contacts' = people list, 'thread' = one conversation open.
const view = ref('contacts');

const unreadCount = ref(page.props.unreadChatCount ?? 0);
const contacts = ref([]);
const isLoadingContacts = ref(false);
const search = ref('');

const activeContact = ref(null);
const conversationId = ref(null);
const messages = ref([]);
const isLoadingThread = ref(false);
const draft = ref('');
const isSending = ref(false);
const errorMessage = ref('');

// Which message (by id) is currently being edited inline, and its
// in-progress text — kept separate from the compose draft so opening
// an edit never clobbers something the user was about to send.
const editingMessageId = ref(null);
const editDraft = ref('');
const isSavingEdit = ref(false);
const openMenuMessageId = ref(null);

const messageList = ref(null);
let badgeTimer = null;
let threadTimer = null;
// Server clock, not the browser's, so "since" comparisons line up with
// the created_at/updated_at values Postgres/MySQL actually wrote.
let lastPolledAt = null;

const hasUnread = computed(() => unreadCount.value > 0);

const filteredContacts = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) {
        return contacts.value;
    }

    return contacts.value.filter((contact) =>
        contact.user.full_name.toLowerCase().includes(term)
        || (contact.user.role ?? '').toLowerCase().includes(term)
        || (contact.user.college ?? '').toLowerCase().includes(term)
    );
});

const lastMessageId = computed(() =>
    messages.value.length ? messages.value[messages.value.length - 1].id : 0
);

async function pollUnreadCount() {
    try {
        const { data } = await axios.get(route('chat.unread-count'));
        unreadCount.value = data.unread_count;
    } catch (e) {
        // A dropped poll tick isn't worth surfacing — it retries in 15s.
    }
}

async function loadContacts() {
    isLoadingContacts.value = true;
    try {
        const { data } = await axios.get(route('chat.contacts'));
        contacts.value = data.contacts;
    } finally {
        isLoadingContacts.value = false;
    }
}

async function openThread(contact) {
    activeContact.value = contact.user;
    view.value = 'thread';
    isLoadingThread.value = true;
    errorMessage.value = '';
    messages.value = [];

    try {
        const { data } = await axios.get(route('chat.open', contact.user.id));
        conversationId.value = data.conversation_id;
        messages.value = data.messages;
        unreadCount.value = data.unread_count;
        lastPolledAt = data.server_time ?? new Date().toISOString();
        startThreadPolling();
        scrollToBottom();
    } catch (e) {
        errorMessage.value = 'Could not open this conversation. Please try again.';
    } finally {
        isLoadingThread.value = false;
    }
}

/**
 * Incremental fetch — only messages after the newest one already on
 * screen, so a long-running thread doesn't re-transfer its history
 * every four seconds.
 */
async function pollThread() {
    if (!conversationId.value) {
        return;
    }

    try {
        const { data } = await axios.get(route('chat.messages', conversationId.value), {
            params: { after_id: lastMessageId.value, since: lastPolledAt },
        });

        if (data.messages.length) {
            // Upsert by id: a message id we already have gets replaced
            // in place (an edit or unsend), anything new is appended.
            const byId = new Map(messages.value.map((m) => [m.id, m]));
            let hasNew = false;
            for (const incoming of data.messages) {
                if (!byId.has(incoming.id)) {
                    hasNew = true;
                }
                byId.set(incoming.id, incoming);
            }
            messages.value = [...byId.values()].sort((a, b) => a.id - b.id);
            if (hasNew) {
                scrollToBottom();
            }
        }
        unreadCount.value = data.unread_count;
        lastPolledAt = data.server_time ?? lastPolledAt;
    } catch (e) {
        // Same as above: silent retry.
    }
}

async function sendMessage() {
    const body = draft.value.trim();
    if (!body || isSending.value || !conversationId.value) {
        return;
    }

    isSending.value = true;
    errorMessage.value = '';

    try {
        const { data } = await axios.post(route('chat.messages.store', conversationId.value), { body });
        messages.value = [...messages.value, data.message];
        draft.value = '';
        lastPolledAt = data.message.created_at;
        scrollToBottom();
    } catch (e) {
        errorMessage.value = e.response?.data?.message ?? 'Message not sent. Check your connection and try again.';
    } finally {
        isSending.value = false;
    }
}

function toggleMenu(message) {
    openMenuMessageId.value = openMenuMessageId.value === message.id ? null : message.id;
}

function closeMenu() {
    openMenuMessageId.value = null;
}

function startEdit(message) {
    closeMenu();
    editingMessageId.value = message.id;
    editDraft.value = message.body;
}

function cancelEdit() {
    editingMessageId.value = null;
    editDraft.value = '';
}

async function saveEdit(message) {
    const body = editDraft.value.trim();
    if (!body || isSavingEdit.value) {
        return;
    }

    isSavingEdit.value = true;
    errorMessage.value = '';

    try {
        const { data } = await axios.patch(route('chat.messages.update', message.id), { body });
        messages.value = messages.value.map((m) => (m.id === message.id ? data.message : m));
        cancelEdit();
    } catch (e) {
        errorMessage.value = e.response?.data?.message ?? 'Could not save the edit.';
    } finally {
        isSavingEdit.value = false;
    }
}

function onEditKeydown(event, message) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        saveEdit(message);
    } else if (event.key === 'Escape') {
        cancelEdit();
    }
}

async function unsendMessage(message) {
    closeMenu();
    if (!confirm('Unsend this message? It will be replaced with "This message was unsent" for everyone.')) {
        return;
    }

    errorMessage.value = '';
    try {
        const { data } = await axios.delete(route('chat.messages.destroy', message.id));
        messages.value = messages.value.map((m) => (m.id === message.id ? data.message : m));
    } catch (e) {
        errorMessage.value = e.response?.data?.message ?? 'Could not unsend this message.';
    }
}

function backToContacts() {
    stopThreadPolling();
    view.value = 'contacts';
    activeContact.value = null;
    conversationId.value = null;
    messages.value = [];
    closeMenu();
    cancelEdit();
    // Refresh previews/unread badges now that this thread is read.
    loadContacts();
}

function togglePanel() {
    isOpen.value = !isOpen.value;
    if (isOpen.value && view.value === 'contacts') {
        loadContacts();
    }
}

function closePanel() {
    isOpen.value = false;
    stopThreadPolling();
}

function startThreadPolling() {
    stopThreadPolling();
    threadTimer = setInterval(pollThread, THREAD_POLL_MS);
}

function stopThreadPolling() {
    if (threadTimer) {
        clearInterval(threadTimer);
        threadTimer = null;
    }
}

function scrollToBottom() {
    nextTick(() => {
        if (messageList.value) {
            messageList.value.scrollTop = messageList.value.scrollHeight;
        }
    });
}

// Enter sends, Shift+Enter makes a new line — the convention people
// already expect from every other messaging app.
function onKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
}

function timeLabel(iso) {
    const date = new Date(iso);
    const today = new Date();
    const sameDay = date.toDateString() === today.toDateString();

    return sameDay
        ? date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })
        : date.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' +
          date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

// Closing the panel must stop the thread poll; reopening on a thread
// resumes it.
watch(isOpen, (open) => {
    if (open && view.value === 'thread' && conversationId.value) {
        startThreadPolling();
    } else if (!open) {
        stopThreadPolling();
    }
});

onMounted(() => {
    badgeTimer = setInterval(pollUnreadCount, BADGE_POLL_MS);
});

onUnmounted(() => {
    clearInterval(badgeTimer);
    stopThreadPolling();
});
</script>

<template>
    <div class="fixed bottom-5 right-5 z-50 flex flex-col items-end gap-3 print:hidden">
        <!-- Panel -->
        <transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="translate-y-2 opacity-0"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="translate-y-2 opacity-0"
        >
            <div
                v-if="isOpen"
                class="neu-card flex h-[30rem] w-[22rem] max-w-[calc(100vw-2.5rem)] flex-col overflow-hidden rounded-2xl shadow-xl"
            >
                <!-- Header -->
                <div class="neu-navy-surface flex items-center gap-2 px-3 py-2.5">
                    <button
                        v-if="view === 'thread'"
                        type="button"
                        class="rounded-lg p-1.5 text-slate-300 transition hover:bg-white/10 hover:text-white"
                        title="Back to contacts"
                        @click="backToContacts"
                    >
                        <i class="pi pi-arrow-left text-sm"></i>
                    </button>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-white">
                            {{ view === 'thread' ? activeContact?.full_name : 'Messages' }}
                        </p>
                        <p v-if="view === 'thread' && activeContact?.role" class="truncate text-[11px] text-slate-300">
                            {{ activeContact.role }}<span v-if="activeContact.college"> · {{ activeContact.college }}</span>
                        </p>
                    </div>

                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-300 transition hover:bg-white/10 hover:text-white"
                        title="Close"
                        @click="closePanel"
                    >
                        <i class="pi pi-times text-sm"></i>
                    </button>
                </div>

                <!-- Contacts list -->
                <div v-if="view === 'contacts'" class="flex min-h-0 flex-1 flex-col">
                    <div class="neu-navy-surface px-3 pb-3">
                        <input
                            v-model="search"
                            type="text"
                            placeholder="Search people..."
                            class="w-full rounded-lg border-0 bg-white/10 px-3 py-1.5 text-sm text-white outline-none placeholder:text-slate-400 focus:bg-white/15"
                        />
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto px-2 pb-2">
                        <p v-if="isLoadingContacts" class="px-2 py-4 text-center text-xs text-slate-500 dark:text-slate-400">
                            Loading...
                        </p>
                        <p v-else-if="!filteredContacts.length" class="px-2 py-6 text-center text-xs text-slate-500 dark:text-slate-400">
                            No other active users to message.
                        </p>

                        <button
                            v-for="contact in filteredContacts"
                            :key="contact.user.id"
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-xl px-2 py-2 text-left transition hover:bg-slate-100 dark:hover:bg-slate-800"
                            @click="openThread(contact)"
                        >
                            <img
                                v-if="contact.user.profile_photo_url"
                                :src="contact.user.profile_photo_url"
                                alt=""
                                class="h-9 w-9 shrink-0 rounded-full object-cover"
                            />
                            <span
                                v-else
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-200 text-[11px] font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-200"
                            >{{ contact.user.initials }}</span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">
                                    {{ contact.user.full_name }}
                                </span>
                                <span class="block truncate text-[11px] text-slate-500 dark:text-slate-400">
                                    <template v-if="contact.last_message">
                                        <span v-if="contact.last_message.is_mine">You: </span>{{ contact.last_message.body }}
                                    </template>
                                    <template v-else>{{ contact.user.role ?? 'System user' }}</template>
                                </span>
                            </span>

                            <span
                                v-if="contact.unread_count"
                                class="ml-1 shrink-0 rounded-full bg-blue-600 px-1.5 py-0.5 text-[10px] font-bold text-white"
                            >{{ contact.unread_count }}</span>
                        </button>
                    </div>
                </div>

                <!-- Thread -->
                <div v-else class="flex min-h-0 flex-1 flex-col">
                    <div ref="messageList" class="min-h-0 flex-1 space-y-2 overflow-y-auto px-3 py-3">
                        <p v-if="isLoadingThread" class="py-4 text-center text-xs text-slate-500 dark:text-slate-400">
                            Loading conversation...
                        </p>
                        <p v-else-if="!messages.length" class="py-6 text-center text-xs text-slate-500 dark:text-slate-400">
                            No messages yet. Say hello.
                        </p>

                        <div
                            v-for="message in messages"
                            :key="message.id"
                            class="group flex items-start gap-1"
                            :class="message.is_mine ? 'justify-end' : 'justify-start'"
                        >
                            <!-- Own-message actions, shown on hover -->
                            <div
                                v-if="message.is_mine && !message.is_unsent && editingMessageId !== message.id"
                                class="relative mt-1.5 shrink-0 opacity-0 transition group-hover:opacity-100"
                                :class="{ 'opacity-100': openMenuMessageId === message.id }"
                            >
                                <button
                                    type="button"
                                    class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"
                                    title="Message options"
                                    @click="toggleMenu(message)"
                                >
                                    <i class="pi pi-ellipsis-h text-xs"></i>
                                </button>

                                <div
                                    v-if="openMenuMessageId === message.id"
                                    class="absolute right-0 z-10 mt-1 w-32 overflow-hidden rounded-lg border border-slate-200 bg-white text-xs shadow-lg dark:border-slate-700 dark:bg-slate-800"
                                >
                                    <button
                                        v-if="message.can_edit"
                                        type="button"
                                        class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-700"
                                        @click="startEdit(message)"
                                    >Edit</button>
                                    <button
                                        v-if="message.can_unsend"
                                        type="button"
                                        class="block w-full px-3 py-2 text-left text-red-600 hover:bg-slate-100 dark:hover:bg-slate-700"
                                        @click="unsendMessage(message)"
                                    >Unsend</button>
                                    <p v-if="!message.can_edit && !message.can_unsend" class="px-3 py-2 text-slate-400">
                                        Too old to change
                                    </p>
                                </div>
                            </div>

                            <!-- Inline edit mode -->
                            <div
                                v-if="editingMessageId === message.id"
                                class="max-w-[75%] rounded-2xl bg-slate-100 px-3 py-2 dark:bg-slate-800"
                            >
                                <textarea
                                    v-model="editDraft"
                                    rows="2"
                                    maxlength="2000"
                                    class="w-full resize-none rounded-md border border-slate-300 bg-white px-2 py-1 text-sm text-slate-800 outline-none dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                                    autofocus
                                    @keydown="onEditKeydown($event, message)"
                                ></textarea>
                                <div class="mt-1 flex justify-end gap-2 text-[11px]">
                                    <button type="button" class="text-slate-500 hover:underline" @click="cancelEdit">Cancel</button>
                                    <button
                                        type="button"
                                        class="font-medium text-blue-600 hover:underline disabled:opacity-50"
                                        :disabled="isSavingEdit || !editDraft.trim()"
                                        @click="saveEdit(message)"
                                    >Save</button>
                                </div>
                            </div>

                            <!-- Normal bubble -->
                            <div
                                v-else
                                class="max-w-[75%] rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap break-words"
                                :class="[
                                    message.is_unsent
                                        ? 'italic text-slate-400 dark:text-slate-500 bg-transparent border border-dashed border-slate-300 dark:border-slate-600'
                                        : message.is_mine
                                            ? 'bg-blue-600 text-white rounded-br-sm'
                                            : 'bg-slate-100 text-slate-800 rounded-bl-sm dark:bg-slate-800 dark:text-slate-100',
                                ]"
                            >
                                <span>{{ message.is_unsent ? 'This message was unsent.' : message.body }}</span>
                                <span
                                    class="mt-1 block text-[10px]"
                                    :class="message.is_mine && !message.is_unsent ? 'text-blue-100' : 'text-slate-500 dark:text-slate-400'"
                                >
                                    {{ timeLabel(message.created_at) }}<span v-if="message.edited_at"> · edited</span>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-200 px-3 py-2 dark:border-slate-700">
                        <p v-if="errorMessage" class="mb-1 text-[11px] text-red-600 dark:text-red-400">{{ errorMessage }}</p>
                        <div class="flex items-end gap-2">
                            <textarea
                                v-model="draft"
                                rows="1"
                                maxlength="2000"
                                placeholder="Type a message..."
                                class="neu-inset max-h-24 min-h-[2.25rem] flex-1 resize-none rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 outline-none placeholder:text-slate-400 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                                @keydown="onKeydown"
                            ></textarea>
                            <button
                                type="button"
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-white transition hover:bg-blue-700 disabled:opacity-50"
                                :disabled="isSending || !draft.trim()"
                                title="Send"
                                @click="sendMessage"
                            >
                                <i class="pi pi-send text-sm"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Floating button -->
        <button
            type="button"
            class="neu-navy-surface relative flex h-12 w-12 items-center justify-center rounded-full text-white shadow-lg transition hover:brightness-110"
            :title="isOpen ? 'Hide messages' : 'Messages'"
            @click="togglePanel"
        >
            <i :class="isOpen ? 'pi pi-times' : 'pi pi-comments'" class="text-lg"></i>
            <span
                v-if="hasUnread && !isOpen"
                class="absolute -top-1 -right-1 flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white"
            >{{ unreadCount > 99 ? '99+' : unreadCount }}</span>
        </button>
    </div>
</template>
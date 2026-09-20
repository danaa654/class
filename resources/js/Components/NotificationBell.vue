<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';

const page = usePage();

// Seeded from HandleInertiaRequests' `unreadNotificationCount` share
// so the badge is correct on first paint, before the first poll
// response comes back (spec Section 11).
const unreadCount = ref(page.props.unreadNotificationCount ?? 0);
const notifications = ref([]);
const isOpen = ref(false);
const isLoading = ref(false);
let pollTimer = null;

const hasUnread = computed(() => unreadCount.value > 0);

/**
 * Lightweight poll — unread-count only, every 20s (spec Section 11:
 * "every 10-30 seconds", "must be lightweight"). The full list is
 * only fetched when the dropdown is actually opened, not on every
 * poll tick.
 */
async function pollUnreadCount() {
    try {
        const { data } = await axios.get(route('notifications.unread-count'));
        unreadCount.value = data.unread_count;
    } catch (e) {
        // A failed poll tick is not worth surfacing to the user —
        // it'll just retry in another 20s.
    }
}

async function loadRecent() {
    isLoading.value = true;
    try {
        const { data } = await axios.get(route('notifications.recent'));
        notifications.value = data.notifications;
        unreadCount.value = data.unread_count;
    } finally {
        isLoading.value = false;
    }
}

function toggleOpen() {
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        loadRecent();
    }
}

function closeDropdown() {
    isOpen.value = false;
}

async function markAllRead() {
    await axios.patch(route('notifications.mark-all-read'));
    unreadCount.value = 0;
    notifications.value = notifications.value.map((n) => ({ ...n, is_read: true }));
}

/**
 * Marks read then navigates via the route the backend resolved from
 * the notification's Section id (spec Section 14) — never a stored
 * URL, and never done unless the mark-read call itself succeeds.
 */
async function openNotification(notification) {
    closeDropdown();
    const { data } = await axios.patch(route('notifications.read', notification.id));
    unreadCount.value = Math.max(0, unreadCount.value - (notification.is_read ? 0 : 1));
    if (data.redirect) {
        router.visit(data.redirect);
    }
}

// Mirrors the full Notifications page: only an already-read notification
// can be deleted (see NotificationController::destroy()) — an unread one
// is still something the recipient hasn't seen yet.
async function deleteNotification(notification) {
    await axios.delete(route('notifications.destroy', notification.id));
    notifications.value = notifications.value.filter((n) => n.id !== notification.id);
}

const iconFor = (type) => ({
    SCHEDULE_FINALIZED: '🔒',
    SCHEDULE_UNLOCKED: '🔓',
    SCHEDULE_UPDATED: '✏️',
    SCHEDULE_CONFLICT: '⚠️',
    CONCURRENCY_CONFLICT: '⚡',
    SECTION_CREATED: '➕',
    SUBJECT_ADDED: '📘',
    SUBJECT_REMOVED: '📕',
    SUBJECT_CREATED_IN_LIBRARY: '📚',
    SUBJECTS_IMPORTED: '📥',
    SUBJECT_UPDATED_IN_LIBRARY: '✏️',
    SUBJECT_DELETED_FROM_LIBRARY: '🗑️',
    ROOM_CREATED: '🏫',
    ROOMS_IMPORTED: '📥',
    ROOM_UPDATED: '✏️',
    ROOM_DELETED: '🗑️',
    AUTO_SCHEDULE_COMPLETED: '✅',
    AUTO_SCHEDULE_NEEDS_ATTENTION: '🛠️',
    PASSWORD_CHANGE_REQUIRED: '🔑',
}[type] ?? '🔔');

// Falls back to initials when the actor has no uploaded photo — same
// "first + last initial" convention as the sidebar/Manage Account
// avatars elsewhere in the app.
const actorInitials = (actor) => `${actor.first_name?.charAt(0) ?? actor.name?.charAt(0) ?? ''}${actor.last_name?.charAt(0) ?? ''}`.toUpperCase();

const PRIORITY_COLORS = {
    INFO: 'bg-slate-400',
    IMPORTANT: 'bg-blue-500',
    WARNING: 'bg-amber-500',
    CRITICAL: 'bg-red-600',
};
const priorityColor = (priority) => PRIORITY_COLORS[priority] ?? PRIORITY_COLORS.IMPORTANT;

function timeAgo(dateString) {
    const seconds = Math.floor((Date.now() - new Date(dateString).getTime()) / 1000);
    if (seconds < 60) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    const days = Math.floor(hours / 24);
    return `${days} day${days === 1 ? '' : 's'} ago`;
}

function onClickOutside(event) {
    if (!event.target.closest('[data-notification-bell]')) {
        closeDropdown();
    }
}

onMounted(() => {
    pollTimer = setInterval(pollUnreadCount, 20000);
    document.addEventListener('click', onClickOutside);
});

onUnmounted(() => {
    if (pollTimer) clearInterval(pollTimer);
    document.removeEventListener('click', onClickOutside);
});
</script>

<template>
    <div class="relative" data-notification-bell>
        <button
            type="button"
            class="neu-bell relative flex h-11 w-11 items-center justify-center rounded-2xl transition-transform duration-150 active:scale-95"
            :aria-label="hasUnread ? `${unreadCount} unread notifications` : 'Notifications'"
            @click="toggleOpen"
        >
            <i class="pi pi-bell text-[17px] text-slate-200"></i>
            <span
                v-if="hasUnread"
                class="neu-badge absolute -right-1.5 -top-1.5 flex h-5 min-w-[20px] items-center justify-center rounded-full px-1 text-[10px] font-bold text-white"
            >
                {{ unreadCount > 99 ? '99+' : unreadCount }}
            </span>
        </button>

        <transition name="neu-fade">
        <div
            v-if="isOpen"
            class="neu-panel absolute right-0 z-50 mt-3 w-96 max-w-[90vw] overflow-hidden rounded-2xl"
        >
            <div class="flex items-center justify-between px-4 py-3.5">
                <span class="text-sm font-bold tracking-wide text-white">Notifications</span>
                <button
                    v-if="hasUnread"
                    type="button"
                    class="text-xs font-medium text-blue-300 transition-colors hover:text-blue-200"
                    @click="markAllRead"
                >
                    Mark all read
                </button>
            </div>

            <div class="neu-divider"></div>

            <div class="neu-scroll max-h-96 overflow-y-auto px-2 py-2">
                <div v-if="isLoading" class="px-4 py-8 text-center text-sm text-slate-400">
                    Loading…
                </div>
                <div v-else-if="notifications.length === 0" class="flex flex-col items-center gap-2 px-4 py-10 text-center">
                    <i class="pi pi-bell-slash text-2xl text-slate-500"></i>
                    <span class="text-sm text-slate-400">No notifications yet.</span>
                </div>
                <div
                    v-for="notification in notifications"
                    :key="notification.id"
                    class="neu-item group relative mb-1.5 flex w-full items-start gap-3 rounded-xl px-3 py-3 text-left transition-colors last:mb-0"
                    :class="!notification.is_read ? 'neu-item--unread' : ''"
                >
                    <button type="button" class="flex min-w-0 flex-1 items-start gap-3 text-left" @click="openNotification(notification)">
                        <img
                            v-if="notification.actor?.profile_photo_url"
                            :src="notification.actor.profile_photo_url"
                            alt=""
                            loading="lazy"
                            decoding="async"
                            class="h-9 w-9 shrink-0 rounded-full object-cover"
                        />
                        <span v-else-if="notification.actor" class="neu-icon-chip flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white">
                            {{ actorInitials(notification.actor) }}
                        </span>
                        <span v-else class="neu-icon-chip flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[13px]">
                            {{ iconFor(notification.type) }}
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="flex items-center gap-1.5 pr-5 text-sm font-semibold text-white">
                                <span class="truncate">{{ notification.title }}</span>
                                <span
                                    v-if="notification.priority && notification.priority !== 'IMPORTANT'"
                                    class="h-1.5 w-1.5 shrink-0 rounded-full"
                                    :class="priorityColor(notification.priority)"
                                    :title="notification.priority"
                                ></span>
                            </span>
                            <span v-if="notification.actor" class="truncate text-[11px] font-medium text-slate-400">{{ notification.actor.name }}</span>
                            <span class="whitespace-pre-line text-xs leading-snug text-slate-300">{{ notification.message }}</span>
                            <span class="text-[11px] text-slate-500">{{ timeAgo(notification.created_at) }}</span>
                        </span>
                    </button>
                    <span v-if="!notification.is_read" class="absolute right-3 top-3.5 h-2 w-2 shrink-0 rounded-full bg-blue-400"></span>
                    <button
                        v-if="notification.is_read"
                        type="button"
                        class="absolute right-2 top-2 rounded-full p-1.5 text-slate-500 opacity-0 transition-opacity hover:bg-white/10 hover:text-red-400 group-hover:opacity-100"
                        aria-label="Delete notification"
                        @click.stop="deleteNotification(notification)"
                    >
                        <i class="pi pi-trash text-xs"></i>
                    </button>
                </div>
            </div>

            <div class="neu-divider"></div>

            <div class="px-4 py-2.5 text-center">
                <a :href="route('notifications')" class="text-xs font-medium text-blue-300 transition-colors hover:text-blue-200">
                    View all notifications
                </a>
            </div>
        </div>
        </transition>
    </div>
</template>

<style scoped>
.neu-bell {
    background: var(--neu-navy-raised-bg, #14225E);
    box-shadow:
        3px 3px 6px rgba(0, 0, 0, 0.55),
        -2px -2px 5px rgba(255, 255, 255, 0.06);
}
.neu-bell:hover {
    box-shadow:
        4px 4px 8px rgba(0, 0, 0, 0.6),
        -2px -2px 6px rgba(255, 255, 255, 0.08);
}
.neu-bell:active {
    box-shadow:
        inset 2px 2px 5px rgba(0, 0, 0, 0.6),
        inset -2px -2px 5px rgba(255, 255, 255, 0.06);
}

.neu-badge {
    background: linear-gradient(145deg, #f87171, #ef4444);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.4);
}

/* Dropdown panel — same navy neumorphic surface as the dashboard cards,
   raised off the header with a soft outer shadow instead of a border. */
.neu-panel {
    background: var(--neu-navy-raised-bg, #14225E);
    box-shadow:
        0 10px 24px rgba(0, 0, 0, 0.45),
        0 2px 6px rgba(0, 0, 0, 0.35),
        inset 0 1px 0 rgba(255, 255, 255, 0.04);
}

.neu-divider {
    height: 1px;
    background: rgba(255, 255, 255, 0.06);
}

/* Each row reads as a subtly inset well on the panel; unread rows get
   a faint blue-tinted inset instead of a flat highlight block. */
.neu-item {
    background: rgba(255, 255, 255, 0.02);
}
.neu-item:hover {
    background: rgba(255, 255, 255, 0.05);
}
.neu-item--unread {
    background: rgba(59, 130, 246, 0.08);
}
.neu-item--unread:hover {
    background: rgba(59, 130, 246, 0.13);
}

.neu-icon-chip {
    background: var(--neu-navy-bg, #0B1849);
    box-shadow:
        2px 2px 4px rgba(0, 0, 0, 0.55),
        -1px -1px 3px rgba(255, 255, 255, 0.05);
}

.neu-scroll {
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.18) transparent;
}
.neu-scroll::-webkit-scrollbar {
    width: 4px;
}
.neu-scroll::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.18);
    border-radius: 999px;
}
.neu-scroll::-webkit-scrollbar-thumb:hover {
    background: rgba(255, 255, 255, 0.3);
}
.neu-scroll::-webkit-scrollbar-track {
    background: transparent;
}

.neu-fade-enter-active,
.neu-fade-leave-active {
    transition: opacity 0.15s ease, transform 0.15s ease;
}
.neu-fade-enter-from,
.neu-fade-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>
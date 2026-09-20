<script setup>
import { Head, router, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from 'primevue/button';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const props = defineProps({
    notifications: { type: Object, required: true },
    filter: { type: String, default: 'all' },
});

const items = computed(() => props.notifications.data ?? []);

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
}[type] ?? '🔔');

// Falls back to initials when the actor has no uploaded photo — same
// "first + last initial" convention as the sidebar/Manage Account
// avatars elsewhere in the app.
const actorInitials = (actor) => `${actor.first_name?.charAt(0) ?? actor.name?.charAt(0) ?? ''}${actor.last_name?.charAt(0) ?? ''}`.toUpperCase();

function setFilter(value) {
    router.get(route('notifications'), { filter: value }, { preserveState: true, preserveScroll: true });
}

async function openNotification(notification) {
    const { data } = await axios.patch(route('notifications.read', notification.id));
    if (data.redirect) {
        router.visit(data.redirect);
    } else {
        router.reload({ only: ['notifications'] });
    }
}

async function markAllRead() {
    await axios.patch(route('notifications.mark-all-read'));
    router.reload({ only: ['notifications'] });
}

// Bulk counterpart to the per-row delete button — same "only
// already-read notifications can go" rule, enforced server-side too
// (see NotificationController::destroyAllRead()), so unread items are
// left untouched no matter what.
async function deleteAllRead() {
    if (! confirm('Delete all read notifications? This cannot be undone.')) return;

    await axios.delete(route('notifications.destroy-all-read'));
    router.reload({ only: ['notifications'] });
}

// Only already-read notifications can be deleted (see
// NotificationController::destroy()) — an unread one is still
// something the recipient hasn't seen yet.
async function deleteNotification(notification) {
    if (! confirm('Delete this notification? This cannot be undone.')) return;

    await axios.delete(route('notifications.destroy', notification.id));
    router.reload({ only: ['notifications'] });
}
</script>

<template>
    <Head title="Notifications" />

    <AppLayout>
        <div class="mx-auto max-w-3xl px-4 py-6">
            <div class="mb-4 flex items-center justify-between">
                <h1 class="text-xl font-bold text-slate-800 dark:text-white">Notifications</h1>
                <div class="flex gap-2">
                    <Button label="Mark all read" size="small" severity="secondary" outlined @click="markAllRead" />
                    <Button label="Delete all read" size="small" severity="danger" outlined @click="deleteAllRead" />
                </div>
            </div>

            <div class="mb-4 flex gap-2">
                <Button
                    label="All"
                    size="small"
                    :severity="filter === 'all' ? 'primary' : 'secondary'"
                    :outlined="filter !== 'all'"
                    @click="setFilter('all')"
                />
                <Button
                    label="Unread"
                    size="small"
                    :severity="filter === 'unread' ? 'primary' : 'secondary'"
                    :outlined="filter !== 'unread'"
                    @click="setFilter('unread')"
                />
            </div>

            <div class="neu-card rounded-2xl p-4 transition-colors duration-300">
                    <div v-if="items.length === 0" class="py-10 text-center text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        No notifications to show.
                    </div>
                    <div
                        v-for="notification in items"
                        :key="notification.id"
                        role="button"
                        tabindex="0"
                        class="group relative flex w-full flex-col gap-1 border-b py-3 text-left last:border-b-0 cursor-pointer"
                        :class="[
                            isDark ? 'border-white/10' : 'border-slate-100',
                            !notification.is_read ? (isDark ? 'bg-blue-500/10 -mx-4 px-4' : 'bg-blue-50/60 -mx-4 px-4') : '',
                        ]"
                        @click="openNotification(notification)"
                        @keydown.enter="openNotification(notification)"
                    >
                        <span class="flex items-center gap-2 pr-8 text-sm font-semibold" :class="isDark ? 'text-white' : 'text-slate-800'">
                            <img
                                v-if="notification.actor?.profile_photo_url"
                                :src="notification.actor.profile_photo_url"
                                alt=""
                                loading="lazy"
                                decoding="async"
                                class="h-6 w-6 shrink-0 rounded-full object-cover"
                            />
                            <span
                                v-else-if="notification.actor"
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white"
                                :class="isDark ? 'bg-white/10' : 'bg-slate-400'"
                            >
                                {{ actorInitials(notification.actor) }}
                            </span>
                            <span v-else>{{ iconFor(notification.type) }}</span>
                            <span>{{ notification.title }}</span>
                            <span v-if="notification.actor" class="font-normal" :class="isDark ? 'text-slate-500' : 'text-slate-400'">— {{ notification.actor.name }}</span>
                            <span v-if="!notification.is_read" class="ml-auto h-2 w-2 shrink-0 rounded-full bg-blue-500"></span>
                        </span>
                        <span class="whitespace-pre-line pr-8 text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">{{ notification.message }}</span>
                        <span class="text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ new Date(notification.created_at).toLocaleString() }}</span>

                        <!-- Delete is only offered for already-read
                             notifications (see NotificationController::destroy())
                             — an unread one is still something the
                             recipient hasn't seen yet, so it can't be
                             cleared out from under them. Uses
                             @click.stop since this button sits inside
                             the row's own click handler. -->
                        <button
                            v-if="notification.is_read"
                            type="button"
                            class="absolute right-1 top-3 rounded-full p-1.5 opacity-0 transition-opacity group-hover:opacity-100"
                            :class="isDark ? 'text-slate-500 hover:bg-white/10 hover:text-red-400' : 'text-slate-400 hover:bg-slate-100 hover:text-red-600'"
                            aria-label="Delete notification"
                            @click.stop="deleteNotification(notification)"
                        >
                            <i class="pi pi-trash text-xs"></i>
                        </button>
                    </div>
            </div>

            <div v-if="notifications.links?.length > 3" class="mt-4 flex flex-wrap justify-center gap-1">
                <Link
                    v-for="link in notifications.links"
                    :key="link.label"
                    :href="link.url || '#'"
                    v-html="link.label"
                    class="rounded px-3 py-1 text-xs"
                    :class="[
                        link.active ? 'bg-blue-600 text-white' : (isDark ? 'text-slate-400 hover:bg-white/10' : 'text-slate-500 hover:bg-slate-100'),
                        !link.url ? 'pointer-events-none opacity-40' : '',
                    ]"
                    preserve-scroll
                />
            </div>
        </div>
    </AppLayout>
</template>
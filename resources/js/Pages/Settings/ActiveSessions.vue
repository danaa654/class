<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BackLink from '@/Components/BackLink.vue';
import Card from 'primevue/card';
import Button from 'primevue/button';
import Tag from 'primevue/tag';

const props = defineProps({
    users: { type: Array, required: true },
});

const forceLogoutForm = useForm({});

function forceLogout(session) {
    if (! confirm('Log this device out now? The user will be signed out immediately.')) {
        return;
    }

    forceLogoutForm.delete(route('settings.active-sessions.destroy', session.id), {
        preserveScroll: true,
    });
}

function refresh() {
    router.reload({ only: ['users'] });
}
</script>

<template>
    <Head title="Active Sessions" />

    <AppLayout>
        <div class="max-w-5xl mx-auto w-full">
            <div class="mb-6 flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-[#1E293B] flex items-center gap-2">
                        Active Sessions
                    </h1>
                    <p class="mt-1 text-slate-500 max-w-2xl">
                        Everyone currently signed in to Classly. A user can appear more than once if they're
                        signed in on more than one device.
                    </p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <Button icon="pi pi-refresh" label="Refresh" text @click="refresh" />
                    <BackLink :href="route('settings')" label="Back to Settings" />
                </div>
            </div>

            <div v-if="users.length === 0" class="neu-card rounded-2xl p-10 text-center text-slate-500">
                No one else is currently signed in.
            </div>

            <div v-else class="space-y-4">
                <div v-for="entry in users" :key="entry.user_id" class="neu-card rounded-2xl p-5">
                    <Card class="!rounded-2xl !bg-transparent !border-0 !shadow-none" :pt="{ body: { class: '!bg-transparent !p-0' } }">
                        <template #content>
                            <div class="flex items-center justify-between mb-3">
                                <div>
                                    <span class="font-semibold text-[#1E293B]">{{ entry.name }}</span>
                                    <Tag v-if="entry.role" :value="entry.role" severity="secondary" class="!text-[10px] ml-2 align-middle" />
                                </div>
                                <span class="text-xs text-slate-400">
                                    {{ entry.sessions.length }} device{{ entry.sessions.length === 1 ? '' : 's' }}
                                </span>
                            </div>

                            <ul class="space-y-2">
                                <li
                                    v-for="session in entry.sessions"
                                    :key="session.id"
                                    class="neu-inset flex items-center justify-between rounded-xl px-3 py-2"
                                >
                                    <div class="flex flex-col">
                                        <span class="text-sm text-[#1E293B]">
                                            {{ session.device }}
                                            <Tag v-if="session.is_current" value="This device" severity="success" class="!text-[10px] ml-1 align-middle" />
                                        </span>
                                        <span class="text-xs text-slate-400">
                                            {{ session.ip_address ?? 'Unknown IP' }} · Last active {{ session.last_active }}
                                        </span>
                                    </div>
                                    <Button
                                        v-if="! session.is_current"
                                        label="Log out"
                                        icon="pi pi-sign-out"
                                        size="small"
                                        severity="danger"
                                        text
                                        @click="forceLogout(session)"
                                    />
                                </li>
                            </ul>
                        </template>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
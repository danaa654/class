<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ProgressBar from 'primevue/progressbar';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import InputNumber from 'primevue/inputnumber';
import Button from 'primevue/button';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');
const page = usePage();
const toast = useToast();
const canChangeMaxLoad = computed(() => !!page.props.auth?.can?.changeFacultyMaxLoad);

const props = defineProps({
    activeTerm: { type: Object, default: null },
    stats: { type: Object, required: true },
    alerts: { type: Object, required: true },
    collegeProgress: { type: Array, default: () => [] },
    topFaculty: { type: Array, default: () => [] },
    topRooms: { type: Array, default: () => [] },
    recentActivity: { type: Array, default: () => [] },
});

const termLabel = computed(() => {
    if (!props.activeTerm) return 'No Active Academic Term';
    return `${props.activeTerm.school_year} | ${props.activeTerm.semester}`;
});

// ADD UNITS — quick edit of a faculty member's load ceiling right from
// the Faculty Utilization widget, instead of navigating to their full
// Faculty Details page just to change one number. Posts to the same
// changeMaxLoad-gated endpoint the Faculty Details page uses, so the
// Registrar sees the exact same cap/authorization rules here.
const addUnitsDialogVisible = ref(false);
const addUnitsTarget = ref(null);
const addUnitsForm = useForm({ value: 0 });

const openAddUnits = (faculty) => {
    addUnitsTarget.value = faculty;
    addUnitsForm.clearErrors();
    addUnitsForm.value = faculty.max;
    addUnitsDialogVisible.value = true;
};

const closeAddUnits = () => {
    addUnitsDialogVisible.value = false;
    addUnitsTarget.value = null;
    addUnitsForm.clearErrors();
};

const submitAddUnits = () => {
    if (!addUnitsTarget.value) return;

    addUnitsForm.patch(route('scheduling.faculty.max-load.update', addUnitsTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({ severity: 'success', summary: 'Updated', detail: `${addUnitsTarget.value.name}'s maximum load was updated.`, life: 2500 });
            closeAddUnits();
        },
        onError: () => {
            toast.add({ severity: 'warn', summary: 'Could not update', detail: 'Please check the value and try again.', life: 3000 });
        },
    });
};

const alertItems = computed(() => {
    const items = [];
    if (props.alerts.no_faculty > 0) {
        items.push({
            text: `${props.alerts.no_faculty} subject${props.alerts.no_faculty === 1 ? '' : 's'} have no faculty assigned.`,
            href: route('scheduling.sections'),
        });
    }
    if (props.alerts.no_room > 0) {
        items.push({
            text: `${props.alerts.no_room} subject${props.alerts.no_room === 1 ? '' : 's'} have no room assigned.`,
            href: route('scheduling.sections'),
        });
    }
    if (props.alerts.faculty_overload > 0) {
        items.push({
            text: `${props.alerts.faculty_overload} faculty overload${props.alerts.faculty_overload === 1 ? '' : 's'} detected.`,
            href: route('scheduling.faculty'),
        });
    }
    if (props.alerts.room_conflicts > 0) {
        items.push({
            text: `${props.alerts.room_conflicts} room conflict${props.alerts.room_conflicts === 1 ? '' : 's'} found.`,
            href: route('scheduling.rooms'),
        });
    }
    if (props.alerts.sections_needing_scheduling > 0) {
        items.push({
            text: `${props.alerts.sections_needing_scheduling} section${props.alerts.sections_needing_scheduling === 1 ? '' : 's'} still require manual scheduling.`,
            href: route('scheduling.sections'),
        });
    }
    return items;
});

// Summary stat cards — icon, accent color, and glow per card. Kept
// inline (rather than the shared KpiCard, which only defines 4 accent
// colors) since this dashboard needs seven distinct accents at a glance.
//
// NOTE: 'remaining_subjects' links to scheduling.sections (the
// sidebar's actively-maintained Sections list — Type/Prospectus/
// Scheduling-status columns), NOT scheduling.section-subjects (an
// older, thinner list page with the same rows but fewer columns).
// Both pages exist and both eventually reach the same section detail
// view, but sending Dashboard traffic to the stale one is why it used
// to look like a "different" Sections page depending on how you got
// there — same fix applied to every alert href above.
const statCards = computed(() => [
    { key: 'total_sections', label: 'Sections', value: props.stats.total_sections, icon: 'pi-th-large', color: isDark.value ? '#5B9CFF' : '#2563EB', glow: isDark.value ? 'rgba(91, 156, 255, 0.3)' : 'rgba(37, 99, 235, 0.25)' },
    { key: 'total_subjects', label: 'Subjects', value: props.stats.total_subjects, icon: 'pi-book', color: isDark.value ? '#5B9CFF' : '#2563EB', glow: isDark.value ? 'rgba(91, 156, 255, 0.3)' : 'rgba(37, 99, 235, 0.25)' },
    { key: 'scheduled_subjects', label: 'Successfully Scheduled', value: props.stats.scheduled_subjects, icon: 'pi-check-circle', color: isDark.value ? '#34D399' : '#059669', glow: isDark.value ? 'rgba(52, 211, 153, 0.3)' : 'rgba(5, 150, 105, 0.25)' },
    { key: 'remaining_subjects', label: 'Needs Scheduling', value: props.stats.remaining_subjects, icon: 'pi-exclamation-circle', color: isDark.value ? '#FBBF24' : '#D97706', glow: isDark.value ? 'rgba(251, 191, 36, 0.3)' : 'rgba(217, 119, 6, 0.25)', href: route('scheduling.sections') },
    { key: 'completion', label: 'Complete', value: `${props.stats.completion}%`, icon: 'pi-percentage', color: isDark.value ? '#5B9CFF' : '#2563EB', glow: isDark.value ? 'rgba(91, 156, 255, 0.3)' : 'rgba(37, 99, 235, 0.25)' },
    { key: 'active_rooms', label: 'Rooms Used', value: props.stats.active_rooms, icon: 'pi-building', color: isDark.value ? '#C4B5FD' : '#7C3AED', glow: isDark.value ? 'rgba(196, 181, 253, 0.3)' : 'rgba(124, 58, 237, 0.25)' },
    { key: 'active_faculty', label: 'Faculty Assigned', value: props.stats.active_faculty, icon: 'pi-users', color: isDark.value ? '#C4B5FD' : '#7C3AED', glow: isDark.value ? 'rgba(196, 181, 253, 0.3)' : 'rgba(124, 58, 237, 0.25)' },
]);

const facultyBarColorClass = (units, max) => {
    if (!max) return '!bg-slate-400';
    const ratio = units / max;
    if (ratio > 1) return '!bg-red-500';
    if (ratio >= 0.85) return '!bg-amber-500';
    return '!bg-blue-500';
};

const activityStatusSeverity = (status) => {
    if (status === 'Scheduled') return 'success';
    if (status === 'Conflict') return 'danger';
    return 'warn';
};

const quickActions = [
    { label: 'Generate All Schedules', icon: 'pi-bolt', href: 'scheduling.sections', accent: true },
    { label: 'Validate Schedules', icon: 'pi-check-square', href: 'scheduling.sections' },
    { label: 'Detect Conflicts', icon: 'pi-search', href: 'scheduling.sections' },
    // Reuses the existing Master Schedule report (Reports > Scheduling)
    // rather than a separate export — passing report_type in the query
    // makes ReportsController generate it immediately on load, so this
    // lands straight on the ready-to-print/export table.
    { label: 'Export Master Schedule', icon: 'pi-download', href: 'reports', params: { report_type: 'master_schedule' } },
];
</script>

<template>
    <Head title="Scheduling Dashboard" />

    <AppLayout>
        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Scheduling Dashboard</span>
        </template>

        <div class="max-w-7xl mx-auto w-full">
            <!-- Page Title -->
            <div class="neu-card neu-spotlight relative mb-8 overflow-hidden rounded-2xl">
                <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                    <div class="absolute inset-0" :class="isDark ? 'bg-gradient-to-br from-blue-500/10 via-transparent to-red-500/10' : 'bg-gradient-to-br from-blue-50 via-transparent to-red-50/60'"></div>
                    <div class="absolute -right-10 -top-16 h-44 w-44 rounded-full bg-gradient-to-br" :class="isDark ? 'from-blue-500/20 via-blue-500/5 to-transparent' : 'from-blue-200/80 via-blue-100/40 to-transparent'"></div>
                    <div class="absolute -bottom-20 right-16 h-40 w-40 rounded-full bg-gradient-to-tr" :class="isDark ? 'from-red-500/20 via-red-500/5 to-transparent' : 'from-red-200/70 via-red-100/30 to-transparent'"></div>
                    <div class="absolute -left-10 bottom-0 h-28 w-28 rounded-full bg-gradient-to-tr border-4" :class="isDark ? 'from-blue-500/10 to-transparent border-blue-400/10' : 'from-blue-100/60 to-transparent border-blue-100'"></div>
                    <div class="absolute left-[38%] top-0 h-3 w-3 rounded-full bg-gradient-to-br" :class="isDark ? 'from-red-400/50 to-blue-400/30' : 'from-red-400/80 to-blue-300/60'"></div>
                    <div class="absolute left-[55%] bottom-4 h-20 w-20 rounded-full bg-gradient-to-r blur-xl" :class="isDark ? 'from-blue-500/10 to-red-500/10' : 'from-blue-200/40 to-red-200/40'"></div>
                </div>
                <div class="relative flex flex-wrap items-start justify-between gap-3 rounded-2xl p-6 transition-colors duration-300">
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2" :class="isDark ? 'text-white' : 'text-[#1E293B]'">
                            Scheduling Dashboard
                            <InfoPopover
                                title="Scheduling Dashboard"
                                :paragraphs="[
                                    'A live overview of scheduling progress, conflicts, and workload for the active academic term.',
                                ]"
                                :bullets="[
                                    'Stat cards and progress reflect the active academic term shown at the top right.',
                                    'Alerts link straight to the relevant workspace so you can act on them immediately.',
                                ]"
                            />
                        </h1>
                        <p class="mt-1 max-w-2xl" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                            Monitor scheduling progress, detect conflicts, validate schedules, and manage the overall
                            scheduling process for the active semester.
                        </p>
                    </div>
                    <span
                        class="neu-inset neu-glow flex items-center gap-2 self-start rounded-full px-4 py-2 text-sm font-semibold"
                        :class="isDark ? 'text-slate-200' : 'text-slate-600'"
                        :style="{ '--neu-glow-color': isDark ? 'rgba(91, 156, 255, 0.2)' : 'rgba(37, 99, 235, 0.2)' }"
                    >
                        <i class="pi pi-calendar" :class="isDark ? 'text-[#5B9CFF]' : 'text-[#2563EB]'"></i>
                        {{ termLabel }}
                    </span>
                </div>
            </div>

            <!-- Summary Stat Cards -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7">
                <component
                    :is="card.href ? Link : 'div'"
                    v-for="card in statCards"
                    :key="card.key"
                    :href="card.href"
                    class="neu-card rounded-2xl p-4 transition-colors duration-300"
                    :class="card.href ? 'neu-card--clickable cursor-pointer' : ''"
                >
                    <span
                        class="neu-icon-well neu-glow flex h-10 w-10 items-center justify-center rounded-xl"
                        :style="{ '--neu-glow-color': card.glow }"
                    >
                        <i class="pi text-base" :class="[card.icon]" :style="{ color: card.color }"></i>
                    </span>
                    <p class="mt-3 text-2xl font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">{{ card.value }}</p>
                    <p class="mt-1 text-xs font-semibold uppercase tracking-wide" :class="isDark ? 'text-slate-400' : 'text-slate-400'">{{ card.label }}</p>
                </component>
            </div>

            <!-- Scheduling Progress -->
            <div class="neu-card mt-6 rounded-2xl p-6 transition-colors duration-300">
                <p class="text-lg font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Scheduling Progress</p>
                <ProgressBar
                    :value="stats.completion"
                    :showValue="false"
                    class="neu-inset mt-4 h-3 !bg-transparent"
                    :pt="{ root: { class: '!bg-transparent' }, value: { class: '!bg-[#2563EB]' } }"
                />
                <div class="mt-2 flex items-center justify-between text-sm">
                    <span :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                        {{ stats.scheduled_subjects }} of {{ stats.total_subjects }} subjects scheduled
                    </span>
                    <span class="font-semibold" :class="isDark ? 'text-[#5B9CFF]' : 'text-[#2563EB]'">{{ stats.completion }}%</span>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <!-- College Progress -->
                <div class="neu-card rounded-2xl p-6 transition-colors duration-300">
                    <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">College Progress</p>
                    <div v-if="collegeProgress.length === 0" class="mt-3 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        No sections found for the active term.
                    </div>
                    <div v-for="college in collegeProgress" :key="college.id" class="mt-4 first:mt-4">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">{{ college.name }}</span>
                            <span class="font-semibold" :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ college.percent }}%</span>
                        </div>
                        <ProgressBar
                            :value="college.percent"
                            :showValue="false"
                            class="neu-inset h-2 !bg-transparent"
                            :pt="{ root: { class: '!bg-transparent' }, value: { class: '!bg-[#2563EB]' } }"
                        />
                    </div>
                </div>

                <!-- Scheduling Alerts -->
                <div class="neu-card rounded-2xl p-6 transition-colors duration-300">
                    <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Scheduling Alerts</p>
                    <div
                        v-if="alertItems.length === 0"
                        class="neu-inset neu-glow mt-4 flex items-center gap-2 rounded-xl px-4 py-3 text-sm font-medium"
                        :class="isDark ? 'text-emerald-300' : 'text-emerald-700'"
                        :style="{ '--neu-glow-color': isDark ? 'rgba(52, 211, 153, 0.25)' : 'rgba(16, 185, 129, 0.25)' }"
                    >
                        <i class="pi pi-check-circle"></i>
                        No scheduling conflicts detected.
                    </div>
                    <Link
                        v-for="(alert, idx) in alertItems"
                        :key="idx"
                        :href="alert.href"
                        class="neu-card neu-card--clickable neu-glow mt-3 flex items-center gap-2 rounded-xl px-4 py-3 text-sm font-medium transition-colors first:mt-4"
                        :class="isDark ? 'text-amber-300' : 'text-amber-800'"
                        :style="{ '--neu-glow-color': isDark ? 'rgba(251, 191, 36, 0.2)' : 'rgba(217, 119, 6, 0.18)' }"
                    >
                        <i class="pi pi-exclamation-triangle" :class="isDark ? 'text-amber-400' : 'text-amber-500'"></i>
                        {{ alert.text }}
                    </Link>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <!-- Faculty Utilization -->
                <div class="neu-card rounded-2xl p-6 transition-colors duration-300">
                    <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Faculty Utilization</p>
                    <div v-if="topFaculty.length === 0" class="mt-3 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        No faculty assignments yet.
                    </div>
                    <div v-for="f in topFaculty" :key="f.name" class="mt-4 first:mt-4">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">{{ f.name }}</span>
                            <span class="flex items-center gap-2">
                                <span :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ f.units }} / {{ f.max }}</span>
                                <Button
                                    v-if="canChangeMaxLoad"
                                    label="Add Units"
                                    text
                                    size="small"
                                    class="!p-0 !text-xs !h-auto"
                                    @click="openAddUnits(f)"
                                />
                            </span>
                        </div>
                        <ProgressBar
                            :value="f.max ? Math.min(100, (f.units / f.max) * 100) : 0"
                            :showValue="false"
                            class="neu-inset h-2 !bg-transparent"
                            :pt="{ root: { class: '!bg-transparent' }, value: { class: facultyBarColorClass(f.units, f.max) } }"
                        />
                    </div>
                </div>

                <!-- Room Utilization -->
                <div class="neu-card rounded-2xl p-6 transition-colors duration-300">
                    <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Room Utilization</p>
                    <div v-if="topRooms.length === 0" class="mt-3 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                        No room assignments yet.
                    </div>
                    <div v-for="r in topRooms" :key="r.name" class="mt-4 first:mt-4">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium" :class="isDark ? 'text-slate-300' : 'text-slate-700'">{{ r.name }}</span>
                            <span :class="isDark ? 'text-slate-400' : 'text-slate-500'">{{ r.occupancy }}%</span>
                        </div>
                        <ProgressBar
                            :value="r.occupancy"
                            :showValue="false"
                            class="neu-inset h-2 !bg-transparent"
                            :pt="{ root: { class: '!bg-transparent' }, value: { class: '!bg-[#2563EB]' } }"
                        />
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="neu-card mt-6 rounded-2xl p-6 transition-colors duration-300">
                <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Quick Actions</p>
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <component
                        :is="action.disabled ? 'span' : Link"
                        v-for="action in quickActions"
                        :key="action.label"
                        :href="action.href ? route(action.href, action.params) : undefined"
                        v-tooltip.top="action.disabled ? 'Coming soon' : undefined"
                        class="neu-card flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold transition-colors duration-300"
                        :class="[
                            action.disabled ? 'cursor-not-allowed opacity-50' : 'neu-card--clickable cursor-pointer',
                            isDark ? 'text-slate-200' : 'text-slate-700',
                        ]"
                    >
                        <span
                            class="neu-icon-well neu-glow flex h-9 w-9 flex-none items-center justify-center rounded-full"
                            :style="{ '--neu-glow-color': action.accent ? (isDark ? 'rgba(91, 156, 255, 0.3)' : 'rgba(37, 99, 235, 0.25)') : (isDark ? 'rgba(148, 163, 184, 0.15)' : 'rgba(100, 116, 139, 0.12)') }"
                        >
                            <i
                                class="pi text-sm"
                                :class="[action.icon, action.accent ? (isDark ? 'text-[#5B9CFF]' : 'text-[#2563EB]') : (isDark ? 'text-slate-300' : 'text-slate-500')]"
                            ></i>
                        </span>
                        {{ action.label }}
                    </component>
                </div>
                <p class="mt-3 text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                    Generating, validating, and resolving schedules happens on the Section Subjects page, and the
                    printable/exportable Master Schedule lives on Reports — these shortcuts take you straight there.
                </p>
            </div>

            <!-- Recent Scheduling Activity -->
            <div class="neu-card mt-6 rounded-2xl p-6 transition-colors duration-300">
                <p class="text-base font-bold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Recent Scheduling Activity</p>
                <div v-if="recentActivity.length === 0" class="mt-3 text-sm" :class="isDark ? 'text-slate-500' : 'text-slate-400'">
                    No scheduling activity yet.
                </div>
                <div
                    v-for="(activity, idx) in recentActivity"
                    :key="idx"
                    class="neu-inset mt-3 flex items-center justify-between gap-3 rounded-xl px-4 py-3 text-sm first:mt-4"
                >
                    <span :class="isDark ? 'text-slate-200' : 'text-slate-700'">{{ activity.label }}</span>
                    <div class="flex flex-none items-center gap-3">
                        <Tag :value="activity.status" :severity="activityStatusSeverity(activity.status)" />
                        <span class="w-24 text-right text-xs" :class="isDark ? 'text-slate-500' : 'text-slate-400'">{{ activity.updated_at }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Units dialog -->
        <Dialog
            v-model:visible="addUnitsDialogVisible"
            modal
            :header="addUnitsTarget ? `Update ${addUnitsTarget.name}'s Maximum Load` : 'Update Maximum Load'"
            :style="{ width: '26rem' }"
        >
            <div class="flex flex-col gap-3">
                <p class="text-sm" :class="isDark ? 'text-slate-300' : 'text-slate-600'">
                    Current load: {{ addUnitsTarget?.units }} {{ addUnitsTarget?.unit_label ?? 'Units' }}
                </p>
                <label for="addUnitsValue" class="text-sm font-medium" :class="isDark ? 'text-slate-200' : 'text-[#1E293B]'">
                    Maximum {{ addUnitsTarget?.unit_label ?? 'Units' }}
                </label>
                <InputNumber
                    inputId="addUnitsValue"
                    v-model="addUnitsForm.value"
                    :min="0"
                    showButtons
                    class="w-full"
                    :invalid="!!addUnitsForm.errors.value"
                />
                <small v-if="addUnitsForm.errors.value" class="text-red-500">{{ addUnitsForm.errors.value }}</small>
            </div>
            <template #footer>
                <Button label="Cancel" text severity="secondary" :disabled="addUnitsForm.processing" @click="closeAddUnits" />
                <Button label="Save" icon="pi pi-check" :loading="addUnitsForm.processing" @click="submitAddUnits" />
            </template>
        </Dialog>

        <Toast />
    </AppLayout>
</template>
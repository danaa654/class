<script setup>
/**
 * Header pill showing the Academic Term the current user is viewing.
 *
 * For most roles this is just a read-only label (same as before).
 * For Administrator/Registrar/Dean/OIC/Assistant Dean (canSwitch), it
 * becomes a dropdown that
 * lets THEM personally switch which term they're browsing — Dashboard,
 * Reports, Sections defaults, etc. all follow their choice — WITHOUT
 * changing the real system-wide Active term or affecting any other
 * user's session. See App\Support\ViewingTerm on the backend.
 *
 * A "Planning" badge appears whenever the user's viewing term isn't
 * the real Active one, so it's always obvious this isn't the live term.
 */
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Popover from 'primevue/popover';

const page = usePage();

const viewingTerm = computed(() => page.props.viewingAcademicTerm);
const isOverride = computed(() => Boolean(page.props.isViewingOverride));
const canSwitch = computed(() => Boolean(page.props.canSwitchViewingTerm));
const availableTerms = computed(() => page.props.availableAcademicTerms ?? []);

const termLabel = (term) => {
    if (!term) return null;
    return [term.school_year?.name, term.semester?.name].filter(Boolean).join(' • ');
};

const viewingTermLabel = computed(() => termLabel(viewingTerm.value));

const popover = ref();
const toggle = (event) => {
    if (!canSwitch.value) return;
    popover.value?.toggle(event);
};

const switching = ref(false);

function selectTerm(term) {
    if (switching.value || term.id === viewingTerm.value?.id) {
        popover.value?.hide();
        return;
    }

    switching.value = true;
    router.put(
        route('viewing-term.update'),
        { academic_term_id: term.id },
        {
            preserveScroll: true,
            onFinish: () => {
                switching.value = false;
                popover.value?.hide();
            },
        }
    );
}

function returnToActive() {
    if (switching.value || !isOverride.value) {
        popover.value?.hide();
        return;
    }

    switching.value = true;
    router.delete(route('viewing-term.destroy'), {
        preserveScroll: true,
        onFinish: () => {
            switching.value = false;
            popover.value?.hide();
        },
    });
}
</script>

<template>
    <span v-if="viewingTermLabel" class="relative inline-flex">
        <button
            type="button"
            class="neu-navy-inset hidden sm:inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors"
            :class="[
                isOverride ? 'text-amber-300' : 'text-emerald-300',
                canSwitch ? 'cursor-pointer hover:text-white' : 'cursor-default',
            ]"
            :title="canSwitch ? 'Switch the academic term you\'re viewing' : 'Currently Active Academic Term'"
            @click="toggle"
        >
            <i class="pi pi-calendar text-[11px]"></i>
            {{ viewingTermLabel }}
            <span
                v-if="isOverride"
                class="ml-0.5 rounded-full bg-amber-400/20 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-300"
            >
                Planning
            </span>
            <i v-if="canSwitch" class="pi pi-chevron-down text-[9px] opacity-70"></i>
        </button>

        <Popover v-if="canSwitch" ref="popover" :pt="{ content: { class: '!p-0' } }">
            <div class="w-72 max-w-[85vw] overflow-hidden -m-[1px] rounded-2xl neu-term-panel">
                <div class="flex items-center gap-2.5 bg-gradient-to-r from-[#2563EB] to-[#3B82F6] px-4 py-3">
                    <span class="flex items-center justify-center h-6 w-6 rounded-full bg-white/20 shrink-0">
                        <i class="pi pi-calendar text-white text-xs"></i>
                    </span>
                    <p class="font-semibold text-white text-sm tracking-tight">Viewing Academic Term</p>
                </div>

                <div class="px-2 py-2 bg-white max-h-80 overflow-y-auto">
                    <p class="px-2.5 py-1.5 text-[11px] text-slate-500 leading-relaxed">
                        This only changes what <span class="font-semibold">you</span> see. Other users keep
                        seeing their own view.
                    </p>

                    <button
                        v-for="term in availableTerms"
                        :key="term.id"
                        type="button"
                        class="w-full flex items-center justify-between gap-2 px-2.5 py-2 rounded-lg text-left text-sm transition-colors hover:bg-blue-50"
                        :class="term.id === viewingTerm?.id ? 'bg-blue-50 text-[#2563EB] font-semibold' : 'text-slate-700'"
                        :disabled="switching"
                        @click="selectTerm(term)"
                    >
                        <span class="flex items-center gap-2">
                            <i class="pi pi-check text-[11px]" :class="term.id === viewingTerm?.id ? 'opacity-100' : 'opacity-0'"></i>
                            {{ termLabel(term) }}
                        </span>
                        <span
                            class="rounded-full px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                            :class="term.status === 'Active' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'"
                        >
                            {{ term.status === 'Active' ? 'Active' : 'Planning' }}
                        </span>
                    </button>

                    <div v-if="!availableTerms.length" class="px-2.5 py-3 text-sm text-slate-400">
                        No academic terms to switch to yet.
                    </div>

                    <div v-if="isOverride" class="mt-1 border-t border-slate-100 pt-2">
                        <button
                            type="button"
                            class="w-full flex items-center gap-2 px-2.5 py-2 rounded-lg text-left text-sm text-slate-500 hover:bg-slate-50"
                            :disabled="switching"
                            @click="returnToActive"
                        >
                            <i class="pi pi-refresh text-[11px]"></i>
                            Return to Active Term
                        </button>
                    </div>
                </div>
            </div>
        </Popover>
    </span>
</template>

<style scoped>
:deep(.p-popover) {
    border-radius: 1rem;
    overflow: hidden;
    border: none;
    background: transparent;
    box-shadow: none;
}

.neu-term-panel {
    box-shadow:
        0 16px 40px -8px rgba(15, 23, 42, 0.35),
        0 6px 16px -4px rgba(15, 23, 42, 0.15),
        0 1px 0 rgba(255, 255, 255, 0.6) inset;
}
</style>
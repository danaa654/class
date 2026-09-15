<script setup>
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useToast } from 'primevue/usetoast';
import Swal from 'sweetalert2';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from 'primevue/card';
import InputText from 'primevue/inputtext';
import InputNumber from 'primevue/inputnumber';
import Textarea from 'primevue/textarea';
import Select from 'primevue/select';
import Button from 'primevue/button';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Tag from 'primevue/tag';
import Dialog from 'primevue/dialog';
import Toast from 'primevue/toast';
import MultiSelect from 'primevue/multiselect';
import Tabs from 'primevue/tabs';
import TabList from 'primevue/tablist';
import Tab from 'primevue/tab';
import TabPanels from 'primevue/tabpanels';
import TabPanel from 'primevue/tabpanel';
import InfoPopover from '@/Components/InfoPopover.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

// PrimeVue's Dialog is teleported straight to <body>, so it sits
// outside this page's normal DOM tree — the "dark-scope" class still
// reaches it via :deep() (see the <style> block below) for text/input
// colors, but the Dialog's own chrome (panel/header/content/footer
// background) is safest set as literal inline styles here rather than
// relying purely on CSS specificity to beat PrimeVue's theme, since
// an inline style is guaranteed to win regardless of stylesheet order.
const darkDialogPt = computed(() => {
    if (!isDark.value) return { root: { class: '' } };

    const panel = { background: '#0F1730', color: '#F8FAFC', border: '1px solid rgba(255,255,255,0.1)' };

    return {
        root: { class: 'dark-scope', style: panel },
        header: { style: { background: '#0F1730', color: '#F8FAFC', borderBottom: '1px solid rgba(255,255,255,0.1)' } },
        content: { style: { background: '#0F1730', color: '#F8FAFC' } },
        footer: { style: { background: '#0F1730', borderTop: '1px solid rgba(255,255,255,0.1)' } },
    };
});

const props = defineProps({
    sections: { type: Object, default: () => ({ data: [], total: 0, per_page: 10, current_page: 1 }) },
    filters: {
        type: Object,
        default: () => ({ section_search: '' }),
    },
    activeMajors: { type: Array, default: () => [] },
    curriculums: { type: Array, default: () => [] },
    colleges: { type: Array, default: () => [] },
    yearLevels: { type: Array, default: () => [] },
    academicTermOptions: { type: Array, default: () => [] },
    termOptions: { type: Array, default: () => [] },
    sectionTypes: { type: Array, default: () => ['Regular', 'Irregular'] },
});

const toast = useToast();
const page = usePage();

// Restricted-role (Dean/OIC) College lock indicator for the Add
// Section / Batch Add forms. The `activeMajors` list sent from the
// server is ALREADY filtered to this user's authorized College(s) —
// this is purely a UI hint, never the authorization boundary itself;
// SectionController re-derives and re-checks the College server-side
// on every create request regardless of what's shown here.
const scopedCollegeId = computed(() => page.props.auth?.collegeId ?? null);
// SECTION-LEVEL SCHEDULE FINALIZATION — now asymmetric
// (SectionPolicy::finalize()/unlockSchedule()): Finalize is open to
// Registrar/Admin AND a Dean/OIC over their own College/Department
// scope; Unlock stays Registrar/Admin only, deliberately. This is
// UI-visibility only; the Policy re-checks server-side.
const canFinalizeSchedule = computed(() => !!page.props.auth?.can?.finalizeSchedule);
const canUnlockSchedule = computed(() => !!page.props.auth?.can?.unlockSchedule);
// Admin/Registrar only — mirrors SectionPolicy::create(); gates the
// "Add Section" button here the same way canManageRooms gates "Add
// Room" on the Rooms page. Server-side enforcement is still the
// Policy check in SectionController::store()/previewBatch()/
// storeBatch() via authorizeSectionCollege().
const canManageSections = computed(() => !!page.props.auth?.can?.manageSections);
const hasNoAssignedCollege = computed(() => !!page.props.auth?.hasNoAssignedCollege);

// Show a toast whenever the backend flashes a success/error message.
watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) {
            toast.add({ severity: 'success', summary: 'Success', detail: message, life: 4000 });
        }
    },
);
watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) {
            toast.add({ severity: 'error', summary: 'Error', detail: message, life: 4000 });
        }
    },
);

/* ------------------------------------------------------------------ */
/* Search / list                                                       */
/* ------------------------------------------------------------------ */

const search = ref(props.filters.section_search ?? '');
const selectedTerm = ref(props.filters.term ?? 'all');
const selectedCollegeId = ref(props.filters.college_id ?? null);
const selectedYearLevel = ref(props.filters.year_level || null);
const selectedSchedulingStatus = ref(props.filters.scheduling_status || 'all');
const loading = ref(false);
let searchDebounce = null;

// College dropdown — "All Colleges" plus every College this user is
// authorized to see (already scoped server-side, see
// SectionController::index()).
const collegeFilterOptions = computed(() => [
    { label: 'All Colleges', value: null },
    ...props.colleges.map((college) => ({ label: college.name, value: college.id })),
]);

const yearLevelFilterOptions = computed(() => [
    { label: 'All Year Levels', value: null },
    ...props.yearLevels.map((level) => ({ label: level, value: level })),
]);

const schedulingStatusFilterOptions = [
    { label: 'All Scheduling Statuses', value: 'all' },
    { label: 'No Subjects Yet', value: 'no_subjects' },
    { label: 'In Progress', value: 'in_progress' },
    { label: 'Fully Scheduled', value: 'fully_scheduled' },
    { label: 'Finalized / Locked', value: 'finalized' },
    { label: 'Needs Attention', value: 'needs_attention' },
];

const reloadSections = (extra = {}) => {
    loading.value = true;

    router.get(
        route('scheduling.sections'),
        {
            section_search: search.value,
            term: selectedTerm.value,
            college_id: selectedCollegeId.value,
            year_level: selectedYearLevel.value,
            scheduling_status: selectedSchedulingStatus.value,
            ...extra,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['sections'],
            onFinish: () => {
                loading.value = false;
            },
        },
    );
};

// Switching the Academic Term filter re-queries immediately (unlike
// the text search, which debounces) — it's a deliberate dropdown pick,
// not something the user is still typing.
const onTermChange = () => {
    reloadSections({ section_page: 1 });
};

// Same as onTermChange — College / Year Level / Scheduling Status are
// deliberate dropdown picks, so they re-query immediately and reset
// back to page 1 (a stale page 3 could otherwise land past the end of
// a much smaller filtered result set).
const onFilterChange = () => {
    reloadSections({ section_page: 1 });
};

// "Filters (n)" / chip count — Academic Year/Semester is deliberately
// excluded, since it's the page's primary context selector rather than
// an optional filter (spec: "must remain the primary context for the
// page").
const activeFilterChips = computed(() => {
    const chips = [];

    if (selectedCollegeId.value) {
        const college = props.colleges.find((item) => item.id === selectedCollegeId.value);
        chips.push({ key: 'college', label: college?.name ?? 'College' });
    }
    if (selectedYearLevel.value) {
        chips.push({ key: 'yearLevel', label: selectedYearLevel.value });
    }
    if (selectedSchedulingStatus.value !== 'all') {
        const option = schedulingStatusFilterOptions.find((item) => item.value === selectedSchedulingStatus.value);
        chips.push({ key: 'schedulingStatus', label: option?.label ?? 'Scheduling' });
    }
    if (search.value.trim() !== '') {
        chips.push({ key: 'search', label: `"${search.value.trim()}"` });
    }

    return chips;
});

const hasActiveFilters = computed(() => activeFilterChips.value.length > 0);

const removeFilterChip = (key) => {
    if (key === 'college') selectedCollegeId.value = null;
    if (key === 'yearLevel') selectedYearLevel.value = null;
    if (key === 'schedulingStatus') selectedSchedulingStatus.value = 'all';
    if (key === 'search') search.value = '';

    reloadSections({ section_page: 1 });
};

// Clears every optional filter (search, College, Year Level,
// Scheduling Status) but deliberately leaves the selected Academic
// Year/Semester alone (spec section 10).
const clearFilters = () => {
    search.value = '';
    selectedCollegeId.value = null;
    selectedYearLevel.value = null;
    selectedSchedulingStatus.value = 'all';
    reloadSections({ section_page: 1 });
};

/**
 * Clicking a Section row jumps straight into "Manage Subjects" for that
 * Section — merges the old two-step "find it in Sections, then find it
 * again in Section Subjects" flow into one click. Edit/Delete buttons
 * in the Actions column call @click.stop so they don't also trigger
 * this navigation.
 */
const goToSectionSubjects = (section) => {
    router.get(route('scheduling.section-subjects.show', section.id));
};

const onRowClick = (event) => {
    goToSectionSubjects(event.data);
};

watch(search, () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        reloadSections({ section_page: 1 });
    }, 350);
});

const onPage = (event) => {
    reloadSections({ section_page: event.page + 1 });
};

const onRefresh = () => {
    reloadSections({ section_page: props.sections.current_page });
};

/* ------------------------------------------------------------------ */
/* Add / Edit Section                                                  */
/* ------------------------------------------------------------------ */

const statusOptions = [
    { label: 'Active', value: 'Active' },
    { label: 'Inactive', value: 'Inactive' },
];

const yearLevelOptions = computed(() => props.yearLevels.map((level) => ({ label: level, value: level })));
// Academic Year / Semester — sourced from real AcademicTerm records
// (see SectionController::academicTermSectionOptions()), not a
// generated range, so a Section can never be created for a School
// Year/Semester with no AcademicTerm behind it (no Scheduling
// Preferences, no class hours/days configured). Archived terms are
// already excluded server-side.
//
// Mirrors the Curriculum/Major and Rooms Department/College pattern
// already used in this app: the backend sends one flat list (each row
// carrying its own academic_year), and Semester options are filtered
// down to whichever Academic Year is currently selected on each form.
const academicYearOptions = computed(() => {
    const seen = new Set();
    const options = [];

    for (const term of props.academicTermOptions) {
        if (!seen.has(term.academic_year)) {
            seen.add(term.academic_year);
            options.push({ label: term.academic_year, value: term.academic_year });
        }
    }

    return options;
});

const semesterOptionsFor = (academicYear) => {
    if (!academicYear) {
        return [];
    }

    return props.academicTermOptions
        .filter((term) => term.academic_year === academicYear)
        .map((term) => ({ label: term.semester, value: term.semester }));
};

// A Section already saved under a term that's since been Archived (or,
// in principle, any academic_year/semester no longer in the list)
// would otherwise show a blank Select on its own Edit form — this adds
// its current value back in as a selectable option ONLY on that form,
// so opening Edit never silently clears a value the Section actually
// has. It intentionally doesn't affect the Add Section batch generator,
// which should only ever offer real, non-Archived terms.
const withCurrentValueOption = (options, academicYear, semester, valueKey) => {
    if (!academicYear || !semester) {
        return options;
    }

    const currentValue = valueKey === 'academic_year' ? academicYear : semester;
    const alreadyListed = options.some((option) => option.value === currentValue);

    return alreadyListed ? options : [...options, { label: currentValue, value: currentValue }];
};

const editAcademicYearOptions = computed(() =>
    withCurrentValueOption(academicYearOptions.value, sectionForm.academic_year, sectionForm.semester, 'academic_year'),
);
const editSemesterOptions = computed(() =>
    withCurrentValueOption(semesterOptionsFor(sectionForm.academic_year), sectionForm.academic_year, sectionForm.semester, 'semester'),
);
const batchSemesterOptions = computed(() => semesterOptionsFor(batchForm.academic_year));
const sectionTypeOptions = computed(() =>
    (props.sectionTypes ?? ['Regular', 'Irregular']).map((type) => ({ label: type, value: type })),
);

const addSectionVisible = ref(false);
const editSectionVisible = ref(false);
const editingSection = ref(null);

/* ------------------------------------------------------------------ */
/* Edit Section (single section — Section Code/Name stay editable      */
/* here so a generated name can still be fixed later)                  */
/* ------------------------------------------------------------------ */

const sectionForm = useForm({
    section_code: '',
    section_name: '',
    section_type: 'Regular',
    major_id: null,
    curriculum_id: null,
    year_level: null,
    academic_year: null,
    semester: null,
    estimated_students: 1,
    status: 'Active',
    remarks: '',
});

// Only show curriculums (Prospectuses) that belong to the selected
// College / Program.
const filteredCurriculums = computed(() => {
    if (!sectionForm.major_id) {
        return [];
    }

    return props.curriculums
        .filter((curriculum) => curriculum.major_id === sectionForm.major_id)
        .map((curriculum) => ({ label: `${curriculum.code} — ${curriculum.name}`, value: curriculum.id }));
});

// If the Program changes and the currently selected Prospectus no
// longer belongs to it, clear the Prospectus selection.
watch(
    () => sectionForm.major_id,
    () => {
        const stillValid = filteredCurriculums.value.some(
            (curriculum) => curriculum.value === sectionForm.curriculum_id,
        );
        if (!stillValid) {
            sectionForm.curriculum_id = null;
        }
    },
);

// If the Academic Year changes and the currently selected Semester
// isn't offered under it, clear the Semester selection — mirrors the
// Program -> Prospectus reset above.
watch(
    () => sectionForm.academic_year,
    () => {
        const stillValid = editSemesterOptions.value.some(
            (semester) => semester.value === sectionForm.semester,
        );
        if (!stillValid) {
            sectionForm.semester = null;
        }
    },
);


// Edit opens a dialog pre-filled with the section's current info, so a
// typo (section code, name, year level, etc.) can be fixed right here
// without leaving the list. Assigning subjects/faculty/rooms still
// only happens on the Section Subjects workspace.
const openEdit = (section) => {
    editingSection.value = section;
    sectionForm.clearErrors();
    sectionForm.section_code = section.section_code;
    sectionForm.section_name = section.section_name;
    sectionForm.section_type = section.section_type ?? 'Regular';
    sectionForm.major_id = section.major_id;
    sectionForm.curriculum_id = section.curriculum_id;
    sectionForm.year_level = section.year_level;
    sectionForm.academic_year = section.academic_year;
    sectionForm.semester = section.semester;
    sectionForm.estimated_students = section.estimated_students;
    sectionForm.status = section.status;
    sectionForm.remarks = section.remarks;
    editSectionVisible.value = true;
};

const closeEditSection = () => {
    editSectionVisible.value = false;
    editingSection.value = null;
    sectionForm.reset();
    sectionForm.clearErrors();
};

const onSaveSection = () => {
    // Guard against double-submit (double-click, or Enter + click) firing
    // two requests before the first one lands — both would pass the
    // "unique" validation check and the second insert would then crash
    // on the database's unique constraint instead of failing validation.
    if (sectionForm.processing || !editingSection.value) {
        return;
    }

    sectionForm.put(route('scheduling.sections.update', editingSection.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            closeEditSection();
            Swal.fire({
                title: 'Section updated',
                text: 'The section was updated successfully.',
                icon: 'success',
                confirmButtonColor: '#16A34A',
            });
            onRefresh();
        },
        onError: () => {
            toast.add({
                severity: 'warn',
                summary: 'Missing information',
                detail: 'Please check the highlighted fields and try again.',
                life: 3000,
            });
        },
    });
};

/* ------------------------------------------------------------------ */
/* Add Section — batch generation flow                                 */
/* (Academic Year / Semester / Program / Year Level / Prospectus +     */
/* Section Prefix + Number of Blocks → BSIT-1A, BSIT-1B, ...)          */
/* ------------------------------------------------------------------ */

const batchForm = useForm({
    academic_year: null,
    semester: null,
    section_type: 'Regular',
    major_id: null,
    year_level: null,
    curriculum_id: null,
    section_prefix: '',
    number_of_blocks: 1,
    estimated_students_per_block: 35,
    status: 'Active',
    remarks: '',
});

// Prospectuses (Curriculum Items) for the selected College / Program.
const filteredProspectuses = computed(() => {
    if (!batchForm.major_id) {
        return [];
    }

    return props.curriculums
        .filter((curriculum) => curriculum.major_id === batchForm.major_id)
        .map((curriculum) => ({ label: `${curriculum.code} — ${curriculum.name}`, value: curriculum.id }));
});

watch(
    () => batchForm.major_id,
    () => {
        const stillValid = filteredProspectuses.value.some(
            (curriculum) => curriculum.value === batchForm.curriculum_id,
        );
        if (!stillValid) {
            batchForm.curriculum_id = null;
        }
    },
);

// Irregular sections don't necessarily follow one Prospectus — its
// subjects are picked manually later (Manual Selection), so Prospectus
// is optional/reference-only for them. Clear it when switching to
// Irregular so a stale selection doesn't linger unseen; the field
// stays visible (disabled+optional) rather than disappearing, since
// the admin may still want to reference one.
//
// Irregular sections are also a single scheduling group, not a set of
// A/B/C blocks (spec section 3) — Number of Blocks doesn't apply, and
// "Estimated Students per Block" becomes a single "Estimated Students"
// count defaulting to 5. Switching back to Regular restores its own
// defaults so re-toggling the type doesn't leave stale values behind.
watch(
    () => batchForm.section_type,
    (type) => {
        if (type === 'Irregular') {
            batchForm.curriculum_id = null;
            batchForm.number_of_blocks = 1;
            batchForm.estimated_students_per_block = 5;
        } else {
            batchForm.estimated_students_per_block = 35;
        }
    },
);

/**
 * Section Prefix auto-suggestion (College/Program + Year Level →
 * "BSIT-1", "BSIT-2", ...). Only overwrites the prefix while the admin
 * hasn't typed their own — tracked via `prefixManuallyEdited` so a
 * Program/Year Level change never silently clobbers a custom prefix.
 */
const prefixManuallyEdited = ref(false);

const yearLevelOrdinal = (yearLevel) => {
    const index = props.yearLevels.indexOf(yearLevel);
    return index === -1 ? null : index + 1;
};

const suggestedPrefix = computed(() => {
    const major = props.activeMajors.find((m) => m.id === batchForm.major_id);
    const ordinal = yearLevelOrdinal(batchForm.year_level);

    if (!major || !ordinal) {
        return '';
    }

    const base = `${major.short_name || major.code}-${ordinal}`;

    // Irregular sections are a single named group (e.g. "BSIT-4-IRREG"),
    // not a letter-suffixed block — see nextIrregularName()'s docblock
    // on the backend. The base "BSIT-4" suggestion still applies, just
    // with the "-IRREG" marker appended.
    return batchForm.section_type === 'Irregular' ? `${base}-IRREG` : base;
});

watch(suggestedPrefix, (suggestion) => {
    if (suggestion && !prefixManuallyEdited.value) {
        batchForm.section_prefix = suggestion;
    }
});

const onPrefixInput = () => {
    // Once the admin types something that no longer matches the
    // auto-suggestion, stop overwriting it on further Program/Year
    // Level changes.
    prefixManuallyEdited.value = batchForm.section_prefix !== suggestedPrefix.value;
};

// The live "Sections to be created" preview — fetched from the server
// (previewBatch) so the next-available-letter logic always matches
// what save will actually do, rather than duplicating that logic in
// JS and risking it drifting out of sync.
const previewSections = ref([]); // [{ section_code, estimated_students }]
const previewLoading = ref(false);
const previewError = ref('');
const nameErrors = ref({}); // index -> message, from server validation on save

const canPreview = computed(
    () =>
        !!batchForm.academic_year &&
        !!batchForm.semester &&
        !!batchForm.major_id &&
        !!batchForm.year_level &&
        (batchForm.section_type !== 'Regular' || !!batchForm.curriculum_id) &&
        !!batchForm.section_prefix &&
        batchForm.number_of_blocks >= 1 &&
        batchForm.estimated_students_per_block >= 1,
);

let previewDebounce = null;

// Reads Laravel's XSRF-TOKEN cookie (URL-decoded) — same pattern as
// TimeRecommendationSelector.vue / RoomRecommendationSelector.vue /
// FacultyRecommendationSelector.vue. Laravel refreshes this cookie on
// every response, so it self-heals as long as the browser has made
// any request recently — unlike the static <meta name="csrf-token">
// value below, which is baked into the page at the last full load and
// goes stale (causing a 419 "CSRF token mismatch") after the session
// changes underneath it, e.g. a `migrate:fresh` wiping the sessions
// table, or simply an idle tab outliving the session lifetime.
const csrfToken = () => {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
};

const refreshPreview = () => {
    clearTimeout(previewDebounce);

    if (!canPreview.value) {
        previewSections.value = [];
        previewError.value = '';
        return;
    }

    previewDebounce = setTimeout(async () => {
        previewLoading.value = true;
        previewError.value = '';
        try {
            const response = await fetch(route('scheduling.sections.preview-batch'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    major_id: batchForm.major_id,
                    section_type: batchForm.section_type,
                    curriculum_id: batchForm.curriculum_id,
                    year_level: batchForm.year_level,
                    academic_year: batchForm.academic_year,
                    semester: batchForm.semester,
                    section_prefix: batchForm.section_prefix,
                    number_of_blocks: batchForm.number_of_blocks,
                    estimated_students_per_block: batchForm.estimated_students_per_block,
                    // Irregular's single-count field — reuses the same
                    // batchForm value as estimated_students_per_block
                    // (relabeled "Estimated Students" in the template
                    // for Irregular) so there's only one input to keep
                    // in sync; the backend picks whichever key its
                    // section_type branch actually needs.
                    estimated_students: batchForm.estimated_students_per_block,
                }),
            });

            if (!response.ok) {
                // Surface the server's actual validation message instead of
                // a generic one — a 422 from PreviewSectionBatchRequest
                // carries a specific reason (e.g. "The selected prospectus
                // does not belong to the selected program"), and hiding it
                // makes the real cause impossible to see from the UI.
                let serverMessage = '';
                try {
                    const errorBody = await response.json();
                    const firstFieldErrors = errorBody?.errors ? Object.values(errorBody.errors)[0] : null;
                    serverMessage = (Array.isArray(firstFieldErrors) ? firstFieldErrors[0] : null)
                        ?? errorBody?.message
                        ?? '';
                } catch (parseError) {
                    // Response wasn't JSON (e.g. a 500 HTML error page) —
                    // fall through to the generic message below.
                }

                previewError.value = serverMessage
                    ? `Could not generate a preview — ${serverMessage}`
                    : `Could not generate a preview — check the fields above. (HTTP ${response.status})`;
                previewSections.value = [];
                return;
            }

            const data = await response.json();
            previewSections.value = data.sections ?? [];
            nameErrors.value = {};
        } catch (e) {
            previewError.value = 'Could not reach the server to generate a preview.';
        } finally {
            previewLoading.value = false;
        }
    }, 350);
};

watch(
    () => [
        batchForm.academic_year,
        batchForm.semester,
        batchForm.section_type,
        batchForm.major_id,
        batchForm.curriculum_id,
        batchForm.year_level,
        batchForm.section_prefix,
        batchForm.number_of_blocks,
        batchForm.estimated_students_per_block,
    ],
    refreshPreview,
);

/* ------------------------------------------------------------------ */
/* Add Section — "Subjects" step                                       */
/* Lets the admin pick, once, exactly which subjects every block being */
/* created (BSIT-1A, BSIT-1B, ...) will share, instead of having to    */
/* open each section afterward and run "Generate Curriculum Subjects"  */
/* or Manual Selection separately per section. Mirrors the Section     */
/* Subjects page's own "Load From Curriculum" / "Manual Selection"     */
/* tabs so the two entry points feel identical.                       */
/* ------------------------------------------------------------------ */

const subjectsTab = ref('curriculum');

// --- Load From Curriculum tab ---
const subjectOptions = ref([]); // [{ id, subject_code, subject_title, category, units }]
const curriculumSelectedSubjectIds = ref(new Set());
const subjectsLoading = ref(false);
const subjectsError = ref('');

// Only meaningful for Regular sections with a Prospectus + Year Level
// + Semester picked — an Irregular section's subjects are always
// picked manually (Manual Selection tab below).
const canPreviewSubjects = computed(
    () =>
        batchForm.section_type === 'Regular' &&
        !!batchForm.curriculum_id &&
        !!batchForm.year_level &&
        !!batchForm.semester,
);

let subjectsDebounce = null;

const refreshSubjectOptions = () => {
    clearTimeout(subjectsDebounce);

    if (!canPreviewSubjects.value) {
        subjectOptions.value = [];
        subjectsError.value = '';
        return;
    }

    subjectsDebounce = setTimeout(async () => {
        subjectsLoading.value = true;
        subjectsError.value = '';
        try {
            const response = await fetch(route('scheduling.sections.curriculum-subjects-preview'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    curriculum_id: batchForm.curriculum_id,
                    year_level: batchForm.year_level,
                    semester: batchForm.semester,
                }),
            });

            if (!response.ok) {
                subjectsError.value = 'Could not load this prospectus\'s subjects.';
                subjectOptions.value = [];
                return;
            }

            const data = await response.json();
            const previousIds = new Set(curriculumSelectedSubjectIds.value);
            subjectOptions.value = data.subjects ?? [];

            // Default to everything selected the first time a fresh
            // list loads, but preserve the admin's own checks/unchecks
            // across a re-fetch caused by an unrelated field changing
            // (e.g. tweaking Number of Blocks shouldn't silently
            // re-select subjects they'd already unchecked).
            const nextIds = new Set();
            subjectOptions.value.forEach((subject) => {
                if (previousIds.size === 0 || previousIds.has(subject.id)) {
                    nextIds.add(subject.id);
                }
            });
            curriculumSelectedSubjectIds.value = nextIds;
        } catch (e) {
            subjectsError.value = 'Could not reach the server to load subjects.';
        } finally {
            subjectsLoading.value = false;
        }
    }, 350);
};

watch(() => [batchForm.curriculum_id, batchForm.year_level, batchForm.semester, batchForm.section_type], refreshSubjectOptions);

const toggleSubject = (subjectId) => {
    const next = new Set(curriculumSelectedSubjectIds.value);
    if (next.has(subjectId)) {
        next.delete(subjectId);
    } else {
        next.add(subjectId);
    }
    curriculumSelectedSubjectIds.value = next;
};

const allSubjectsSelected = computed(
    () => subjectOptions.value.length > 0 && subjectOptions.value.every((subject) => curriculumSelectedSubjectIds.value.has(subject.id)),
);

const toggleAllSubjects = () => {
    if (allSubjectsSelected.value) {
        curriculumSelectedSubjectIds.value = new Set();
    } else {
        curriculumSelectedSubjectIds.value = new Set(subjectOptions.value.map((subject) => subject.id));
    }
};

// --- Manual Selection tab ---
// Every Active subject for the selected Program (+ General Education),
// independent of Curriculum/Year Level/Semester — same broad pool the
// Section Subjects page's own Manual Selection tab searches. Always
// available once a Program is picked, which is what makes it the only
// Subjects option for Irregular sections (they don't follow one
// Prospectus — see the batchForm.section_type watcher elsewhere on
// this page).
const manualSubjectOptions = ref([]);
const manualSelectedSubjectIds = ref([]); // array — MultiSelect's own v-model shape
const manualSubjectsLoading = ref(false);
const manualSubjectsError = ref('');

let manualSubjectsDebounce = null;

const refreshManualSubjectOptions = () => {
    clearTimeout(manualSubjectsDebounce);

    if (!batchForm.major_id) {
        manualSubjectOptions.value = [];
        manualSubjectsError.value = '';
        return;
    }

    manualSubjectsDebounce = setTimeout(async () => {
        manualSubjectsLoading.value = true;
        manualSubjectsError.value = '';
        try {
            const response = await fetch(route('scheduling.sections.manual-subjects-preview'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ major_id: batchForm.major_id }),
            });

            if (!response.ok) {
                manualSubjectsError.value = 'Could not load subjects for this program.';
                manualSubjectOptions.value = [];
                return;
            }

            const data = await response.json();
            manualSubjectOptions.value = data.subjects ?? [];

            // A Program change may have invalidated some previously
            // hand-picked subjects (they belonged to the old Program)
            // — drop anything no longer in the fresh list rather than
            // silently keeping a stale, now-irrelevant selection.
            const validIds = new Set(manualSubjectOptions.value.map((subject) => subject.id));
            manualSelectedSubjectIds.value = manualSelectedSubjectIds.value.filter((id) => validIds.has(id));
        } catch (e) {
            manualSubjectsError.value = 'Could not reach the server to load subjects.';
        } finally {
            manualSubjectsLoading.value = false;
        }
    }, 350);
};

watch(() => batchForm.major_id, refreshManualSubjectOptions);

// The Subjects panel appears once a Program is picked — Load From
// Curriculum additionally needs a Prospectus/Year Level/Semester (see
// canPreviewSubjects above), but Manual Selection only ever needs the
// Program itself, so it's always reachable even before those are set.
const showSubjectsStep = computed(() => !!batchForm.major_id);

// Union of both tabs' picks — what actually gets sent on save. The two
// tabs track their own selection independently (a checkbox list vs a
// MultiSelect have different natural shapes) and are only merged here.
const combinedSelectedSubjectIds = computed(() => {
    const combined = new Set(curriculumSelectedSubjectIds.value);
    manualSelectedSubjectIds.value.forEach((id) => combined.add(id));
    return combined;
});

// Overview panel data (Prompt: "overview when the user clicks Create
// Section") — the exact subjects about to be placed, resolved from
// whichever tab(s) they came from (curriculum checklist and/or manual
// search), since combinedSelectedSubjectIds only holds ids. EDP Codes
// are never guessed here — they're minted server-side the moment each
// SectionSubject row is actually created (see EDPCodeService), so this
// only shows what's known for certain before that happens.
const combinedSelectedSubjectsPreview = computed(() => {
    const bySubjectId = new Map();
    subjectOptions.value.forEach((subject) => bySubjectId.set(subject.id, subject));
    manualSubjectOptions.value.forEach((subject) => bySubjectId.set(subject.id, subject));

    return Array.from(combinedSelectedSubjectIds.value)
        .map((id) => bySubjectId.get(id))
        .filter(Boolean)
        .sort((a, b) => (a.subject_code || '').localeCompare(b.subject_code || ''));
});


const previewDuplicates = computed(() => {
    const seen = new Map();
    previewSections.value.forEach((row, index) => {
        const key = (row.section_code || '').trim().toUpperCase();
        if (!key) return;
        if (seen.has(key)) {
            seen.get(key).push(index);
        } else {
            seen.set(key, [index]);
        }
    });

    const dupIndexes = new Set();
    for (const indexes of seen.values()) {
        if (indexes.length > 1) {
            indexes.forEach((i) => dupIndexes.add(i));
        }
    }
    return dupIndexes;
});

const hasBlankNames = computed(() => previewSections.value.some((row) => !(row.section_code || '').trim()));

const canSaveBatch = computed(
    () =>
        previewSections.value.length > 0 &&
        previewDuplicates.value.size === 0 &&
        !hasBlankNames.value &&
        !previewLoading.value,
);

const openAdd = () => {
    editingSection.value = null;
    batchForm.reset();
    batchForm.clearErrors();
    prefixManuallyEdited.value = false;
    previewSections.value = [];
    previewError.value = '';
    nameErrors.value = {};
    subjectsTab.value = 'curriculum';
    subjectOptions.value = [];
    curriculumSelectedSubjectIds.value = new Set();
    subjectsError.value = '';
    manualSubjectOptions.value = [];
    manualSelectedSubjectIds.value = [];
    manualSubjectsError.value = '';
    addSectionVisible.value = true;
};

const closeAddSection = () => {
    addSectionVisible.value = false;
    batchForm.reset();
    batchForm.clearErrors();
    prefixManuallyEdited.value = false;
    previewSections.value = [];
    nameErrors.value = {};
    subjectsTab.value = 'curriculum';
    subjectOptions.value = [];
    curriculumSelectedSubjectIds.value = new Set();
    subjectsError.value = '';
    manualSubjectOptions.value = [];
    manualSelectedSubjectIds.value = [];
    manualSubjectsError.value = '';
};

// ARCHIVED-SECTION DETECTION (Rule 10) — called once per preview row
// right before Save. Returns the archived Section payload from
// checkArchived(), or null when no match exists for that row.
const checkArchivedFor = async (row) => {
    try {
        const response = await fetch(route('scheduling.sections.check-archived'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                major_id: batchForm.major_id,
                section_code: (row.section_code || '').trim(),
                academic_year: batchForm.academic_year,
                semester: batchForm.semester,
                year_level: batchForm.year_level,
            }),
        });

        if (!response.ok) {
            return null;
        }

        const body = await response.json();
        return body?.archived ?? null;
    } catch (error) {
        // A failed check should never block Save outright — worst
        // case, the admin just doesn't get the Restore prompt for
        // this one row and ends up creating a fresh instance, exactly
        // like today's behavior before this feature existed.
        return null;
    }
};

// Presents the Restore Existing Section / Create New Section Instance
// / Cancel choice (Rule 3, Rule 10) for ONE row that matched an
// archived Section. Returns 'restore' | 'new' | 'cancel'.
const promptArchivedChoice = async (row, archived) => {
    const subjectCount = archived.section_subjects_count ?? 0;
    const result = await Swal.fire({
        title: `${row.section_code} was previously archived`,
        html: `This section was deleted before${subjectCount ? ` with <b>${subjectCount}</b> subject${subjectCount === 1 ? '' : 's'} and their original EDP codes` : ''}. Restore it, or create a brand-new section instance instead?`,
        icon: 'question',
        showDenyButton: true,
        showCancelButton: true,
        confirmButtonText: 'Restore Existing Section',
        denyButtonText: 'Create New Section Instance',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#16A34A',
        denyButtonColor: '#4F46E5',
        reverseButtons: true,
        // STACKING FIX — see the matching comment in
        // SectionSubjects/Index.vue's promptArchivedChoice(): without
        // this, the prompt renders behind this page's own "Add
        // Section" PrimeVue Dialog (still open behind it mid-submit)
        // instead of on top of it.
        customClass: { container: 'swal-above-dialog' },
        didOpen: (popup) => {
            const container = popup.closest('.swal2-container');
            if (container) {
                container.style.zIndex = 20000;
            }
        },
    });

    if (result.isConfirmed) return 'restore';
    if (result.isDenied) return 'new';
    return 'cancel';
};

// Runs the archived-section check across every row in the current
// preview batch, resolving each conflict with the admin one at a
// time. Rows the admin restores are handled immediately via the
// restore endpoint and dropped from the batch (their original
// subjects/EDP codes are already in place — nothing left to create);
// rows left as "new instance" continue through storeBatch() exactly
// as before. Returns false if the admin cancels out of Save entirely.
const resolveArchivedConflicts = async () => {
    const remaining = [];

    for (const row of previewSections.value) {
        const archived = await checkArchivedFor(row);

        if (!archived) {
            remaining.push(row);
            continue;
        }

        const choice = await promptArchivedChoice(row, archived);

        if (choice === 'cancel') {
            return false;
        }

        if (choice === 'restore') {
            await new Promise((resolve) => {
                router.put(route('scheduling.sections.restore', archived.id), {}, {
                    preserveScroll: true,
                    onFinish: resolve,
                });
            });
            continue; // restored — not part of the create batch
        }

        remaining.push(row); // 'new' — keep creating a fresh instance
    }

    previewSections.value = remaining;
    return true;
};

const onSaveBatch = async () => {
    if (batchForm.processing || !canSaveBatch.value) {
        return;
    }

    nameErrors.value = {};

    const shouldContinue = await resolveArchivedConflicts();
    if (!shouldContinue) {
        return;
    }

    if (previewSections.value.length === 0) {
        // Every row in the batch was restored — nothing left to
        // create, so skip straight to a fresh list instead of
        // posting an empty "sections" array.
        closeAddSection();
        Swal.fire({
            title: 'Section restored',
            text: 'The archived section was restored with its original EDP codes.',
            icon: 'success',
            confirmButtonColor: '#16A34A',
        });
        onRefresh();
        return;
    }

    batchForm
        .transform((data) => ({
            major_id: data.major_id,
            section_type: data.section_type,
            curriculum_id: data.curriculum_id,
            year_level: data.year_level,
            academic_year: data.academic_year,
            semester: data.semester,
            status: data.status,
            remarks: data.remarks,
            sections: previewSections.value.map((row) => ({
                section_code: (row.section_code || '').trim(),
                estimated_students: row.estimated_students,
            })),
            subject_ids: Array.from(combinedSelectedSubjectIds.value),
        }))
        .post(route('scheduling.sections.store-batch'), {
            preserveScroll: true,
            onSuccess: () => {
                const count = previewSections.value.length;
                closeAddSection();
                Swal.fire({
                    title: count === 1 ? 'Section saved' : 'Sections saved',
                    text:
                        count === 1
                            ? 'The section was created successfully.'
                            : `${count} sections were created successfully.`,
                    icon: 'success',
                    confirmButtonColor: '#16A34A',
                });
                onRefresh();
            },
            onError: (errors) => {
                // Map "sections.0.section_code" style errors back onto
                // the matching preview row.
                const mapped = {};
                Object.entries(errors).forEach(([key, message]) => {
                    const match = key.match(/^sections\.(\d+)\.section_code$/);
                    if (match) {
                        mapped[match[1]] = message;
                    }
                });
                nameErrors.value = mapped;


                toast.add({
                    severity: 'warn',
                    summary: 'Check the sections list',
                    detail: 'Please fix the highlighted section names and try again.',
                    life: 3500,
                });
            },
        });
};

const removePreviewRow = (index) => {
    previewSections.value.splice(index, 1);
};

// DELETE CONFIRMATION — three tiers, per what's actually at stake:
//
//   1. Finalized schedule: deletion is blocked outright (also
//      enforced server-side in SectionController::destroy() — this
//      is just the friendlier, immediate frontend message). Must be
//      unlocked first.
//   2. No subjects placed yet: single confirmation is enough —
//      there's no scheduling work to lose.
//   3. Has subjects placed (partial OR fully scheduled — Draft/
//      Scheduled/Conflict rows all count, since any of them
//      represents real work): a first confirmation explaining the
//      section is archived (not permanently destroyed) and can be
//      restored later, THEN a second step requiring the admin to
//      type the section's own name to proceed — the typed-confirmation
//      pattern used for genuinely destructive actions elsewhere in
//      the app, here specifically to prevent a stray misclick from
//      wiping out a section someone has already been scheduling.
const performSectionDelete = (section) => {
    router.delete(route('scheduling.sections.destroy', section.id), {
        preserveScroll: true,
        onSuccess: () => onRefresh(),
    });
};

const onDeleteSection = (section) => {
    if (section.is_finalized) {
        Swal.fire({
            title: 'Schedule is finalized',
            html: `<strong>${section.section_code}</strong>'s schedule is finalized and locked. Unlock it first (Actions → Unlock Schedule) before it can be deleted.`,
            icon: 'info',
            confirmButtonColor: '#4F46E5',
            confirmButtonText: 'Got it',
        });
        return;
    }

    const hasSubjects = (section.total_subjects_count ?? 0) > 0;

    Swal.fire({
        title: 'Delete this section?',
        html: `<p>${section.section_code} — ${section.section_name} will be <strong>archived</strong>, not permanently deleted.</p>
               <p style="margin-top:8px;">Its subjects and EDP codes stay on record and can be brought back later — re-enter the same section name on Add Section and you'll be offered the option to restore it.</p>
               ${hasSubjects ? `<p style="margin-top:8px;color:#DC2626;">This section already has ${section.total_subjects_count} subject${section.total_subjects_count === 1 ? '' : 's'} placed on it — you'll be asked to confirm once more.</p>` : ''}`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#DC2626',
        cancelButtonColor: '#64748B',
        confirmButtonText: hasSubjects ? 'Continue' : 'Yes, delete it',
        cancelButtonText: 'Cancel',
    }).then((firstResult) => {
        if (!firstResult.isConfirmed) {
            return;
        }

        if (!hasSubjects) {
            performSectionDelete(section);
            return;
        }

        Swal.fire({
            title: 'Type the section name to confirm',
            html: `To confirm deleting <strong>${section.section_code}</strong>, type its name below exactly.`,
            input: 'text',
            inputPlaceholder: section.section_code,
            showCancelButton: true,
            confirmButtonText: 'Delete Section',
            confirmButtonColor: '#DC2626',
            cancelButtonText: 'Cancel',
            inputValidator: (value) => {
                if ((value || '').trim() !== section.section_code) {
                    return "That doesn't match — type the section name exactly to confirm.";
                }
            },
        }).then((secondResult) => {
            if (secondResult.isConfirmed) {
                performSectionDelete(section);
            }
        });
    });
};

const onFinalizeSection = (section) => {
    Swal.fire({
        title: 'Finalize this schedule?',
        html: `<strong>${section.section_name}</strong>'s schedule will be locked — Room Grid, manual edits, and Auto Generate will all be blocked until it's unlocked again.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#2563EB',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Yes, finalize it',
    }).then((result) => {
        if (result.isConfirmed) {
            router.post(route('scheduling.sections.finalize', section.id), {}, {
                preserveScroll: true,
                onSuccess: () => onRefresh(),
                onError: () => {
                    toast.add({
                        severity: 'error',
                        summary: 'Could not finalize',
                        detail: 'Something went wrong finalizing this schedule. Please try again.',
                        life: 4000,
                    });
                },
            });
        }
    });
};

const onUnlockSection = (section) => {
    Swal.fire({
        title: 'Unlock this schedule?',
        input: 'text',
        inputLabel: `Reason for unlocking ${section.section_name}`,
        inputPlaceholder: 'e.g. Correcting a room conflict flagged by the Dean',
        inputValidator: (value) => (!value ? 'A reason is required.' : undefined),
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#2563EB',
        cancelButtonColor: '#64748B',
        confirmButtonText: 'Unlock',
    }).then((result) => {
        if (result.isConfirmed) {
            router.post(route('scheduling.sections.unlock', section.id), { reason: result.value }, {
                preserveScroll: true,
                onSuccess: () => onRefresh(),
                onError: () => {
                    toast.add({
                        severity: 'error',
                        summary: 'Could not unlock',
                        detail: 'Something went wrong unlocking this schedule. Please try again.',
                        life: 4000,
                    });
                },
            });
        }
    });
};
</script>

<template>
    <Head title="Sections" />

    <AppLayout>
        <Toast />

        <template #header>
            <span class="text-lg font-semibold" :class="isDark ? 'text-white' : 'text-[#1E293B]'">Sections</span>
        </template>

        <div class="max-w-7xl mx-auto w-full" :class="isDark ? 'dark-scope' : ''">
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
                <div class="relative rounded-2xl p-6 transition-colors duration-300">
                <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2 text-[#1E293B]">
                    Sections
                    <InfoPopover
                        title="Sections"
                        :paragraphs="[
                            'Academic sections (blocks of students) used when building schedules for the selected academic term.',
                        ]"
                        :bullets="[
                            'Irregular sections don\'t follow the standard curriculum flow and may need subjects merged in from a regular section.',
                            'Scheduling status tracks how many of a section\'s subjects have been assigned a room, faculty, and time.',
                            '🔒 A finalized section\'s schedule is locked from normal editing — Admin/Registrar, or a Dean/OIC over their own College, can unlock it.',
                            'Click a row to open that section\'s subjects and manage its schedule.',
                        ]"
                    />
                </h1>
                <p class="mt-1 text-slate-500">
                    Manage academic sections used for class scheduling.
                </p>
                </div>
            </div>

            <div class="neu-card rounded-2xl transition-colors duration-300">
            <Card
                class="!rounded-2xl !bg-transparent !border-0 !shadow-none"
                :pt="{ body: { class: '!bg-transparent' } }"
            >
                <template #content>
                    <!-- Top Toolbar. Built as a plain flex row (not
                         PrimeVue's Toolbar #start/#end slots) because
                         Toolbar's default justify-content: space-between
                         combined with flex-wrap meant that once the
                         filters (search + 4 selects) wrapped onto a
                         second line, "Add Section" became the lone item
                         on that line — and space-between collapses a
                         single wrapped item to the left instead of
                         keeping it right-aligned. Splitting filters and
                         actions into two explicit flex groups, with the
                         actions group pinned via lg:ml-auto, keeps "Add
                         Section" on the right at every width. -->
                    <div class="flex flex-col gap-3 pb-4 lg:flex-row lg:items-center lg:justify-between neu-form">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="relative w-full sm:w-80">
                                <i class="pi pi-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                                <InputText
                                    v-model="search"
                                    v-uppercase
                                    placeholder="Search by code, name, major or year"
                                    class="neu-inset w-full !rounded-xl !border-none !pl-9"
                                    :class="isDark ? '!text-white placeholder:!text-slate-500' : ''"
                                />
                            </span>
                            <Select
                                v-model="selectedCollegeId"
                                :options="collegeFilterOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="All Colleges"
                                class="w-full sm:w-52"
                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                @change="onFilterChange"
                            />
                            <Select
                                v-model="selectedYearLevel"
                                :options="yearLevelFilterOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="All Year Levels"
                                class="w-full sm:w-52"
                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                @change="onFilterChange"
                            />
                            <Select
                                v-model="selectedSchedulingStatus"
                                :options="schedulingStatusFilterOptions"
                                optionLabel="label"
                                optionValue="value"
                                class="w-full sm:w-56"
                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                @change="onFilterChange"
                            />
                            <Select
                                v-model="selectedTerm"
                                :options="termOptions"
                                optionLabel="label"
                                optionValue="value"
                                class="w-full sm:w-64"
                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                @change="onTermChange"
                            >
                                <template #option="{ option }">
                                    <span class="flex items-center gap-2">
                                        {{ option.label }}
                                        <Tag
                                            v-if="option.status === 'Archived'"
                                            value="Archived"
                                            severity="warn"
                                            class="!text-[10px] !py-0.5"
                                        />
                                    </span>
                                </template>
                            </Select>
                        </div>
                        <div class="flex items-center gap-2 shrink-0 lg:ml-auto">
                            <Button
                                icon="pi pi-refresh"
                                severity="secondary"
                                text
                                class="neu-icon-well !rounded-full"
                                :loading="loading"
                                @click="onRefresh"
                                aria-label="Refresh"
                            />
                            <Button v-if="canManageSections" label="Add Section" icon="pi pi-plus" severity="success" @click="openAdd" />
                        </div>
                    </div>

                    <!-- Active Filter Indicator -->
                    <div v-if="hasActiveFilters" class="flex flex-wrap items-center gap-2 pb-4 -mt-2">
                        <span class="text-xs font-medium text-slate-500">Filters ({{ activeFilterChips.length }})</span>
                        <Tag
                            v-for="chip in activeFilterChips"
                            :key="chip.key"
                            severity="secondary"
                            class="!cursor-pointer"
                            @click="removeFilterChip(chip.key)"
                        >
                            <span class="flex items-center gap-1">
                                {{ chip.label }}
                                <i class="pi pi-times text-[10px]"></i>
                            </span>
                        </Tag>
                        <Button
                            label="Clear Filters"
                            size="small"
                            text
                            severity="secondary"
                            class="!py-1 !px-2 !text-xs"
                            @click="clearFilters"
                        />
                    </div>

                    <!-- Sections Table -->
                    <DataTable
                        :value="sections.data"
                        :loading="loading"
                        dataKey="id"
                        class="neu-inset neu-table rounded-xl overflow-hidden"
                        :class="isDark ? 'neu-table-dark' : ''"
                        stripedRows
                        responsiveLayout="scroll"
                        lazy
                        paginator
                        :rows="sections.per_page"
                        :totalRecords="sections.total"
                        :first="(sections.current_page - 1) * sections.per_page"
                        rowHover
                        :rowClass="() => 'cursor-pointer'"
                        @page="onPage"
                        @row-click="onRowClick"
                    >
                        <template #empty>
                            <div class="text-center py-10">
                                <p class="text-slate-500 font-medium">No sections found.</p>
                                <p class="text-slate-400 text-sm mt-1">
                                    <template v-if="hasActiveFilters">
                                        Try changing or clearing your filters.
                                    </template>
                                    <template v-else-if="canManageSections">
                                        Click "Add Section" to create your first section.
                                    </template>
                                </p>
                                <Button
                                    v-if="hasActiveFilters"
                                    label="Clear Filters"
                                    icon="pi pi-filter-slash"
                                    severity="secondary"
                                    outlined
                                    class="mt-3"
                                    @click="clearFilters"
                                />
                                <Button
                                    v-else-if="canManageSections"
                                    label="Add Section"
                                    icon="pi pi-plus"
                                    severity="success"
                                    class="mt-3"
                                    @click="openAdd"
                                />
                            </div>
                        </template>

                        <Column field="section_name" header="Section Name" style="width: 10rem" />
                        <Column header="Type" style="width: 7rem">
                            <template #body="{ data }">
                                <Tag
                                    :value="data.section_type || 'Regular'"
                                    :severity="data.section_type === 'Irregular' ? 'warn' : 'info'"
                                />
                            </template>
                        </Column>
                        <Column header="Program" style="width: 10rem">
                            <template #body="{ data }">
                                {{ data.major?.name || '—' }}
                            </template>
                        </Column>
                        <Column header="Prospectus" style="width: 12rem">
                            <template #body="{ data }">
                                {{ data.curriculum?.code || '—' }}
                            </template>
                        </Column>
                        <Column header="Year Level" style="width: 9rem">
                            <template #body="{ data }">
                                {{ data.year_level }}
                            </template>
                        </Column>
                        <Column header="Academic Year" style="width: 9rem">
                            <template #body="{ data }">
                                {{ data.academic_year }}
                            </template>
                        </Column>
                        <Column header="Est. Students" style="width: 8rem">
                            <template #body="{ data }">
                                {{ data.estimated_students }}
                            </template>
                        </Column>
                        <Column style="width: 9rem">
                            <template #header>
                                <span class="flex items-center gap-1">
                                    Status
                                    <InfoPopover
                                        title="Section Status"
                                        :bullets="[
                                            'Active — currently used for scheduling.',
                                            'Inactive — kept for historical records.',
                                            '🔒 Lock icon — this section is finalized; Admin/Registrar, or a Dean/OIC over their own College, can unlock it to make corrections.',
                                        ]"
                                        width="w-64"
                                    />
                                </span>
                            </template>
                            <template #body="{ data }">
                                <div class="flex items-center gap-1.5">
                                    <Tag
                                        :value="data.status"
                                        :severity="data.status === 'Active' ? 'success' : 'secondary'"
                                    />
                                    <i
                                        v-if="data.is_finalized"
                                        class="pi pi-lock text-amber-500"
                                        v-tooltip.top="`Finalized${data.finalized_at ? ' ' + new Date(data.finalized_at).toLocaleDateString() : ''}`"
                                    ></i>
                                </div>
                            </template>
                        </Column>
                        <Column style="width: 11rem">
                            <template #header>
                                <span class="flex items-center gap-1">
                                    Scheduling
                                    <InfoPopover
                                        title="Scheduling Status"
                                        :bullets="[
                                            'No Subjects Yet — the section has no subjects assigned from the curriculum.',
                                            'Not Scheduled — subjects exist but none have a room, faculty, or time yet.',
                                            'Partially Scheduled — some subjects are scheduled, others still need it.',
                                            'Fully Scheduled — every subject has a complete schedule.',
                                        ]"
                                        width="w-72"
                                    />
                                </span>
                            </template>
                            <template #body="{ data }">
                                <Tag
                                    v-if="data.total_subjects_count === 0"
                                    value="No Subjects Yet"
                                    severity="secondary"
                                />
                                <Tag
                                    v-else-if="data.assigned_subjects_count === 0"
                                    value="Not Scheduled"
                                    severity="secondary"
                                />
                                <Tag
                                    v-else-if="data.assigned_subjects_count < data.total_subjects_count"
                                    :value="`Partially Scheduled (${data.assigned_subjects_count}/${data.total_subjects_count})`"
                                    severity="warn"
                                />
                                <Tag
                                    v-else
                                    :value="`Fully Scheduled (${data.assigned_subjects_count}/${data.total_subjects_count})`"
                                    severity="success"
                                />
                            </template>
                        </Column>
                        <Column header="Actions" style="width: 15rem">
                            <template #body="{ data }">
                                <div class="flex gap-1">
                                    <Button
                                        icon="pi pi-book"
                                        text
                                        rounded
                                        severity="info"
                                        size="small"
                                        aria-label="Manage Subjects"
                                        @click.stop="goToSectionSubjects(data)"
                                    />
                                    <Button
                                        icon="pi pi-pencil"
                                        text
                                        rounded
                                        severity="secondary"
                                        size="small"
                                        aria-label="Edit"
                                        @click.stop="openEdit(data)"
                                    />
                                    <Button
                                        v-if="canFinalizeSchedule && !data.is_finalized && data.total_subjects_count > 0 && data.assigned_subjects_count === data.total_subjects_count"
                                        icon="pi pi-lock"
                                        text
                                        rounded
                                        severity="warn"
                                        size="small"
                                        aria-label="Finalize Schedule"
                                        v-tooltip.top="'Finalize schedule'"
                                        @click.stop="onFinalizeSection(data)"
                                    />
                                    <Button
                                        v-if="canUnlockSchedule && data.is_finalized"
                                        icon="pi pi-lock-open"
                                        text
                                        rounded
                                        severity="warn"
                                        size="small"
                                        aria-label="Unlock Schedule"
                                        v-tooltip.top="'Unlock schedule'"
                                        @click.stop="onUnlockSection(data)"
                                    />
                                    <Button
                                        icon="pi pi-trash"
                                        text
                                        rounded
                                        severity="danger"
                                        size="small"
                                        aria-label="Delete"
                                        @click.stop="onDeleteSection(data)"
                                    />
                                </div>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
            </div>
        </div>

        <!-- Add Section Dialog (batch generation flow) -->
        <Dialog
            v-model:visible="addSectionVisible"
            modal
            header="Add Section"
            :style="{ width: '760px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="darkDialogPt"
            @hide="closeAddSection"
        >
            <form class="flex flex-col gap-5 neu-form" @submit.prevent="onSaveBatch">
                <!-- Academic Information -->
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-2">
                        Academic Information
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
                        <div class="flex flex-col gap-1">
                            <label for="batch_academic_year" class="text-sm font-medium text-slate-700">
                                Academic Year <span class="text-red-500">*</span>
                            </label>
                            <Select
                                id="batch_academic_year"
                                v-model="batchForm.academic_year"
                                :options="academicYearOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="e.g. 2026-2027"
                                :invalid="!!batchForm.errors.academic_year"
                                class="w-full"
                            />
                            <small v-if="batchForm.errors.academic_year" class="text-red-500">
                                {{ batchForm.errors.academic_year }}
                            </small>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="batch_semester" class="text-sm font-medium text-slate-700">
                                Semester <span class="text-red-500">*</span>
                            </label>
                            <Select
                                id="batch_semester"
                                v-model="batchForm.semester"
                                :options="batchSemesterOptions"
                                optionLabel="label"
                                optionValue="value"
                                :disabled="!batchForm.academic_year"
                                :placeholder="batchForm.academic_year ? 'e.g. 1st Semester' : 'Select an academic year first'"
                                :invalid="!!batchForm.errors.semester"
                                class="w-full"
                            />
                            <small v-if="batchForm.errors.semester" class="text-red-500">
                                {{ batchForm.errors.semester }}
                            </small>
                        </div>

                        <div class="flex flex-col gap-1 sm:col-span-2">
                            <label for="batch_section_type" class="text-sm font-medium text-slate-700">
                                Section Type <span class="text-red-500">*</span>
                            </label>
                            <Select
                                id="batch_section_type"
                                v-model="batchForm.section_type"
                                :options="sectionTypeOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="Select section type"
                                :invalid="!!batchForm.errors.section_type"
                                class="w-full"
                            />
                            <small v-if="batchForm.errors.section_type" class="text-red-500">
                                {{ batchForm.errors.section_type }}
                            </small>
                            <p v-else class="text-xs text-slate-400">
                                Irregular sections have subjects scheduled one at a time — Auto Generate will try to
                                merge each one into a compatible Regular section's class before creating an
                                independent schedule.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Program Information -->
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-2">
                        Program Information
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
                        <div class="flex flex-col gap-1 sm:col-span-2">
                            <label for="batch_major_id" class="text-sm font-medium text-slate-700">
                                College / Program <span class="text-red-500">*</span>
                            </label>
                            <Select
                                id="batch_major_id"
                                v-model="batchForm.major_id"
                                :options="activeMajors"
                                optionLabel="name"
                                optionValue="id"
                                filter
                                placeholder="e.g. Bachelor of Science in Information Technology (BSIT)"
                                :invalid="!!batchForm.errors.major_id"
                                class="w-full"
                            />
                            <small v-if="batchForm.errors.major_id" class="text-red-500">
                                {{ batchForm.errors.major_id }}
                            </small>
                            <small v-else-if="scopedCollegeId && !hasNoAssignedCollege" class="text-slate-400">
                                Showing programs for your assigned College only.
                            </small>
                            <small v-else-if="hasNoAssignedCollege" class="text-red-500">
                                Your account has no assigned College yet, so no programs are available. Contact an
                                Administrator.
                            </small>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="batch_year_level" class="text-sm font-medium text-slate-700">
                                Year Level <span class="text-red-500">*</span>
                            </label>
                            <Select
                                id="batch_year_level"
                                v-model="batchForm.year_level"
                                :options="yearLevelOptions"
                                optionLabel="label"
                                optionValue="value"
                                placeholder="e.g. 1st Year"
                                :invalid="!!batchForm.errors.year_level"
                                class="w-full"
                            />
                            <small v-if="batchForm.errors.year_level" class="text-red-500">
                                {{ batchForm.errors.year_level }}
                            </small>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="batch_curriculum_id" class="text-sm font-medium text-slate-700">
                                Prospectus
                                <span v-if="batchForm.section_type === 'Regular'" class="text-red-500">*</span>
                                <span v-else class="text-slate-400 font-normal">(optional / reference)</span>
                            </label>
                            <Select
                                id="batch_curriculum_id"
                                v-model="batchForm.curriculum_id"
                                :options="filteredProspectuses"
                                optionLabel="label"
                                optionValue="value"
                                filter
                                showClear
                                :disabled="!batchForm.major_id"
                                :placeholder="batchForm.major_id ? 'Select a prospectus' : 'Select a program first'"
                                :invalid="!!batchForm.errors.curriculum_id"
                                class="w-full"
                            />
                            <small v-if="batchForm.errors.curriculum_id" class="text-red-500">
                                {{ batchForm.errors.curriculum_id }}
                            </small>
                            <p v-else-if="batchForm.section_type === 'Irregular'" class="text-xs text-slate-400">
                                Irregular sections don't have to follow one Prospectus — subjects are added manually
                                on the section's Manage Subjects page.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Section Generation -->
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-2">
                        Section Generation
                    </p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-x-5 gap-y-4">
                        <div class="flex flex-col gap-1">
                            <label for="batch_prefix" class="text-sm font-medium text-slate-700">
                                Section Prefix <span class="text-red-500">*</span>
                            </label>
                            <InputText
                                id="batch_prefix"
                                v-model="batchForm.section_prefix"
                                v-uppercase
                                :placeholder="batchForm.section_type === 'Regular' ? 'e.g. BSIT-1' : 'e.g. BSIT-4-IRREG'"
                                :invalid="!!batchForm.errors.section_prefix"
                                class="w-full"
                                @input="onPrefixInput"
                            />
                            <small v-if="batchForm.errors.section_prefix" class="text-red-500">
                                {{ batchForm.errors.section_prefix }}
                            </small>
                            <small v-else class="text-slate-400">
                                Auto-suggested from Program + Year Level — edit freely if your school uses a
                                different convention.
                            </small>
                        </div>

                        <div v-if="batchForm.section_type === 'Regular'" class="flex flex-col gap-1">
                            <label for="batch_blocks" class="text-sm font-medium text-slate-700">
                                Number of Blocks <span class="text-red-500">*</span>
                            </label>
                            <InputNumber
                                id="batch_blocks"
                                v-model="batchForm.number_of_blocks"
                                :min="1"
                                :max="100"
                                showButtons
                                buttonLayout="horizontal"
                                :invalid="!!batchForm.errors.number_of_blocks"
                                class="w-full"
                                inputClass="w-full"
                            />
                            <small v-if="batchForm.errors.number_of_blocks" class="text-red-500">
                                {{ batchForm.errors.number_of_blocks }}
                            </small>
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="batch_students" class="text-sm font-medium text-slate-700">
                                {{ batchForm.section_type === 'Regular' ? 'Est. Students per Block' : 'Estimated Students' }}
                                <span class="text-red-500">*</span>
                            </label>
                            <InputNumber
                                id="batch_students"
                                v-model="batchForm.estimated_students_per_block"
                                :min="1"
                                showButtons
                                buttonLayout="horizontal"
                                :invalid="!!batchForm.errors.estimated_students_per_block || !!batchForm.errors.estimated_students"
                                class="w-full"
                                inputClass="w-full"
                            />
                            <small v-if="batchForm.errors.estimated_students_per_block || batchForm.errors.estimated_students" class="text-red-500">
                                {{ batchForm.errors.estimated_students_per_block || batchForm.errors.estimated_students }}
                            </small>
                        </div>
                    </div>
                    <p class="text-xs text-slate-400 mt-2">
                        <template v-if="batchForm.section_type === 'Regular'">
                            Classly automatically generates the next available section letters (A, B, C, ...), skipping
                            any that already exist for this Program, Academic Year and Semester. You can edit the
                            generated names below before saving.
                        </template>
                        <template v-else>
                            An Irregular section is a single scheduling group, not a set of lettered blocks — the name
                            below is exactly what you typed as the Section Prefix. Edit it freely before saving if your
                            school uses a different naming convention.
                        </template>
                    </p>
                </div>

                <!-- Subjects (optional) -->
                <div v-if="showSubjectsStep">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400 mb-2">
                        Subjects <span class="text-slate-400 font-normal normal-case">(optional)</span>
                    </p>

                    <div class="rounded-xl border border-slate-200 overflow-hidden">
                        <Tabs v-model:value="subjectsTab">
                            <TabList>
                                <Tab value="curriculum">Load From Curriculum</Tab>
                                <Tab value="manual">Manual Selection</Tab>
                            </TabList>
                            <TabPanels>
                                <!-- Load From Curriculum -->
                                <TabPanel value="curriculum">
                                    <div v-if="batchForm.section_type !== 'Regular'" class="text-sm text-slate-400 px-1 py-2">
                                        Irregular sections don't follow one Prospectus — use Manual Selection instead.
                                    </div>

                                    <div v-else-if="!batchForm.curriculum_id" class="text-sm text-slate-400 px-1 py-2">
                                        Select a Prospectus above to choose which subjects every section in this batch
                                        starts with.
                                    </div>

                                    <template v-else>
                                        <div class="flex items-center justify-between px-1 py-1">
                                            <label class="flex items-center gap-2 text-sm font-semibold text-slate-700 cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    :checked="allSubjectsSelected"
                                                    :disabled="!subjectOptions.length"
                                                    @change="toggleAllSubjects"
                                                />
                                                Select all
                                                <span v-if="subjectOptions.length" class="text-slate-400 font-normal">
                                                    ({{ curriculumSelectedSubjectIds.size }}/{{ subjectOptions.length }} selected)
                                                </span>
                                            </label>
                                            <i v-if="subjectsLoading" class="pi pi-spin pi-spinner text-slate-400"></i>
                                        </div>

                                        <p v-if="subjectsError" class="text-sm text-red-500 px-1 py-2">{{ subjectsError }}</p>

                                        <p v-else-if="!subjectOptions.length && !subjectsLoading" class="text-sm text-slate-400 px-1 py-2">
                                            No subjects found for this Prospectus, Year Level, and Semester.
                                        </p>

                                        <div v-else class="max-h-56 overflow-y-auto divide-y divide-slate-100 border border-slate-100 rounded-lg">
                                            <label
                                                v-for="subject in subjectOptions"
                                                :key="subject.id"
                                                class="flex items-center gap-3 px-3 py-2 cursor-pointer hover:bg-slate-50"
                                            >
                                                <input
                                                    type="checkbox"
                                                    :checked="curriculumSelectedSubjectIds.has(subject.id)"
                                                    @change="toggleSubject(subject.id)"
                                                />
                                                <span class="flex-1">
                                                    <span class="font-medium text-slate-700">{{ subject.subject_code }}</span>
                                                    <span class="text-slate-500"> — {{ subject.subject_title }}</span>
                                                </span>
                                                <span class="text-xs text-slate-400">{{ subject.units }} units</span>
                                            </label>
                                        </div>

                                        <!-- Add subjects outside this Prospectus — for irregular-style cases -->
                                        <!-- where a section otherwise following this curriculum still needs a -->
                                        <!-- subject from another year level/semester (bridging, replacement, -->
                                        <!-- cross-enrolled). Shares state with the Manual Selection tab -->
                                        <!-- (manualSelectedSubjectIds/manualSubjectOptions) so a subject added -->
                                        <!-- here shows up there too, and both are combined on save via -->
                                        <!-- combinedSelectedSubjectIds. -->
                                        <div class="mt-3 pt-3 border-t border-slate-100">
                                            <p class="text-xs font-semibold text-slate-500 mb-1">
                                                Add subjects outside this Prospectus
                                                <span class="text-slate-400 font-normal normal-case">
                                                    — e.g. a lower-year, bridging, or cross-enrolled subject
                                                </span>
                                            </p>
                                            <MultiSelect
                                                v-model="manualSelectedSubjectIds"
                                                :options="manualSubjectOptions"
                                                optionLabel="subject_code"
                                                optionValue="id"
                                                filter
                                                filterPlaceholder="Search subject code or title"
                                                display="chip"
                                                :loading="manualSubjectsLoading"
                                                placeholder="Search and add a subject..."
                                                class="w-full"
                                                :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                            >
                                                <template #option="{ option }">
                                                    <span class="font-medium">{{ option.subject_code }}</span>
                                                    <span class="text-slate-400"> — {{ option.subject_title }}</span>
                                                </template>
                                            </MultiSelect>
                                            <small v-if="manualSubjectsError" class="text-red-500">{{ manualSubjectsError }}</small>
                                        </div>
                                    </template>
                                </TabPanel>

                                <!-- Manual Selection -->
                                <TabPanel value="manual">
                                    <p class="text-sm text-slate-500 mb-2">
                                        Search any Active subject for this Program — useful for bridging subjects,
                                        replacements, cross-enrolled subjects, or every subject on an Irregular section.
                                    </p>

                                    <div class="flex flex-col gap-1">
                                        <MultiSelect
                                            v-model="manualSelectedSubjectIds"
                                            :options="manualSubjectOptions"
                                            optionLabel="subject_code"
                                            optionValue="id"
                                            filter
                                            filterPlaceholder="Search subject code or title"
                                            display="chip"
                                            :loading="manualSubjectsLoading"
                                            placeholder="Select one or multiple subjects"
                                            class="w-full"
                                            :pt="{ overlay: { class: isDark ? 'dark-scope' : '' } }"
                                        >
                                            <template #option="{ option }">
                                                <span class="font-medium">{{ option.subject_code }}</span>
                                                <span class="text-slate-400"> — {{ option.subject_title }}</span>
                                            </template>
                                        </MultiSelect>
                                        <small v-if="manualSubjectsError" class="text-red-500">{{ manualSubjectsError }}</small>
                                    </div>
                                </TabPanel>
                            </TabPanels>
                        </Tabs>

                        <p class="text-xs text-slate-400 px-4 py-2 border-t border-slate-100">
                            Every section created below will start out with the subjects checked/selected above already
                            placed — two blocks of BSIT-1 (e.g. 1A and 1B) end up sharing the exact same subject list
                            instead of needing "Generate Curriculum Subjects" or Manual Selection run separately for
                            each. You can still add, remove, or override subjects per section afterward.
                        </p>
                    </div>

                    <!-- Subjects & EDP Codes Overview — lets the admin verify the
                         exact subject list about to be placed before clicking
                         Create Section(s). EDP Codes are minted the moment each
                         subject is actually placed (server-side, see
                         EDPCodeService), so the exact code can't be predicted
                         here — this only confirms WHICH subjects will get one,
                         plus the standing rule about what happens if one is
                         later removed. -->
                    <div v-if="combinedSelectedSubjectsPreview.length" class="rounded-xl border border-slate-200 overflow-hidden mt-3">
                        <div class="bg-slate-50 px-4 py-2 border-b border-slate-200">
                            <span class="text-sm font-semibold text-slate-700">
                                Subjects & EDP Codes Overview ({{ combinedSelectedSubjectsPreview.length }})
                            </span>
                        </div>
                        <div class="max-h-40 overflow-y-auto divide-y divide-slate-100">
                            <div
                                v-for="subject in combinedSelectedSubjectsPreview"
                                :key="subject.id"
                                class="flex items-center justify-between px-4 py-1.5 text-sm"
                            >
                                <span>
                                    <span class="font-medium text-slate-700">{{ subject.subject_code }}</span>
                                    <span class="text-slate-500"> — {{ subject.subject_title }}</span>
                                </span>
                                <span class="text-xs text-slate-400 italic">EDP Code assigned on creation</span>
                            </div>
                        </div>
                        <p class="text-xs px-4 py-2 border-t border-slate-100" style="color:#B45309; background-color:#FFFBEB;">
                            Once a subject is placed, its EDP Code is permanent — if it's later removed from a
                            section, that EDP Code will not be reused for a different subject.
                        </p>
                    </div>
                </div>

                <!-- Live Preview -->
                <div v-if="batchForm.section_prefix || previewSections.length" class="rounded-xl border border-slate-200 overflow-hidden">
                    <div class="bg-slate-50 px-4 py-2 border-b border-slate-200 flex items-center justify-between">
                        <span class="text-sm font-semibold text-slate-700">Sections to be created</span>
                        <i v-if="previewLoading" class="pi pi-spin pi-spinner text-slate-400"></i>
                    </div>

                    <p v-if="previewError" class="text-sm text-red-500 px-4 py-3">{{ previewError }}</p>

                    <p v-else-if="!canPreview" class="text-sm text-slate-400 px-4 py-3">
                        Fill in the fields above to preview the sections that will be created.
                    </p>

                    <p v-else-if="!previewSections.length && !previewLoading" class="text-sm text-slate-400 px-4 py-3">
                        No sections to preview yet.
                    </p>

                    <div v-else class="divide-y divide-slate-100">
                        <div
                            v-for="(row, index) in previewSections"
                            :key="index"
                            class="flex items-center gap-3 px-4 py-2"
                        >
                            <div class="flex-1 flex flex-col gap-1">
                                <InputText
                                    v-model="row.section_code"
                                    v-uppercase
                                    class="w-full"
                                    :invalid="previewDuplicates.has(index) || !!nameErrors[index] || !row.section_code?.trim()"
                                />
                                <small v-if="nameErrors[index]" class="text-red-500">{{ nameErrors[index] }}</small>
                                <small v-else-if="previewDuplicates.has(index)" class="text-red-500">
                                    This name is used more than once below.
                                </small>
                                <small v-else-if="!row.section_code?.trim()" class="text-red-500">
                                    Section name is required.
                                </small>
                            </div>
                            <InputNumber
                                v-model="row.estimated_students"
                                :min="1"
                                class="w-32"
                                inputClass="w-full"
                                suffix=" students"
                            />
                            <Button
                                icon="pi pi-times"
                                text
                                rounded
                                severity="danger"
                                size="small"
                                aria-label="Remove"
                                @click="removePreviewRow(index)"
                            />
                        </div>
                    </div>
                </div>

                <!-- Other -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4">
                    <div class="flex flex-col gap-1">
                        <label for="batch_status" class="text-sm font-medium text-slate-700">
                            Status <span class="text-red-500">*</span>
                        </label>
                        <Select
                            id="batch_status"
                            v-model="batchForm.status"
                            :options="statusOptions"
                            optionLabel="label"
                            optionValue="value"
                            class="w-full"
                        />
                    </div>
                    <div class="flex flex-col gap-1">
                        <label for="batch_remarks" class="text-sm font-medium text-slate-700">Remarks</label>
                        <Textarea
                            id="batch_remarks"
                            v-model="batchForm.remarks"
                            autoResize
                            rows="1"
                            placeholder="Optional notes"
                            class="w-full"
                        />
                    </div>
                </div>

                <small v-if="batchForm.errors.sections" class="text-red-500">{{ batchForm.errors.sections }}</small>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeAddSection" />
                <Button
                    :label="previewSections.length > 1 ? `Create ${previewSections.length} Sections` : 'Create Section'"
                    icon="pi pi-check"
                    severity="success"
                    :loading="batchForm.processing"
                    :disabled="batchForm.processing || !canSaveBatch"
                    @click="onSaveBatch"
                />
            </template>
        </Dialog>

        <!-- Edit Section Dialog -->
        <Dialog
            v-model:visible="editSectionVisible"
            modal
            header="Edit Section"
            :style="{ width: '700px' }"
            :breakpoints="{ '960px': '90vw', '640px': '95vw' }"
            :draggable="false"
            :pt="darkDialogPt"
            @hide="closeEditSection"
        >
            <form class="grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4 neu-form" @submit.prevent="onSaveSection">
                <!-- Section Code -->
                <div class="flex flex-col gap-1">
                    <label for="section_code" class="text-sm font-medium text-slate-700">
                        Section Name <span class="text-red-500">*</span>
                    </label>
                    <InputText
                        id="section_code"
                        v-model="sectionForm.section_code"
                        v-uppercase
                        placeholder="e.g. BSIT-1A"
                        :invalid="!!sectionForm.errors.section_code"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.section_code" class="text-red-500">
                        {{ sectionForm.errors.section_code }}
                    </small>
                </div>

                <!-- Section Type -->
                <div class="flex flex-col gap-1">
                    <label for="section_type" class="text-sm font-medium text-slate-700">
                        Section Type <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="section_type"
                        v-model="sectionForm.section_type"
                        :options="sectionTypeOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select section type"
                        :invalid="!!sectionForm.errors.section_type"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.section_type" class="text-red-500">
                        {{ sectionForm.errors.section_type }}
                    </small>
                    <p v-else class="text-xs text-slate-400">
                        Irregular sections have subjects scheduled one at a time — Auto Generate will try to merge
                        each one into a compatible Regular section's class before creating an independent schedule.
                    </p>
                </div>

                <!-- Major -->
                <div class="flex flex-col gap-1">
                    <label for="major_id" class="text-sm font-medium text-slate-700">
                        College / Program <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="major_id"
                        v-model="sectionForm.major_id"
                        :options="activeMajors"
                        optionLabel="name"
                        optionValue="id"
                        filter
                        placeholder="Select a program"
                        :invalid="!!sectionForm.errors.major_id"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.major_id" class="text-red-500">
                        {{ sectionForm.errors.major_id }}
                    </small>
                    <p v-else-if="scopedCollegeId && !hasNoAssignedCollege" class="text-xs text-slate-400">
                        Showing programs for your assigned College only.
                    </p>
                    <p v-else-if="hasNoAssignedCollege" class="text-xs text-red-500">
                        Your account has no assigned College yet, so no programs are available. Contact an
                        Administrator.
                    </p>
                </div>

                <!-- Curriculum -->
                <div class="flex flex-col gap-1">
                    <label for="curriculum_id" class="text-sm font-medium text-slate-700">
                        Prospectus <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="curriculum_id"
                        v-model="sectionForm.curriculum_id"
                        :options="filteredCurriculums"
                        optionLabel="label"
                        optionValue="value"
                        filter
                        :disabled="!sectionForm.major_id"
                        :placeholder="sectionForm.major_id ? 'Select a prospectus' : 'Select a program first'"
                        :invalid="!!sectionForm.errors.curriculum_id"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.curriculum_id" class="text-red-500">
                        {{ sectionForm.errors.curriculum_id }}
                    </small>
                </div>

                <!-- Year Level -->
                <div class="flex flex-col gap-1">
                    <label for="year_level" class="text-sm font-medium text-slate-700">
                        Year Level <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="year_level"
                        v-model="sectionForm.year_level"
                        :options="yearLevelOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select year level"
                        :invalid="!!sectionForm.errors.year_level"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.year_level" class="text-red-500">
                        {{ sectionForm.errors.year_level }}
                    </small>
                </div>

                <!-- Academic Year -->
                <div class="flex flex-col gap-1">
                    <label for="academic_year" class="text-sm font-medium text-slate-700">
                        Academic Year <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="academic_year"
                        v-model="sectionForm.academic_year"
                        :options="editAcademicYearOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select academic year"
                        :invalid="!!sectionForm.errors.academic_year"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.academic_year" class="text-red-500">
                        {{ sectionForm.errors.academic_year }}
                    </small>
                </div>

                <!-- Semester -->
                <div class="flex flex-col gap-1">
                    <label for="semester" class="text-sm font-medium text-slate-700">
                        Semester <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="semester"
                        v-model="sectionForm.semester"
                        :options="editSemesterOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select semester"
                        :invalid="!!sectionForm.errors.semester"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.semester" class="text-red-500">
                        {{ sectionForm.errors.semester }}
                    </small>
                </div>

                <!-- Estimated Students -->
                <div class="flex flex-col gap-1">
                    <label for="estimated_students" class="text-sm font-medium text-slate-700">
                        Estimated Number of Students <span class="text-red-500">*</span>
                    </label>
                    <InputNumber
                        id="estimated_students"
                        v-model="sectionForm.estimated_students"
                        :min="1"
                        showButtons
                        buttonLayout="horizontal"
                        :invalid="!!sectionForm.errors.estimated_students"
                        class="w-full"
                        inputClass="w-full"
                    />
                    <small v-if="sectionForm.errors.estimated_students" class="text-red-500">
                        {{ sectionForm.errors.estimated_students }}
                    </small>
                </div>

                <!-- Status -->
                <div class="flex flex-col gap-1">
                    <label for="status" class="text-sm font-medium text-slate-700">
                        Status <span class="text-red-500">*</span>
                    </label>
                    <Select
                        id="status"
                        v-model="sectionForm.status"
                        :options="statusOptions"
                        optionLabel="label"
                        optionValue="value"
                        placeholder="Select status"
                        :invalid="!!sectionForm.errors.status"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.status" class="text-red-500">
                        {{ sectionForm.errors.status }}
                    </small>
                </div>

                <!-- Remarks -->
                <div class="flex flex-col gap-1 sm:col-span-2">
                    <label for="remarks" class="text-sm font-medium text-slate-700">Remarks</label>
                    <Textarea
                        id="remarks"
                        v-model="sectionForm.remarks"
                        autoResize
                        rows="3"
                        placeholder="Optional notes about this section"
                        :invalid="!!sectionForm.errors.remarks"
                        class="w-full"
                    />
                    <small v-if="sectionForm.errors.remarks" class="text-red-500">
                        {{ sectionForm.errors.remarks }}
                    </small>
                </div>
            </form>

            <template #footer>
                <Button label="Cancel" severity="secondary" outlined @click="closeEditSection" />
                <Button
                    label="Update Section"
                    icon="pi pi-check"
                    severity="success"
                    :loading="sectionForm.processing"
                    :disabled="sectionForm.processing"
                    @click="onSaveSection"
                />
            </template>
        </Dialog>
    </AppLayout>
</template>
<style scoped>
/* Dark-mode overrides. Wrapping an element in the "dark-scope" class
   (added conditionally via isDark) recolors the shared light-mode
   Tailwind utility classes and PrimeVue component chrome used
   throughout this page — including inside Dialogs, which PrimeVue
   teleports to <body> but keeps as one contiguous subtree, so these
   descendant selectors still reach them. */
.dark-scope :deep(.text-\[\#1E293B\]) { color: #F8FAFC !important; }
.dark-scope :deep(.text-slate-700) { color: #CBD5E1 !important; }
.dark-scope :deep(.text-slate-600) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-500) { color: #94A3B8 !important; }
.dark-scope :deep(.text-slate-400) { color: #64748B !important; }
.dark-scope :deep(.bg-white) { background-color: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.bg-slate-50) { background-color: rgba(255, 255, 255, 0.04) !important; }
.dark-scope :deep(.bg-slate-100) { background-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.border-slate-100) { border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.border-slate-200) { border-color: rgba(255, 255, 255, 0.12) !important; }

.dark-scope :deep(.p-card) { background: rgba(255, 255, 255, 0.06) !important; color: #F8FAFC; }
.dark-scope :deep(.p-card .p-card-body) { background: transparent !important; }

.dark-scope :deep(.p-dialog) { background: #0F1730 !important; color: #F8FAFC !important; border: 1px solid rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-dialog-header) { background: #0F1730 !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-dialog-content) { background: #0F1730 !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-dialog-footer) { background: #0F1730 !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-inputtext),
.dark-scope :deep(.p-password-input),
.dark-scope :deep(.p-select),
.dark-scope :deep(.p-multiselect),
.dark-scope :deep(.p-inputnumber-input) {
    background: rgba(255, 255, 255, 0.06) !important;
    border-color: rgba(255, 255, 255, 0.15) !important;
    color: #F8FAFC !important;
}
.dark-scope :deep(.p-select-label),
.dark-scope :deep(.p-multiselect-label) { color: #F8FAFC !important; }

.dark-scope :deep(.p-divider.p-divider-horizontal:before) { border-color: rgba(255, 255, 255, 0.1) !important; }

.dark-scope :deep(.p-tablist) { background: transparent !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-tab) { color: #94A3B8 !important; }
.dark-scope :deep(.p-tab-active) { color: #F8FAFC !important; }

.dark-scope :deep(.p-datatable-thead > tr > th) { background: rgba(255, 255, 255, 0.04) !important; color: #CBD5E1 !important; border-color: rgba(255, 255, 255, 0.1) !important; }
.dark-scope :deep(.p-datatable-tbody > tr) { background: transparent !important; color: #E2E8F0 !important; }
.dark-scope :deep(.p-datatable-tbody > tr.p-datatable-row-striped) { background: rgba(255, 255, 255, 0.03) !important; }
.dark-scope :deep(.p-datatable-tbody > tr > td) { border-color: rgba(255, 255, 255, 0.08) !important; }
.dark-scope :deep(.p-datatable-tbody > tr:hover) { background: rgba(255, 255, 255, 0.06) !important; }
.dark-scope :deep(.p-paginator) { background: transparent !important; color: #CBD5E1 !important; }

.dark-scope :deep(.p-menu) { background: #0F1730 !important; border-color: rgba(255, 255, 255, 0.1) !important; color: #F8FAFC !important; }
.dark-scope :deep(.p-menu .p-menu-item-link) { color: #E2E8F0 !important; }
.dark-scope :deep(.p-menu .p-menu-item-link:hover) { background: rgba(255, 255, 255, 0.06) !important; }
</style>
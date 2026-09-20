<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import ChatWidget from '@/Components/ChatWidget.vue';
import TermSwitcher from '@/Components/TermSwitcher.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

const page = usePage();
const user = computed(() => page.props.auth?.user);

// SCHOOL BRANDING — shared globally by HandleInertiaRequests from
// Settings → General (single source of truth). Kept distinct from
// CLASSLY's own system branding (logo/name) in the header below.
const schoolBranding = computed(() => page.props.schoolBranding ?? { name: null, logoUrl: null });

// User Management (create/edit accounts) is Administrator only —
// Registrar, Dean, OIC and Assistant Dean never see this section, since
// the backend also blocks them from those routes directly.
const authRoles = computed(() => page.props.auth?.roles ?? []);
const isAdministrator = computed(() => authRoles.value.includes('Administrator'));

// Coarse module abilities shared by HandleInertiaRequests — UI
// convenience only. The backend independently re-checks every one of
// these on every request (Policies + Gate::define), so this list is
// never the actual security boundary, only what decides whether a
// link is worth showing.
const can = computed(() => page.props.auth?.can ?? {});

// SIDEBAR — persistent, collapsible navigation rail.
//   Desktop/tablet: always visible. Expanded (230px, icons + labels) or
//   minimized (72px, icon-only rail). It is never hidden completely.
//   Phone widths (below `md`, 768px): an overlay drawer that is closed by
//   default and slides in over the page, so it never squeezes content.
// `sidebarOpen` therefore means "expanded" on desktop and "drawer open" on
// mobile. The desktop choice is remembered between visits.
const MOBILE_BREAKPOINT = 768;
const SIDEBAR_PREF_KEY = 'classly.sidebar.minimized';
const isMobile = ref(false);
const sidebarOpen = ref(true);

const readMinimizedPref = () => {
    try {
        return window.localStorage.getItem(SIDEBAR_PREF_KEY) === '1';
    } catch (e) {
        return false;
    }
};
const writeMinimizedPref = (minimized) => {
    try {
        window.localStorage.setItem(SIDEBAR_PREF_KEY, minimized ? '1' : '0');
    } catch (e) {
        // Storage unavailable (private mode etc.) — the choice just won't persist.
    }
};

// Only (re)apply the default on the first check or when the viewport
// crosses the mobile breakpoint — resizing the window within desktop
// widths must not undo the user's expanded/minimized choice.
let firstViewportCheck = true;
const applyViewport = () => {
    const mobile = window.innerWidth < MOBILE_BREAKPOINT;
    const crossedBreakpoint = mobile !== isMobile.value;
    isMobile.value = mobile;
    if (firstViewportCheck || crossedBreakpoint) {
        firstViewportCheck = false;
        sidebarOpen.value = mobile ? false : !readMinimizedPref();
    }
};

onMounted(() => {
    applyViewport();
    window.addEventListener('resize', applyViewport);
});
onUnmounted(() => window.removeEventListener('resize', applyViewport));

// True only for the minimized desktop icon rail.
const railMode = computed(() => !isMobile.value && !sidebarOpen.value);

// Minimize / expand (desktop) or open / close the drawer (mobile).
const toggleSidebar = () => {
    hideRailTip();
    sidebarOpen.value = !sidebarOpen.value;
    if (!isMobile.value) writeMinimizedPref(!sidebarOpen.value);
};

// Tooltip for the minimized rail. Rendered once, outside the scrolling
// nav, so it can't be clipped by the sidebar's overflow.
const railTip = ref(null);
const showRailTip = (event, label) => {
    if (!railMode.value) return;
    const rect = event.currentTarget.getBoundingClientRect();
    railTip.value = { label, top: rect.top + rect.height / 2 };
};
function hideRailTip() {
    railTip.value = null;
}

const closeSidebarOnMobile = () => {
    if (isMobile.value) sidebarOpen.value = false;
};

const menuItems = [
    { label: 'Dashboard', route: 'dashboard', icon: 'pi pi-home' },
];

const userManagementItems = [
    { label: 'Users', route: 'users', icon: 'pi pi-users' },
];

// Academic Setup — everything that defines the academic environment
// (school year/semester/rules, colleges/departments/programs, the
// curriculum map, and subject records) before scheduling can begin.
// Term Setup/Structure/Curriculum are Admin/Registrar-only
// (spec Section 21); Subjects stays visible to every scheduling role
// since Assistant Dean/Dean/OIC all need to browse it (write access
// is enforced per-row by the backend regardless).
const academicSetupItems = computed(() => [
    ...(can.value.manageAcademicCalendar ? [{ label: 'Term Setup', route: 'academic-calendar', icon: 'pi pi-calendar' }] : []),
    ...(can.value.manageAcademicStructure ? [{ label: 'Academic Structure', route: 'academic-structure', icon: 'pi pi-sitemap' }] : []),
    ...(can.value.manageCurriculum ? [{ label: 'Curriculum', route: 'curriculums', icon: 'pi pi-book' }] : []),
    { label: 'Subjects', route: 'subjects', icon: 'pi pi-bookmark' },
]);

// Resource Management — the people/rooms/sections the scheduling
// engine draws on. Visible to every Scheduling-side role; write
// access within each page is enforced per-record by the backend
// (Faculty/Room/Section Policies) and reflected via `can_manage`
// flags the controllers attach to each row.
const resourceManagementItems = [
    // Load Requests now lives inside the Faculty page itself (a
    // section below the roster) rather than its own nav item — see
    // FacultyController@index and Scheduling/Faculty/Index.vue.
    { label: 'Faculty', route: 'scheduling.faculty', icon: 'pi pi-user' },
    { label: 'Rooms', route: 'scheduling.rooms', icon: 'pi pi-building' },
    { label: 'Sections', route: 'scheduling.sections', icon: 'pi pi-th-large' },
];

// Scheduling — the control center for generating and monitoring
// schedules. (Faculty/Rooms/Sections moved to Resource Management
// above; actual schedule editing still lives under Sections >
// Section Subjects, reached from the Sections page.)
const schedulingItems = [
    { label: 'Scheduling Dashboard', route: 'scheduling', icon: 'pi pi-calendar-plus' },
];

const reportsItems = [
    { label: 'Reports', route: 'reports', icon: 'pi pi-chart-bar' },
];

const systemItems = [
    { label: 'Settings', route: 'settings', icon: 'pi pi-cog' },
];

// A few nav items are "sections" of the app rather than single pages
// — clicking a row takes you into a detail route with a different
// name, but you're still logically inside that nav item. Plain
// route().current(routeName) only matches the exact name, so it goes
// dark the moment you drill in. These three get a wildcard match
// against their whole route-name family instead; every other nav
// item keeps its exact-match behavior untouched.
const WILDCARD_NAV_ROUTES = {
    'scheduling.sections': ['scheduling.sections*', 'scheduling.section-subjects*'],
    'scheduling.faculty': ['scheduling.faculty*'],
    curriculums: ['curriculums*'],
};

const isActive = (routeName) => {
    try {
        if (WILDCARD_NAV_ROUTES[routeName]) {
            return WILDCARD_NAV_ROUTES[routeName].some((pattern) => route().current(pattern));
        }
        return route().current(routeName);
    } catch (e) {
        return false;
    }
};

// Every navigation group in display order. One list drives both the
// expanded sidebar (with section headings) and the minimized rail (with
// dividers), so the two can never drift apart.
const navSections = computed(() => [
    { key: 'main', title: null, items: menuItems },
    ...(isAdministrator.value ? [{ key: 'users', title: 'User Management', items: userManagementItems }] : []),
    ...(academicSetupItems.value.length ? [{ key: 'academic', title: 'Academic Setup', items: academicSetupItems.value }] : []),
    { key: 'resources', title: 'Resource Management', items: resourceManagementItems },
    { key: 'scheduling', title: 'Scheduling', items: schedulingItems },
    { key: 'reports', title: 'Reports', items: reportsItems },
    { key: 'system', title: 'System', items: systemItems },
]);
</script>

<template>
    <div class="relative min-h-screen overflow-hidden transition-colors duration-300" :class="isDark ? 'bg-[#0B1020]' : 'bg-[#F2F2F2]'">
        <!-- Translucent gradient wash behind every page. Fixed, so it stays put while content scrolls. -->
        <div class="app-gradient-bg" :class="isDark ? 'app-gradient-bg--dark' : 'app-gradient-bg--light'" aria-hidden="true"></div>
        <!-- Top Navigation Bar -->
        <header class="neu-navy-surface h-16 w-full flex items-center justify-between px-3 sm:px-6 fixed top-0 left-0 right-0 z-40 border-b border-black/10">
            <div class="flex items-center gap-2 sm:gap-4 min-w-0">
                <button
                    type="button"
                    class="flex h-9 w-9 items-center justify-center rounded-lg transition hover:bg-white/10 active:scale-95"
                    :title="sidebarOpen ? 'Minimize sidebar' : 'Expand sidebar'"
                    :aria-label="sidebarOpen ? 'Minimize sidebar' : 'Expand sidebar'"
                    @click="toggleSidebar"
                >
                    <img src="/logo.png" alt="Toggle sidebar" class="h-7 w-7" />
                </button>
                <span class="text-lg sm:text-xl font-bold tracking-tight text-white shrink-0">CLASSLY</span>

                <!-- School branding (Settings → General) — separate from CLASSLY's own mark above -->
                <template v-if="schoolBranding.name">
                    <span class="h-5 w-px bg-white/15"></span>
                    <div class="hidden items-center gap-2 sm:flex min-w-0">
                        <img
                            v-if="schoolBranding.logoUrl"
                            :src="schoolBranding.logoUrl"
                            alt=""
                            class="h-6 w-6 rounded-full object-cover shrink-0"
                            @error="$event.target.style.display = 'none'"
                        />
                        <span class="max-w-[320px] truncate text-xs font-medium text-slate-300">{{ schoolBranding.name }}</span>
                    </div>
                </template>
            </div>

            <div class="flex items-center gap-2.5 sm:gap-5 shrink-0">
                <TermSwitcher />
                <span class="neu-navy-raised flex h-9 w-9 shrink-0 items-center justify-center rounded-lg">
                    <NotificationBell v-if="user" />
                </span>
                <ThemeToggle />
            </div>
        </header>

        <!-- Backdrop — only rendered as an overlay drawer on mobile; tapping
             it closes the sidebar instead of navigating "through" it. -->
        <div
            v-if="isMobile && sidebarOpen"
            class="fixed inset-0 top-16 z-10 bg-black/50"
            @click="sidebarOpen = false"
        ></div>

        <!-- Left Sidebar — a persistent navigation rail. The active item is a
             "notch" tab in the page colour that bleeds into the content area
             (see .nav-tab in app.css). Expanded = 230px
             (icons + labels); minimized = 72px (icons only, tooltips on
             hover). Minimized/expanded with the CLASSLY logo in the header.
             Never hidden on desktop; on mobile it is a drawer. -->
        <aside
            class="neu-navy-surface fixed top-16 left-0 bottom-0 flex flex-col overflow-hidden text-slate-200 transition-[width] duration-300 ease-in-out"
            :class="[
                isMobile ? (sidebarOpen ? 'w-[230px]' : 'w-0') : (sidebarOpen ? 'w-[230px]' : 'w-[72px]'),
                isMobile ? 'z-30' : 'z-20',
            ]"
            aria-label="Main navigation"
        >
            <nav
                class="sidebar-scroll flex-1 space-y-1 overflow-y-auto overflow-x-hidden py-5 text-[13px]"
                @click="closeSidebarOnMobile"
                @scroll="hideRailTip"
            >
                <div v-for="(section, sectionIndex) in navSections" :key="section.key" :class="sectionIndex > 0 ? 'pt-2' : ''">
                    <!-- Section heading (expanded) / thin divider (minimized) -->
                    <p
                        v-if="section.title && !railMode"
                        class="whitespace-nowrap px-6 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500"
                    >
                        {{ section.title }}
                    </p>
                    <div v-else-if="railMode && sectionIndex > 0" class="mx-auto mb-2 h-px w-7 bg-white/10"></div>

                    <Link
                        v-for="item in section.items"
                        :key="item.label"
                        :href="route(item.route)"
                        :aria-label="item.label"
                        :aria-current="isActive(item.route) ? 'page' : undefined"
                        class="flex h-10 items-center font-medium transition-colors duration-150"
                        :class="isActive(item.route)
                            ? 'nav-tab ml-3 rounded-l-full'
                            : 'ml-3 mr-4 overflow-hidden rounded-xl text-slate-300 hover:bg-white/10 hover:text-white'"
                        @mouseenter="showRailTip($event, item.label)"
                        @mouseleave="hideRailTip"
                        @focus="showRailTip($event, item.label)"
                        @blur="hideRailTip"
                    >
                        <span class="flex h-10 w-11 shrink-0 items-center justify-center">
                            <i :class="item.icon" class="text-[15px] opacity-90"></i>
                        </span>
                        <span v-show="!railMode" class="whitespace-nowrap pr-3">{{ item.label }}</span>
                    </Link>
                </div>
            </nav>

            <!-- Footer: profile, logout -->
            <div class="shrink-0 space-y-1 border-t border-white/10 px-3 pb-3 pt-3">
                <template v-if="user">
                    <Link
                        :href="`${route('settings')}?tab=account`"
                        :aria-label="`${user.name} — account settings`"
                        class="group flex h-[52px] w-full items-center overflow-hidden rounded-xl transition-colors duration-150 hover:bg-emerald-500/15"
                        @mouseenter="showRailTip($event, user.name)"
                        @mouseleave="hideRailTip"
                    >
                        <span class="flex h-[52px] w-11 shrink-0 items-center justify-center">
                            <span v-if="user.profile_photo_url" class="h-8 w-8 overflow-hidden rounded-full">
                                <img :src="user.profile_photo_url" alt="Profile photo" class="h-full w-full object-cover" />
                            </span>
                            <span v-else class="neu-navy-raised flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold text-white">
                                {{ user.name?.charAt(0)?.toUpperCase() }}
                            </span>
                        </span>
                        <span v-show="!railMode" class="min-w-0 flex-1 pr-3">
                            <span class="block truncate text-[13px] font-semibold text-white transition-colors duration-150 group-hover:text-emerald-300">{{ user.name }}</span>
                            <span v-if="authRoles.length" class="block truncate text-[11px] text-slate-400 transition-colors duration-150 group-hover:text-emerald-200/70">{{ authRoles.join(', ') }}</span>
                        </span>
                    </Link>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        aria-label="Logout"
                        class="flex h-10 w-full items-center overflow-hidden rounded-xl text-[13px] font-medium text-slate-300 transition-colors duration-150 hover:bg-red-500/15 hover:text-red-400"
                        @mouseenter="showRailTip($event, 'Logout')"
                        @mouseleave="hideRailTip"
                    >
                        <span class="flex h-10 w-11 shrink-0 items-center justify-center">
                            <i class="pi pi-sign-out text-[13px]"></i>
                        </span>
                        <span v-show="!railMode" class="whitespace-nowrap pr-3">Logout</span>
                    </Link>
                </template>
            </div>
        </aside>

        <!-- Tooltip for the minimized rail (item name on hover) -->
        <div
            v-if="railTip && railMode"
            class="pointer-events-none fixed z-50 -translate-y-1/2 whitespace-nowrap rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg ring-1 ring-white/10"
            :style="{ top: railTip.top + 'px', left: '80px' }"
            role="tooltip"
        >
            {{ railTip.label }}
        </div>

        <!-- Main Content — expands into the space the sidebar releases:
             230px when expanded, 72px beside the minimized rail. On mobile
             the sidebar is an overlay drawer, so padding stays 0. -->
        <main
            class="relative z-0 pt-16 transition-[padding] duration-300 ease-in-out"
            :class="isMobile ? 'pl-0' : (sidebarOpen ? 'pl-[230px]' : 'pl-[72px]')"
        >
            <div class="p-4 sm:p-6 lg:p-8" :class="isDark ? 'text-slate-100' : ''">
                <slot :is-dark="isDark" />
            </div>
        </main>
    
        <!-- IN-SYSTEM MESSAGING — layout-level so the chatbox survives
             page navigation instead of remounting per page. -->
        <ChatWidget v-if="user" />
</div>
</template>

<style scoped>
/* Soft translucent gradient wash behind every page (brand blue / violet /
   red / cyan). Purely decorative: fixed, click-through, and sitting under
   the header, sidebar and page content. Kept shape-free and gentle so it
   stays easy on the eyes for older users. */
.app-gradient-bg {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    overflow: hidden;
}
.app-gradient-bg--light {
    background:
        radial-gradient(60rem 40rem at 0% 0%, rgba(37, 99, 235, 0.26), transparent 60%),
        radial-gradient(50rem 36rem at 100% 10%, rgba(124, 58, 237, 0.20), transparent 60%),
        radial-gradient(55rem 40rem at 100% 100%, rgba(225, 29, 46, 0.15), transparent 60%),
        radial-gradient(50rem 36rem at 0% 100%, rgba(56, 189, 248, 0.20), transparent 60%);
}
.app-gradient-bg--dark {
    background:
        radial-gradient(60rem 40rem at 0% 0%, rgba(37, 99, 235, 0.28), transparent 60%),
        radial-gradient(50rem 36rem at 100% 10%, rgba(124, 58, 237, 0.22), transparent 60%),
        radial-gradient(55rem 40rem at 100% 100%, rgba(225, 29, 46, 0.16), transparent 60%),
        radial-gradient(50rem 36rem at 0% 100%, rgba(56, 189, 248, 0.16), transparent 60%);
}

/* Keep the sidebar nav scrollable (so items are never cut off on shorter
   screens) but hide the scrollbar itself for a clean look. */
.sidebar-scroll {
    scrollbar-width: none; /* Firefox */
    -ms-overflow-style: none; /* IE/Edge legacy */
}
.sidebar-scroll::-webkit-scrollbar {
    display: none; /* Chrome, Edge, Safari */
}
</style>
<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import Button from 'primevue/button';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { useTheme } from '@/composables/useTheme';

const { theme } = useTheme();
const isDark = computed(() => theme.value === 'dark');

// SCHOOL BRANDING — shared globally by HandleInertiaRequests from
// Settings → General. This is distinct from CLASSLY's own system
// branding (name/logo/tagline below), which never changes based on
// this. Falls back gracefully: no logo shown if none configured.
const page = usePage();
const schoolBranding = computed(() => page.props.schoolBranding ?? { name: null, logoUrl: null });

const features = [
    {
        icon: 'pi pi-calendar',
        title: 'Smart Scheduling',
        description: 'Build conflict-free class schedules in minutes, not spreadsheets.',
        accent: '#0EA5E9',
        details: [
            'Auto-generate schedule drafts in seconds',
            'Instant conflict detection for rooms, faculty, and time slots',
            'Review and finalize a term schedule in one click',
        ],
    },
    {
        icon: 'pi pi-users',
        title: 'Faculties',
        description: 'Assign instructors with real-time availability and load checking.',
        accent: '#38BDF8',
        details: [
            'See live faculty availability while assigning classes',
            'Automatic warnings before a teacher is overloaded',
            'Keep every faculty load fair and policy-compliant',
        ],
    },
    {
        icon: 'pi pi-building',
        title: 'Rooms',
        description: 'Match sections to rooms with type, capacity, and usage awareness.',
        accent: '#7DD3FC',
        details: [
            'Recommend rooms based on type and live availability',
            'Flag capacity and room-type mismatches automatically',
            'See utilization across every room, every term',
        ],
    },
    {
        icon: 'pi pi-chart-bar',
        title: 'Reports',
        description: 'Generate printable, term-ready scheduling reports on demand.',
        accent: '#0284C7',
        details: [
            'Export section, faculty, and room schedules as PDFs',
            'Track scheduling progress across the whole term',
            'Share print-ready reports with administrators instantly',
        ],
    },
];

// Which feature cards are currently showing their back (info) face.
const flipped = reactive({});
const toggleFlip = (title) => {
    flipped[title] = !flipped[title];
};

// Spawns a short-lived "bubble" ripple at the click point on a feature card.
const spawnRipple = (event) => {
    const card = event.currentTarget;
    const rect = card.getBoundingClientRect();
    const ripple = document.createElement('span');
    const size = Math.max(rect.width, rect.height) * 1.4;
    ripple.className = 'click-ripple';
    ripple.style.width = ripple.style.height = `${size}px`;
    ripple.style.left = `${event.clientX - rect.left - size / 2}px`;
    ripple.style.top = `${event.clientY - rect.top - size / 2}px`;
    card.appendChild(ripple);
    ripple.addEventListener('animationend', () => ripple.remove());
};

const onFeatureCardClick = (event, title) => {
    spawnRipple(event);
    toggleFlip(title);
};

// Hero-only firefly effect: amber in dark mode, green in light mode.
// Randomized per page load so it doesn't feel like a fixed, repeating
// pattern.
const fireflies = Array.from({ length: 22 }, (_, i) => ({
    id: i,
    left: Math.random() * 100,
    top: Math.random() * 100,
    size: 2 + Math.random() * 3,
    duration: 7 + Math.random() * 8,
    delay: -Math.random() * 12,
    drift: (Math.random() > 0.5 ? 1 : -1) * (20 + Math.random() * 40),
}));
</script>

<template>
    <Head title="Welcome" />

    <!-- Outer canvas: a soft powder-blue backdrop the rounded hero "card" sits on,
         echoing the teal-frame / inset-card layout of the reference design. -->
    <div class="min-h-screen" style="zoom: 1.1;" :class="isDark ? 'bg-[#020617]' : 'bg-[#BAE6FD]'">
        <div class="relative overflow-hidden" :class="isDark ? 'bg-[#0B1220]' : 'bg-[#F2F2F2]'">

            <!-- ============ HERO (powder-blue gradient card) ============ -->
            <div
                class="relative overflow-hidden bg-gradient-to-br"
                :class="isDark ? 'from-[#075985] via-[#0369A1] to-[#0284C7]' : 'from-[#7DD3FC] via-[#38BDF8] to-[#0284C7]'"
            >
                <!-- decorative blobs / dotted pattern, like the reference hero -->
                <div class="pointer-events-none absolute inset-0 overflow-hidden">
                    <div class="absolute -left-10 top-24 h-16 w-16 rounded-full border-4 border-white/30"></div>
                    <div class="absolute left-16 top-16 h-10 w-10 rounded-full bg-white/15"></div>
                    <div class="absolute right-[18%] top-10 h-8 w-8 rounded-full bg-white/20"></div>
                    <div class="absolute bottom-40 right-[8%] h-24 w-24 rounded-full border-4 border-white/25"></div>
                    <div class="absolute -bottom-10 left-1/3 h-6 w-6 rotate-45 bg-white/20"></div>
                    <svg class="absolute right-6 top-28 h-24 w-24 text-white/25 lg:right-16" viewBox="0 0 60 60" fill="currentColor">
                        <circle v-for="n in 16" :key="n" :cx="(n % 4) * 16 + 4" :cy="Math.floor(n / 4) * 16 + 4" r="2" />
                    </svg>
                </div>

                <!-- Fireflies: amber in dark mode, green in light mode. Scoped to the hero only. -->
                <div class="pointer-events-none absolute inset-0 z-[5] overflow-hidden">
                    <span
                        v-for="fly in fireflies"
                        :key="'fly-' + fly.id"
                        class="firefly"
                        :class="{ 'firefly--green': !isDark }"
                        :style="{
                            left: fly.left + '%',
                            top: fly.top + '%',
                            width: fly.size + 'px',
                            height: fly.size + 'px',
                            animationDuration: fly.duration + 's',
                            animationDelay: fly.delay + 's',
                            '--drift': fly.drift + 'px',
                        }"
                    />
                </div>

                <!-- Navigation: liquid-glass floating pill -->
                <nav class="relative z-10 mx-auto max-w-6xl px-6 pt-6 lg:px-10">
                    <div class="liquid-glass-nav flex items-center justify-between rounded-full px-5 py-3">
                        <div class="flex items-center gap-2.5">
                            <img src="/logo.png" alt="CLASSLY" class="h-8 w-8 rounded-lg bg-white/90 p-1" />
                            <span class="text-lg font-bold tracking-tight" :class="isDark ? 'text-white' : 'text-[#0F172A]'">CLASSLY</span>
                            <template v-if="schoolBranding.name">
                                <span class="h-5 w-px" :class="isDark ? 'bg-white/30' : 'bg-[#0F172A]/20'"></span>
                                <span class="text-xs font-medium" :class="isDark ? 'text-white/80' : 'text-[#0F172A]/70'">{{ schoolBranding.name }}</span>
                            </template>
                        </div>

                        <div class="hidden items-center gap-8 text-sm font-medium md:flex" :class="isDark ? 'text-white/90' : 'text-[#0F172A]/80'">
                            <a href="#features" class="transition-colors" :class="isDark ? 'hover:text-white' : 'hover:text-[#0284C7]'">Features</a>
                            <a href="#about" class="transition-colors" :class="isDark ? 'hover:text-white' : 'hover:text-[#0284C7]'">About</a>
                        </div>

                        <div class="flex items-center gap-3">
                            <ThemeToggle />
                            <Link :href="route('login')">
                                <Button label="Sign In" class="!border-0 !bg-[#0284C7] !text-white hover:!bg-[#026CA3]" />
                            </Link>
                        </div>
                    </div>
                </nav>

                <!-- Hero content -->
                <section class="relative z-10 mx-auto grid w-full max-w-7xl items-center gap-8 px-6 pb-10 pt-6 lg:grid-cols-2 lg:px-10 lg:pb-14 lg:pt-10">
                    <div class="text-center lg:pl-10 lg:text-left">
                        <p class="glass-surface mb-4 inline-flex items-center rounded-full !border-red-400/40 !bg-red-500/25 px-3 py-1 text-[10px] font-semibold uppercase tracking-widest text-white shadow-lg backdrop-blur-xl">
                            Class Scheduling &amp; Management System
                        </p>
                        <h1
                            class="text-4xl font-extrabold leading-tight sm:text-5xl lg:text-6xl"
                            style="font-family: 'Baloo 2', 'Inter', sans-serif;"
                        >
                            <span style="color: #1B2CC1;">CLASS</span><span class="text-red-500">LY</span>
                        </h1>
                        <p class="mt-1 text-lg font-semibold text-white/90 sm:text-xl">
                            Your <span style="color: #1B2CC1;">Friend</span><span class="text-red-500">ly</span> Class Scheduler
                        </p>
                        <p class="mx-auto mt-5 max-w-md text-sm text-white/85 lg:mx-0">
                            Professional Academy of the Philippines' centralized platform for class schedules,
                            faculty assignments, rooms, and academic planning.
                        </p>

                        <div class="mt-8 flex flex-wrap items-center justify-center gap-4 lg:justify-start">
                            <Link :href="route('login')">
                                <Button
                                    label="Get Started"
                                    class="!border-0 !bg-white !px-6 !py-3 !font-semibold !text-[#0284C7] shadow-lg hover:!bg-white/90"
                                />
                            </Link>
                            <a
                                href="#features"
                                class="flex items-center gap-2 rounded-full border border-white/40 px-5 py-3 text-sm font-semibold text-white transition-colors hover:bg-white/10"
                            >
                                Explore Features
                                <i class="pi pi-arrow-right text-xs"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Logo mark: glowing glass orb with orbit ring, floating chips and sparkle accents -->
                    <div class="flex justify-center lg:justify-end lg:-translate-x-10">
                        <div class="classly-logo-hero relative flex h-72 w-72 items-center justify-center lg:h-80 lg:w-80">
                            <!-- Outer ambient glow, pulses slowly -->
                            <div class="absolute inset-0 rounded-full bg-white/10 blur-2xl classly-hero-glow"></div>

                            <!-- Dashed orbit ring, slow continuous spin -->
                            <svg class="pointer-events-none absolute inset-0 h-full w-full classly-orbit-spin" viewBox="0 0 300 300" fill="none">
                                <circle cx="150" cy="150" r="138" stroke="rgba(255,255,255,0.35)" stroke-width="1.5" stroke-dasharray="2 10" stroke-linecap="round" />
                            </svg>

                            <!-- Faint dot constellation, spins the opposite way for parallax -->
                            <svg class="pointer-events-none absolute inset-0 h-full w-full classly-orbit-spin-reverse" viewBox="0 0 300 300" fill="none">
                                <circle v-for="n in 10" :key="n" :cx="150 + 128 * Math.cos((n * Math.PI * 2) / 10)" :cy="150 + 128 * Math.sin((n * Math.PI * 2) / 10)" r="2" fill="rgba(255,255,255,0.55)" />
                            </svg>

                            <!-- Rotating conic sheen ring hugging the glass orb -->
                            <div class="classly-sheen-ring absolute h-60 w-60 rounded-full lg:h-[17rem] lg:w-[17rem]"></div>

                            <!-- Core glass orb with logo mark, gentle float -->
                            <div class="glass-surface classly-logo-float relative flex h-56 w-56 items-center justify-center rounded-full !border-white/40 !bg-white/20 backdrop-blur-xl lg:h-64 lg:w-64">
                                <div class="absolute inset-2 rounded-full bg-gradient-to-br from-white/25 via-transparent to-transparent"></div>
                                <img src="/logo.png" alt="CLASSLY calendar mark" class="relative h-32 w-32 drop-shadow-[0_8px_24px_rgba(2,132,199,0.45)] lg:h-36 lg:w-36" />
                            </div>

                            <!-- Sparkle accents -->
                            <span class="classly-sparkle classly-sparkle--a pi pi-sparkles absolute -right-1 -top-1 text-sm text-white/80"></span>
                            <span class="classly-sparkle classly-sparkle--b pi pi-sparkles absolute -bottom-2 left-2 text-xs text-white/70"></span>

                        </div>
                    </div>
                </section>

                <!-- Scroll cue -->
                <div class="relative z-10 mx-auto -mt-6 mb-4 flex justify-center pb-2">
                    <a
                        href="#about"
                        class="flex h-10 w-10 items-center justify-center rounded-full !border-white/40 bg-white/15 text-white backdrop-blur-xl transition-colors hover:bg-white/25"
                        style="animation: academic-glow-pulse 2.5s ease-in-out infinite;"
                    >
                        <i class="pi pi-chevron-down"></i>
                    </a>
                </div>

                <!-- Wave divider: powder blue → white (tall, organic blob-style wave) -->
                <svg class="relative z-10 -mb-1 block w-full" viewBox="0 0 1440 220" preserveAspectRatio="none" style="height: 160px;">
                    <path
                        :fill="isDark ? '#0B1220' : '#F2F2F2'"
                        d="M0,90 C160,20 320,150 480,110 C640,70 720,180 880,150 C1040,120 1120,40 1280,80 C1360,100 1400,130 1440,110 L1440,220 L0,220 Z"
                    />
                </svg>
            </div>

            <!-- ============ ABOUT (copy only) ============ -->
            <section id="about" class="relative z-10 mx-auto w-full max-w-3xl overflow-hidden px-6 pb-16 pt-10 text-center lg:px-10">
                <svg class="pointer-events-none absolute left-4 top-4 h-20 w-20 opacity-20" :class="isDark ? 'text-white' : 'text-[#0284C7]'" viewBox="0 0 60 60" fill="currentColor">
                    <circle v-for="n in 16" :key="n" :cx="(n % 4) * 16 + 4" :cy="Math.floor(n / 4) * 16 + 4" r="2" />
                </svg>

                <p class="mb-2 text-sm font-semibold uppercase tracking-widest" :class="isDark ? 'text-[#38BDF8]' : 'text-[#0284C7]'">
                    About CLASSLY
                </p>
                <h2 class="text-3xl font-bold" :class="isDark ? 'text-white' : 'text-[#0F172A]'">
                    Why {{ schoolBranding.name || 'PAP' }} runs on CLASSLY
                </h2>
                <p class="mx-auto mt-4 max-w-xl" :class="isDark ? 'text-slate-400' : 'text-slate-500'">
                    A single source of truth for every section, faculty load, and room assignment —
                    built so the registrar's office spends less time untangling conflicts and more
                    time planning the term ahead.
                </p>
            </section>

            <!-- Wave divider: white → powder blue (tall, organic blob-style wave) -->
            <svg class="relative z-10 -mb-1 block w-full" viewBox="0 0 1440 220" preserveAspectRatio="none" style="height: 160px;">
                <path
                    fill="#0284C7"
                    d="M0,120 C160,180 320,60 480,90 C640,120 720,30 880,60 C1040,90 1120,160 1280,130 C1360,115 1400,95 1440,105 L1440,220 L0,220 Z"
                />
            </svg>

            <!-- ============ FOOTER: FEATURES (powder blue, horizontal row, flip on click) ============ -->
            <footer id="features" class="relative overflow-hidden bg-gradient-to-br from-[#0284C7] to-[#0EA5E9] px-6 py-14 lg:px-10">
                <div class="pointer-events-none absolute -right-16 -top-16 h-48 w-48 rounded-full bg-white/10"></div>
                <div class="pointer-events-none absolute -bottom-20 -left-10 h-56 w-56 rounded-full bg-white/10"></div>

                <div class="relative z-10 mx-auto mb-12 max-w-xl text-center">
                    <p class="mb-2 text-sm font-semibold uppercase tracking-widest text-white/80">Why Choose CLASSLY</p>
                    <h2 class="text-2xl font-bold text-white sm:text-3xl">Our Features</h2>
                    <p class="mt-3 text-white/85">
                        Everything the registrar's office needs to plan a conflict-free term, in one place.
                        Tap a card to see the details.
                    </p>
                </div>

                <div class="relative z-10 mx-auto grid max-w-7xl gap-8 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(feature, index) in features"
                        :key="feature.title"
                        class="feature-flip-scene relative cursor-pointer select-none overflow-hidden text-center"
                        role="button"
                        tabindex="0"
                        :aria-pressed="!!flipped[feature.title]"
                        @click="onFeatureCardClick($event, feature.title)"
                        @keydown.enter.prevent="toggleFlip(feature.title)"
                        @keydown.space.prevent="toggleFlip(feature.title)"
                    >
                        <div class="feature-flip-card" :class="{ 'is-flipped': flipped[feature.title] }">
                            <!-- Front face -->
                            <div class="feature-flip-face feature-flip-face--front">
                                <div class="footer-feature-card relative flex h-full flex-col items-center rounded-2xl p-6 pt-8 backdrop-blur-xl">
                                    <span class="absolute right-4 top-3 text-3xl font-extrabold text-white/20">{{ String(index + 1).padStart(2, '0') }}</span>
                                    <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-white/20 text-white shadow-md">
                                        <i :class="feature.icon" class="text-2xl"></i>
                                    </div>
                                    <h3 class="mb-1 text-lg font-semibold text-white">{{ feature.title }}</h3>
                                    <p class="text-sm text-white/80">{{ feature.description }}</p>
                                    <span class="mt-3 flex items-center gap-1.5 text-xs font-semibold text-white">
                                        Tap to learn more
                                        <i class="pi pi-arrow-up-right text-[10px]"></i>
                                    </span>
                                </div>
                            </div>

                            <!-- Back face -->
                            <div class="feature-flip-face feature-flip-face--back">
                                <div class="footer-feature-card flex h-full flex-col rounded-2xl p-5 text-left backdrop-blur-xl">
                                    <h3 class="mb-3 text-lg font-semibold text-white">{{ feature.title }}</h3>
                                    <ul class="flex flex-col gap-2">
                                        <li v-for="detail in feature.details" :key="detail" class="flex items-start gap-2 text-sm text-white/85">
                                            <i class="pi pi-check-circle mt-0.5 shrink-0 text-white"></i>
                                            <span>{{ detail }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="relative z-10 mx-auto mt-12 max-w-md text-center">
                    <Link :href="route('login')" class="inline-block">
                        <Button label="Login to CLASSLY" class="!border-0 !bg-white !px-6 !py-3 !font-semibold !text-[#0284C7] shadow-lg hover:!bg-white/90" />
                    </Link>
                </div>

                <div class="relative z-10 mx-auto mt-12 max-w-7xl border-t border-white/20 pt-6 text-center text-sm text-white/70">
                    <template v-if="schoolBranding.name">&copy; 2026 {{ schoolBranding.name }} — by CLASSLY @ DJS</template>
                    <template v-else>CLASSLY &copy; 2026 -DJS</template>
                </div>
            </footer>
        </div>
    </div>
</template>
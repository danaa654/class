<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    href: { type: String, required: true },
    label: { type: String, required: true },
});
</script>

<template>
    <Link
        :href="href"
        class="back-link group inline-flex items-center gap-2 rounded-full py-1.5 pl-1.5 pr-4 text-sm font-semibold
               border border-slate-200 bg-white text-slate-600 shadow-sm
               transition-all duration-200 ease-out
               hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700 hover:shadow-md
               active:scale-[0.97]
               focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 focus-visible:ring-offset-2
               dark:border-white/10 dark:bg-white/5 dark:text-slate-300
               dark:hover:border-blue-400/40 dark:hover:bg-blue-500/10 dark:hover:text-blue-300
               dark:focus-visible:ring-offset-[#0B1020]"
    >
        <span
            class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-100 text-slate-500
                   transition-all duration-200 ease-out
                   group-hover:bg-blue-600 group-hover:text-white
                   dark:bg-white/10 dark:text-slate-300 dark:group-hover:bg-blue-500"
        >
            <i class="pi pi-arrow-left text-xs transition-transform duration-200 ease-out group-hover:-translate-x-0.5"></i>
        </span>
        <span>{{ label }}</span>
    </Link>
</template>

<style scoped>
@media (prefers-reduced-motion: reduce) {
    .back-link,
    .back-link * {
        transition: none !important;
        transform: none !important;
    }
}
</style>
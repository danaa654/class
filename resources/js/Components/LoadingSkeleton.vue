<script setup>
/**
 * PERFORMANCE / PERCEIVED PERFORMANCE — loading skeletons.
 *
 * Pages like Sections/Index.vue already track a `loading` ref while
 * a filter/search/pagination request is in flight (router.get with
 * onFinish setting loading.value = false). Today that mostly just
 * disables a button. Render this component in place of the table/
 * card content while `loading` is true so the page never shows a
 * blank or frozen area during a reload — the shimmering placeholder
 * communicates "this is updating" instead of "this is broken".
 *
 * Usage:
 *   <LoadingSkeleton v-if="loading" variant="table" :rows="8" :columns="5" />
 *   <DataTable v-else :value="sections" ... />
 *
 *   <LoadingSkeleton v-if="loading" variant="card" :rows="3" />
 *   <LoadingSkeleton v-if="loading" variant="list" :rows="6" />
 */
defineProps({
    variant: {
        type: String,
        default: 'table', // 'table' | 'card' | 'list'
        validator: (value) => ['table', 'card', 'list'].includes(value),
    },
    rows: {
        type: Number,
        default: 6,
    },
    columns: {
        type: Number,
        default: 4,
    },
});
</script>

<template>
    <div class="skeleton-wrap" role="status" aria-live="polite" aria-label="Loading">
        <!-- Table variant: header bar + N rows of column-wide bars -->
        <template v-if="variant === 'table'">
            <div class="skeleton-row skeleton-row--header">
                <span
                    v-for="col in columns"
                    :key="`h-${col}`"
                    class="skeleton-bar skeleton-bar--header"
                />
            </div>
            <div v-for="row in rows" :key="`r-${row}`" class="skeleton-row">
                <span
                    v-for="col in columns"
                    :key="`r-${row}-c-${col}`"
                    class="skeleton-bar"
                />
            </div>
        </template>

        <!-- Card variant: stacked rectangular cards -->
        <template v-else-if="variant === 'card'">
            <div v-for="row in rows" :key="`card-${row}`" class="skeleton-card">
                <span class="skeleton-bar skeleton-bar--title" />
                <span class="skeleton-bar skeleton-bar--subtitle" />
            </div>
        </template>

        <!-- List variant: avatar + two lines, repeated -->
        <template v-else>
            <div v-for="row in rows" :key="`list-${row}`" class="skeleton-list-item">
                <span class="skeleton-avatar" />
                <span class="skeleton-lines">
                    <span class="skeleton-bar skeleton-bar--line1" />
                    <span class="skeleton-bar skeleton-bar--line2" />
                </span>
            </div>
        </template>
    </div>
</template>

<style scoped>
.skeleton-wrap {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    width: 100%;
}

.skeleton-row {
    display: flex;
    gap: 0.75rem;
}

.skeleton-row--header {
    opacity: 0.6;
}

.skeleton-bar {
    display: block;
    height: 1rem;
    flex: 1;
    border-radius: 0.5rem;
    background: linear-gradient(90deg, rgba(148, 163, 184, 0.25) 25%, rgba(148, 163, 184, 0.4) 37%, rgba(148, 163, 184, 0.25) 63%);
    background-size: 400% 100%;
    animation: skeleton-shimmer 1.4s ease-in-out infinite;
}

.skeleton-bar--header {
    height: 1.1rem;
    opacity: 0.9;
}

.skeleton-card {
    padding: 1rem;
    border-radius: 0.75rem;
    background: rgba(148, 163, 184, 0.08);
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.skeleton-bar--title {
    width: 40%;
    height: 1.25rem;
}

.skeleton-bar--subtitle {
    width: 70%;
}

.skeleton-list-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.skeleton-avatar {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    flex-shrink: 0;
    background: linear-gradient(90deg, rgba(148, 163, 184, 0.25) 25%, rgba(148, 163, 184, 0.4) 37%, rgba(148, 163, 184, 0.25) 63%);
    background-size: 400% 100%;
    animation: skeleton-shimmer 1.4s ease-in-out infinite;
}

.skeleton-lines {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.skeleton-bar--line1 {
    width: 50%;
}

.skeleton-bar--line2 {
    width: 30%;
    opacity: 0.7;
}

@keyframes skeleton-shimmer {
    0% {
        background-position: 100% 50%;
    }
    100% {
        background-position: 0 50%;
    }
}

@media (prefers-reduced-motion: reduce) {
    .skeleton-bar,
    .skeleton-avatar {
        animation: none;
    }
}
</style>
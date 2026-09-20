import { customRef } from 'vue';

/**
 * PERFORMANCE — debounce input handlers.
 *
 * Several pages (Sections, Faculty, Rooms, SectionSubjects, Reports,
 * Notifications, ...) already hand-roll the same pattern for their
 * search box:
 *
 *   let searchDebounce = null;
 *   watch(search, () => {
 *       clearTimeout(searchDebounce);
 *       searchDebounce = setTimeout(() => reload(), 350);
 *   });
 *
 * That's correct, but it's copy-pasted per page and easy to get
 * subtly wrong (forgetting to clear the previous timer, using a
 * different delay everywhere). `useDebouncedRef` below is a drop-in
 * replacement: a ref whose writes are delayed by `delay`ms before
 * they actually update (and therefore before any `watch()` on it
 * fires), so a fast typist never triggers a server round-trip per
 * keystroke.
 *
 * Usage (compare to the Sections/Index.vue pattern above):
 *
 *   import { useDebouncedRef } from '@/composables/useDebounce';
 *
 *   const search = useDebouncedRef(props.filters.section_search ?? '', 350);
 *
 *   watch(search, () => reloadSections({ section_page: 1 }));
 *   // bind v-model="search" on the <InputText> as usual — the input
 *   // stays instantly responsive, only the *watch* is debounced.
 *
 * If you need the debounced function form instead of a ref (e.g. to
 * debounce a plain event handler rather than a v-model), use
 * `useDebouncedFn`.
 */
export function useDebouncedRef(initialValue, delay = 350) {
    let timeout = null;
    let value = initialValue;

    return customRef((track, trigger) => ({
        get() {
            track();
            return value;
        },
        set(newValue) {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                value = newValue;
                trigger();
            }, delay);
        },
    }));
}

/**
 * Wrap any function so repeated calls within `delay`ms collapse into
 * one trailing call. Returns the wrapped function plus a `cancel()`
 * to clear a pending call (call this in onUnmounted for anything
 * that might fire after the component using it is torn down).
 */
export function useDebouncedFn(fn, delay = 350) {
    let timeout = null;

    const debounced = (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => fn(...args), delay);
    };

    debounced.cancel = () => clearTimeout(timeout);

    return debounced;
}
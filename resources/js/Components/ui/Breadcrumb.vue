<script setup>
import { Link, usePage } from '@inertiajs/vue3';

defineProps({
    // [{ label, href? }, ...] — href omitted on the last crumb; it auto-links
    // to the current page URI so the last crumb is always clickable.
    crumbs: { type: Array, required: true },
});

// Current page URI (pathname only — query/filters stripped) so the last crumb
// links to the canonical page URL, not a filtered view.
const currentUrl = usePage().url.split('?')[0];
</script>

<template>
    <div class="flex items-center gap-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] min-w-0">
        <template v-for="(crumb, i) in crumbs" :key="i">
            <!-- Intermediate crumbs: link when an href is given, else plain text -->
            <Link
                v-if="i < crumbs.length - 1 && crumb.href"
                :href="crumb.href"
                :title="crumb.label"
                class="hover:text-[var(--md-sys-color-on-surface)] transition-colors inline-flex min-w-0 max-w-[200px]"
            ><span class="truncate"><slot name="crumb" :crumb="crumb" :index="i">{{ crumb.label }}</slot></span></Link>
            <span v-else-if="i < crumbs.length - 1" :title="crumb.label" class="inline-flex min-w-0 max-w-[200px]"><span class="truncate"><slot name="crumb" :crumb="crumb" :index="i">{{ crumb.label }}</slot></span></span>

            <!-- Last crumb: clickable link back to the current page URI -->
            <Link
                v-else
                :href="currentUrl"
                :title="crumb.label"
                class="text-[var(--md-sys-color-on-surface)] hover:text-[var(--md-sys-color-on-surface)] transition-colors inline-flex min-w-0 max-w-[240px]"
            ><span class="truncate"><slot name="crumb" :crumb="crumb" :index="i">{{ crumb.label }}</slot></span></Link>

            <span v-if="i < crumbs.length - 1" class="flex-shrink-0">/</span>
        </template>
    </div>
</template>

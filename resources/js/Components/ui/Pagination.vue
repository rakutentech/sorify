<script setup>
import { router } from '@inertiajs/vue3';

defineProps({
    paginator: { type: Object, required: true },
    label: { type: String, default: '' },
});

function go(link) {
    if (!link.url || link.active) return;
    router.get(link.url, {}, { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <div class="flex items-center justify-between px-5 py-3 border-t border-[var(--md-sys-color-outline-variant)]">
        <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ label }}</p>

        <div class="flex items-center gap-3">
            <div v-if="paginator.last_page > 1" class="flex items-center gap-1">
                <button
                    v-for="link in paginator.links"
                    :key="link.label"
                    :disabled="!link.url || link.active"
                    @click="go(link)"
                    class="px-2.5 py-1 rounded-[var(--md-sys-shape-corner-small)] md-label-small transition-colors"
                    :class="[
                        link.active
                            ? 'bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)] font-medium'
                            : link.url
                                ? 'text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)]'
                                : 'text-[var(--md-sys-color-on-surface-variant)] opacity-50 cursor-not-allowed',
                    ]"
                    v-html="link.label"
                />
            </div>

            <slot name="extra" />
        </div>
    </div>
</template>

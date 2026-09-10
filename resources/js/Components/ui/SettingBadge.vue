<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { SETTING_KIND } from '@/utils/iconMaps.js';

const props = defineProps({
    label: { type: String, required: true },
    active: { type: Boolean, default: false },
    successActive: { type: Boolean, default: false },
    kind: { type: String, default: null }, // teams | webhook | screenshots | proxy | variables | cookies | schedule | browser | headless | timeout | retries | keepRuns
    truncate: { type: Boolean, default: false }, // fill the parent and ellipsize the label when it overflows
});

const kindInfo = computed(() => SETTING_KIND[props.kind] ?? null);
// Icon color: active -> kind accent (or success when successActive); inactive -> muted.
const iconColor = computed(() => {
    if (!props.active) return 'var(--md-sys-color-on-surface-variant)';
    if (props.successActive) return 'var(--md-ext-color-success)';
    return kindInfo.value?.color ?? 'var(--md-sys-color-on-surface)';
});

// In truncate mode the full label is only revealed in a hover tooltip when it
// actually overflows — a chip whose text fits shows no tooltip at all.
const labelEl = ref(null);
const isTruncated = ref(false);

function syncTruncation() {
    isTruncated.value = !!(labelEl.value && labelEl.value.scrollWidth > labelEl.value.clientWidth);
}

watch(() => props.label, () => nextTick(syncTruncation));

onMounted(() => {
    if (!props.truncate) return;
    syncTruncation();
    window.addEventListener('resize', syncTruncation);
});

onBeforeUnmount(() => window.removeEventListener('resize', syncTruncation));
</script>

<template>
    <span
        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] md-label-small border"
        :class="[active
            ? (successActive
                ? 'text-[var(--md-ext-color-success)] border-[var(--md-ext-color-success)]'
                : 'text-[var(--md-sys-color-on-surface)] border-[var(--md-sys-color-outline-variant)]')
            : 'text-[var(--md-sys-color-on-surface-variant)] border-[var(--md-sys-color-outline)] opacity-60',
            truncate ? 'relative group/badge w-full min-w-0' : '']"
    >
        <component
            :is="kindInfo.icon"
            v-if="kindInfo"
            :size="13"
            class="flex-shrink-0"
            :style="{ color: iconColor }"
        />
        <svg v-else-if="active" class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
        </svg>
        <span v-if="truncate" ref="labelEl" class="truncate">{{ label }}</span>
        <template v-else>{{ label }}</template>

        <span
            v-if="truncate && isTruncated"
            class="pointer-events-none absolute left-1/2 bottom-full z-50 mb-1.5 -translate-x-1/2 whitespace-nowrap rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-inverse-surface)] px-2.5 py-1.5 md-label-small text-[var(--md-sys-color-inverse-on-surface)] opacity-0 shadow-lg transition-opacity duration-150 group-hover/badge:opacity-100"
        >
            {{ label }}
        </span>
    </span>
</template>

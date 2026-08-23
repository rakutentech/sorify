<script setup>
import { computed } from 'vue';
import { SETTING_KIND } from '@/utils/iconMaps.js';

const props = defineProps({
    label: { type: String, required: true },
    active: { type: Boolean, default: false },
    successActive: { type: Boolean, default: false },
    kind: { type: String, default: null }, // teams | webhook | screenshots | proxy | variables | cookies | schedule | browser | headless | timeout | retries | keepRuns
});

const kindInfo = computed(() => SETTING_KIND[props.kind] ?? null);
// Icon color: active -> kind accent (or success when successActive); inactive -> muted.
const iconColor = computed(() => {
    if (!props.active) return 'var(--md-sys-color-on-surface-variant)';
    if (props.successActive) return 'var(--md-ext-color-success)';
    return kindInfo.value?.color ?? 'var(--md-sys-color-on-surface)';
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] md-label-small border"
        :class="active
            ? (successActive
                ? 'text-[var(--md-ext-color-success)] border-[var(--md-ext-color-success)]'
                : 'text-[var(--md-sys-color-on-surface)] border-[var(--md-sys-color-outline-variant)]')
            : 'text-[var(--md-sys-color-on-surface-variant)] border-[var(--md-sys-color-outline)] opacity-60'"
    >
        <component
            :is="kindInfo.icon"
            v-if="kindInfo"
            :size="13"
            class="flex-shrink-0"
            :style="{ color: iconColor }"
        />
        <svg v-else-if="active" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
        </svg>
        {{ label }}
    </span>
</template>

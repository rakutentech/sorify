<script setup>
defineProps({
    label: { type: String, required: true },
    active: { type: Boolean, default: false },
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-[var(--md-sys-shape-corner-full)] md-label-small"
        :class="active
            ? 'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]'
            : 'bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)] opacity-70'"
    >
        <svg v-if="active" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
        </svg>
        {{ label }}
    </span>
</template>

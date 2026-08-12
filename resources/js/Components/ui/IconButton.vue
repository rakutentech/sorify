<script setup>
defineProps({
    variant: { type: String, default: 'standard' }, // standard | filled | tonal | outlined
    label: { type: String, required: true },
});

defineEmits(['click']);
</script>

<template>
    <button
        type="button"
        :aria-label="label"
        @click="$emit('click')"
        class="w-10 h-10 flex items-center justify-center rounded-[var(--md-sys-shape-corner-full)] transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--md-sys-color-primary)]"
        :class="{
            'text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)]': variant === 'standard',
            'bg-[var(--md-sys-color-primary)] text-[var(--md-sys-color-on-primary)] hover:brightness-90': variant === 'filled',
            'bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)] hover:brightness-90': variant === 'tonal',
            'border border-[var(--md-sys-color-outline)] text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)]': variant === 'outlined',
        }"
    >
        <slot />
    </button>
</template>

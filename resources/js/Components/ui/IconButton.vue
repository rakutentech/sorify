<script setup>
defineProps({
    variant: { type: String, default: 'standard' }, // standard | filled | tonal | outlined
    label: { type: String, required: true },
    title: { type: String, default: null },
    disabled: { type: Boolean, default: false },
    // Extra classes for the tooltip span — e.g. "!left-auto right-0
    // !translate-x-0" to anchor it under the right edge instead of centered
    // (use for buttons flush against the viewport's right side).
    tipClass: { type: String, default: '' },
});

defineEmits(['click']);
</script>

<template>
    <div class="relative inline-flex group/tip">
        <button
            type="button"
            :aria-label="label"
            :disabled="disabled"
            @click="$emit('click')"
            class="w-10 h-10 flex items-center justify-center rounded-[var(--md-sys-shape-corner-full)] transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--md-sys-color-primary)] disabled:opacity-40 disabled:pointer-events-none"
            :class="{
                'text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)]': variant === 'standard',
                'bg-[var(--md-sys-color-primary)] text-[var(--md-sys-color-on-primary)] hover:brightness-90': variant === 'filled',
                'bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)] hover:brightness-90': variant === 'tonal',
                'border border-[var(--md-sys-color-outline)] text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)]': variant === 'outlined',
            }"
        >
            <slot />
        </button>
        <!-- display:none while idle (not opacity) so the invisible tooltip
             never contributes to the page's scrollable overflow. -->
        <span
            v-if="(title ?? label) && !disabled"
            class="pointer-events-none absolute top-full left-1/2 -translate-x-1/2 mt-1.5 px-2 py-1 rounded-[var(--md-sys-shape-corner-extra-small)] bg-gray-900 text-white md-label-small whitespace-nowrap hidden group-hover/tip:block z-50"
            :class="tipClass"
        >{{ title ?? label }}</span>
    </div>
</template>

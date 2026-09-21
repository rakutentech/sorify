<script setup>
const props = defineProps({
    text: {
        type: String,
        default: '',
    },
    // Which side of the trigger the tooltip renders on: above (default) or
    // below — use "below" when the trigger sits near the top of a panel and a
    // tooltip above it would be clipped by the viewport or overlapped.
    placement: {
        type: String,
        default: 'top',
        validator: (value) => ['top', 'bottom'].includes(value),
    },
});
</script>

<template>
    <span class="group relative inline-flex">
        <slot />
        <span
            v-if="text"
            class="pointer-events-none absolute left-1/2 z-50 -translate-x-1/2 whitespace-nowrap rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-inverse-surface)] px-2.5 py-1.5 md-label-small text-[var(--md-sys-color-inverse-on-surface)] opacity-0 shadow-lg transition-all duration-150"
            :class="placement === 'bottom'
                ? 'top-full mb-1.5 translate-y-1 group-hover:translate-y-0 group-hover:opacity-100'
                : 'bottom-full mt-1.5 -translate-y-1 group-hover:translate-y-0 group-hover:opacity-100'"
        >
            {{ text }}
        </span>
    </span>
</template>

<script setup>
import { computed, useSlots } from 'vue';
import { CircleCheck, CircleX, TriangleAlert, Info } from '@lucide/vue';

const props = defineProps({
    tone: { type: String, default: 'info' }, // success | error | warning | info
});

const slots = useSlots();

const DEFAULT_ICON = {
    success: CircleCheck,
    error: CircleX,
    warning: TriangleAlert,
    info: Info,
};

const defaultIcon = computed(() => DEFAULT_ICON[props.tone] ?? Info);
const hasIconSlot = computed(() => !!slots.icon);
</script>

<template>
    <div
        class="flex items-center gap-3 rounded-[var(--md-sys-shape-corner-extra-small)] px-4 py-3 md-body-medium"
        :class="{
            'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]': tone === 'success',
            'bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)]': tone === 'error',
            'bg-[var(--md-ext-color-warning-container)] text-[var(--md-ext-color-on-warning-container)]': tone === 'warning',
            'bg-[var(--md-sys-color-primary-container)] text-[var(--md-sys-color-on-primary-container)]': tone === 'info',
        }"
    >
        <component :is="defaultIcon" v-if="!hasIconSlot" :size="18" class="flex-shrink-0" />
        <slot name="icon" v-else />
        <div class="flex-1"><slot /></div>
    </div>
</template>

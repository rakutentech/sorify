<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    variant: { type: String, default: 'filled' }, // filled | outlined | text | tonal | elevated
    size: { type: String, default: 'md' }, // sm | md
    disabled: { type: Boolean, default: false },
    href: { type: String, default: null },
    type: { type: String, default: 'button' },
});

const tag = computed(() => (props.href ? Link : 'button'));

const variantClasses = {
    filled: 'bg-[var(--md-sys-color-primary)] text-[var(--md-sys-color-on-primary)] hover:brightness-90 active:brightness-85',
    tonal: 'bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)] hover:brightness-90 active:brightness-85',
    elevated: 'bg-[var(--md-sys-color-surface-container-low)] text-[var(--md-sys-color-primary)] shadow-elevation-1 hover:shadow-elevation-2',
    outlined: 'bg-transparent text-[var(--md-sys-color-primary)] border border-[var(--md-sys-color-outline)] hover:bg-[color-mix(in_srgb,var(--md-sys-color-primary)_8%,transparent)]',
    text: 'bg-transparent text-[var(--md-sys-color-primary)] hover:bg-[color-mix(in_srgb,var(--md-sys-color-primary)_8%,transparent)]',
};

const sizeClasses = {
    sm: 'h-8 px-4 text-xs gap-1.5',
    md: 'h-10 px-6 text-sm gap-2',
};
</script>

<template>
    <component
        :is="tag"
        :href="href"
        :type="href ? undefined : type"
        :disabled="disabled"
        class="inline-flex items-center justify-center rounded-[var(--md-sys-shape-corner-full)] md-label-large transition-[background-color,box-shadow,filter] duration-150 disabled:opacity-40 disabled:pointer-events-none focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--md-sys-color-primary)] focus-visible:ring-offset-2"
        :class="[variantClasses[variant], sizeClasses[size]]"
    >
        <slot name="leading" />
        <slot />
        <slot name="trailing" />
    </component>
</template>

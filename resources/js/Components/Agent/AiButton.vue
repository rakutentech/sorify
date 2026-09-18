<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePage } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';

const { t } = useI18n();
const page = usePage();

const props = defineProps({
    label: { type: String, required: true },
    size: { type: String, default: 'sm' }, // sm | xs
    // Optional tooltip override; otherwise derived from why the button is
    // disabled (no agent profile / disabled by admin).
    title: { type: String, default: null },
});

const user = computed(() => page.props.auth?.user ?? null);

// The AI buttons render everywhere but go inert when the user has no agent
// profile yet (or an admin disabled agents for them) — with a tooltip that
// explains what to do.
const hasAgent = computed(() => user.value?.has_agent_profile === true);
const adminDisabled = computed(() => user.value?.agent_disabled === true);

const disabled = computed(() => !hasAgent.value || adminDisabled.value);

const tooltip = computed(() => {
    if (props.title) return props.title;
    if (adminDisabled.value) return t('agent.buttons.disabledByAdmin');
    if (!hasAgent.value) return t('agent.buttons.setupFirst');

    return null;
});

const sizeClasses = {
    xs: 'h-7 px-2.5 text-[11px] gap-1',
    sm: 'h-8 px-3.5 text-xs gap-1.5',
};
</script>

<template>
    <button
        type="button"
        :disabled="disabled"
        :title="tooltip"
        class="inline-flex items-center justify-center rounded-[var(--md-sys-shape-corner-full)] md-label-large font-medium transition-all duration-200 disabled:opacity-45 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--md-sys-color-primary)] focus-visible:ring-offset-2"
        :class="[
            disabled
                ? 'bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)]'
                : [
                    // Light: white gradient with dark text; dark: the colored
                    // primary→tertiary gradient with white text.
                    'bg-gradient-to-r from-white to-[color-mix(in_srgb,var(--md-sys-color-primary)_14%,white)] text-[var(--md-sys-color-on-surface)] ring-1 ring-inset ring-[color-mix(in_srgb,var(--md-sys-color-primary)_30%,transparent)] hover:shadow-[0_0_12px_color-mix(in_srgb,var(--md-sys-color-primary)_35%,transparent)]',
                    'dark:from-[var(--md-sys-color-primary)] dark:to-[var(--md-sys-color-tertiary)] dark:text-[var(--md-sys-color-inverse-on-surface)] dark:ring-0 dark:hover:brightness-110',
                    'active:brightness-95',
                ],
            sizeClasses[size],
        ]"
        @click="$emit('click')"
    >
        <Sparkles :size="size === 'xs' ? 12 : 14" class="flex-shrink-0" />
        <span class="whitespace-nowrap">{{ label }}</span>
    </button>
</template>

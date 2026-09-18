<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Check, Terminal } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    // The question/instruction that follows the "/sorify:gateway " prefix.
    message: { type: String, required: true },
    // Optional Sorify URL pointing at what the prompt is about, so the
    // local agent can look the entity up (run page, test page, ...).
    url: { type: String, default: null },
    size: { type: String, default: 'sm' }, // sm | xs
});

const copied = ref(false);

// The full prompt copied to the clipboard. Meant to be pasted into a
// local coding agent (Claude Code, Codex, Cursor, ...) that has the
// Sorify plugin connected — the /sorify:gateway command gives it the
// same MCP-backed reach the in-app agent has. Unlike AiButton this is
// never disabled: no agent profile is needed, everything runs locally.
const prompt = computed(() =>
    `/sorify:gateway ${props.message}${props.url ? ` ${props.url}` : ''}`.trim());

function copy() {
    navigator.clipboard.writeText(prompt.value).then(() => {
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    });
}

const sizeClasses = {
    xs: 'h-7 px-2.5 text-[11px] gap-1',
    sm: 'h-8 px-3.5 text-xs gap-1.5',
};
</script>

<template>
    <button
        type="button"
        :title="t('agent.buttons.copyGatewayTitle')"
        class="inline-flex items-center justify-center rounded-[var(--md-sys-shape-corner-full)] md-label-large font-medium transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--md-sys-color-primary)] focus-visible:ring-offset-2 text-[var(--md-sys-color-on-secondary-container)] bg-[var(--md-sys-color-secondary-container)] hover:brightness-95 active:brightness-90"
        :class="sizeClasses[size]"
        @click="copy"
    >
        <Check v-if="copied" :size="size === 'xs' ? 12 : 14" class="flex-shrink-0" :style="{ color: 'var(--md-ext-color-success)' }" />
        <Terminal v-else :size="size === 'xs' ? 12 : 14" class="flex-shrink-0" />
        <span class="whitespace-nowrap">{{ copied ? t('agent.buttons.gatewayCopied') : t('agent.buttons.copyGateway') }}</span>
    </button>
</template>

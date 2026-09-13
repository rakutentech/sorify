<script setup>
import { computed, ref } from 'vue';
import { ChevronDown, CircleAlert, Wrench } from '@lucide/vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const props = defineProps({
    toolCall: { type: Object, required: true },
});

const expanded = ref(false);

const statusColor = computed(() => {
    if (props.toolCall.isError) return 'var(--md-sys-color-error)';
    if (props.toolCall.result !== null && props.toolCall.result !== undefined) return 'var(--md-ext-color-success)';

    return 'var(--md-sys-color-primary)';
});

const resultPreview = computed(() => {
    const result = props.toolCall.result;

    if (result === null || result === undefined) return null;

    if (typeof result !== 'string') return JSON.stringify(result, null, 2);

    return result;
});

const argumentsPreview = computed(() => {
    const args = props.toolCall.arguments;

    if (!args || Object.keys(args).length === 0) return '';

    return JSON.stringify(args, null, 2);
});
</script>

<template>
    <div class="rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-highest)] overflow-hidden">
        <button
            class="w-full flex items-center gap-2 px-3 py-2 text-left hover:bg-[var(--md-sys-color-surface-container)] transition-colors"
            @click="expanded = !expanded"
        >
            <Wrench :size="14" :style="{ color: statusColor }" class="flex-shrink-0" />
            <span class="md-body-small font-medium text-[var(--md-sys-color-on-surface)] font-mono truncate">{{ toolCall.name }}</span>
            <CircleAlert v-if="toolCall.isError" :size="14" class="text-[var(--md-sys-color-error)] flex-shrink-0" />
            <span
                v-if="toolCall.result === null || toolCall.result === undefined"
                class="md-body-small text-[var(--md-sys-color-primary)] flex-shrink-0"
            >
                {{ t('agent.chat.toolRunning') }}
            </span>
            <ChevronDown
                :size="14"
                class="text-[var(--md-sys-color-on-surface-variant)] flex-shrink-0 ml-auto transition-transform"
                :class="expanded ? 'rotate-180' : ''"
            />
        </button>
        <div v-if="expanded" class="px-3 pb-3 space-y-2">
            <div v-if="toolCall.arguments && Object.keys(toolCall.arguments).length">
                <p class="md-label-small uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)] mb-1">{{ t('agent.chat.toolArguments') }}</p>
                <pre class="md-body-small text-[var(--md-sys-color-on-surface)] font-mono whitespace-pre-wrap break-all bg-[var(--md-sys-color-surface-container-low)] rounded-[var(--md-sys-shape-corner-extra-small)] p-2 overflow-x-auto max-h-48">{{ argumentsPreview }}</pre>
            </div>
            <div v-if="resultPreview !== null">
                <p class="md-label-small uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)] mb-1">{{ t('agent.chat.toolResult') }}</p>
                <pre class="md-body-small text-[var(--md-sys-color-on-surface)] font-mono whitespace-pre-wrap break-all bg-[var(--md-sys-color-surface-container-low)] rounded-[var(--md-sys-shape-corner-extra-small)] p-2 overflow-x-auto max-h-64">{{ resultPreview }}</pre>
            </div>
        </div>
    </div>
</template>

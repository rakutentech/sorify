<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ChevronDown, CircleAlert, Wrench } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import ToolCallChip from './ToolCallChip.vue';

const { t, te } = useI18n();

const props = defineProps({
    toolCalls: { type: Array, required: true },
});

const expanded = ref(false);

// Once the user manually collapses the section, stop auto-expanding on
// errors — their choice wins over the error surfacing.
const manuallyCollapsed = ref(false);

function toggle() {
    expanded.value = !expanded.value;

    if (!expanded.value) manuallyCollapsed.value = true;
}

const runningCall = computed(() => props.toolCalls.find((call) => call.result === null || call.result === undefined));

const anyRunning = computed(() => runningCall.value !== undefined);

const errorCount = computed(() => props.toolCalls.filter((call) => call.isError).length);

const statusColor = computed(() => {
    if (errorCount.value > 0) return 'var(--md-sys-color-error)';
    if (!anyRunning.value) return 'var(--md-ext-color-success)';

    return 'var(--md-sys-color-primary)';
});

/** Human-readable action label; falls back to the raw tool name. */
function toolLabel(name) {
    const key = `agent.tools.${name}`;

    return te(key) || te(key, 'en') ? t(key) : name;
}

const runningLabel = computed(() => {
    if (!anyRunning.value || !runningCall.value) return null;

    return toolLabel(runningCall.value.name);
});

// Stamp completion times as results arrive so the total elapsed span can
// be computed for live turns. History-replayed calls have no startedAt,
// so no duration is shown for them.
watch(
    () => props.toolCalls.map((call) => call.result === null || call.result === undefined),
    () => {
        for (const call of props.toolCalls) {
            if (call.result !== null && call.result !== undefined && call.startedAt && !call.finishedAt) {
                call.finishedAt = Date.now();
            }
        }
    },
);

// Auto-expand when a tool call fails so errors surface immediately.
watch(
    () => props.toolCalls.some((call) => call.isError),
    (hasError) => {
        if (hasError && !expanded.value && !manuallyCollapsed.value) expanded.value = true;
    },
);

// Tick once a second while a tool runs so the user can see it is alive.
const now = ref(Date.now());
let timer = null;

watch(anyRunning, (isRunning) => {
    if (isRunning && timer === null) {
        timer = setInterval(() => {
            now.value = Date.now();
        }, 1000);
    } else if (!isRunning && timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}, { immediate: true });

onBeforeUnmount(() => {
    if (timer !== null) clearInterval(timer);
});

const elapsedSeconds = computed(() => {
    if (!anyRunning.value || !runningCall.value?.startedAt) return null;

    return Math.max(0, Math.round((now.value - runningCall.value.startedAt) / 1000));
});

const totalSeconds = computed(() => {
    if (anyRunning.value) return null;

    const started = props.toolCalls.map((call) => call.startedAt).filter(Boolean);
    const finished = props.toolCalls.map((call) => call.finishedAt).filter(Boolean);

    if (started.length !== props.toolCalls.length || finished.length !== props.toolCalls.length) return null;

    return Math.max(0, Math.round((Math.max(...finished) - Math.min(...started)) / 1000));
});

const toggleTitle = computed(() => (expanded.value
    ? t('agent.chat.toolSummaryCollapse')
    : t('agent.chat.toolSummaryExpand')));
</script>

<template>
    <div>
        <button
            class="w-full flex items-center gap-2 px-3 py-1.5 text-left bg-[var(--md-sys-color-secondary-container)] hover:brightness-90 rounded-[var(--md-sys-shape-corner-small)] transition-[background-color,filter]"
            :aria-expanded="expanded"
            :title="toggleTitle"
            @click="toggle"
        >
            <Wrench
                :size="14"
                :style="{ color: statusColor }"
                class="flex-shrink-0"
                :class="anyRunning ? 'animate-pulse' : ''"
            />
            <span class="md-body-small text-[var(--md-sys-color-on-secondary-container)] truncate">
                <template v-if="anyRunning && runningLabel">
                    {{ t('agent.chat.toolSummaryRunning', { tool: runningLabel }) }}<template v-if="elapsedSeconds !== null"> · {{ elapsedSeconds }}s</template>
                </template>
                <template v-else>
                    {{ t('agent.chat.toolSummaryRan', { count: toolCalls.length }) }}<template v-if="totalSeconds !== null"> · {{ totalSeconds }}s</template>
                </template>
            </span>
            <span
                v-if="errorCount > 0"
                class="md-body-small text-[var(--md-sys-color-error)] flex-shrink-0 inline-flex items-center gap-1"
            >
                <CircleAlert :size="12" />
                {{ t('agent.chat.toolSummaryFailed', { count: errorCount }) }}
            </span>
            <ChevronDown
                :size="14"
                class="text-[var(--md-sys-color-on-secondary-container)] flex-shrink-0 ml-auto transition-transform"
                :class="expanded ? 'rotate-180' : ''"
            />
        </button>
        <div v-if="expanded" class="space-y-1.5 mt-1.5">
            <ToolCallChip v-for="call in toolCalls" :key="call.id" :tool-call="call" />
        </div>
    </div>
</template>

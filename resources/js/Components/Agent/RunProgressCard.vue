<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Ban, CircleCheck, CircleX, ExternalLink, LoaderCircle } from '@lucide/vue';

/**
 * Live progress card for a test run referenced by the agent's chat.
 *
 * Polls the existing run status endpoint until the run reaches a
 * terminal state (completed / failed / cancelled), the user navigates
 * away, or a safety cap is hit — runs can outlive the chat turn that
 * started them.
 */
const props = defineProps({
    runId: { type: [Number, String], required: true },
});

const { t } = useI18n();

const POLL_INTERVAL_MS = 2000;
const MAX_POLL_MS = 15 * 60 * 1000;
const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled'];

const run = ref(null);
const pollFailed = ref(false);

let timer = null;
const startedAt = Date.now();

async function poll() {
    try {
        const response = await fetch(`/sorify/runs/${props.runId}/status`, { headers: { Accept: 'application/json' } });

        if (!response.ok) {
            pollFailed.value = true;
            stopPolling();

            return;
        }

        run.value = await response.json();

        if (TERMINAL_STATUSES.includes(run.value.status) || Date.now() - startedAt > MAX_POLL_MS) {
            stopPolling();
        }
    } catch {
        pollFailed.value = true;
        stopPolling();
    }
}

function stopPolling() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

onMounted(() => {
    poll();
    timer = setInterval(poll, POLL_INTERVAL_MS);
});

onBeforeUnmount(stopPolling);

const isRunning = computed(() => run.value !== null && !TERMINAL_STATUSES.includes(run.value.status));

const statusColor = computed(() => {
    switch (run.value?.status) {
        case 'completed': return 'var(--md-ext-color-success)';
        case 'failed': return 'var(--md-sys-color-error)';
        case 'cancelled': return 'var(--md-sys-color-on-surface-variant)';
        default: return 'var(--md-sys-color-primary)';
    }
});

const doneCount = computed(() =>
    (run.value?.passed_count ?? 0) + (run.value?.failed_count ?? 0) + (run.value?.error_count ?? 0));

const progressPct = computed(() => {
    const total = run.value?.total_tests ?? 0;

    if (total > 0) return Math.min(100, Math.round((doneCount.value / total) * 100));

    return isRunning.value ? 0 : 100;
});

function formatDuration(ms) {
    if (ms === null || ms === undefined) return null;
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;

    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}
</script>

<template>
    <div class="rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-highest)] px-3 py-2.5">
        <div class="flex items-center gap-2">
            <LoaderCircle
                v-if="isRunning"
                :size="14"
                class="flex-shrink-0 animate-spin text-[var(--md-sys-color-primary)]"
            />
            <CircleCheck
                v-else-if="run?.status === 'completed'"
                :size="14"
                class="flex-shrink-0"
                :style="{ color: statusColor }"
            />
            <CircleX
                v-else-if="run?.status === 'failed'"
                :size="14"
                class="flex-shrink-0"
                :style="{ color: statusColor }"
            />
            <Ban
                v-else-if="run?.status === 'cancelled'"
                :size="14"
                class="flex-shrink-0"
                :style="{ color: statusColor }"
            />
            <span class="md-body-small font-medium text-[var(--md-sys-color-on-surface)] truncate">
                {{ t('agent.chat.runCard.title') }}
            </span>
            <span class="md-label-small uppercase tracking-wider flex-shrink-0" :style="{ color: statusColor }">
                {{ run?.status ?? '…' }}
            </span>
            <Link
                :href="`/sorify/runs/${runId}`"
                :title="t('common.viewRun')"
                :aria-label="t('common.viewRun')"
                class="ml-auto p-1 rounded-full text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] transition-colors flex-shrink-0"
            >
                <ExternalLink :size="13" />
            </Link>
        </div>

        <p v-if="pollFailed" class="mt-2 md-body-small text-[var(--md-sys-color-on-surface-variant)]">
            {{ t('agent.chat.runCard.unavailable') }}
        </p>

        <template v-else>
            <div class="mt-2.5 h-1 rounded-full bg-[var(--md-sys-color-surface-container)] overflow-hidden">
                <div
                    class="h-full rounded-full transition-all duration-500"
                    :style="{ width: `${progressPct}%`, background: statusColor }"
                />
            </div>
            <div class="mt-1.5 flex items-center gap-3 md-body-small flex-wrap">
                <span class="text-[var(--md-ext-color-success)]">{{ t('agent.chat.runCard.passed') }} {{ run?.passed_count ?? 0 }}</span>
                <span class="text-[var(--md-sys-color-error)]">{{ t('agent.chat.runCard.failed') }} {{ run?.failed_count ?? 0 }}</span>
                <span class="text-[var(--md-sys-color-tertiary)]">{{ t('agent.chat.runCard.errors') }} {{ run?.error_count ?? 0 }}</span>
                <span class="text-[var(--md-sys-color-on-surface-variant)]">{{ doneCount }}/{{ run?.total_tests ?? '—' }}</span>
                <span v-if="run?.duration_ms != null" class="text-[var(--md-sys-color-on-surface-variant)]">{{ formatDuration(run.duration_ms) }}</span>
            </div>
            <p v-if="run?.status_note" class="mt-1.5 md-body-small text-[var(--md-sys-color-on-surface-variant)]">
                {{ run.status_note }}
            </p>
        </template>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Chip, Button } from '@/Components/ui';
import { formatDate } from '@/utils/date';
import { Bot, LoaderCircle, RadioTower, Square, Zap } from '@lucide/vue';
const { t } = useI18n();

const props = defineProps({
    turns: { type: Array, default: () => [] },
});

const turns = ref([...props.turns]);
const stoppingId = ref(null);
let pollTimer = null;

// Elapsed seconds per running turn, ticked locally between polls so the
// "running for" counter feels live without hammering the server.
const now = ref(Math.floor(Date.now() / 1000));
let tickTimer = null;

const runningTurns = computed(() => turns.value);

function elapsed(turn) {
    return Math.max(0, now.value - Math.floor(Date.parse(turn.started_at) / 1000));
}

function formatDuration(seconds) {
    if (seconds < 60) return `${seconds}s`;

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) return `${minutes}m ${seconds % 60}s`;

    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

function csrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function poll() {
    try {
        const response = await fetch('/sorify/admin/agent-runs/list', { headers: { Accept: 'application/json' } });

        if (response.ok) {
            const data = await response.json();

            turns.value = data.turns ?? [];
        }
    } catch {
        // network hiccup — the next poll retries
    }
}

async function stop(turn) {
    stoppingId.value = turn.id;

    try {
        await fetch(`/sorify/admin/agent-runs/${turn.id}/stop`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        });

        await poll();
    } finally {
        stoppingId.value = null;
    }
}

onMounted(() => {
    pollTimer = setInterval(poll, 10_000);
    tickTimer = setInterval(() => { now.value = Math.floor(Date.now() / 1000); }, 1000);
});

onBeforeUnmount(() => {
    clearInterval(pollTimer);
    clearInterval(tickTimer);
});
</script>

<template>
    <AppLayout>
        <Head :title="t('adminAgentRuns.pageTitle')" />

        <div class="space-y-5">
            <div class="flex items-center justify-between">
                <h1 class="md-title-large text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5">
                    <Bot :size="26" :style="{ color: 'var(--md-sys-color-primary)' }" />
                    {{ t('adminAgentRuns.heading') }}
                </h1>
                <span class="md-label-medium text-[var(--md-sys-color-on-surface-variant)] flex items-center gap-1.5">
                    <RadioTower :size="14" />
                    {{ t('adminAgentRuns.live', { count: runningTurns.length }) }}
                </span>
            </div>

            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">
                {{ t('adminAgentRuns.hint') }}
            </p>

            <Card padding="p-0" class="overflow-hidden">
                <div v-if="!runningTurns.length" class="px-5 py-12 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                    <Bot :size="32" class="mx-auto mb-3 opacity-40" />
                    {{ t('adminAgentRuns.empty') }}
                </div>

                <div v-else class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                    <div
                        v-for="turn in runningTurns"
                        :key="turn.id"
                        class="flex items-center justify-between gap-4 px-5 py-3.5 flex-wrap hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors"
                    >
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <Chip
                                :status="turn.stale ? 'error' : 'running'"
                                :fixed="true"
                                :label="turn.stale ? t('adminAgentRuns.stale') : null"
                            />
                            <div class="min-w-0">
                                <p class="md-body-medium text-[var(--md-sys-color-on-surface)] truncate">
                                    {{ turn.user?.name ?? t('adminAgentRuns.unknownUser') }}
                                    <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">· {{ turn.user?.email }}</span>
                                </p>
                                <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)] truncate">
                                    <Bot v-if="turn.mode === 'agent'" :size="12" class="inline -mt-0.5 mr-0.5" />
                                    <Zap v-else :size="12" class="inline -mt-0.5 mr-0.5" />
                                    {{ turn.mode === 'agent' ? t('adminAgentRuns.agentMode') : t('adminAgentRuns.askMode') }}
                                    · {{ t('adminAgentRuns.startedAt', { time: formatDate(turn.started_at) }) }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-4 flex-shrink-0">
                            <span class="md-label-medium text-[var(--md-sys-color-on-surface-variant)] tabular-nums" :title="turn.last_activity_at ? t('adminAgentRuns.lastActivity', { time: formatDate(turn.last_activity_at) }) : null">
                                {{ t('adminAgentRuns.runningFor', { time: formatDuration(elapsed(turn)) }) }}
                            </span>

                            <!-- Stop requested and the worker is still alive:
                                 the turn loop notices at its next step boundary.
                                 Stale rows (worker gone) keep the button so the
                                 ghost row can be cleared — "Stopping…" there
                                 would be a false message nobody can act on. -->
                            <span
                                v-if="turn.cancel_requested && !turn.stale"
                                class="md-label-small text-[var(--md-sys-color-on-surface-variant)] italic"
                                :title="t('adminAgentRuns.stoppingHint')"
                            >
                                {{ t('adminAgentRuns.stopping') }}
                            </span>
                            <Button
                                v-else
                                variant="tonal"
                                size="sm"
                                :class="turn.stale ? '!text-[var(--md-sys-color-error)]' : null"
                                :title="turn.stale ? t('adminAgentRuns.clearHint') : null"
                                :disabled="stoppingId === turn.id"
                                @click="stop(turn)"
                            >
                                <template #leading>
                                    <LoaderCircle v-if="stoppingId === turn.id" :size="14" class="animate-spin" />
                                    <Square v-else :size="14" />
                                </template>
                                {{ turn.stale ? t('adminAgentRuns.clear') : t('adminAgentRuns.stop') }}
                            </Button>
                        </div>
                    </div>
                </div>
            </Card>
        </div>
    </AppLayout>
</template>

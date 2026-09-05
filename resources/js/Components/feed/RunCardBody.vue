<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Chip, RanBy, ScreenshotThumbs } from '@/Components/ui';
import { Timer } from '@lucide/vue';

const props = defineProps({
    activity: { type: Object, required: true },
    run: { type: Object, default: null },
});

const emit = defineEmits(['open-lightbox']);

const { t } = useI18n();

const counts = computed(() => ({
    passed: runValue('passed_count') ?? payloadNumber('passed_count'),
    failed: runValue('failed_count') ?? payloadNumber('failed_count'),
    error: runValue('error_count') ?? payloadNumber('error_count'),
    total: runValue('total_tests') ?? payloadNumber('total_tests'),
}));

function runValue(key) {
    return props.run?.[key] ?? null;
}

function payloadNumber(key) {
    const value = props.activity.payload?.[key];
    return Number.isFinite(value) ? value : null;
}

const status = computed(() => props.run?.status ?? props.activity.payload?.status ?? null);
const durationMs = computed(() => runValue('duration_ms') ?? payloadNumber('duration_ms'));
const isActive = computed(() => ['pending', 'running'].includes(status.value));
const passRate = computed(() => {
    const total = counts.value.total;
    if (!total) return 0;
    return Math.round(((counts.value.passed ?? 0) / total) * 100);
});
const hasFailures = computed(() => (counts.value.failed ?? 0) + (counts.value.error ?? 0) > 0);

const failedTests = computed(() => {
    if (!props.run?.failed_tests?.length) return [];
    return props.run.failed_tests;
});

const screenshots = computed(() => props.run?.screenshots ?? []);

const triggeredBy = computed(() => props.run?.triggered_by ?? props.activity.payload?.triggered_by ?? null);
const triggeredByUser = computed(() => props.run?.triggered_by_user ?? null);

function formatDuration(ms) {
    if (ms === null || ms === undefined) return '—';
    if (!ms && ms !== 0) return '—';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}
</script>

<template>
    <div class="mt-3 space-y-3">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <Chip v-if="status" :status="status" :label="status === 'failed' ? t('feed.failedInterrupted') : status" />
            <span class="md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                <span class="text-[var(--md-ext-color-success)]">{{ counts.passed ?? 0 }}</span>
                <span class="opacity-70">/{{ counts.total ?? 0 }}</span>
                <span v-if="hasFailures" class="text-[var(--md-sys-color-error)] ml-1.5">
                    {{ t('feed.failedCount', { count: (counts.failed ?? 0) + (counts.error ?? 0) }) }}
                </span>
            </span>
            <span v-if="durationMs !== null" class="inline-flex items-center gap-1 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                <Timer :size="14" />{{ formatDuration(durationMs) }}
            </span>
            <span class="inline-flex items-center gap-1.5 md-label-medium text-[var(--md-sys-color-on-surface-variant)]">
                <RanBy :triggered-by="triggeredBy" :triggered-by-user="triggeredByUser" />
            </span>
        </div>

        <!-- Pass-rate bar -->
        <div v-if="counts.total" class="h-2 rounded-[var(--md-sys-shape-corner-full)] overflow-hidden flex bg-[var(--md-sys-color-surface-container-high)]">
            <div
                class="bg-[var(--md-ext-color-success)] h-full transition-all duration-500"
                :style="{ width: `${passRate}%` }"
            />
            <div
                v-if="hasFailures"
                class="bg-[var(--md-sys-color-error)] h-full flex-1 transition-all duration-500"
            />
        </div>

        <p v-if="run?.status_note" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] italic">{{ run.status_note }}</p>

        <!-- Failed test names -->
        <div v-if="failedTests.length" class="flex flex-wrap gap-1.5">
            <span
                v-for="name in failedTests"
                :key="name"
                :title="name"
                class="inline-flex items-center px-2 py-0.5 rounded-[var(--md-sys-shape-corner-small)] md-label-small bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)] truncate max-w-full"
            >
                {{ name }}
            </span>
        </div>

        <div class="flex items-center justify-between gap-3">
            <ScreenshotThumbs :screenshots="screenshots" @open="(shots, i) => emit('open-lightbox', shots, i)" />

            <Link
                v-if="run"
                :href="`/sorify/runs/${run.id}`"
                class="md-label-small text-[var(--md-sys-color-primary)] hover:underline whitespace-nowrap"
            >
                {{ isActive ? t('feed.viewLiveRun') : t('feed.viewRun') }} →
            </Link>
        </div>
    </div>
</template>

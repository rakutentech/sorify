<script setup>
import { Link } from '@inertiajs/vue3';
import { formatDate, formatRelativeTime } from '@/utils/date';
import ScreenshotThumbs from './ScreenshotThumbs.vue';

defineProps({
    run: { type: Object, required: true },
});

const emit = defineEmits(['open-lightbox']);

const RUN_DOT_CLASS = {
    passed: 'bg-[var(--md-ext-color-success)]',
    failed: 'bg-[var(--md-sys-color-error)]',
    error: 'bg-[var(--md-ext-color-warning)]',
    timeout: 'bg-[var(--md-ext-color-warning)]',
    running: 'bg-[var(--md-sys-color-primary)]',
    pending: 'bg-[var(--md-sys-color-primary)]',
    cancelled: 'bg-[var(--md-sys-color-on-surface-variant)]',
};

function formatDuration(ms) {
    if (!ms && ms !== 0) return '—';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}
</script>

<template>
    <span class="inline-flex items-center gap-1">
        <Link
            :href="`/sorify/runs/${run.run_id}`"
            class="group relative inline-flex items-center gap-1 md-label-small text-[var(--md-sys-color-primary)] hover:underline"
        >
            <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="[RUN_DOT_CLASS[run.status] ?? 'bg-[var(--md-sys-color-on-surface-variant)]', run.status === 'running' ? 'animate-pulse' : '']" />
            {{ run.status }} · {{ formatRelativeTime(run.created_at) }}
            <span v-if="run.duration_ms != null" class="text-[var(--md-sys-color-on-surface-variant)]">({{ formatDuration(run.duration_ms) }})</span>
            <div class="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-1.5 z-20 hidden group-hover:flex flex-col items-center whitespace-nowrap">
                <div class="px-2.5 py-1.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-inverse-surface)] text-[var(--md-sys-color-inverse-on-surface)] md-label-small shadow-elevation-1">
                    {{ formatDate(run.created_at) }}
                </div>
            </div>
        </Link>
        <ScreenshotThumbs :screenshots="run.screenshots ?? []" @open="(shots, i) => emit('open-lightbox', shots, i)" />
    </span>
</template>

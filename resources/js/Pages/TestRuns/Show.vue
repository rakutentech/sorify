<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ScreenshotGallery from '@/Components/ScreenshotGallery.vue';
import { Card, Chip, Button, SuiteName, RanBy } from '@/Components/ui';
import { formatDate } from '@/utils/date';

const props = defineProps({
    run: { type: Object, required: true },
    results: { type: Array, default: () => [] },
});

// Auto-refresh when run is active
let refreshTimer = null;

const isActive = computed(() =>
    props.run.status === 'running' || props.run.status === 'pending',
);

function stopRefresh() {
    if (refreshTimer) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
}

function startRefresh() {
    stopRefresh();
    if (isActive.value) {
        refreshTimer = setInterval(() => {
            router.reload({ only: ['run', 'results'] });
        }, 2000);
    }
}

onMounted(() => startRefresh());

onUnmounted(() => stopRefresh());

// Re-running/cancelling redirects to a run URL that's still handled by this
// same page component instance (Inertia doesn't remount it), so onMounted
// never fires again — restart polling whenever the run identity or activity
// state changes, instead of only ever stopping it.
watch(
    () => [props.run.id, props.run.status],
    () => startRefresh(),
);

// Accordion state
const expandedResults = ref(new Set());

function toggleResult(id) {
    if (expandedResults.value.has(id)) {
        expandedResults.value.delete(id);
    } else {
        expandedResults.value.add(id);
    }
}

function isExpanded(result) {
    // Always show a running test's row so its live output is visible.
    return result.status === 'running' || expandedResults.value.has(result.id);
}

function expandAll() {
    const ids = new Set(props.results.map(r => r.id));
    expandedResults.value = ids;
    expandedStdout.value = new Set(props.results.filter(r => r.stdout).map(r => r.id));
}

function collapseAll() {
    expandedResults.value = new Set();
    expandedStdout.value = new Set();
}

// Stdout collapse state
const expandedStdout = ref(new Set());

function toggleStdout(id) {
    if (expandedStdout.value.has(id)) {
        expandedStdout.value.delete(id);
    } else {
        expandedStdout.value.add(id);
    }
}

function isStdoutExpanded(result) {
    return result.status === 'running' || expandedStdout.value.has(result.id);
}

// Auto-scroll a running test's live stdout box to the bottom as new output arrives.
const stdoutEls = new Map();

function registerStdoutEl(id, el) {
    if (el) {
        stdoutEls.set(id, el);
    } else {
        stdoutEls.delete(id);
    }
}

watch(
    () => props.results,
    () => {
        for (const result of props.results) {
            if (result.status === 'running') {
                const el = stdoutEls.get(result.id);
                if (el) el.scrollTop = el.scrollHeight;
            }
        }
    },
    { flush: 'post', deep: true },
);

function formatDuration(ms) {
    if (!ms && ms !== 0) return '—';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}

// Re-run
const rerunning = ref(false);

function rerun() {
    if (!props.run.suite) return;
    rerunning.value = true;
    const testIds = props.results.map((r) => r.test_id).filter(Boolean);
    router.post(
        `/sorify/suites/${props.run.suite.id}/runs`,
        testIds.length ? { test_ids: testIds } : {},
        {
            async: true,
            onFinish: () => { rerunning.value = false; },
        },
    );
}

// Cancel
const cancelling = ref(false);

function cancelRun() {
    if (!confirm('Cancel this run? Any test currently executing will be stopped immediately.')) return;
    cancelling.value = true;
    router.post(`/sorify/runs/${props.run.id}/cancel`, {}, {
        async: true,
        onFinish: () => { cancelling.value = false; },
    });
}

const results     = computed(() => props.results);
const passedCount = computed(() => results.value.filter((r) => r.status === 'passed').length);
const failedCount = computed(() => results.value.filter((r) => ['failed', 'error', 'timeout'].includes(r.status)).length);

const totalTests     = computed(() => props.run.total_tests || 0);
const completedCount = computed(() => results.value.filter((r) => r.status !== 'running').length);
const progressPct    = computed(() => {
    if (totalTests.value === 0) return 0;
    return Math.round((completedCount.value / totalTests.value) * 100);
});
const passedPct = computed(() => {
    if (totalTests.value === 0) return 0;
    return Math.round((passedCount.value / totalTests.value) * 100);
});
const failedPct = computed(() => {
    if (totalTests.value === 0) return 0;
    return Math.round((failedCount.value / totalTests.value) * 100);
});
</script>

<template>
    <AppLayout>
        <Head :title="`Run #${run.id}`" />

        <!-- Breadcrumb -->
        <div class="flex items-center gap-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-4">
            <Link href="/sorify/suites" class="hover:text-[var(--md-sys-color-on-surface)] transition-colors">Test Suites</Link>
            <span>/</span>
            <Link v-if="run.suite" :href="`/sorify/suites/${run.suite.id}`" class="hover:text-[var(--md-sys-color-on-surface)] transition-colors"><SuiteName :name="run.suite.name" /></Link>
            <span>/</span>
            <span class="text-[var(--md-sys-color-on-surface)]">Run #{{ run.id }}</span>
        </div>

        <!-- Run header -->
        <Card class="mb-6">
            <div class="flex items-start justify-between flex-wrap gap-4">
                <div>
                    <span class="inline-block md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-tertiary-container)] bg-[var(--md-sys-color-tertiary-container)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] mb-1.5">Test Run</span>
                    <div class="flex items-center gap-3 flex-wrap">
                        <h1 class="md-title-large text-[var(--md-sys-color-on-surface)]">
                            <SuiteName v-if="run.suite" :name="run.suite.name" /><span v-else>Test Run</span>
                            — Run #{{ run.id }}
                        </h1>
                        <Chip :status="run.status" />
                    </div>
                    <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1.5 flex items-center gap-2 flex-wrap">
                        <span>
                            Started {{ formatDate(run.started_at ?? run.created_at) }}
                            <span v-if="run.duration_ms">&bull; {{ formatDuration(run.duration_ms) }}</span>
                        </span>
                        <span>&bull;</span>
                        <RanBy :triggered-by="run.triggered_by" :triggered-by-user="run.triggered_by_user" />
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Live indicator -->
                    <div v-if="isActive" class="flex items-center gap-2 md-label-medium text-[var(--md-sys-color-on-primary-container)] bg-[var(--md-sys-color-primary-container)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-1.5">
                        <span class="w-2 h-2 rounded-full bg-current animate-pulse"></span>
                        Live — refreshing every 2s
                    </div>

                    <!-- Re-run -->
                    <Button v-if="run.suite" variant="filled" @click="rerun" :disabled="rerunning">
                        <svg v-if="rerunning" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        {{ rerunning ? 'Starting...' : 'Re-run' }}
                    </Button>

                    <!-- Cancel -->
                    <Button v-if="isActive" variant="text" class="!text-[var(--md-sys-color-error)]" @click="cancelRun" :disabled="cancelling">
                        {{ cancelling ? 'Cancelling...' : 'Cancel' }}
                    </Button>
                </div>
            </div>

            <!-- Summary counts -->
            <div class="flex items-center gap-6 mt-4 md-body-medium">
                <span class="text-[var(--md-sys-color-on-surface-variant)]">{{ results.length }} tests</span>
                <span class="text-[var(--md-ext-color-success)] font-medium">{{ passedCount }} passed</span>
                <span class="text-[var(--md-sys-color-error)] font-medium">{{ failedCount }} failed</span>
            </div>
        </Card>

        <!-- Progress bar (visible while pending or running) -->
        <Card v-if="isActive || run.status === 'running'" class="mb-6">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-[var(--md-sys-color-primary)] animate-pulse"></span>
                    <span class="md-title-small text-[var(--md-sys-color-on-surface)]">
                        {{ run.status === 'pending' ? 'Waiting to start…' : `Running tests — ${completedCount} of ${totalTests} complete` }}
                    </span>
                </div>
                <span class="md-title-small text-[var(--md-sys-color-on-surface)]">{{ progressPct }}%</span>
            </div>

            <!-- Track -->
            <div class="h-3 bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-full)] overflow-hidden flex">
                <div
                    class="h-full bg-[var(--md-ext-color-success)] transition-all duration-500 ease-out"
                    :style="{ width: passedPct + '%' }"
                ></div>
                <div
                    class="h-full bg-[var(--md-sys-color-error)] transition-all duration-500 ease-out"
                    :style="{ width: failedPct + '%' }"
                ></div>
                <div
                    v-if="run.status === 'pending'"
                    class="h-full flex-1 bg-[var(--md-sys-color-surface-container-high)] relative overflow-hidden"
                >
                    <div class="absolute inset-0 bg-gradient-to-r from-transparent via-current/20 to-transparent animate-shimmer"></div>
                </div>
            </div>

            <div class="flex items-center gap-4 mt-3 md-body-small text-[var(--md-sys-color-on-surface-variant)]">
                <span v-if="totalTests">{{ totalTests }} total</span>
                <span class="text-[var(--md-ext-color-success)]">{{ passedCount }} passed</span>
                <span class="text-[var(--md-sys-color-error)]">{{ failedCount }} failed</span>
                <span v-if="completedCount < totalTests">{{ totalTests - completedCount }} remaining</span>
            </div>
        </Card>

        <!-- Results accordion -->
        <Card padding="p-0" class="overflow-hidden">
            <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)] flex items-center justify-between">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">Test Results</h2>
                <div v-if="results.length" class="flex items-center gap-2">
                    <Button variant="text" size="sm" @click="expandAll">Expand all</Button>
                    <Button variant="text" size="sm" @click="collapseAll">Collapse all</Button>
                </div>
            </div>

            <div v-if="!results.length" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                No results yet.
            </div>

            <div v-else>
                <div v-for="result in results" :key="result.id" class="border-b border-[var(--md-sys-color-outline-variant)] last:border-b-0">
                    <!-- Accordion header -->
                    <button
                        class="w-full flex items-center justify-between px-5 py-4 hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors text-left"
                        @click="toggleResult(result.id)"
                    >
                        <div class="flex items-center gap-3 flex-wrap min-w-0">
                            <svg
                                class="w-4 h-4 text-[var(--md-sys-color-on-surface-variant)] flex-shrink-0 transition-transform"
                                :class="{ 'rotate-90': isExpanded(result) }"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            <span class="md-body-medium font-medium text-[var(--md-sys-color-on-surface)] truncate">
                                {{ result.test?.name ?? result.test_name ?? `Test #${result.id}` }}
                            </span>
                            <Chip :status="result.status" />
                        </div>
                        <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] flex-shrink-0 ml-3">
                            {{ formatDuration(result.duration_ms) }}
                        </span>
                    </button>

                    <!-- Accordion body -->
                    <div v-if="isExpanded(result)" class="px-5 pb-5 bg-[var(--md-sys-color-surface-container-lowest)]">
                        <!-- Error message -->
                        <div v-if="result.error_message" class="mt-4">
                            <p class="md-label-small font-medium text-[var(--md-sys-color-error)] uppercase tracking-wider mb-2">Error</p>
                            <pre class="text-[var(--md-sys-color-on-error-container)] md-body-small bg-[var(--md-sys-color-error-container)] rounded-[var(--md-sys-shape-corner-small)] p-3 overflow-x-auto whitespace-pre-wrap">{{ result.error_message }}</pre>
                        </div>

                        <!-- Stack trace -->
                        <div v-if="result.error_stack" class="mt-3">
                            <p class="md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider mb-2">Stack Trace</p>
                            <pre class="text-[var(--md-sys-color-on-surface-variant)] md-body-small bg-code border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)] p-3 overflow-x-auto whitespace-pre font-mono">{{ result.error_stack }}</pre>
                        </div>

                        <!-- Stdout -->
                        <div v-if="result.stdout || result.status === 'running'" class="mt-3">
                            <button
                                class="flex items-center gap-2 md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider mb-2 hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                                @click="toggleStdout(result.id)"
                            >
                                <svg
                                    class="w-3.5 h-3.5 transition-transform"
                                    :class="{ 'rotate-90': isStdoutExpanded(result) }"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                                Stdout / Logs
                                <span v-if="result.status === 'running'" class="flex items-center gap-1 text-[var(--md-sys-color-primary)] normal-case tracking-normal">
                                    <span class="w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span>
                                    Live
                                </span>
                            </button>
                            <pre
                                v-if="isStdoutExpanded(result)"
                                :ref="(el) => registerStdoutEl(result.id, el)"
                                class="text-[var(--md-sys-color-on-surface-variant)] md-body-small bg-code border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)] p-3 overflow-x-auto whitespace-pre font-mono max-h-64"
                            >{{ result.stdout || 'Waiting for output…' }}</pre>
                        </div>

                        <!-- Screenshots -->
                        <div v-if="result.screenshots?.length" class="mt-4">
                            <p class="md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider mb-3">Screenshots</p>
                            <ScreenshotGallery :screenshots="result.screenshots" />
                        </div>

                        <!-- Empty expanded state -->
                        <div v-if="result.status !== 'running' && !result.error_message && !result.stdout && !result.screenshots?.length" class="mt-4 md-body-small text-[var(--md-sys-color-on-surface-variant)] italic">
                            No additional details.
                        </div>
                    </div>
                </div>
            </div>
        </Card>
    </AppLayout>
</template>

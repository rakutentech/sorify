<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import ScreenshotGallery from '@/Components/ScreenshotGallery.vue';
import { Card, Chip, Button, Breadcrumb, SuiteName, RanBy, Avatar, ScreenshotThumbs, ScreenshotLightbox, Pagination } from '@/Components/ui';
import { formatDate, formatRelativeTime } from '@/utils/date';
import { useScreenshotLightbox } from '@/composables/useScreenshotLightbox';
import { Activity, RotateCcw, LoaderCircle, ChevronRight, Search, ChevronDown, X } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    run: { type: Object, required: true },
    results: { type: Object, default: () => ({ data: [], links: [], meta: {} }) },
    resultTestIds: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ search: '', per_page: 50, status: [], test_id: null }) },
    filteredTest: { type: Object, default: null },
});

// Auto-refresh when run is active
let refreshTimer = null;

const isActive = computed(() =>
    props.run.status === 'running' || props.run.status === 'pending',
);

const SOURCE_LABELS = {
    ci: 'CI (GitHub Actions)',
    schedule: 'Scheduled',
    mcp: 'MCP',
    manual: 'Manual',
};
const sourceLabel = computed(() => SOURCE_LABELS[props.run.triggered_by] ?? 'Manual');

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

// Screenshot lightbox
const lightbox = useScreenshotLightbox();

// Results filters: search, status (multi-select), per-page, and test_id
// (test_id arrives via filter[test_id] from the RunPill links on the suite/
//  review pages; the controller echoes it back as filters.test_id).
function debounce(fn, delay) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
}

const perPage = ref(props.filters.per_page ?? 50);
const testSearch = ref(props.filters.search ?? '');
const testStatus = ref([...(props.filters.status ?? [])]);
const testId = ref(props.filters.test_id ?? null);

const STATUS_OPTIONS = ['passed', 'failed', 'error', 'timeout', 'running', 'pending', 'cancelled'];

function reloadResults(overrides = {}) {
    const params = {
        search: testSearch.value,
        per_page: perPage.value,
        status: testStatus.value,
        page: 1,
        ...overrides,
    };
    if (testId.value) {
        params['filter[test_id]'] = testId.value;
    }
    router.get(window.location.pathname, params, { preserveState: true, preserveScroll: true, replace: true });
}

const debouncedTestSearch = debounce(() => reloadResults({ page: 1 }), 350);

watch(testSearch, () => debouncedTestSearch());
watch(testStatus, () => reloadResults({ page: 1 }), { deep: true });
watch(perPage, () => reloadResults({ page: 1 }));

// Status filter dropdown
const showStatusFilter = ref(false);
const statusFilterRef = ref(null);

function toggleStatusOption(status) {
    const next = new Set(testStatus.value);
    if (next.has(status)) next.delete(status);
    else next.add(status);
    testStatus.value = [...next];
}

function clearStatusFilter() {
    testStatus.value = [];
}

function clearTestFilter() {
    testId.value = null;
    const params = {
        search: testSearch.value,
        per_page: perPage.value,
        status: testStatus.value,
        page: 1,
    };
    router.get(window.location.pathname, params, { preserveState: true, preserveScroll: true, replace: true });
}

function onClickOutsideStatusFilter(event) {
    if (statusFilterRef.value && !statusFilterRef.value.contains(event.target)) {
        showStatusFilter.value = false;
    }
}

onMounted(() => document.addEventListener('mousedown', onClickOutsideStatusFilter));
onUnmounted(() => document.removeEventListener('mousedown', onClickOutsideStatusFilter));

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
    const ids = new Set(props.results.data.map(r => r.id));
    expandedResults.value = ids;
    expandedStdout.value = new Set(props.results.data.filter(r => r.stdout).map(r => r.id));
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
        for (const result of props.results.data) {
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
    const testIds = props.resultTestIds.filter(Boolean);
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
    if (!confirm(t('testRunShow.confirmCancel'))) return;
    cancelling.value = true;
    router.post(`/sorify/runs/${props.run.id}/cancel`, {}, {
        async: true,
        onFinish: () => { cancelling.value = false; },
    });
}

// Counts come from the run's own tallies (kept in sync server-side as each test
// finishes) rather than the `results` page, since results are now paginated
// and a page may hold only a fraction of the run's tests.
const results     = computed(() => props.results.data);
const passedCount = computed(() => props.run.passed_count ?? 0);
const failedCount = computed(() => (props.run.failed_count ?? 0) + (props.run.error_count ?? 0));

const totalTests     = computed(() => props.run.total_tests || 0);
const completedCount = computed(() => passedCount.value + failedCount.value);
const runningCount   = computed(() => (isActive.value ? Math.max(0, totalTests.value - completedCount.value) : 0));
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
        <Head :title="t('testRunShow.runNumber', { id: run.id })" />

        <!-- Breadcrumb -->
        <Breadcrumb :crumbs="[
            { label: t('testSuites.title'), href: '/sorify/suites' },
            { label: run.suite?.name, href: run.suite ? `/sorify/suites/${run.suite.id}` : null, suite: true },
            { label: t('testRunShow.runNumber', { id: run.id }) },
        ]">
            <template #crumb="{ crumb }">
                <SuiteName v-if="crumb.suite" :name="crumb.label" />
                <template v-else>{{ crumb.label }}</template>
            </template>
        </Breadcrumb>

        <!-- Run header -->
        <Card variant="plain" class="mb-6">
            <div class="flex items-start justify-between flex-wrap gap-4">
                <div>
                    <span class="inline-flex items-center gap-3 mb-1.5">
                        <span class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-tertiary-container)] bg-[var(--md-sys-color-tertiary-container)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">{{ t('testRunShow.testRun') }}</span>
                        <span v-if="run.triggered_by_user" class="flex items-center gap-1.5">
                            <Avatar :name="run.triggered_by_user.name" :email="run.triggered_by_user.email" :avatar-url="run.triggered_by_user.avatar_url" />
                            <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('testRunShow.triggeredBy', { name: run.triggered_by_user.name }) }}</span>
                        </span>
                        <span v-else class="flex items-center gap-1.5">
                            <RanBy :triggered-by="run.triggered_by" :triggered-by-user="run.triggered_by_user" />
                            <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ sourceLabel }}</span>
                        </span>
                    </span>
                    <div class="flex items-center gap-3 flex-wrap">
                        <h1 class="md-title-large text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5">
                            <Activity :size="26" :style="{ color: 'var(--md-ext-color-success)' }" />
                            <span>
                                <SuiteName v-if="run.suite" :name="run.suite.name" /><span v-else>{{ t('testRunShow.testRun') }}</span>
                                — {{ t('testRunShow.runNumber', { id: run.id }) }}
                            </span>
                        </h1>
                    </div>
                    <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1.5 flex items-center gap-2 flex-wrap">
                        <span class="inline-flex items-center gap-2">
                            <span class="group relative inline-flex items-center">
                                {{ t('testRunShow.started', { date: formatRelativeTime(run.started_at ?? run.created_at) }) }}
                                <div class="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-1.5 z-20 hidden group-hover:flex flex-col items-center whitespace-nowrap">
                                    <div class="px-2.5 py-1.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-inverse-surface)] text-[var(--md-sys-color-inverse-on-surface)] md-label-small shadow-elevation-1">
                                        {{ formatDate(run.started_at ?? run.created_at) }}
                                    </div>
                                </div>
                            </span>
                            <span v-if="run.duration_ms">&bull; {{ formatDuration(run.duration_ms) }}<span v-if="run.completed_at"> ({{ formatRelativeTime(run.completed_at) }})</span></span>
                        </span>
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Live indicator -->
                    <div v-if="isActive" class="flex items-center gap-2 md-label-medium text-[var(--md-sys-color-on-primary-container)] bg-[var(--md-sys-color-primary-container)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-1.5">
                        <span class="w-2 h-2 rounded-full bg-current animate-pulse"></span>
                        {{ t('testRunShow.live') }}
                    </div>

                    <!-- Re-run -->
                    <Button v-if="run.suite" variant="filled" @click="rerun" :disabled="rerunning">
                        <template #leading>
                            <LoaderCircle v-if="rerunning" :size="16" class="animate-spin" />
                            <RotateCcw v-else :size="16" />
                        </template>
                        {{ rerunning ? t('testRunShow.starting') : t('testRunShow.rerun') }}
                    </Button>

                    <!-- Cancel -->
                    <Button v-if="isActive" variant="text" class="!text-[var(--md-sys-color-error)]" @click="cancelRun" :disabled="cancelling">
                        {{ cancelling ? t('testRunShow.cancelling') : t('testRunShow.cancel') }}
                    </Button>
                </div>
            </div>

            <!-- Summary counts -->
            <div class="flex items-center gap-6 mt-4 md-body-medium">
                <span v-if="runningCount" class="text-[var(--md-sys-color-primary)] font-medium">{{ t('testRunShow.running', { count: runningCount }) }}</span>
                <span class="text-[var(--md-ext-color-success)] font-medium">{{ t('testRunShow.passedRatio', { passed: passedCount, total: totalTests }) }}</span>
                <span class="text-[var(--md-sys-color-error)] font-medium">{{ t('testRunShow.failed', { count: failedCount }) }}</span>
            </div>
        </Card>

        <!-- Progress bar (visible while pending or running) -->
        <Card v-if="isActive || run.status === 'running'" class="mb-6">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-[var(--md-sys-color-primary)] animate-pulse"></span>
                    <span class="md-title-small text-[var(--md-sys-color-on-surface)]">
                        {{ run.status === 'pending' ? t('testRunShow.waitingToStart') : t('testRunShow.runningTests', { completed: completedCount, total: totalTests }) }}
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
                <span v-if="totalTests">{{ t('testRunShow.totalCount', { count: totalTests }) }}</span>
                <span class="text-[var(--md-ext-color-success)]">{{ t('testRunShow.passed', { count: passedCount }) }}</span>
                <span class="text-[var(--md-sys-color-error)]">{{ t('testRunShow.failed', { count: failedCount }) }}</span>
                <span v-if="completedCount < totalTests">{{ t('testRunShow.remainingCount', { count: totalTests - completedCount }) }}</span>
            </div>
        </Card>

        <!-- Results accordion -->
        <Card padding="p-0">
            <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)] flex items-center justify-between">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">{{ t('testRunShow.testResults') }}</h2>
                <div v-if="results.length" class="flex items-center gap-2">
                    <Button variant="text" size="sm" @click="expandAll">{{ t('testRunShow.expandAll') }}</Button>
                    <Button variant="text" size="sm" @click="collapseAll">{{ t('testRunShow.collapseAll') }}</Button>
                </div>
            </div>

            <!-- Filter toolbar: search + status + active-test chip -->
            <div class="px-5 py-3 border-b border-[var(--md-sys-color-outline-variant)] flex items-center gap-3 flex-wrap">
                <div class="relative max-w-xs flex-1 min-w-[10rem]">
                    <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--md-sys-color-on-surface-variant)] pointer-events-none" />
                    <input
                        v-model="testSearch"
                        type="text"
                        :placeholder="t('testRunShow.searchResultsPlaceholder')"
                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] pl-9 pr-4 py-2 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                    />
                </div>

                <div ref="statusFilterRef" class="relative">
                    <button
                        type="button"
                        @click="showStatusFilter = !showStatusFilter"
                        class="flex items-center gap-1.5 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-2 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                    >
                        {{ t('testRunShow.statusFilterLabel') }}<span v-if="testStatus.length">&nbsp;({{ testStatus.length }})</span>
                        <ChevronDown :size="14" />
                    </button>

                    <div
                        v-if="showStatusFilter"
                        class="absolute z-10 mt-1 w-48 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)] shadow-lg py-1"
                    >
                        <label
                            v-for="status in STATUS_OPTIONS"
                            :key="status"
                            class="flex items-center gap-2 px-3 py-1.5 md-body-medium text-[var(--md-sys-color-on-surface)] hover:bg-[var(--md-sys-color-surface-container-high)] cursor-pointer"
                        >
                            <input
                                type="checkbox"
                                :checked="testStatus.includes(status)"
                                @change="toggleStatusOption(status)"
                                class="w-4 h-4 rounded-[var(--md-sys-shape-corner-extra-small)] border-[var(--md-sys-color-outline)] accent-[var(--md-sys-color-primary)] cursor-pointer"
                            />
                            {{ t(`testSuiteShow.status_${status}`) }}
                        </label>
                        <button
                            v-if="testStatus.length"
                            type="button"
                            @click="clearStatusFilter"
                            class="w-full text-left px-3 py-1.5 mt-1 border-t border-[var(--md-sys-color-outline-variant)] md-label-small text-[var(--md-sys-color-primary)] hover:underline"
                        >
                            {{ t('testRunShow.statusFilterClear') }}
                        </button>
                    </div>
                </div>

                <!-- Active test filter chip (from filter[test_id]) -->
                <button
                    v-if="filteredTest"
                    type="button"
                    @click="clearTestFilter"
                    :title="t('testRunShow.clearFilter')"
                    class="inline-flex items-center gap-1.5 bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1 md-label-small hover:opacity-80 transition-opacity max-w-xs"
                >
                    <span class="truncate">{{ t('testRunShow.filteredByTest', { name: filteredTest.name }) }}</span>
                    <X :size="13" class="flex-shrink-0" />
                </button>
            </div>

            <div v-if="!results.length" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                <Activity :size="32" class="mx-auto mb-3 opacity-40" />
                {{ t('testRunShow.noResultsYet') }}
            </div>

            <div v-else>
                <div v-for="result in results" :key="result.id" class="border-b border-[var(--md-sys-color-outline-variant)] last:border-b-0">
                    <!-- Accordion header -->
                    <div
                        class="w-full flex items-center justify-between px-5 py-4 hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors text-left cursor-pointer"
                        role="button"
                        tabindex="0"
                        @click="toggleResult(result.id)"
                        @keydown.enter="toggleResult(result.id)"
                        @keydown.space.prevent="toggleResult(result.id)"
                    >
                        <div class="flex items-center gap-3 flex-wrap min-w-0">
                            <ChevronRight
                                :size="16"
                                class="text-[var(--md-sys-color-on-surface-variant)] flex-shrink-0 transition-transform"
                                :class="{ 'rotate-90': isExpanded(result) }"
                            />
                            <Link
                                v-if="run.suite && (result.test_id ?? result.test?.id)"
                                :href="`/sorify/suites/${run.suite.id}/tests/${result.test_id ?? result.test?.id}`"
                                :title="result.test?.name ?? result.test_name ?? t('testRunShow.testFallbackName', { id: result.id })"
                                class="md-body-medium font-medium text-[var(--md-sys-color-on-surface)] truncate min-w-0 max-w-[28rem] hover:text-[var(--md-sys-color-primary)] hover:underline transition-colors"
                                @click.stop
                            >
                                {{ result.test?.name ?? result.test_name ?? t('testRunShow.testFallbackName', { id: result.id }) }}
                            </Link>
                            <span v-else class="md-body-medium font-medium text-[var(--md-sys-color-on-surface)] truncate min-w-0 max-w-[28rem]">
                                {{ result.test?.name ?? result.test_name ?? t('testRunShow.testFallbackName', { id: result.id }) }}
                            </span>
                            <Chip :status="result.status" />
                        </div>
                        <div class="flex items-center gap-3 flex-shrink-0 ml-3">
                            <ScreenshotThumbs v-if="result.screenshots?.length" :screenshots="result.screenshots" @open="lightbox.open" @click.stop />
                            <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                {{ formatDuration(result.duration_ms) }}
                            </span>
                        </div>
                    </div>

                    <!-- Accordion body -->
                    <div v-if="isExpanded(result)" class="px-5 pb-5 bg-[var(--md-sys-color-surface-container-lowest)]">
                        <!-- Error message -->
                        <div v-if="result.error_message" class="mt-4">
                            <p class="md-label-small font-medium text-[var(--md-sys-color-error)] uppercase tracking-wider mb-2">{{ t('testRunShow.error') }}</p>
                            <pre class="text-[var(--md-sys-color-on-error-container)] md-body-small bg-[var(--md-sys-color-error-container)] rounded-[var(--md-sys-shape-corner-small)] p-3 overflow-x-auto whitespace-pre-wrap">{{ result.error_message }}</pre>
                        </div>

                        <!-- Stack trace -->
                        <div v-if="result.error_stack" class="mt-3">
                            <p class="md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider mb-2">{{ t('testRunShow.stackTrace') }}</p>
                            <pre class="text-[var(--md-sys-color-on-surface-variant)] md-body-small bg-code border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)] p-3 overflow-x-auto whitespace-pre font-mono">{{ result.error_stack }}</pre>
                        </div>

                        <!-- Stdout -->
                        <div v-if="result.stdout || result.status === 'running'" class="mt-3">
                            <button
                                class="flex items-center gap-2 md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider mb-2 hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                                @click="toggleStdout(result.id)"
                            >
                                <ChevronRight
                                    :size="14"
                                    class="transition-transform"
                                    :class="{ 'rotate-90': isStdoutExpanded(result) }"
                                />
                                {{ t('testRunShow.stdoutLogs') }}
                                <span v-if="result.status === 'running'" class="flex items-center gap-1 text-[var(--md-sys-color-primary)] normal-case tracking-normal">
                                    <span class="w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span>
                                    {{ t('testRunShow.liveShort') }}
                                </span>
                            </button>
                            <pre
                                v-if="isStdoutExpanded(result)"
                                :ref="(el) => registerStdoutEl(result.id, el)"
                                class="text-[var(--md-sys-color-on-surface-variant)] md-body-small bg-code border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)] p-3 overflow-x-auto whitespace-pre font-mono max-h-64"
                            >{{ result.stdout || t('testRunShow.waitingForOutput') }}</pre>
                        </div>

                        <!-- Screenshots -->
                        <div v-if="result.screenshots?.length" class="mt-4">
                            <p class="md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider mb-3">{{ t('testRunShow.screenshots') }}</p>
                            <ScreenshotGallery :screenshots="result.screenshots" />
                        </div>

                        <!-- Empty expanded state -->
                        <div v-if="result.status !== 'running' && !result.error_message && !result.stdout && !result.screenshots?.length" class="mt-4 md-body-small text-[var(--md-sys-color-on-surface-variant)] italic">
                            {{ t('testRunShow.noAdditionalDetails') }}
                        </div>
                    </div>
                </div>
            </div>

            <Pagination
                v-if="results.length"
                :paginator="props.results"
                :label="t('testRunShow.showingResults', { from: props.results.from ?? 0, to: props.results.to ?? 0, total: props.results.total })"
            >
                <template #extra>
                    <select
                        v-model="perPage"
                        class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2 py-1 md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                    >
                        <option :value="25">{{ t('testRunShow.perPage25') }}</option>
                        <option :value="50">{{ t('testRunShow.perPage50') }}</option>
                        <option :value="100">{{ t('testRunShow.perPage100') }}</option>
                        <option :value="200">{{ t('testRunShow.perPage200') }}</option>
                    </select>
                </template>
            </Pagination>
        </Card>

        <!-- Screenshot lightbox -->
        <ScreenshotLightbox
            :shots="lightbox.shots.value"
            :index="lightbox.index.value"
            @close="lightbox.close"
            @update:index="lightbox.setIndex"
        />
    </AppLayout>
</template>

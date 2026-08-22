<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import TestCodeEditor from '@/Components/TestCodeEditor.vue';
import CopyButton from '@/Components/CopyButton.vue';
import { Card, Chip, Button, SuiteName, Avatar, RunPill, ScreenshotLightbox, MarkdownRenderer } from '@/Components/ui';
import { formatDate, formatRelativeTime } from '@/utils/date';
import { useScreenshotLightbox } from '@/composables/useScreenshotLightbox';

const { t } = useI18n();

const props = defineProps({
    suite: { type: Object, required: true },
    tests: { type: Object, default: () => ({ data: [], links: [], meta: {} }) },
    filters: { type: Object, default: () => ({ search: '', per_page: 50 }) },
    users: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({ edit: false, run: false }) },
});

function debounce(fn, delay) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
}

const testSearch = ref(props.filters.search ?? '');
const testPerPage = ref(props.filters.per_page ?? 50);

function reloadTests(overrides = {}) {
    router.get(
        `/sorify/suites/${props.suite.id}/review`,
        { search: testSearch.value, per_page: testPerPage.value, ...overrides },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const debouncedTestSearch = debounce(() => reloadTests({ page: 1 }), 350);

function onSearchInput() {
    debouncedTestSearch();
}

function onPerPageChange() {
    reloadTests({ page: 1 });
}

function uploader(email) {
    const user = props.users.find(u => u.email === email);
    return { name: user?.name ?? email, email, avatar_url: user?.avatar_url ?? null };
}

function lineCount(code) {
    if (!code) return 0;
    return code.split('\n').length;
}

function previewCode(code) {
    if (!code) return '';
    return code;
}

// Recent-run screenshot lightbox
const lightbox = useScreenshotLightbox();

// Per-test "show all runs" expansion (defaults to collapsed; only the most
// recent run is shown by default to keep the layout scannable).
const expandedRuns = ref(new Set());

function toggleRunsExpanded(testId) {
    const next = new Set(expandedRuns.value);
    if (next.has(testId)) {
        next.delete(testId);
    } else {
        next.add(testId);
    }
    expandedRuns.value = next;
}

// Per-test run trigger
const runningIds = ref(new Set());

function runTest(testId) {
    runningIds.value = new Set([...runningIds.value, testId]);
    router.post(
        `/sorify/suites/${props.suite.id}/runs`,
        { test_ids: [testId] },
        {
            async: true,
            onFinish: () => {
                const next = new Set(runningIds.value);
                next.delete(testId);
                runningIds.value = next;
                router.reload({ only: ['tests'] });
            },
        },
    );
}

const totalTests = computed(() => props.tests.total ?? props.tests.data.length);
const testsWithCode = computed(() => props.tests.data.filter(t => t.playwright_code).length);
const totalLines = computed(() =>
    props.tests.data.reduce((sum, t) => sum + lineCount(t.playwright_code), 0),
);
</script>

<template>
    <AppLayout>
        <Head :title="`${t('testSuiteReview.pageTitle', { name: suite.name })}`" />

        <!-- Breadcrumb + header -->
        <div class="flex items-center gap-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-3">
            <Link href="/sorify/suites" class="hover:text-[var(--md-sys-color-on-surface)] transition-colors">{{ t('testSuites.title') }}</Link>
            <span>/</span>
            <Link :href="`/sorify/suites/${suite.id}`" class="hover:text-[var(--md-sys-color-on-surface)] transition-colors"><SuiteName :name="suite.name" /></Link>
            <span>/</span>
            <span class="text-[var(--md-sys-color-on-surface)]">{{ t('testSuiteReview.breadcrumb') }}</span>
        </div>

        <div class="flex items-start justify-between gap-4 mb-6 flex-wrap">
            <div class="min-w-0 flex-1">
                <span class="inline-block md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-tertiary-container)] bg-[var(--md-sys-color-tertiary-container)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] mb-1.5">{{ t('testSuiteReview.badge') }}</span>
                <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)] flex items-center gap-2 flex-wrap">
                    <SuiteName :name="suite.name" />
                    <span class="md-title-medium text-[var(--md-sys-color-on-surface-variant)] font-normal">· {{ t('testSuiteReview.heading') }}</span>
                </h1>
                <div v-if="suite.description" class="mt-1.5 opacity-80">
                    <MarkdownRenderer :content="suite.description" density="compact" collapsible :collapsed-lines="10" />
                </div>
                <p v-else class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1.5">{{ t('testSuiteReview.subtitle') }}</p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <Button variant="text" size="sm" :href="`/sorify/suites/${suite.id}`">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    {{ t('testSuiteReview.backToSuite') }}
                </Button>
            </div>
        </div>

        <!-- Summary strip -->
        <div class="grid grid-cols-3 gap-3 mb-6">
            <Card padding="px-4 py-3">
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteReview.statTests') }}</p>
                <p class="md-title-large text-[var(--md-sys-color-on-surface)] mt-0.5">{{ totalTests }}</p>
            </Card>
            <Card padding="px-4 py-3">
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteReview.statWithCode') }}</p>
                <p class="md-title-large text-[var(--md-sys-color-on-surface)] mt-0.5">{{ testsWithCode }}</p>
            </Card>
            <Card padding="px-4 py-3">
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteReview.statLines') }}</p>
                <p class="md-title-large text-[var(--md-sys-color-on-surface)] mt-0.5">{{ totalLines.toLocaleString() }}</p>
            </Card>
        </div>

        <!-- Suite variables (shared across all tests in this review) -->
        <Card v-if="(suite.variables ?? []).length" padding="px-4 py-3" class="mb-6">
            <div class="flex items-center gap-2 mb-2 flex-wrap">
                <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">{{ t('testShow.suiteVariables') }}</p>
                <code class="md-label-small font-mono bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface)] px-1.5 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">variables.KEY</code>
                <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-70">{{ t('testShow.variablesHint') }}</span>
            </div>
            <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-70 mb-2">
                {{ t('testShow.variablesManageCaption') }}
                <Link :href="`/sorify/suites/${suite.id}`" class="text-[var(--md-sys-color-primary)] hover:underline">{{ t('testSuiteShow.suiteSettings') }}</Link>.
            </p>
            <div class="flex flex-wrap gap-1.5">
                <div
                    v-for="variable in suite.variables"
                    :key="variable.key"
                    class="flex items-center gap-1.5 bg-[var(--md-sys-color-surface-container-high)] border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-extra-small)] px-2 py-1"
                    :title="variable.value ? `${variable.key} = ${variable.value}` : variable.key"
                >
                    <code class="md-label-small font-mono font-semibold text-[var(--md-sys-color-primary)]">{{ variable.key }}</code>
                    <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">=</span>
                    <code class="md-label-small font-mono text-[var(--md-sys-color-on-surface-variant)] max-w-[12rem] truncate">{{ variable.value || '∅' }}</code>
                </div>
            </div>
        </Card>

        <!-- Toolbar -->
        <div class="flex items-center gap-3 flex-wrap mb-4">
            <div class="relative max-w-sm flex-1 min-w-[12rem]">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--md-sys-color-on-surface-variant)] pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                </svg>
                <input
                    v-model="testSearch"
                    @input="onSearchInput"
                    type="text"
                    :placeholder="t('testSuiteReview.searchPlaceholder')"
                    class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] pl-9 pr-4 py-2 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                />
            </div>
            <div class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                <span>{{ t('testSuiteReview.showing', { from: tests.from ?? 0, to: tests.to ?? 0, total: tests.total ?? 0 }) }}</span>
            </div>
            <div class="ml-auto flex items-center gap-2">
                <select
                    v-model="testPerPage"
                    @change="onPerPageChange"
                    class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2 py-1.5 md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                >
                    <option :value="10">{{ t('testSuites.perPage10') }}</option>
                    <option :value="30">{{ t('testSuites.perPage30') }}</option>
                    <option :value="50">{{ t('testSuites.perPage50') }}</option>
                    <option :value="100">{{ t('testSuites.perPage100') }}</option>
                </select>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="!tests.data.length" class="px-5 py-12 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
            <template v-if="testSearch">{{ t('testSuiteReview.noMatchSearch') }}</template>
            <template v-else>{{ t('testSuiteReview.noneYet') }}</template>
        </div>

        <!-- Review list: each test = one PR-style two-column card -->
        <div v-else class="space-y-5">
            <Card
                v-for="test in tests.data"
                :key="test.id"
                variant="outlined"
                padding="p-0"
                :class="['overflow-hidden', test.status === 'disabled' ? 'opacity-70' : '']"
            >
                <!-- Card header: chip + index + name + status + actions -->
                <div class="flex items-center justify-between gap-3 px-5 py-3 border-b border-[var(--md-sys-color-outline-variant)] bg-[var(--md-sys-color-surface-container-low)] flex-wrap">
                    <div class="flex items-center gap-2.5 min-w-0 flex-1">
                        <Chip
                            :status="test.current_status || 'never_ran'"
                            :fixed="true"
                            :label="test.current_status ? null : t('testSuiteReview.neverRan')"
                        />
                        <Link
                            :href="`/sorify/suites/${suite.id}/tests/${test.id}`"
                            class="md-title-small text-[var(--md-sys-color-on-surface)] hover:text-[var(--md-sys-color-primary)] hover:underline transition-colors truncate"
                        >
                            {{ test.name }}
                        </Link>
                        <span
                            v-if="test.status === 'disabled'"
                            class="md-label-small px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] text-[var(--md-ext-color-on-warning-container)] bg-[var(--md-ext-color-warning-container)]"
                        >{{ t('testSuiteReview.disabled') }}</span>
                    </div>
                    <div class="flex items-center gap-3 flex-shrink-0">
                        <!-- Created / Updated timestamps (subtle, hover for absolute date) -->
                        <div v-if="test.updated_at || test.created_at" class="flex items-center gap-2">
                            <span v-if="test.updated_at" class="relative group/tip">
                                <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-60 cursor-default whitespace-nowrap">{{ t('testSuiteReview.updatedAgo', { time: formatRelativeTime(test.updated_at) }) }}</span>
                                <span class="pointer-events-none absolute bottom-full right-0 mb-1.5 px-2 py-1 rounded-[var(--md-sys-shape-corner-extra-small)] bg-gray-900 text-white md-label-small whitespace-nowrap opacity-0 group-hover/tip:opacity-100 transition-opacity duration-150 z-10">{{ formatDate(test.updated_at) }}</span>
                            </span>
                            <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-30">·</span>
                            <span v-if="test.created_at" class="relative group/tip">
                                <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-60 cursor-default whitespace-nowrap">{{ t('testSuiteReview.createdAgo', { time: formatRelativeTime(test.created_at) }) }}</span>
                                <span class="pointer-events-none absolute bottom-full right-0 mb-1.5 px-2 py-1 rounded-[var(--md-sys-shape-corner-extra-small)] bg-gray-900 text-white md-label-small whitespace-nowrap opacity-0 group-hover/tip:opacity-100 transition-opacity duration-150 z-10">{{ formatDate(test.created_at) }}</span>
                            </span>
                        </div>
                        <span v-if="test.playwright_code" class="md-label-small text-[var(--md-sys-color-on-surface-variant)] tabular-nums">{{ lineCount(test.playwright_code) }} {{ t('testSuiteReview.lines') }}</span>
                        <Button
                            v-if="can.run"
                            variant="filled"
                            size="sm"
                            @click="runTest(test.id)"
                            :disabled="runningIds.has(test.id) || test.status === 'disabled' || !test.playwright_code"
                            :title="!test.playwright_code ? t('testSuiteReview.noCodeTitle') : t('testSuiteReview.runThisTest')"
                        >
                            <svg v-if="runningIds.has(test.id)" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ runningIds.has(test.id) ? t('testSuiteReview.starting') : t('testSuiteReview.run') }}
                        </Button>
                    </div>
                </div>

                <!-- Two-column review body -->
                <div class="grid grid-cols-1 lg:grid-cols-5 gap-0">
                    <!-- Left: metadata, runs, screenshots -->
                    <div class="lg:col-span-2 px-5 py-4 space-y-4 border-b lg:border-b-0 lg:border-r border-[var(--md-sys-color-outline-variant)] bg-[var(--md-sys-color-surface-container-lowest)]">
                        <!-- Description -->
                        <div v-if="test.description">
                            <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)] mb-1.5">{{ t('testSuiteReview.description') }}</p>
                            <MarkdownRenderer :content="test.description" density="compact" collapsible :collapsed-lines="10" />
                        </div>

                        <!-- Uploader -->
                        <div>
                            <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)] mb-1.5">{{ t('testSuiteReview.uploadedBy') }}</p>
                            <div class="flex items-center gap-2">
                                <Avatar
                                    v-if="test.uploaded_by"
                                    size="sm"
                                    :name="uploader(test.uploaded_by).name"
                                    :email="uploader(test.uploaded_by).email"
                                    :avatar-url="uploader(test.uploaded_by).avatar_url"
                                />
                                <div
                                    v-else
                                    class="w-5 h-5 rounded-full ring-2 ring-[var(--md-sys-color-surface-container-low)] bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)] flex items-center justify-center flex-shrink-0"
                                >
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                </div>
                                <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ test.uploaded_by ? uploader(test.uploaded_by).name : '—' }}</span>
                            </div>
                        </div>

                        <!-- Recent runs -->
                        <div v-if="test.recent_runs?.length">
                            <div class="flex items-center justify-between mb-1.5">
                                <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteReview.recentRuns') }}</p>
                                <button
                                    v-if="test.recent_runs.length > 1"
                                    type="button"
                                    class="md-label-small text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-primary)] hover:underline"
                                    @click="toggleRunsExpanded(test.id)"
                                >
                                    {{ expandedRuns.has(test.id) ? t('testSuiteReview.hideRuns') : t('testSuiteReview.moreRuns', { count: test.recent_runs.length - 1 }) }}
                                </button>
                            </div>
                            <ul class="space-y-1.5">
                                <li
                                    v-for="(run, runIdx) in (expandedRuns.has(test.id) ? test.recent_runs : test.recent_runs.slice(0, 1))"
                                    :key="run.run_id"
                                    class="flex items-center gap-2 flex-wrap"
                                >
                                    <RunPill :run="run" @open-lightbox="lightbox.open" />
                                </li>
                            </ul>
                        </div>

                        <!-- Last error (if latest run failed) -->
                        <div v-if="test.recent_runs?.[0]?.error_message">
                            <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-error)] mb-1.5">{{ t('testSuiteReview.lastError') }}</p>
                            <pre class="text-[var(--md-sys-color-on-error-container)] md-label-small bg-[var(--md-sys-color-error-container)] rounded-[var(--md-sys-shape-corner-small)] p-2.5 overflow-x-auto whitespace-pre-wrap max-h-32">{{ test.recent_runs[0].error_message }}</pre>
                        </div>
                    </div>

                    <!-- Right: Playwright code -->
                    <div class="lg:col-span-3 p-3 min-w-0">
                        <div class="flex items-center justify-between mb-2 px-1">
                            <div class="flex items-center gap-2">
                                <svg class="w-3.5 h-3.5 text-[var(--md-sys-color-on-surface-variant)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                                </svg>
                                <span class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteReview.playwrightCode') }}</span>
                            </div>
                            <CopyButton v-if="test.playwright_code" :value="test.playwright_code" :label="t('testSuiteReview.copyCode')" />
                        </div>
                        <TestCodeEditor
                            v-if="test.playwright_code"
                            :code="previewCode(test.playwright_code)"
                            :editable="false"
                        />
                        <div v-else class="rounded-[var(--md-sys-shape-corner-small)] border border-dashed border-[var(--md-sys-color-outline-variant)] px-4 py-8 text-center md-body-small text-[var(--md-sys-color-on-surface-variant)] italic">
                            {{ t('testSuiteReview.noCode') }}
                        </div>
                    </div>
                </div>
            </Card>
        </div>

        <!-- Pagination footer -->
        <div
            v-if="tests.data.length && tests.last_page > 1"
            class="flex items-center justify-between mt-6 px-5 py-3 rounded-[var(--md-sys-shape-corner-medium)] bg-[var(--md-sys-color-surface-container-low)]"
        >
            <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                {{ t('testSuiteReview.showing', { from: tests.from ?? 0, to: tests.to ?? 0, total: tests.total ?? 0 }) }}
            </p>
            <div class="flex items-center gap-1">
                <button
                    v-for="link in tests.links"
                    :key="link.label"
                    :disabled="!link.url || link.active"
                    @click="link.url && router.get(link.url, { search: testSearch, per_page: testPerPage }, { preserveState: true, preserveScroll: true, replace: true })"
                    class="px-2.5 py-1 rounded-[var(--md-sys-shape-corner-small)] md-label-small transition-colors"
                    :class="[
                        link.active
                            ? 'bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)] font-medium'
                            : link.url
                                ? 'text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)]'
                                : 'text-[var(--md-sys-color-on-surface-variant)] opacity-50 cursor-not-allowed',
                    ]"
                    v-html="link.label"
                />
            </div>
        </div>

        <!-- Screenshot lightbox -->
        <ScreenshotLightbox
            :shots="lightbox.shots.value"
            :index="lightbox.index.value"
            @close="lightbox.close"
            @update:index="lightbox.setIndex"
        />
    </AppLayout>
</template>

<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Chip, SuiteName, RanBy, Avatar, ScreenshotThumbs, ScreenshotLightbox, SortableTh } from '@/Components/ui';
import { useScreenshotLightbox } from '@/composables/useScreenshotLightbox';
import { formatDate, formatRelativeTime } from '@/utils/date';
import { Activity, User, FolderKanban, FlaskConical, Timer, Camera, Calendar, CircleDot, X } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    runs: {
        type: Object,
        default: () => ({ data: [], links: [], meta: {} }),
    },
    filters: {
        type: Object,
        default: () => ({ per_page: 30 }),
    },
    filteredTest: { type: Object, default: null },
});

const perPage = ref(props.filters.per_page ?? 30);
const testId = ref(props.filters.test_id ?? null);
const sort = ref(props.filters.sort ?? 'run_date');
const sortDir = ref(props.filters.sort_dir === 'asc' ? 'asc' : 'desc');
const lightbox = useScreenshotLightbox();

function reloadRuns(overrides = {}) {
    const params = { per_page: perPage.value, sort: sort.value, sort_dir: sortDir.value, page: 1, ...overrides };
    if (testId.value || overrides.test_id !== undefined) {
        params.test_id = overrides.test_id !== undefined ? overrides.test_id : testId.value;
    }
    if (params.test_id === null || params.test_id === undefined) delete params.test_id;
    router.get('/sorify/runs', params, { preserveState: true, replace: true });
}

watch(perPage, () => reloadRuns({ page: 1 }));

function setSort(field, dir) {
    sort.value = field;
    sortDir.value = dir;
    reloadRuns({ page: 1 });
}

function clearTestFilter() {
    testId.value = null;
    router.get('/sorify/runs', { per_page: perPage.value, sort: sort.value, sort_dir: sortDir.value, page: 1 }, { preserveState: true, replace: true });
}

function runStatusLabel(status) {
    return status === 'failed' ? t('runs.statusHasFailures') : status;
}

function formatDuration(ms) {
    if (!ms && ms !== 0) return '—';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}
</script>

<template>
    <AppLayout>
        <Head :title="t('runs.title')" />

        <div class="mb-6">
            <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5">
                <Activity :size="26" :style="{ color: 'var(--md-ext-color-success)' }" />
                {{ t('runs.title') }}
            </h1>
            <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">{{ t('runs.subtitle') }}</p>
        </div>

        <Card padding="p-0">
            <!-- Active test filter chip -->
            <div v-if="filteredTest" class="px-5 py-2.5 border-b border-[var(--md-sys-color-outline-variant)] flex items-center">
                <button
                    type="button"
                    @click="clearTestFilter"
                    :title="t('runs.clearFilter')"
                    class="inline-flex items-center gap-1.5 bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1 md-label-small hover:opacity-80 transition-opacity max-w-xs"
                >
                    <span class="truncate">{{ t('runs.filteredByTest', { name: filteredTest.name }) }}</span>
                    <X :size="13" class="flex-shrink-0" />
                </button>
            </div>

            <div v-if="!runs.data.length" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                <Activity :size="32" class="mx-auto mb-3 opacity-40" />
                {{ t('runs.noneYet') }}
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-[var(--md-sys-color-surface-container-low)]">
                            <SortableTh field="suite" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><FolderKanban :size="13" />{{ t('runs.colSuite') }}</SortableTh>
                            <SortableTh field="status" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><CircleDot :size="13" />{{ t('runs.colStatus') }}</SortableTh>
                            <SortableTh field="passed" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><FlaskConical :size="13" />{{ t('runs.colPassed') }}</SortableTh>
                            <SortableTh field="duration" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><Timer :size="13" />{{ t('runs.colDuration') }}</SortableTh>
                            <SortableTh field="screenshots" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><Camera :size="13" />{{ t('runs.colScreenshots') }}</SortableTh>
                            <SortableTh field="created_by" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><User :size="13" />{{ t('runs.colCreatedBy') }}</SortableTh>
                            <SortableTh field="ran_by" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><Activity :size="13" />{{ t('runs.colRanBy') }}</SortableTh>
                            <SortableTh field="run_date" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><Calendar :size="13" />{{ t('runs.colRunDate') }}</SortableTh>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <tr
                            v-for="run in runs.data"
                            :key="run.id"
                            class="hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors"
                        >
                            <td class="px-5 py-3 md-body-medium">
                                <Link
                                    v-if="run.suite_id"
                                    :href="`/sorify/suites/${run.suite_id}`"
                                    class="text-[var(--md-sys-color-on-surface)] hover:text-[var(--md-sys-color-primary)] transition-colors"
                                >
                                    <SuiteName v-if="run.suite_name ?? run.suite?.name" :name="run.suite_name ?? run.suite?.name" :bold="false" />
                                    <span v-else>—</span>
                                </Link>
                                <span v-else class="text-[var(--md-sys-color-on-surface)]">
                                    <SuiteName v-if="run.suite_name ?? run.suite?.name" :name="run.suite_name ?? run.suite?.name" :bold="false" />
                                    <span v-else>—</span>
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <Chip :status="run.status" :label="runStatusLabel(run.status)" />
                            </td>
                            <td class="px-5 py-3 md-body-medium">
                                <span class="text-[var(--md-ext-color-success)]">{{ run.passed_count ?? 0 }}</span><span class="text-[var(--md-sys-color-on-surface-variant)]">/{{ run.total_tests ?? 0 }}</span>
                                <span v-if="run.failed_count" class="text-[var(--md-sys-color-error)] ml-1.5">{{ t('testSuiteShow.failed', { count: run.failed_count }) }}</span>
                            </td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">{{ formatDuration(run.duration_ms) }}</td>
                            <td class="px-5 py-3">
                                <ScreenshotThumbs :screenshots="run.screenshots ?? []" @open="lightbox.open" />
                            </td>
                            <td class="px-5 py-3">
                                <Avatar
                                    v-if="run.created_by_user"
                                    :name="run.created_by_user.name"
                                    :email="run.created_by_user.email"
                                    :avatar-url="run.created_by_user.avatar_url"
                                />
                                <div
                                    v-else
                                    class="group relative w-7 h-7 rounded-full ring-2 ring-[var(--md-sys-color-surface-container-low)] bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)] flex items-center justify-center flex-shrink-0"
                                >
                                    <User :size="12" />
                                </div>
                            </td>
                            <td class="px-5 py-3"><RanBy :triggered-by="run.triggered_by" :triggered-by-user="run.triggered_by_user" :ci-ip="run.ci_ip" :ci-user-agent="run.ci_user_agent" /></td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                                <span class="group relative inline-flex items-center">
                                    {{ formatRelativeTime(run.created_at) }}
                                    <div class="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-1.5 z-20 hidden group-hover:flex flex-col items-center whitespace-nowrap">
                                        <div class="px-2.5 py-1.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-inverse-surface)] text-[var(--md-sys-color-inverse-on-surface)] md-label-small shadow-elevation-1">
                                            {{ formatDate(run.created_at) }}
                                        </div>
                                    </div>
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <Link
                                    :href="`/sorify/runs/${run.id}`"
                                    class="md-label-small text-[var(--md-sys-color-primary)] hover:underline"
                                >
                                    {{ t('testSuiteShow.viewRun') }}<span v-if="run.total_tests != null"> ({{ run.total_tests }} {{ run.total_tests === 1 ? t('testSuiteShow.test') : t('testSuiteShow.testsPlural') }})</span>
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination footer -->
            <div
                v-if="runs.data.length"
                class="flex items-center justify-between px-5 py-3 border-t border-[var(--md-sys-color-outline-variant)]"
            >
                <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                    {{ t('runs.showing', { from: runs.from ?? 0, to: runs.to ?? 0, total: runs.total }) }}
                </p>

                <div class="flex items-center gap-3">
                    <div v-if="runs.last_page > 1" class="flex items-center gap-1">
                        <button
                            v-for="link in runs.links"
                            :key="link.label"
                            :disabled="!link.url || link.active"
                            @click="link.url && router.get(link.url, { per_page: perPage, test_id: testId, sort, sort_dir: sortDir }, { preserveState: true, replace: true })"
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

                    <select
                        v-model="perPage"
                        class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2 py-1 md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                    >
                        <option :value="10">{{ t('testSuites.perPage10') }}</option>
                        <option :value="30">{{ t('testSuites.perPage30') }}</option>
                        <option :value="50">{{ t('testSuites.perPage50') }}</option>
                        <option :value="100">{{ t('testSuites.perPage100') }}</option>
                    </select>
                </div>
            </div>
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

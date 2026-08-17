<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Chip, SuiteName, RanBy, ScreenshotThumbs, ScreenshotLightbox } from '@/Components/ui';
import { useScreenshotLightbox } from '@/composables/useScreenshotLightbox';
import { formatDate, formatRelativeTime } from '@/utils/date';

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
});

const perPage = ref(props.filters.per_page ?? 30);
const lightbox = useScreenshotLightbox();

watch(perPage, () => {
    router.get('/sorify/runs', { per_page: perPage.value, page: 1 }, { preserveState: true, replace: true });
});

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
            <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)]">{{ t('runs.title') }}</h1>
            <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">{{ t('runs.subtitle') }}</p>
        </div>

        <Card padding="p-0">
            <div v-if="!runs.data.length" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                {{ t('runs.noneYet') }}
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-[var(--md-sys-color-surface-container-low)]">
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('runs.colSuite') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('runs.colStatus') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('runs.colPassed') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('runs.colFailed') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('runs.colDuration') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('runs.colScreenshots') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('runs.colCreatedBy') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('runs.colRanBy') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('runs.colRunDate') }}</th>
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
                            <td class="px-5 py-3 md-body-medium text-[var(--md-ext-color-success)]">{{ run.passed_count ?? '—' }}</td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-sys-color-error)]">{{ run.failed_count ?? '—' }}</td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">{{ formatDuration(run.duration_ms) }}</td>
                            <td class="px-5 py-3">
                                <ScreenshotThumbs :screenshots="run.screenshots ?? []" @open="lightbox.open" />
                            </td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">{{ run.created_by ?? '—' }}</td>
                            <td class="px-5 py-3"><RanBy :triggered-by="run.triggered_by" :triggered-by-user="run.triggered_by_user" /></td>
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
                            @click="link.url && router.get(link.url, { per_page: perPage }, { preserveState: true, replace: true })"
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

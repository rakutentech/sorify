<script setup>
import { ref, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, IconButton, SuiteName, AvatarGroup, SettingBadge, Pagination } from '@/Components/ui';
import { formatDate } from '@/utils/date';

const { t } = useI18n();

function debounce(fn, delay) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
}

const props = defineProps({
    suites: {
        type: Object,
        default: () => ({ data: [], links: [], meta: {} }),
    },
    filters: {
        type: Object,
        default: () => ({ search: '', per_page: 30 }),
    },
});

const search  = ref(props.filters.search ?? '');
const perPage = ref(props.filters.per_page ?? 30);

function reload(overrides = {}) {
    router.get(
        '/sorify/bookmarks',
        { search: search.value, per_page: perPage.value, ...overrides },
        { preserveState: true, replace: true },
    );
}

const debouncedSearch = debounce(() => reload({ page: 1 }), 350);

watch(search, () => debouncedSearch());
watch(perPage, () => reload({ page: 1 }));

function removeBookmark(suite) {
    router.delete(`/sorify/suites/${suite.id}/bookmark`, { preserveState: true, preserveScroll: true });
}

function formatPassRate(rate) {
    if (rate === null || rate === undefined) return '—';
    return `${Math.round(rate)}%`;
}
</script>

<template>
    <AppLayout>
        <Head :title="t('bookmarks.title')" />

        <div class="flex flex-col flex-1 min-h-0">

        <!-- Header -->
        <div class="mb-6">
            <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)]">{{ t('bookmarks.title') }}</h1>
            <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">{{ t('bookmarks.subtitle') }}</p>
        </div>

        <!-- Search -->
        <div class="mb-4">
            <div class="relative max-w-sm">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--md-sys-color-on-surface-variant)] pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                </svg>
                <input
                    v-model="search"
                    type="text"
                    :placeholder="t('bookmarks.searchPlaceholder')"
                    class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] pl-9 pr-4 py-2.5 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                />
            </div>
        </div>

        <!-- Bookmarked suites table -->
        <Card padding="p-0" class="flex flex-col flex-1 min-h-0">
            <div v-if="!suites.data.length" class="px-5 py-12 text-center">
                <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                    {{ search ? t('bookmarks.noMatchSearch') : t('bookmarks.noneYet') }}
                </p>
                <Link v-if="!search" href="/sorify/suites" class="mt-3 inline-block md-label-medium text-[var(--md-sys-color-primary)] hover:underline">
                    {{ t('bookmarks.browseSuites') }}
                </Link>
            </div>

            <div v-else class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
                <table class="w-full">
                    <thead>
                        <tr class="bg-[var(--md-sys-color-surface-container-low)]">
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('bookmarks.colName') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('bookmarks.colUsers') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('bookmarks.colTests') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('bookmarks.colRuns') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('bookmarks.colPassRate') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('bookmarks.colLastRun') }}</th>
                            <th class="text-right px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('bookmarks.colActions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <tr
                            v-for="suite in suites.data"
                            :key="suite.id"
                            class="hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors"
                        >
                            <td class="px-5 py-4">
                                <div>
                                    <Link
                                        :href="`/sorify/suites/${suite.id}`"
                                        class="md-body-medium text-[var(--md-sys-color-on-surface)] hover:text-[var(--md-sys-color-primary)] hover:underline transition-colors"
                                    >
                                        <SuiteName :name="suite.name" :bold="false" />
                                    </Link>
                                    <p v-if="suite.description" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mt-0.5 truncate max-w-xs">{{ suite.description }}</p>
                                    <div class="flex flex-wrap gap-1.5 mt-1.5">
                                        <SettingBadge :label="t('testSuites.badgeTeams')" :active="!!suite.has_teams_webhook" />
                                        <SettingBadge :label="t('testSuites.badgeScreenshots')" :active="!!suite.take_screenshot" />
                                        <SettingBadge :label="t('testSuites.badgeProxy')" :active="!!(suite.proxy_rules_count || suite.playwright_proxy)" />
                                        <SettingBadge :label="t('testSuites.badgeSchedule')" :active="!!(suite.schedule && suite.schedule.is_enabled)" />
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <AvatarGroup :users="suite.members ?? []" :suite-id="suite.id" />
                            </td>
                            <td class="px-5 py-4 md-body-medium text-[var(--md-sys-color-on-surface)]">{{ suite.tests_count ?? '—' }}</td>
                            <td class="px-5 py-4 md-body-medium text-[var(--md-sys-color-on-surface)]">{{ suite.test_runs_count ?? '—' }}</td>
                            <td class="px-5 py-4">
                                <span
                                    :class="{
                                        'text-[var(--md-ext-color-success)]': suite.pass_rate >= 90,
                                        'text-[var(--md-ext-color-warning)]': suite.pass_rate >= 70 && suite.pass_rate < 90,
                                        'text-[var(--md-sys-color-error)]': suite.pass_rate < 70 && suite.pass_rate !== null && suite.pass_rate !== undefined,
                                        'text-[var(--md-sys-color-on-surface-variant)]': suite.pass_rate === null || suite.pass_rate === undefined,
                                    }"
                                >
                                    {{ formatPassRate(suite.pass_rate) }}
                                </span>
                            </td>
                            <td class="px-5 py-4 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ formatDate(suite.last_run_at) }}</td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end">
                                    <IconButton variant="standard" :label="t('testSuites.bookmarkRemove')" @click="removeBookmark(suite)">
                                        <svg class="w-4 h-4 text-[var(--md-sys-color-primary)]" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.958a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.446a1 1 0 00-.363 1.118l1.287 3.957c.3.922-.755 1.688-1.538 1.118l-3.367-2.446a1 1 0 00-1.176 0l-3.367 2.446c-.783.57-1.838-.196-1.539-1.118l1.287-3.957a1 1 0 00-.363-1.118L2.062 9.385c-.783-.57-.38-1.81.588-1.81h4.163a1 1 0 00.95-.69l1.286-3.958z"/>
                                        </svg>
                                    </IconButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination footer -->
            <Pagination v-if="suites.data.length" :paginator="suites" :label="t('bookmarks.showing', { from: suites.from ?? 0, to: suites.to ?? 0, total: suites.total })">
                <template #extra>
                    <select
                        v-model="perPage"
                        class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2 py-1 md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                    >
                        <option :value="10">{{ t('bookmarks.perPage10') }}</option>
                        <option :value="30">{{ t('bookmarks.perPage30') }}</option>
                        <option :value="50">{{ t('bookmarks.perPage50') }}</option>
                        <option :value="100">{{ t('bookmarks.perPage100') }}</option>
                    </select>
                </template>
            </Pagination>
        </Card>

        </div><!-- end flex-1 column -->
    </AppLayout>
</template>

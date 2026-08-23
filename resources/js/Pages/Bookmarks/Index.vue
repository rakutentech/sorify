<script setup>
import { ref, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, IconButton, SuiteName, AvatarGroup, SettingBadge, Pagination } from '@/Components/ui';
import { formatDate, formatRelativeTime } from '@/utils/date';
import { Star, Search, StarOff, FolderKanban, Users, FlaskConical, Activity, Gauge, Clock } from '@lucide/vue';

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
            <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5">
                <Star :size="26" :style="{ color: 'var(--md-ext-color-warning)' }" />
                {{ t('bookmarks.title') }}
            </h1>
            <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">{{ t('bookmarks.subtitle') }}</p>
        </div>

        <!-- Search -->
        <div class="mb-4">
            <div class="relative max-w-sm">
                <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--md-sys-color-on-surface-variant)] pointer-events-none" />
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
                <StarOff :size="32" class="mx-auto mb-3 opacity-40" />
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
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider"><span class="inline-flex items-center gap-1"><FolderKanban :size="13" />{{ t('bookmarks.colName') }}</span></th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider"><span class="inline-flex items-center gap-1"><Users :size="13" />{{ t('bookmarks.colUsers') }}</span></th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider"><span class="inline-flex items-center gap-1"><FlaskConical :size="13" />{{ t('bookmarks.colTests') }}</span></th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider"><span class="inline-flex items-center gap-1"><Activity :size="13" />{{ t('bookmarks.colRuns') }}</span></th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider"><span class="inline-flex items-center gap-1"><Gauge :size="13" />{{ t('bookmarks.colPassRate') }}</span></th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider"><span class="inline-flex items-center gap-1"><Clock :size="13" />{{ t('bookmarks.colLastRun') }}</span></th>
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
                                        <SettingBadge :label="t('testSuites.badgeTeams')" :active="!!suite.has_teams_webhook" success-active />
                                        <SettingBadge :label="t('testSuites.badgeScreenshots')" :active="!!suite.take_screenshot" success-active />
                                        <SettingBadge :label="t('testSuites.badgeProxy')" :active="!!(suite.proxy_rules_count || suite.playwright_proxy)" success-active />
                                        <SettingBadge :label="t('testSuites.badgeSchedule')" :active="!!(suite.schedule && suite.schedule.is_enabled)" success-active />
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
                            <td class="px-5 py-4 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                                <span v-if="suite.last_run_at" class="group relative inline-flex items-center">
                                    {{ formatRelativeTime(suite.last_run_at) }}
                                    <div class="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-1.5 z-20 hidden group-hover:flex flex-col items-center whitespace-nowrap">
                                        <div class="px-2.5 py-1.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-inverse-surface)] text-[var(--md-sys-color-inverse-on-surface)] md-label-small shadow-elevation-1">
                                            {{ formatDate(suite.last_run_at) }}
                                        </div>
                                    </div>
                                </span>
                                <span v-else>—</span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end">
                                    <IconButton variant="standard" :label="t('testSuites.bookmarkRemove')" @click="removeBookmark(suite)">
                                        <Star :size="16" class="fill-current" :style="{ color: 'var(--md-ext-color-warning)' }" />
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

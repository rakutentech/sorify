<script setup>
import { ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button, TextField, Modal, SuiteName, AvatarGroup, SettingBadge, IconButton, SortableTh } from '@/Components/ui';
import { formatDate, formatRelativeTime } from '@/utils/date';
import {
    FolderKanban, Plus, Search, Users, FlaskConical, Activity, Gauge,
    Clock, Star, Copy, LoaderCircle, FolderOpen, Check, X,
} from '@lucide/vue';

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
    can: {
        type: Object,
        default: () => ({ create: true }),
    },
});

const search  = ref(props.filters.search ?? '');
const perPage = ref(props.filters.per_page ?? 30);
const sort    = ref(props.filters.sort ?? 'created');
const sortDir = ref(props.filters.sort_dir === 'asc' ? 'asc' : 'desc');

function reload(overrides = {}) {
    router.get(
        '/sorify/suites',
        { search: search.value, per_page: perPage.value, sort: sort.value, sort_dir: sortDir.value, ...overrides },
        { preserveState: true, replace: true },
    );
}

const debouncedSearch = debounce(() => reload({ page: 1 }), 350);

watch(search, () => debouncedSearch());
watch(perPage, () => reload({ page: 1 }));

function setSort(field, dir) {
    sort.value = field;
    sortDir.value = dir;
    reload({ page: 1 });
}

// ── Modal ──────────────────────────────────────────────────────────────────
const showModal = ref(false);

const form = useForm({
    name: '',
    playwright_proxy: '',
    history_retention: 5,
    max_retries: 0,
    description: '',
});

function openModal() {
    form.reset();
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    form.reset();
}

function submit() {
    form.post('/sorify/suites', {
        onSuccess: () => closeModal(),
    });
}

// ── Helpers ────────────────────────────────────────────────────────────────
function formatPassRate(rate) {
    if (rate === null || rate === undefined) return '—';
    return `${Math.round(rate)}%`;
}

function toggleBookmark(suite) {
    const options = { preserveState: true, preserveScroll: true, only: ['suites'] };
    const url = `/sorify/suites/${suite.id}/bookmark`;

    if (suite.is_bookmarked) {
        router.delete(url, options);
    } else {
        router.post(url, {}, options);
    }
}

// ── Duplicate suite ────────────────────────────────────────────────────────
const duplicatingSuiteIds = ref(new Set());

function duplicateSuite(suite) {
    if (!confirm(t('testSuites.confirmDuplicateSuite', { name: suite.name }))) return;

    duplicatingSuiteIds.value = new Set([...duplicatingSuiteIds.value, suite.id]);
    router.post(
        `/sorify/suites/${suite.id}/duplicate`,
        {},
        {
            onFinish: () => {
                const next = new Set(duplicatingSuiteIds.value);
                next.delete(suite.id);
                duplicatingSuiteIds.value = next;
            },
        },
    );
}
</script>

<template>
    <AppLayout>
        <Head :title="t('testSuites.title')" />

        <div class="flex flex-col flex-1 min-h-0">

        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5">
                    <FolderKanban :size="26" :style="{ color: 'var(--md-sys-color-tertiary)' }" />
                    {{ t('testSuites.title') }}
                </h1>
                <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">{{ t('testSuites.subtitle') }}</p>
            </div>
            <Button v-if="can.create" variant="filled" @click="openModal">
                <template #leading><Plus :size="16" /></template>
                {{ t('testSuites.newSuite') }}
            </Button>
        </div>

        <!-- Search -->
        <div class="mb-4">
            <div class="relative max-w-sm">
                <Search :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--md-sys-color-on-surface-variant)] pointer-events-none" />
                <input
                    v-model="search"
                    type="text"
                    :placeholder="t('testSuites.searchPlaceholder')"
                    class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] pl-9 pr-4 py-2.5 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                />
            </div>
        </div>

        <!-- Suites table -->
        <Card padding="p-0" class="flex flex-col flex-1 min-h-0">
            <div v-if="!suites.data.length" class="px-5 py-12 text-center">
                <component
                    :is="search ? Search : FolderOpen"
                    :size="32"
                    class="mx-auto mb-3 opacity-40"
                />
                <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                    {{ search ? t('testSuites.noMatchSearch') : (can.create ? t('testSuites.noneYetCanCreate') : t('testSuites.noneYetNoCreate')) }}
                </p>
                <button
                    v-if="!search && can.create"
                    @click="openModal"
                    class="mt-3 md-label-medium text-[var(--md-sys-color-primary)] hover:underline inline-flex items-center gap-1"
                >
                    <Plus :size="14" />
                    {{ t('testSuites.createFirst') }}
                </button>
            </div>

            <div v-else class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
                <table class="w-full">
                    <thead>
                        <tr class="bg-[var(--md-sys-color-surface-container-low)]">
                            <SortableTh field="name" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><FolderKanban :size="13" />{{ t('testSuites.colName') }}</SortableTh>
                            <SortableTh field="users" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><Users :size="13" />{{ t('testSuites.colUsers') }}</SortableTh>
                            <SortableTh field="tests" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><FlaskConical :size="13" />{{ t('testSuites.colTests') }}</SortableTh>
                            <SortableTh field="runs" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><Activity :size="13" />{{ t('testSuites.colRuns') }}</SortableTh>
                            <SortableTh field="pass_rate" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><Gauge :size="13" />{{ t('testSuites.colPassRate') }}</SortableTh>
                            <SortableTh field="last_run" :current-sort="sort" :current-dir="sortDir" @sort="setSort"><Clock :size="13" />{{ t('testSuites.colLastRun') }}</SortableTh>
                            <th class="w-10 px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testSuites.colActions') }}</th>
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
                                        <SettingBadge kind="teams" :label="t('testSuites.badgeTeams')" :active="!!suite.has_teams_webhook" success-active />
                                        <SettingBadge kind="screenshots" :label="t('testSuites.badgeScreenshots')" :active="!!suite.take_screenshot" success-active />
                                        <SettingBadge kind="proxy" :label="t('testSuites.badgeProxy')" :active="!!(suite.proxy_rules_count || suite.playwright_proxy)" success-active />
                                        <SettingBadge kind="variables" :label="t('testSuites.badgeVariables')" :active="!!(suite.variables_count > 0)" success-active />
                                        <SettingBadge kind="cookies" :label="t('testSuiteShow.cookiesCount', { count: suite.cookies_count ?? 0 })" :active="!!(suite.cookies_count > 0)" success-active />
                                        <SettingBadge kind="schedule" :label="t('testSuites.badgeSchedule')" :active="!!(suite.schedule && suite.schedule.is_enabled)" success-active />
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4">
                                <AvatarGroup :users="suite.members ?? []" :suite-id="suite.id" />
                            </td>
                            <td class="px-5 py-4 md-body-medium text-[var(--md-sys-color-on-surface)]">{{ suite.tests_count ?? suite.test_count ?? '—' }}</td>
                            <td class="px-5 py-4 md-body-medium text-[var(--md-sys-color-on-surface)]">{{ suite.test_runs_count ?? suite.runs_count ?? '—' }}</td>
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
                                <span v-if="suite.last_run_at" class="relative group/tip">
                                    <span class="cursor-default">{{ formatRelativeTime(suite.last_run_at) }}</span>
                                    <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded-[var(--md-sys-shape-corner-extra-small)] bg-gray-900 text-white md-label-small whitespace-nowrap opacity-0 group-hover/tip:opacity-100 transition-opacity duration-150 z-50">{{ formatDate(suite.last_run_at) }}</span>
                                </span>
                                <span v-else>—</span>
                            </td>
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-1">
                                    <IconButton
                                        variant="standard"
                                        :label="suite.is_bookmarked ? t('testSuites.bookmarkRemove') : t('testSuites.bookmarkAdd')"
                                        @click="toggleBookmark(suite)"
                                    >
                                        <Star
                                            :size="16"
                                            :class="suite.is_bookmarked ? 'fill-current' : ''"
                                            :style="{ color: suite.is_bookmarked ? 'var(--md-ext-color-warning)' : 'var(--md-sys-color-on-surface-variant)' }"
                                        />
                                    </IconButton>
                                    <IconButton
                                        v-if="can.create"
                                        variant="standard"
                                        :label="t('testSuites.duplicate')"
                                        :disabled="duplicatingSuiteIds.has(suite.id)"
                                        @click="duplicateSuite(suite)"
                                    >
                                        <LoaderCircle v-if="duplicatingSuiteIds.has(suite.id)" :size="16" class="animate-spin" />
                                        <Copy v-else :size="16" :style="{ color: 'var(--md-sys-color-on-surface-variant)' }" />
                                    </IconButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination footer -->
            <div
                v-if="suites.data.length"
                class="flex items-center justify-between px-5 py-3 border-t border-[var(--md-sys-color-outline-variant)]"
            >
                <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                    {{ t('testSuites.showing', { from: suites.from ?? 0, to: suites.to ?? 0, total: suites.total }) }}
                </p>

                <div class="flex items-center gap-3">
                    <div v-if="suites.last_page > 1" class="flex items-center gap-1">
                        <button
                            v-for="link in suites.links"
                            :key="link.label"
                            :disabled="!link.url || link.active"
                            @click="link.url && router.get(link.url, { search, per_page: perPage, sort, sort_dir: sortDir }, { preserveState: true, replace: true })"
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

        </div><!-- end flex-1 column -->

        <!-- New Suite Modal -->
        <Modal :show="showModal" :title="t('testSuites.modalTitle')" max-width="max-w-lg" @close="closeModal">
                    <form @submit.prevent="submit" class="px-6 py-5 space-y-4">
                        <TextField v-model="form.name" :label="t('testSuites.suiteName')" :placeholder="t('testSuites.suiteNamePlaceholder')" required :error="form.errors.name" />

                        <TextField
                            v-model="form.playwright_proxy"
                            :label="t('testSuites.httpProxy')"
                            :placeholder="t('testSuites.httpProxyPlaceholder')"
                            :hint="t('testSuites.httpProxyHint')"
                            :error="form.errors.playwright_proxy"
                        />

                        <div>
                            <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">{{ t('testSuites.keepHistory') }}</label>
                            <select
                                v-model="form.history_retention"
                                class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                            >
                                <option :value="3">{{ t('testSuites.last3Runs') }}</option>
                                <option :value="5">{{ t('testSuites.last5Runs') }}</option>
                                <option :value="10">{{ t('testSuites.last10Runs') }}</option>
                            </select>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mt-1.5">{{ t('testSuites.historyHint') }}</p>
                            <p v-if="form.errors.history_retention" class="md-body-small text-[var(--md-sys-color-error)] mt-1.5">{{ form.errors.history_retention }}</p>
                        </div>

                        <div>
                            <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">{{ t('testSuites.retries') }}</label>
                            <select
                                v-model="form.max_retries"
                                class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                            >
                                <option :value="0">{{ t('testSuites.noRetries') }}</option>
                                <option :value="1">{{ t('testSuites.retryOnce') }}</option>
                                <option :value="2">{{ t('testSuites.retryTwice') }}</option>
                                <option :value="3">{{ t('testSuites.retry3Times') }}</option>
                            </select>
                            <p v-if="form.errors.max_retries" class="md-body-small text-[var(--md-sys-color-error)] mt-1.5">{{ form.errors.max_retries }}</p>
                        </div>

                        <TextField v-model="form.description" :label="t('testSuites.description')" type="textarea" :rows="3" :placeholder="t('testSuites.descriptionPlaceholder')" :error="form.errors.description" />

                        <div class="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="text" @click="closeModal">
                                <template #leading><X :size="16" /></template>
                                {{ t('testSuites.cancel') }}
                            </Button>
                            <Button type="submit" variant="filled" :disabled="form.processing">
                                <template #leading>
                                    <LoaderCircle v-if="form.processing" :size="16" class="animate-spin" />
                                    <Check v-else :size="16" />
                                </template>
                                {{ form.processing ? t('testSuites.creating') : t('testSuites.createSuite') }}
                            </Button>
                        </div>
                    </form>
        </Modal>
    </AppLayout>
</template>

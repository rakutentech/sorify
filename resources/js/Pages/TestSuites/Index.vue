<script setup>
import { ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button, TextField, Modal, SuiteName, AvatarGroup, SettingBadge, IconButton } from '@/Components/ui';
import { formatDate, formatRelativeTime } from '@/utils/date';

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

function reload(overrides = {}) {
    router.get(
        '/sorify/suites',
        { search: search.value, per_page: perPage.value, ...overrides },
        { preserveState: true, replace: true },
    );
}

const debouncedSearch = debounce(() => reload({ page: 1 }), 350);

watch(search, () => debouncedSearch());
watch(perPage, () => reload({ page: 1 }));

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
                <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)]">{{ t('testSuites.title') }}</h1>
                <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">{{ t('testSuites.subtitle') }}</p>
            </div>
            <Button v-if="can.create" variant="filled" @click="openModal">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                {{ t('testSuites.newSuite') }}
            </Button>
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
                    :placeholder="t('testSuites.searchPlaceholder')"
                    class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] pl-9 pr-4 py-2.5 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                />
            </div>
        </div>

        <!-- Suites table -->
        <Card padding="p-0" class="flex flex-col flex-1 min-h-0">
            <div v-if="!suites.data.length" class="px-5 py-12 text-center">
                <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                    {{ search ? t('testSuites.noMatchSearch') : (can.create ? t('testSuites.noneYetCanCreate') : t('testSuites.noneYetNoCreate')) }}
                </p>
                <button
                    v-if="!search && can.create"
                    @click="openModal"
                    class="mt-3 md-label-medium text-[var(--md-sys-color-primary)] hover:underline"
                >
                    {{ t('testSuites.createFirst') }}
                </button>
            </div>

            <div v-else class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
                <table class="w-full">
                    <thead>
                        <tr class="bg-[var(--md-sys-color-surface-container-low)]">
                            <th class="w-10 px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testSuites.colActions') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testSuites.colName') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testSuites.colUsers') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testSuites.colTests') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testSuites.colRuns') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testSuites.colPassRate') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testSuites.colLastRun') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <tr
                            v-for="suite in suites.data"
                            :key="suite.id"
                            class="hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors"
                        >
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-1">
                                    <IconButton
                                        variant="standard"
                                        :label="suite.is_bookmarked ? t('testSuites.bookmarkRemove') : t('testSuites.bookmarkAdd')"
                                        @click="toggleBookmark(suite)"
                                    >
                                        <svg
                                            class="w-4 h-4"
                                            :class="suite.is_bookmarked ? 'text-[var(--md-sys-color-primary)]' : 'text-[var(--md-sys-color-on-surface-variant)]'"
                                            :fill="suite.is_bookmarked ? 'currentColor' : 'none'"
                                            stroke="currentColor"
                                            viewBox="0 0 20 20"
                                            stroke-width="1.5"
                                        >
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.958a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.446a1 1 0 00-.363 1.118l1.287 3.957c.3.922-.755 1.688-1.538 1.118l-3.367-2.446a1 1 0 00-1.176 0l-3.367 2.446c-.783.57-1.838-.196-1.539-1.118l1.287-3.957a1 1 0 00-.363-1.118L2.062 9.385c-.783-.57-.38-1.81.588-1.81h4.163a1 1 0 00.95-.69l1.286-3.958z"/>
                                        </svg>
                                    </IconButton>
                                    <IconButton
                                        v-if="can.create"
                                        variant="standard"
                                        :label="t('testSuites.duplicate')"
                                        :disabled="duplicatingSuiteIds.has(suite.id)"
                                        @click="duplicateSuite(suite)"
                                    >
                                        <svg v-if="duplicatingSuiteIds.has(suite.id)" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                        </svg>
                                        <svg v-else class="w-4 h-4 text-[var(--md-sys-color-on-surface-variant)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/>
                                        </svg>
                                    </IconButton>
                                </div>
                            </td>
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
                            <td class="px-5 py-4 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                <span v-if="suite.last_run_at" class="relative group/tip">
                                    <span class="cursor-default">{{ formatRelativeTime(suite.last_run_at) }}</span>
                                    <span class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 rounded-[var(--md-sys-shape-corner-extra-small)] bg-gray-900 text-white md-label-small whitespace-nowrap opacity-0 group-hover/tip:opacity-100 transition-opacity duration-150 z-50">{{ formatDate(suite.last_run_at) }}</span>
                                </span>
                                <span v-else>—</span>
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
                            @click="link.url && router.get(link.url, { search, per_page: perPage }, { preserveState: true, replace: true })"
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
                            <Button type="button" variant="text" @click="closeModal">{{ t('testSuites.cancel') }}</Button>
                            <Button type="submit" variant="filled" :disabled="form.processing">
                                {{ form.processing ? t('testSuites.creating') : t('testSuites.createSuite') }}
                            </Button>
                        </div>
                    </form>
        </Modal>
    </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import CopyableSecret from '@/Components/CopyableSecret.vue';
import CopyButton from '@/Components/CopyButton.vue';
import { Card, Chip, Button, TextField, Autocomplete, Modal, SuiteName, Avatar, AvatarGroup, RanBy, SettingBadge, RunPill, ScreenshotThumbs, ScreenshotLightbox } from '@/Components/ui';
import { formatDate } from '@/utils/date';
import { useScreenshotLightbox } from '@/composables/useScreenshotLightbox';

const { t } = useI18n();

const props = defineProps({
    suite: { type: Object, required: true },
    stats: { type: Object, default: () => ({}) },
    tests: { type: Object, default: () => ({ data: [], links: [], meta: {} }) },
    filters: { type: Object, default: () => ({ search: '', per_page: 50 }) },
    recentRuns: { type: Array, default: () => [] },
    webhookUrl: { type: String, default: null },
    members: { type: Array, default: () => [] },
    candidates: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({ edit: false, delete: false, run: false, manageUsers: false, manageSchedule: false }) },
});

function debounce(fn, delay) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
}

const testSearch = ref(props.filters.search ?? '');
const testPerPage = ref(props.filters.per_page ?? 50);
const testSort = ref(props.filters.sort ?? '');
const testStatus = ref([...(props.filters.status ?? [])]);

const STATUS_OPTIONS = ['passed', 'failed', 'error', 'timeout', 'running', 'pending', 'cancelled'];

function reloadTests(overrides = {}) {
    router.get(
        `/sorify/suites/${props.suite.id}`,
        { search: testSearch.value, per_page: testPerPage.value, sort: testSort.value, status: testStatus.value, ...overrides },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const debouncedTestSearch = debounce(() => reloadTests({ page: 1 }), 350);

watch(testSearch, () => debouncedTestSearch());
watch(testPerPage, () => reloadTests({ page: 1 }));
watch(testSort, () => reloadTests({ page: 1 }));
watch(testStatus, () => reloadTests({ page: 1 }), { deep: true });

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

function onClickOutsideStatusFilter(event) {
    if (statusFilterRef.value && !statusFilterRef.value.contains(event.target)) {
        showStatusFilter.value = false;
    }
}

onMounted(() => document.addEventListener('mousedown', onClickOutsideStatusFilter));
onUnmounted(() => document.removeEventListener('mousedown', onClickOutsideStatusFilter));

// CI webhook
const statusUrlTemplate = computed(() => props.webhookUrl ? props.webhookUrl.replace(/\/trigger$/, '/runs/{run}/status') : null);

const showCurlExample = ref(false);
const curlCommand = computed(() => props.webhookUrl
    ? `curl -X POST "${props.webhookUrl}" \\\n  -H "Content-Type: application/json" \\\n  -d '{"test_ids": [1, 2, 3]}'`
    : '');

const showTriggerResponseSample = ref(false);
const triggerResponseSample = computed(() => JSON.stringify({
    run_id: 42,
    status: 'running',
    status_url: statusUrlTemplate.value?.replace('{run}', '42') ?? null,
}, null, 2));

const showStatusResponseSample = ref(false);
const statusResponseSample = JSON.stringify({
    status: 'completed',
    passed_count: 8,
    failed_count: 1,
    error_count: 0,
    total_tests: 9,
    duration_ms: 4213,
}, null, 2);

function regenerateWebhook() {
    if (!confirm(t('testSuiteShow.confirmRegenerateWebhook'))) return;
    router.post(`/sorify/suites/${props.suite.id}/webhook/regenerate`);
}

// Schedule
const showScheduleModal = ref(false);
const scheduleForm = useForm({
    cron_expression: props.suite.schedule?.cron_expression ?? '',
    timezone: props.suite.schedule?.timezone ?? 'UTC',
    is_enabled: props.suite.schedule?.is_enabled ?? true,
});

function openScheduleModal() {
    scheduleForm.cron_expression = props.suite.schedule?.cron_expression ?? '';
    scheduleForm.timezone = props.suite.schedule?.timezone ?? 'UTC';
    scheduleForm.is_enabled = props.suite.schedule?.is_enabled ?? true;
    scheduleForm.clearErrors();
    showScheduleModal.value = true;
}

function submitSchedule() {
    scheduleForm.put(`/sorify/suites/${props.suite.id}/schedule`, {
        onSuccess: () => { showScheduleModal.value = false; },
    });
}

function removeSchedule() {
    if (!confirm(t('testSuiteShow.confirmRemoveSchedule'))) return;
    router.delete(`/sorify/suites/${props.suite.id}/schedule`);
}

// Edit suite modal
const showEditModal = ref(false);
const editForm = useForm({
    name: props.suite.name ?? '',
    playwright_proxy: props.suite.playwright_proxy ?? '',
    proxy_rules: (props.suite.proxy_rules ?? []).map(r => ({ domain: r.domain, proxy: r.proxy })),
    browser: props.suite.browser ?? 'chromium',
    headless: props.suite.headless ?? true,
    history_retention: props.suite.history_retention ?? 5,
    timeout_ms: props.suite.timeout_ms ?? 30000,
    max_retries: props.suite.max_retries ?? 0,
    take_screenshot: props.suite.take_screenshot ?? true,
    description: props.suite.description ?? '',
    teams_webhook_url: props.suite.teams_webhook_url ?? '',
    teams_webhook_proxy: props.suite.teams_webhook_proxy ?? '',
    teams_notify_on_success: props.suite.teams_notify_on_success ?? false,
    teams_notify_on_failure: props.suite.teams_notify_on_failure ?? false,
});

function openEditModal() {
    editForm.name = props.suite.name ?? '';
    editForm.playwright_proxy = props.suite.playwright_proxy ?? '';
    editForm.proxy_rules = (props.suite.proxy_rules ?? []).map(r => ({ domain: r.domain, proxy: r.proxy }));
    editForm.browser = props.suite.browser ?? 'chromium';
    editForm.headless = props.suite.headless ?? true;
    editForm.history_retention = props.suite.history_retention ?? 5;
    editForm.timeout_ms = props.suite.timeout_ms ?? 30000;
    editForm.max_retries = props.suite.max_retries ?? 0;
    editForm.take_screenshot = props.suite.take_screenshot ?? true;
    editForm.description = props.suite.description ?? '';
    editForm.teams_webhook_url = props.suite.teams_webhook_url ?? '';
    editForm.teams_webhook_proxy = props.suite.teams_webhook_proxy ?? '';
    editForm.teams_notify_on_success = props.suite.teams_notify_on_success ?? false;
    editForm.teams_notify_on_failure = props.suite.teams_notify_on_failure ?? false;
    showEditModal.value = true;
}

function submitEdit() {
    editForm.put(`/sorify/suites/${props.suite.id}`, {
        onSuccess: () => { showEditModal.value = false; },
    });
}

function addProxyRule() {
    editForm.proxy_rules.push({ domain: '', proxy: '' });
}

function removeProxyRule(index) {
    editForm.proxy_rules.splice(index, 1);
}

// Manage Users modal
const showManageUsersModal = ref(false);
const newMemberForm = useForm({
    user_id: '',
    can_view: true,
    can_edit: false,
    can_delete: false,
    can_run: false,
});

function openManageUsersModal() {
    newMemberForm.reset();
    showManageUsersModal.value = true;
}

const selectedCandidateIsViewOnly = computed(() => {
    const candidate = props.candidates.find(c => c.id === newMemberForm.user_id);
    return candidate?.is_view_only ?? false;
});

watch(selectedCandidateIsViewOnly, (isViewOnly) => {
    if (isViewOnly) {
        newMemberForm.can_edit = false;
        newMemberForm.can_delete = false;
        newMemberForm.can_run = false;
    }
});

function submitAddMember() {
    newMemberForm.post(`/sorify/suites/${props.suite.id}/users`, {
        onSuccess: () => { newMemberForm.reset(); },
    });
}

function updateMemberPrivilege(member, key) {
    router.put(`/sorify/suites/${props.suite.id}/users/${member.id}`, {
        can_view: member.can_view,
        can_edit: member.can_edit,
        can_delete: member.can_delete,
        can_run: member.can_run,
    }, {
        onError: () => { member[key] = !member[key]; },
    });
}

function removeMember(member) {
    if (!confirm(t('testSuiteShow.confirmRemoveMember', { name: member.name }))) return;
    router.delete(`/sorify/suites/${props.suite.id}/users/${member.id}`);
}

// New test modal
const showTestModal = ref(false);
const testForm = useForm({
    name: '',
    description: '',
    uploaded_by: '',
});

function openTestModal() {
    testForm.reset();
    showTestModal.value = true;
}

function submitTest() {
    testForm.post(`/sorify/suites/${props.suite.id}/tests`, {
        onSuccess: () => { showTestModal.value = false; },
    });
}

// Delete suite
function deleteSuite() {
    if (!confirm(t('testSuiteShow.confirmDeleteSuite', { name: props.suite.name }))) return;
    router.delete(`/sorify/suites/${props.suite.id}`);
}

// Auto-refresh while any test has a run in progress
let refreshTimer = null;

const hasActiveTest = computed(() =>
    props.tests.data.some(t => t.current_status === 'running' || t.current_status === 'pending'),
);

function stopRefresh() {
    if (refreshTimer) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
}

function startRefresh() {
    stopRefresh();
    if (hasActiveTest.value) {
        refreshTimer = setInterval(() => {
            router.reload({ only: ['tests', 'stats', 'recentRuns'] });
        }, 2000);
    }
}

onMounted(() => startRefresh());

onUnmounted(() => stopRefresh());

watch(hasActiveTest, () => startRefresh());

// Run all tests
const running = ref(false);

function runAll() {
    running.value = true;
    router.post(
        `/sorify/suites/${props.suite.id}/runs`,
        {},
        {
            async: true,
            onFinish: () => {
                running.value = false;
                router.reload({ only: ['tests', 'stats', 'recentRuns'] });
            },
        },
    );
}

// Per-row run
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
                router.reload({ only: ['tests', 'stats', 'recentRuns'] });
            },
        },
    );
}

// Toggle test enabled/disabled
const togglingIds = ref(new Set());

function toggleStatus(test) {
    togglingIds.value = new Set([...togglingIds.value, test.id]);
    router.patch(
        `/sorify/suites/${props.suite.id}/tests/${test.id}/toggle-status`,
        {},
        {
            onFinish: () => {
                const next = new Set(togglingIds.value);
                next.delete(test.id);
                togglingIds.value = next;
            },
        },
    );
}

// Bulk selection & delete
const selectedIds = ref(new Set());
const hasSelection = computed(() => selectedIds.value.size > 0);
const allSelected = computed(() => props.tests.data.length > 0 && selectedIds.value.size === props.tests.data.length);
const someSelected = computed(() => hasSelection.value && !allSelected.value);

function toggleSelect(id) {
    const next = new Set(selectedIds.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selectedIds.value = next;
}

function toggleSelectAll() {
    if (allSelected.value) selectedIds.value = new Set();
    else selectedIds.value = new Set(props.tests.data.map(t => t.id));
}

function bulkDelete() {
    if (!confirm(t('testSuiteShow.confirmDeleteTests', { count: selectedIds.value.size }))) return;
    router.delete(`/sorify/suites/${props.suite.id}/tests/bulk`, {
        data: { test_ids: [...selectedIds.value] },
        onSuccess: () => { selectedIds.value = new Set(); },
    });
}

const bulkRunning = ref(false);

function bulkRun() {
    bulkRunning.value = true;
    router.post(
        `/sorify/suites/${props.suite.id}/runs`,
        { test_ids: [...selectedIds.value] },
        {
            async: true,
            onSuccess: () => { selectedIds.value = new Set(); },
            onFinish: () => {
                bulkRunning.value = false;
                router.reload({ only: ['tests', 'stats', 'recentRuns'] });
            },
        },
    );
}

const bulkStatusProcessing = ref(false);

function bulkSetStatus(status) {
    bulkStatusProcessing.value = true;
    router.patch(
        `/sorify/suites/${props.suite.id}/tests/bulk/status`,
        { test_ids: [...selectedIds.value], status },
        {
            onSuccess: () => { selectedIds.value = new Set(); },
            onFinish: () => { bulkStatusProcessing.value = false; },
        },
    );
}

function uploader(email) {
    const user = props.users.find(u => u.email === email);
    return { name: user?.name ?? email, email };
}

function formatDuration(ms) {
    if (!ms && ms !== 0) return '—';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}

// Recent-run screenshot lightbox
const lightbox = useScreenshotLightbox();

// Collapsed older runs per test
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
</script>

<template>
    <AppLayout>
        <Head :title="suite.name" />

        <!-- Suite header -->
        <div class="flex items-start justify-between mb-6">
            <div>
                <div class="flex items-center gap-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1">
                    <Link href="/sorify/suites" class="hover:text-[var(--md-sys-color-on-surface)] transition-colors">{{ t('testSuites.title') }}</Link>
                    <span>/</span>
                    <span class="text-[var(--md-sys-color-on-surface)]"><SuiteName :name="suite.name" /></span>
                </div>
                <span class="inline-block md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-primary-container)] bg-[var(--md-sys-color-primary-container)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] mb-1.5">{{ t('testSuiteShow.badge') }}</span>
                <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)]"><SuiteName :name="suite.name" /></h1>
                <p v-if="suite.description" class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1 whitespace-pre-line">{{ suite.description }}</p>
                <div v-if="suite.created_by" class="flex items-center gap-2 mt-1.5">
                    <Avatar :name="suite.created_by.name" :email="suite.created_by.email" />
                    <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                        {{ t('testSuiteShow.createdBy', { name: suite.created_by.name }) }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0 ml-4">
                <Button v-if="can.delete" variant="text" @click="deleteSuite" class="!text-[var(--md-sys-color-error)]">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    {{ t('testSuiteShow.delete') }}
                </Button>
                <Button v-if="can.edit" variant="tonal" @click="openEditModal">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    {{ t('testSuiteShow.edit') }}
                </Button>
                <Button v-if="can.manageUsers" variant="tonal" @click="openManageUsersModal">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-3.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-4-4"/>
                    </svg>
                    {{ t('testSuiteShow.manageUsers') }}
                </Button>
                <Button v-if="can.run" variant="filled" @click="runAll" :disabled="running">
                    <svg v-if="running" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    {{ running ? t('testSuiteShow.starting') : t('testSuiteShow.runAllTests') }}
                </Button>
            </div>
        </div>

        <!-- Stats row -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
            <Card padding="px-4 py-3">
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.statTests') }}</p>
                <p class="md-title-large text-[var(--md-sys-color-on-surface)] mt-1">{{ stats.test_count ?? tests.total ?? tests.data.length }}</p>
            </Card>
            <Card padding="px-4 py-3">
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.statRuns') }}</p>
                <p class="md-title-large text-[var(--md-sys-color-on-surface)] mt-1">{{ stats.run_count ?? recentRuns.length }}</p>
            </Card>
            <Card padding="px-4 py-3">
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.statPassRate') }}</p>
                <p class="md-title-large mt-1"
                    :class="stats.pass_rate >= 90 ? 'text-[var(--md-ext-color-success)]' : stats.pass_rate >= 70 ? 'text-[var(--md-ext-color-warning)]' : stats.pass_rate != null ? 'text-[var(--md-sys-color-error)]' : 'text-[var(--md-sys-color-on-surface-variant)]'"
                >
                    {{ stats.pass_rate != null ? `${Math.round(stats.pass_rate)}%` : '—' }}
                </p>
            </Card>
            <Card padding="px-4 py-3">
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.statProxy') }}</p>
                <p class="md-body-medium font-medium text-[var(--md-sys-color-on-surface)] mt-1 truncate">
                    {{ suite.proxy_rules?.length ? t('testSuiteShow.proxyRules', { count: suite.proxy_rules.length }) : (suite.playwright_proxy || '—') }}
                </p>
            </Card>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Tests list -->
            <div class="lg:col-span-2">
                <Card padding="p-0">
                    <div class="flex items-center justify-between px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                        <div class="flex items-center gap-3">
                            <input
                                v-if="tests.data.length && (can.delete || can.edit || can.run)"
                                type="checkbox"
                                :checked="allSelected"
                                :indeterminate="someSelected"
                                @change="toggleSelectAll"
                                class="w-4 h-4 rounded-[var(--md-sys-shape-corner-extra-small)] border-[var(--md-sys-color-outline)] accent-[var(--md-sys-color-primary)] cursor-pointer"
                            />
                            <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">{{ t('testSuiteShow.testsHeading') }}</h2>
                            <Button v-if="hasSelection && can.run" variant="text" size="sm" @click="bulkRun" :disabled="bulkRunning">
                                <svg v-if="bulkRunning" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                {{ bulkRunning ? t('testSuiteShow.starting') : t('testSuiteShow.run', { count: selectedIds.size }) }}
                            </Button>
                            <Button v-if="hasSelection && can.edit" variant="text" size="sm" @click="bulkSetStatus('active')" :disabled="bulkStatusProcessing">
                                {{ t('testSuiteShow.activate', { count: selectedIds.size }) }}
                            </Button>
                            <Button v-if="hasSelection && can.edit" variant="text" size="sm" @click="bulkSetStatus('disabled')" :disabled="bulkStatusProcessing">
                                {{ t('testSuiteShow.deactivate', { count: selectedIds.size }) }}
                            </Button>
                            <Button v-if="hasSelection && can.delete" variant="text" size="sm" @click="bulkDelete" class="!text-[var(--md-sys-color-error)]">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                {{ t('testSuiteShow.deleteCount', { count: selectedIds.size }) }}
                            </Button>
                        </div>
                        <Button v-if="can.edit" variant="filled" size="sm" @click="openTestModal">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            {{ t('testSuiteShow.newTest') }}
                        </Button>
                    </div>

                    <div class="px-5 py-3 border-b border-[var(--md-sys-color-outline-variant)] flex items-center gap-3 flex-wrap">
                        <div class="relative max-w-xs flex-1 min-w-[10rem]">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--md-sys-color-on-surface-variant)] pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                            </svg>
                            <input
                                v-model="testSearch"
                                type="text"
                                :placeholder="t('testSuiteShow.searchTestsPlaceholder')"
                                class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] pl-9 pr-4 py-2 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                            />
                        </div>

                        <select
                            v-model="testSort"
                            class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-2 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                        >
                            <option value="">{{ t('testSuiteShow.sortNewest') }}</option>
                            <optgroup :label="t('testSuiteShow.sortGroupRunStatus')">
                                <option value="passed">{{ t('testSuiteShow.sortPassedFirst') }}</option>
                                <option value="errors">{{ t('testSuiteShow.sortErrorsFirst') }}</option>
                                <option value="running">{{ t('testSuiteShow.sortRunningFirst') }}</option>
                            </optgroup>
                            <optgroup :label="t('testSuiteShow.sortGroupActiveDisabled')">
                                <option value="status_active">{{ t('testSuiteShow.sortActiveFirst') }}</option>
                                <option value="status_disabled">{{ t('testSuiteShow.sortDisabledFirst') }}</option>
                            </optgroup>
                            <optgroup :label="t('testSuiteShow.sortGroupDuration')">
                                <option value="duration_long">{{ t('testSuiteShow.sortLongest') }}</option>
                                <option value="duration_short">{{ t('testSuiteShow.sortShortest') }}</option>
                            </optgroup>
                            <optgroup :label="t('testSuiteShow.sortGroupDate')">
                                <option value="oldest">{{ t('testSuiteShow.sortOldest') }}</option>
                            </optgroup>
                        </select>

                        <div ref="statusFilterRef" class="relative">
                            <button
                                type="button"
                                @click="showStatusFilter = !showStatusFilter"
                                class="flex items-center gap-1.5 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-2 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                            >
                                {{ t('testSuiteShow.statusFilterLabel') }}<span v-if="testStatus.length">&nbsp;({{ testStatus.length }})</span>
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
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
                                    {{ t('testSuiteShow.statusFilterClear') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-if="!tests.data.length" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                        {{ (testSearch || testStatus.length) ? t('testSuiteShow.noMatchSearch') : t('testSuiteShow.noneYet') }}
                    </div>

                    <div v-else>
                        <div
                            v-for="test in tests.data"
                            :key="test.id"
                            :class="['px-5 py-4 hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors flex items-start justify-between gap-3 border-b border-[var(--md-sys-color-outline-variant)] last:border-b-0', test.status === 'disabled' ? 'opacity-60' : '']"
                        >
                            <!-- Row checkbox -->
                            <input
                                v-if="can.delete || can.edit || can.run"
                                type="checkbox"
                                :checked="selectedIds.has(test.id)"
                                @change="toggleSelect(test.id)"
                                class="mt-1 w-4 h-4 flex-shrink-0 rounded-[var(--md-sys-shape-corner-extra-small)] border-[var(--md-sys-color-outline)] accent-[var(--md-sys-color-primary)] cursor-pointer"
                            />

                            <!-- Test info -->
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <Link
                                        :href="`/sorify/suites/${suite.id}/tests/${test.id}`"
                                        class="font-medium md-body-medium text-[var(--md-sys-color-on-surface)] hover:text-[var(--md-sys-color-primary)] hover:underline transition-colors"
                                    >
                                        {{ test.name }}
                                    </Link>
                                    <Chip v-if="test.current_status" :status="test.current_status" />
                                </div>
                                <p v-if="test.description" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mt-0.5 truncate">{{ test.description }}</p>
                                <div v-if="test.uploaded_by || test.recent_runs?.length" class="flex items-center gap-2.5 mt-1 flex-wrap">
                                    <div v-if="test.uploaded_by" class="flex items-center gap-2">
                                        <Avatar size="sm" :name="uploader(test.uploaded_by).name" :email="uploader(test.uploaded_by).email" />
                                        <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                            {{ t('testSuiteShow.by', { name: uploader(test.uploaded_by).name }) }}
                                        </p>
                                    </div>
                                    <template v-if="test.recent_runs?.length">
                                        <span v-if="test.uploaded_by" class="text-[var(--md-sys-color-outline-variant)]">·</span>
                                        <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.latestRuns') }}</span>
                                        <RunPill :run="test.recent_runs[0]" @open-lightbox="lightbox.open" />
                                        <button
                                            v-if="test.recent_runs.length > 1"
                                            type="button"
                                            class="md-label-small text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-primary)] hover:underline"
                                            @click="toggleRunsExpanded(test.id)"
                                        >
                                            {{ expandedRuns.has(test.id) ? t('testSuiteShow.hide') : t('testSuiteShow.moreRuns', { count: test.recent_runs.length - 1 }) }}
                                        </button>
                                        <div v-if="expandedRuns.has(test.id)" class="w-full flex items-center gap-2.5 flex-wrap">
                                            <RunPill
                                                v-for="run in test.recent_runs.slice(1)"
                                                :key="run.run_id"
                                                :run="run"
                                                @open-lightbox="lightbox.open"
                                            />
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <!-- Disable/Enable toggle -->
                                <button
                                    v-if="can.edit"
                                    @click="toggleStatus(test)"
                                    :disabled="togglingIds.has(test.id)"
                                    :title="test.status === 'disabled' ? t('testSuiteShow.clickToEnable') : t('testSuiteShow.clickToDisable')"
                                    :class="[
                                        'md-label-small px-2 py-1 rounded-[var(--md-sys-shape-corner-extra-small)] transition-colors disabled:opacity-50 disabled:cursor-not-allowed',
                                        test.status === 'disabled'
                                            ? 'text-[var(--md-ext-color-on-warning-container)] bg-[var(--md-ext-color-warning-container)]'
                                            : 'text-[var(--md-ext-color-on-success-container)] bg-[var(--md-ext-color-success-container)]',
                                    ]"
                                >
                                    {{ togglingIds.has(test.id) ? '...' : (test.status === 'disabled' ? t('testSuiteShow.disabled') : t('testSuiteShow.active')) }}
                                </button>

                                <!-- Per-row Run button -->
                                <button
                                    v-if="can.run"
                                    @click="runTest(test.id)"
                                    :disabled="runningIds.has(test.id) || test.status === 'disabled'"
                                    class="flex items-center gap-1 md-label-small text-[var(--md-sys-color-on-primary)] bg-[var(--md-sys-color-primary)] px-2 py-1 rounded-[var(--md-sys-shape-corner-extra-small)] transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    :title="t('testSuiteShow.runThisTest')"
                                >
                                    <svg v-if="runningIds.has(test.id)" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                    <svg v-else class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    {{ runningIds.has(test.id) ? '...' : t('testSuiteShow.runShort') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination footer -->
                    <div
                        v-if="tests.data.length"
                        class="flex items-center justify-between px-5 py-3 border-t border-[var(--md-sys-color-outline-variant)]"
                    >
                        <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                            {{ t('testSuiteShow.showing', { from: tests.from ?? 0, to: tests.to ?? 0, total: tests.total }) }}
                        </p>

                        <div class="flex items-center gap-3">
                            <div v-if="tests.last_page > 1" class="flex items-center gap-1">
                                <button
                                    v-for="link in tests.links"
                                    :key="link.label"
                                    :disabled="!link.url || link.active"
                                    @click="link.url && router.get(link.url, { search: testSearch, per_page: testPerPage, sort: testSort, status: testStatus }, { preserveState: true, preserveScroll: true, replace: true })"
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
                                v-model="testPerPage"
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
            </div>

            <div>
                <!-- Settings summary -->
                <Card padding="p-0">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">{{ t('testSuiteShow.settingsHeading') }}</h2>
                    </div>
                    <div class="px-5 py-4 flex flex-wrap gap-1.5">
                        <SettingBadge :label="t('testSuites.badgeTeams')" :active="!!suite.teams_webhook_url" />
                        <SettingBadge :label="t('testSuites.badgeScreenshots')" :active="!!suite.take_screenshot" />
                        <SettingBadge :label="t('testSuites.badgeProxy')" :active="!!(suite.proxy_rules?.length || suite.playwright_proxy)" />
                        <SettingBadge :label="t('testSuites.badgeSchedule')" :active="!!(suite.schedule && suite.schedule.is_enabled)" />
                        <SettingBadge :label="suite.max_retries ? t('testSuiteShow.retriesLabel', { count: suite.max_retries }) : t('testSuiteShow.retries')" :active="!!suite.max_retries" />
                    </div>
                </Card>

                <!-- Users -->
                <Card padding="p-0" class="mt-6">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">{{ t('testSuiteShow.usersHeading') }}</h2>
                    </div>
                    <div class="px-5 py-4">
                        <AvatarGroup :users="suite.members ?? []" :suite-id="suite.id" :max="20" />
                    </div>
                </Card>

                <!-- CI Webhook -->
                <Card padding="p-0" class="mt-6">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)] flex items-center justify-between">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">{{ t('testSuiteShow.ciWebhookHeading') }}</h2>
                        <Button v-if="can.edit" variant="text" size="sm" @click="regenerateWebhook" class="!text-[var(--md-sys-color-error)]">
                            {{ t('testSuiteShow.regenerate') }}
                        </Button>
                    </div>
                    <div class="px-5 py-4">
                        <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)] mb-1.5">{{ t('testSuiteShow.triggerARun') }}</p>
                        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-3">{{ t('testSuiteShow.webhookInstructions') }}</p>
                        <CopyableSecret v-if="webhookUrl" :value="webhookUrl" />
                        <p v-else class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.noWebhookConfigured') }}</p>

                        <div v-if="webhookUrl" class="mt-3 pt-3 border-t border-[var(--md-sys-color-outline-variant)]">
                            <button
                                @click="showCurlExample = !showCurlExample"
                                class="flex items-center gap-1.5 md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                            >
                                <svg
                                    class="w-3.5 h-3.5 transition-transform"
                                    :class="{ 'rotate-90': showCurlExample }"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                                {{ showCurlExample ? t('testSuiteShow.hideCurlExample') : t('testSuiteShow.showCurlExample') }}
                            </button>
                            <div v-if="showCurlExample" class="mt-2">
                                <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-2">
                                    {{ t('testSuiteShow.testIdsHint') }}
                                </p>
                                <div class="relative">
                                    <pre class="md-body-small font-mono bg-code border border-[var(--md-sys-color-outline-variant)] text-[var(--md-sys-color-on-surface)] rounded-[var(--md-sys-shape-corner-small)] p-3 pr-4 overflow-x-auto whitespace-pre">{{ curlCommand }}</pre>
                                    <div class="absolute top-2 right-2">
                                        <CopyButton :value="curlCommand" />
                                    </div>
                                </div>

                                <button
                                    @click="showTriggerResponseSample = !showTriggerResponseSample"
                                    class="flex items-center gap-1.5 md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] transition-colors mt-2"
                                >
                                    <svg
                                        class="w-3.5 h-3.5 transition-transform"
                                        :class="{ 'rotate-90': showTriggerResponseSample }"
                                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                    >
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                    </svg>
                                    {{ showTriggerResponseSample ? t('testSuiteShow.hideSampleResponse') : t('testSuiteShow.showSampleResponse') }}
                                </button>
                                <pre v-if="showTriggerResponseSample" class="md-body-small font-mono bg-code border border-[var(--md-sys-color-outline-variant)] text-[var(--md-sys-color-on-surface)] rounded-[var(--md-sys-shape-corner-small)] p-3 mt-2 overflow-x-auto whitespace-pre">{{ triggerResponseSample }}</pre>
                            </div>
                        </div>

                        <div v-if="webhookUrl" class="mt-3 pt-3 border-t border-[var(--md-sys-color-outline-variant)]">
                            <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)] mb-1.5">{{ t('testSuiteShow.pollRunStatus') }}</p>
                            <CopyableSecret :value="statusUrlTemplate" />

                            <button
                                @click="showStatusResponseSample = !showStatusResponseSample"
                                class="flex items-center gap-1.5 md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] transition-colors mt-2"
                            >
                                <svg
                                    class="w-3.5 h-3.5 transition-transform"
                                    :class="{ 'rotate-90': showStatusResponseSample }"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                                {{ showStatusResponseSample ? t('testSuiteShow.hideSampleResponse') : t('testSuiteShow.showSampleResponse') }}
                            </button>
                            <pre v-if="showStatusResponseSample" class="md-body-small font-mono bg-code border border-[var(--md-sys-color-outline-variant)] text-[var(--md-sys-color-on-surface)] rounded-[var(--md-sys-shape-corner-small)] p-3 mt-2 overflow-x-auto whitespace-pre">{{ statusResponseSample }}</pre>
                        </div>
                    </div>
                </Card>

                <!-- Schedule -->
                <Card padding="p-0" class="mt-6">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)] flex items-center justify-between">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">{{ t('testSuiteShow.scheduleHeading') }}</h2>
                        <div v-if="can.manageSchedule" class="flex items-center gap-1">
                            <Button v-if="suite.schedule" variant="text" size="sm" @click="removeSchedule" class="!text-[var(--md-sys-color-error)]">
                                {{ t('testSuiteShow.remove') }}
                            </Button>
                            <Button variant="text" size="sm" @click="openScheduleModal">
                                {{ suite.schedule ? t('testSuiteShow.scheduleEdit') : t('testSuiteShow.scheduleAdd') }}
                            </Button>
                        </div>
                    </div>
                    <div class="px-5 py-4">
                        <template v-if="suite.schedule">
                            <div class="flex items-center gap-2 mb-2">
                                <code class="md-body-small font-mono bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">{{ suite.schedule.cron_expression }}</code>
                                <span
                                    :class="[
                                        'md-label-small px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]',
                                        suite.schedule.is_enabled
                                            ? 'text-[var(--md-ext-color-on-success-container)] bg-[var(--md-ext-color-success-container)]'
                                            : 'text-[var(--md-sys-color-on-surface-variant)] bg-[var(--md-sys-color-surface-container-high)]',
                                    ]"
                                >
                                    {{ suite.schedule.is_enabled ? t('testSuiteShow.enabled') : t('testSuiteShow.disabled') }}
                                </span>
                            </div>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.timezoneLabel', { tz: suite.schedule.timezone }) }}</p>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.nextRun', { date: formatDate(suite.schedule.next_run_at) }) }}</p>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.lastRun', { date: formatDate(suite.schedule.last_run_at) }) }}</p>
                        </template>
                        <p v-else class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.noScheduleConfigured') }}</p>
                    </div>
                </Card>

                <!-- Recent runs -->
                <Card padding="p-0" class="mt-6">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">{{ t('testSuiteShow.recentRunsHeading') }}</h2>
                    </div>

                    <div v-if="!recentRuns.length" class="px-5 py-6 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                        {{ t('testSuiteShow.noRunsYet') }}
                    </div>

                    <div v-else>
                        <div
                            v-for="run in recentRuns"
                            :key="run.id"
                            class="px-5 py-3 hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors border-b border-[var(--md-sys-color-outline-variant)] last:border-b-0"
                        >
                            <div class="flex items-center justify-between mb-1">
                                <Chip :status="run.status" />
                                <Link :href="`/sorify/runs/${run.id}`" class="md-label-small text-[var(--md-sys-color-primary)] hover:underline">
                                    {{ t('testSuiteShow.viewRun') }}<span v-if="run.total_tests != null"> ({{ run.total_tests }} {{ run.total_tests === 1 ? t('testSuiteShow.test') : t('testSuiteShow.testsPlural') }})</span>
                                </Link>
                            </div>
                            <div class="flex items-center gap-3 md-body-small text-[var(--md-sys-color-on-surface-variant)]">
                                <span class="text-[var(--md-ext-color-success)]">{{ t('testSuiteShow.passed', { count: run.passed_count ?? 0 }) }}</span>
                                <span class="text-[var(--md-sys-color-error)]">{{ t('testSuiteShow.failed', { count: run.failed_count ?? 0 }) }}</span>
                                <span>{{ formatDuration(run.duration_ms) }}</span>
                            </div>
                            <div class="flex items-center justify-between mt-0.5">
                                <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ formatDate(run.created_at) }}</p>
                                <RanBy :triggered-by="run.triggered_by" :triggered-by-user="run.triggered_by_user" />
                            </div>
                            <ScreenshotThumbs v-if="run.screenshots?.length" :screenshots="run.screenshots" class="mt-2" @open="lightbox.open" />
                        </div>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Edit Suite Modal -->
        <Modal :show="showEditModal" :title="t('testSuiteShow.editSuiteModalTitle')" max-width="max-w-lg" @close="showEditModal = false">
                    <form @submit.prevent="submitEdit" class="px-6 py-5 space-y-4">
                        <TextField v-model="editForm.name" :label="t('testSuiteShow.suiteNameLabel')" required :error="editForm.errors.name" />
                        <TextField v-model="editForm.description" :label="t('testSuiteShow.descriptionLabel')" type="textarea" :rows="3" />
                        <TextField
                            v-model="editForm.playwright_proxy"
                            :label="t('testSuiteShow.defaultHttpProxy')"
                            placeholder="http://proxy.example.com:8080"
                            :hint="t('testSuiteShow.defaultProxyHint')"
                            :error="editForm.errors.playwright_proxy"
                        />
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('testSuiteShow.perHostProxyRules') }}</label>
                                <Button type="button" variant="text" size="sm" @click="addProxyRule">{{ t('testSuiteShow.addRule') }}</Button>
                            </div>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-1">
                                {{ t('testSuiteShow.perHostProxyHint') }}
                            </p>
                            <ul class="md-body-small text-[var(--md-sys-color-on-surface-variant)] list-disc pl-5 mb-2 space-y-0.5">
                                <li><code class="font-mono">^example\.com$</code> — <strong>exact host only</strong>, e.g. matches <code class="font-mono">example.com</code> but not <code class="font-mono">foo.example.com</code>.</li>
                                <li><code class="font-mono">(^|\.)example\.com$</code> — <strong>host or any subdomain</strong>, e.g. matches <code class="font-mono">example.com</code> and <code class="font-mono">foo.example.com</code>, but not <code class="font-mono">notexample.com</code>.</li>
                                <li><code class="font-mono">example\.com$</code> — avoid: also matches unrelated hosts like <code class="font-mono">notexample.com</code>.</li>
                            </ul>
                            <div v-if="!editForm.proxy_rules.length" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] py-2">
                                {{ t('testSuiteShow.noRulesConfigured') }}
                            </div>
                            <div v-for="(rule, index) in editForm.proxy_rules" :key="index" class="flex items-start gap-2 mb-2">
                                <div class="flex-1">
                                    <input
                                        v-model="rule.domain"
                                        type="text"
                                        placeholder="^example\.com$"
                                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-2 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                                    />
                                    <p v-if="editForm.errors[`proxy_rules.${index}.domain`]" class="mt-1 md-body-small text-[var(--md-sys-color-error)]">{{ editForm.errors[`proxy_rules.${index}.domain`] }}</p>
                                </div>
                                <div class="flex-1">
                                    <input
                                        v-model="rule.proxy"
                                        type="text"
                                        placeholder="http://proxy.example.com:8080"
                                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-2 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                                    />
                                    <p v-if="editForm.errors[`proxy_rules.${index}.proxy`]" class="mt-1 md-body-small text-[var(--md-sys-color-error)]">{{ editForm.errors[`proxy_rules.${index}.proxy`] }}</p>
                                </div>
                                <button type="button" @click="removeProxyRule(index)" class="p-2 text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-error)] transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">{{ t('testSuiteShow.browser') }}</label>
                                <select v-model="editForm.browser" class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent">
                                    <option value="chromium">Chromium</option>
                                    <option value="firefox">Firefox</option>
                                    <option value="webkit">WebKit</option>
                                </select>
                                <p v-if="editForm.errors.browser" class="text-[var(--md-sys-color-error)] md-body-small mt-1.5">{{ editForm.errors.browser }}</p>
                            </div>
                            <div>
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">{{ t('testSuiteShow.mode') }}</label>
                                <select v-model="editForm.headless" class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent">
                                    <option :value="true">{{ t('testSuiteShow.headless') }}</option>
                                    <option :value="false">{{ t('testSuiteShow.headedVisible') }}</option>
                                </select>
                                <p v-if="editForm.errors.headless" class="text-[var(--md-sys-color-error)] md-body-small mt-1.5">{{ editForm.errors.headless }}</p>
                            </div>
                            <div>
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">{{ t('testSuiteShow.keepHistory') }}</label>
                                <select v-model="editForm.history_retention" class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent">
                                    <option :value="3">{{ t('testSuites.last3Runs') }}</option>
                                    <option :value="5">{{ t('testSuites.last5Runs') }}</option>
                                    <option :value="10">{{ t('testSuites.last10Runs') }}</option>
                                </select>
                                <p v-if="editForm.errors.history_retention" class="text-[var(--md-sys-color-error)] md-body-small mt-1.5">{{ editForm.errors.history_retention }}</p>
                            </div>
                        </div>
                        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.historyHint') }}</p>
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">{{ t('testSuiteShow.timeout') }}</label>
                                <select v-model="editForm.timeout_ms" class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent">
                                    <option :value="10000">{{ t('testSuiteShow.tenSeconds') }}</option>
                                    <option :value="30000">{{ t('testSuiteShow.thirtySeconds') }}</option>
                                    <option :value="60000">{{ t('testSuiteShow.sixtySeconds') }}</option>
                                    <option :value="120000">{{ t('testSuiteShow.twoMinutes') }}</option>
                                    <option :value="300000">{{ t('testSuiteShow.fiveMinutes') }}</option>
                                    <option :value="600000">{{ t('testSuiteShow.tenMinutes') }}</option>
                                </select>
                                <p v-if="editForm.errors.timeout_ms" class="text-[var(--md-sys-color-error)] md-body-small mt-1.5">{{ editForm.errors.timeout_ms }}</p>
                            </div>
                            <div>
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">{{ t('testSuiteShow.screenshots') }}</label>
                                <select v-model="editForm.take_screenshot" class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent">
                                    <option :value="true">{{ t('testSuiteShow.enabled') }}</option>
                                    <option :value="false">{{ t('testSuiteShow.screenshotsDisabled') }}</option>
                                </select>
                                <p v-if="editForm.errors.take_screenshot" class="text-[var(--md-sys-color-error)] md-body-small mt-1.5">{{ editForm.errors.take_screenshot }}</p>
                            </div>
                            <div>
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">{{ t('testSuiteShow.retries') }}</label>
                                <select v-model="editForm.max_retries" class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent">
                                    <option :value="0">{{ t('testSuites.noRetries') }}</option>
                                    <option :value="1">{{ t('testSuites.retryOnce') }}</option>
                                    <option :value="2">{{ t('testSuites.retryTwice') }}</option>
                                    <option :value="3">{{ t('testSuites.retry3Times') }}</option>
                                </select>
                                <p v-if="editForm.errors.max_retries" class="text-[var(--md-sys-color-error)] md-body-small mt-1.5">{{ editForm.errors.max_retries }}</p>
                            </div>
                        </div>
                        <div class="pt-2 border-t border-[var(--md-sys-color-outline-variant)]">
                            <TextField
                                v-model="editForm.teams_webhook_url"
                                :label="t('testSuiteShow.msTeamsWebhookUrl')"
                                :hint="t('testSuiteShow.msTeamsWebhookHint')"
                                :error="editForm.errors.teams_webhook_url"
                            />
                            <TextField
                                v-model="editForm.teams_webhook_proxy"
                                :label="t('testSuiteShow.msTeamsWebhookProxy')"
                                :hint="t('testSuiteShow.msTeamsWebhookProxyHint')"
                                placeholder="http://proxy.example.com:8080"
                                class="mt-4"
                                :error="editForm.errors.teams_webhook_proxy"
                            />
                            <div class="flex items-center gap-4 mt-2.5">
                                <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                    <input type="checkbox" v-model="editForm.teams_notify_on_success" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer" /> {{ t('testSuiteShow.notifyOnSuccess') }}
                                </label>
                                <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                    <input type="checkbox" v-model="editForm.teams_notify_on_failure" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer" /> {{ t('testSuiteShow.notifyOnFailure') }}
                                </label>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="text" @click="showEditModal = false">{{ t('testSuiteShow.cancel') }}</Button>
                            <Button type="submit" variant="filled" :disabled="editForm.processing">
                                {{ editForm.processing ? t('testSuiteShow.saving') : t('testSuiteShow.saveChanges') }}
                            </Button>
                        </div>
                    </form>
        </Modal>

        <!-- Schedule Modal -->
        <Modal :show="showScheduleModal" :title="t('testSuiteShow.scheduleModalTitle')" max-width="max-w-md" @close="showScheduleModal = false">
                    <form @submit.prevent="submitSchedule" class="px-6 py-5 space-y-4">
                        <TextField
                            v-model="scheduleForm.cron_expression"
                            :label="t('testSuiteShow.crontabExpression')"
                            placeholder="0 */6 * * *"
                            :hint="t('testSuiteShow.crontabHint')"
                            required
                            :error="scheduleForm.errors.cron_expression"
                        />
                        <TextField
                            v-model="scheduleForm.timezone"
                            :label="t('testSuiteShow.timezoneField')"
                            placeholder="UTC"
                            :hint="t('testSuiteShow.timezoneHint')"
                            :error="scheduleForm.errors.timezone"
                        />
                        <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                            <input type="checkbox" v-model="scheduleForm.is_enabled" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer" /> {{ t('testSuiteShow.enabled') }}
                        </label>
                        <div class="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="text" @click="showScheduleModal = false">{{ t('testSuiteShow.cancel') }}</Button>
                            <Button type="submit" variant="filled" :disabled="scheduleForm.processing">
                                {{ scheduleForm.processing ? t('testSuiteShow.saving') : t('testSuiteShow.saveSchedule') }}
                            </Button>
                        </div>
                    </form>
        </Modal>

        <!-- Manage Users Modal -->
        <Modal :show="showManageUsersModal" :title="t('testSuiteShow.manageUsersModalTitle')" max-width="max-w-2xl" @close="showManageUsersModal = false">
                    <div class="px-6 py-5 space-y-4">
                        <p v-if="newMemberForm.errors.member" class="text-[var(--md-sys-color-error)] md-body-small">{{ newMemberForm.errors.member }}</p>

                        <form v-if="candidates.length" @submit.prevent="submitAddMember" class="space-y-3 pb-4 border-b border-[var(--md-sys-color-outline-variant)]">
                            <Autocomplete
                                v-model="newMemberForm.user_id"
                                :options="candidates"
                                value-key="id"
                                :emit-on-input="false"
                                :label="t('testSuiteShow.addUser')"
                                :placeholder="t('testSuiteShow.searchNameOrEmail')"
                                :error="newMemberForm.errors.user_id"
                                class="w-full"
                            >
                                <template #trailing>
                                    <Button type="submit" variant="filled" size="sm" :disabled="newMemberForm.processing">{{ t('testSuiteShow.add') }}</Button>
                                </template>
                            </Autocomplete>
                            <div class="flex items-center gap-4">
                                <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                    <input type="checkbox" v-model="newMemberForm.can_view" class="w-4 h-4 accent-[var(--md-sys-color-primary)]" /> {{ t('testSuiteShow.view') }}
                                </label>
                                <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                    <input type="checkbox" v-model="newMemberForm.can_edit" :disabled="selectedCandidateIsViewOnly" class="w-4 h-4 accent-[var(--md-sys-color-primary)] disabled:opacity-40" /> {{ t('testSuiteShow.editPerm') }}
                                </label>
                                <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                    <input type="checkbox" v-model="newMemberForm.can_delete" :disabled="selectedCandidateIsViewOnly" class="w-4 h-4 accent-[var(--md-sys-color-primary)] disabled:opacity-40" /> {{ t('testSuiteShow.deletePerm') }}
                                </label>
                                <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                    <input type="checkbox" v-model="newMemberForm.can_run" :disabled="selectedCandidateIsViewOnly" class="w-4 h-4 accent-[var(--md-sys-color-primary)] disabled:opacity-40" /> {{ t('testSuiteShow.runPerm') }}
                                </label>
                            </div>
                        </form>
                        <p v-else class="md-body-small text-[var(--md-sys-color-on-surface-variant)] pb-4 border-b border-[var(--md-sys-color-outline-variant)]">{{ t('testSuiteShow.allUsersHaveAccess') }}</p>

                        <table class="w-full">
                            <thead>
                                <tr class="text-left border-b border-[var(--md-sys-color-outline-variant)]">
                                    <th class="pb-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testSuiteShow.colUser') }}</th>
                                    <th class="pb-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider text-center">{{ t('testSuiteShow.colView') }}</th>
                                    <th class="pb-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider text-center">{{ t('testSuiteShow.colEdit') }}</th>
                                    <th class="pb-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider text-center">{{ t('testSuiteShow.colDelete') }}</th>
                                    <th class="pb-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider text-center">{{ t('testSuiteShow.colRun') }}</th>
                                    <th class="pb-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                                <tr v-for="member in members" :key="member.id">
                                    <td class="py-2 md-body-medium text-[var(--md-sys-color-on-surface)]">
                                        {{ member.name }}
                                        <span v-if="member.is_view_only" class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.viewOnly') }}</span>
                                        <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ member.email }}</p>
                                    </td>
                                    <td class="py-2 text-center">
                                        <input type="checkbox" v-model="member.can_view" @change="updateMemberPrivilege(member, 'can_view')" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer" />
                                    </td>
                                    <td class="py-2 text-center">
                                        <input type="checkbox" v-model="member.can_edit" :disabled="member.is_view_only" :title="member.is_view_only ? t('testSuiteShow.viewOnlyEditTitle') : null" @change="updateMemberPrivilege(member, 'can_edit')" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer disabled:cursor-not-allowed disabled:opacity-40" />
                                    </td>
                                    <td class="py-2 text-center">
                                        <input type="checkbox" v-model="member.can_delete" :disabled="member.is_view_only" :title="member.is_view_only ? t('testSuiteShow.viewOnlyDeleteTitle') : null" @change="updateMemberPrivilege(member, 'can_delete')" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer disabled:cursor-not-allowed disabled:opacity-40" />
                                    </td>
                                    <td class="py-2 text-center">
                                        <input type="checkbox" v-model="member.can_run" :disabled="member.is_view_only" :title="member.is_view_only ? t('testSuiteShow.viewOnlyRunTitle') : null" @change="updateMemberPrivilege(member, 'can_run')" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer disabled:cursor-not-allowed disabled:opacity-40" />
                                    </td>
                                    <td class="py-2 text-right">
                                        <button @click="removeMember(member)" class="md-label-small text-[var(--md-sys-color-error)] hover:underline">{{ t('testSuiteShow.removeMember') }}</button>
                                    </td>
                                </tr>
                                <tr v-if="!members.length">
                                    <td colspan="6" class="py-4 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.noUsersAdded') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <template #footer>
                        <Button variant="text" @click="showManageUsersModal = false">{{ t('testSuiteShow.close') }}</Button>
                    </template>
        </Modal>

        <!-- New Test Modal -->
        <Modal :show="showTestModal" :title="t('testSuiteShow.newTestModalTitle')" max-width="max-w-md" @close="showTestModal = false">
                    <form @submit.prevent="submitTest" class="px-6 py-5 space-y-4">
                        <TextField v-model="testForm.name" :label="t('testSuiteShow.testNameLabel')" :placeholder="t('testSuiteShow.testNamePlaceholder')" required :error="testForm.errors.name" />
                        <TextField
                            v-model="testForm.description"
                            :label="t('testSuiteShow.descriptionLabel')"
                            type="textarea"
                            :rows="3"
                            :placeholder="t('testSuiteShow.testDescriptionPlaceholder')"
                            :error="testForm.errors.description"
                        />
                        <Autocomplete
                            v-model="testForm.uploaded_by"
                            :options="users"
                            :label="t('testSuiteShow.uploadedBy')"
                            :error="testForm.errors.uploaded_by"
                        />
                        <div class="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="text" @click="showTestModal = false">{{ t('testSuiteShow.cancel') }}</Button>
                            <Button type="submit" variant="filled" :disabled="testForm.processing">
                                {{ testForm.processing ? t('testSuiteShow.creating') : t('testSuiteShow.createTest') }}
                            </Button>
                        </div>
                    </form>
        </Modal>

        <!-- Recent-run screenshot lightbox -->
        <ScreenshotLightbox
            :shots="lightbox.shots.value"
            :index="lightbox.index.value"
            @close="lightbox.close"
            @update:index="lightbox.setIndex"
        />
    </AppLayout>
</template>

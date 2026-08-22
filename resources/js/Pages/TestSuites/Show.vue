<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import CopyableSecret from '@/Components/CopyableSecret.vue';
import CopyButton from '@/Components/CopyButton.vue';
import { Card, Chip, Button, IconButton, TextField, Autocomplete, Modal, SuiteName, Avatar, AvatarGroup, SettingBadge, RunPill, ScreenshotThumbs, ScreenshotLightbox, MarkdownRenderer } from '@/Components/ui';
import { formatDate, formatRelativeTime } from '@/utils/date';
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
    isBookmarked: { type: Boolean, default: false },
});

function debounce(fn, delay) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
}

const testSearch = ref(props.filters.search ?? '');
const testPerPage = ref(props.filters.per_page ?? 50);
const testSort = ref(props.filters.sort || 'run_date');
const testSortDir = ref(props.filters.sort_dir === 'asc' ? 'asc' : 'desc');
const testStatus = ref([...(props.filters.status ?? [])]);

const STATUS_OPTIONS = ['passed', 'failed', 'error', 'timeout', 'running', 'pending', 'cancelled'];

const STATUS_BADGE_CLASSES = {
    passed: 'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]',
    failed: 'bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)]',
    error: 'bg-[var(--md-ext-color-warning-container)] text-[var(--md-ext-color-on-warning-container)]',
    timeout: 'bg-[var(--md-ext-color-warning-container)] text-[var(--md-ext-color-on-warning-container)]',
    running: 'bg-[var(--md-sys-color-primary-container)] text-[var(--md-sys-color-on-primary-container)]',
    pending: 'bg-[var(--md-sys-color-primary-container)] text-[var(--md-sys-color-on-primary-container)]',
    cancelled: 'bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)]',
    skipped: 'bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)]',
};

// Left status accent bar for each test row — mirrors the recent-runs card pattern
// so a quick vertical glance tells you which tests are healthy, failing, or running.
const STATUS_ACCENT_CLASSES = {
    passed: 'bg-[var(--md-ext-color-success)]',
    failed: 'bg-[var(--md-sys-color-error)]',
    error: 'bg-[var(--md-ext-color-warning)]',
    timeout: 'bg-[var(--md-ext-color-warning)]',
    running: 'bg-[var(--md-sys-color-primary)]',
    pending: 'bg-[var(--md-sys-color-primary)]',
    cancelled: 'bg-[var(--md-sys-color-outline-variant)]',
    skipped: 'bg-[var(--md-sys-color-outline-variant)]',
    disabled: 'bg-[var(--md-sys-color-outline-variant)]',
};

function statusAccentClass(status) {
    return STATUS_ACCENT_CLASSES[status] ?? 'bg-[var(--md-sys-color-outline-variant)]';
}

const hasStatusCounts = computed(() => {
    const counts = props.stats?.status_counts;
    return counts && Object.values(counts).some(c => c > 0);
});

function reloadTests(overrides = {}) {
    router.get(
        `/sorify/suites/${props.suite.id}`,
        { search: testSearch.value, per_page: testPerPage.value, sort: testSort.value, sort_dir: testSortDir.value, status: testStatus.value, ...overrides },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const debouncedTestSearch = debounce(() => reloadTests({ page: 1 }), 350);

watch(testSearch, () => debouncedTestSearch());
watch(testPerPage, () => reloadTests({ page: 1 }));
watch(testSort, () => reloadTests({ page: 1 }));
watch(testSortDir, () => reloadTests({ page: 1 }));
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

// Clicking a status chip in the stats card filters the table to that status.
// If already the sole active filter, clears it instead (toggle behaviour).
function filterByStatus(status) {
    if (testStatus.value.length === 1 && testStatus.value[0] === status) {
        testStatus.value = [];
    } else {
        testStatus.value = [status];
    }
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

function toggleBookmark() {
    const options = { preserveState: true, preserveScroll: true, only: ['isBookmarked'] };
    const url = `/sorify/suites/${props.suite.id}/bookmark`;

    if (props.isBookmarked) {
        router.delete(url, options);
    } else {
        router.post(url, {}, options);
    }
}

function regenerateWebhook() {
    if (!confirm(t('testSuiteShow.confirmRegenerateWebhook'))) return;
    router.post(`/sorify/suites/${props.suite.id}/webhook/regenerate`);
}

// Schedule — auto-saved on change (cron expression, timezone, enabled toggle),
// mirroring the inline save pattern used by the other suite settings.
const scheduleForm = useForm({
    cron_expression: props.suite.schedule?.cron_expression ?? '',
    timezone: props.suite.schedule?.timezone ?? 'UTC',
    is_enabled: props.suite.schedule?.is_enabled ?? true,
});
const scheduleError = ref(null);

watch(() => props.suite.schedule, (s) => {
    scheduleForm.cron_expression = s?.cron_expression ?? '';
    scheduleForm.timezone = s?.timezone ?? 'UTC';
    scheduleForm.is_enabled = s?.is_enabled ?? true;
});

function saveSchedule() {
    // Don't auto-delete when the user clears the cron field — use the Remove
    // button for that. Skip the save entirely if cron is empty.
    if (!scheduleForm.cron_expression.trim()) {
        scheduleError.value = null;
        return;
    }

    savingSuiteSetting.value = true;
    scheduleError.value = null;

    const oldCron = props.suite.schedule?.cron_expression ?? '';
    const oldTz = props.suite.schedule?.timezone ?? 'UTC';
    const oldEnabled = props.suite.schedule?.is_enabled ?? true;

    router.put(
        `/sorify/suites/${props.suite.id}/schedule`,
        {
            cron_expression: scheduleForm.cron_expression,
            timezone: scheduleForm.timezone,
            is_enabled: scheduleForm.is_enabled,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                savedSuiteField.value = 'schedule';
                clearTimeout(savedSuiteTimer);
                savedSuiteTimer = setTimeout(() => { savedSuiteField.value = null; }, 1500);
            },
            onError: (errors) => {
                scheduleForm.cron_expression = oldCron;
                scheduleForm.timezone = oldTz;
                scheduleForm.is_enabled = oldEnabled;
                scheduleError.value = errors.cron_expression || errors.timezone || null;
            },
            onFinish: () => { savingSuiteSetting.value = false; },
        },
    );
}

function removeSchedule() {
    if (!confirm(t('testSuiteShow.confirmRemoveSchedule'))) return;
    router.delete(`/sorify/suites/${props.suite.id}/schedule`);
}

// Edit suite modal (name + description only)
const showEditModal = ref(false);
const editForm = useForm({
    name: props.suite.name ?? '',
    description: props.suite.description ?? '',
});

function openEditModal() {
    editForm.name = props.suite.name ?? '';
    editForm.description = props.suite.description ?? '';
    editForm.clearErrors();
    showEditModal.value = true;
}

function submitEdit() {
    editForm.put(`/sorify/suites/${props.suite.id}`, {
        onSuccess: () => { showEditModal.value = false; },
    });
}

// Inline suite settings (Proxy, MS Teams) — collapsible, auto-saved on change.
const showSuiteSettings = ref(false);
const showProxyRulesInfo = ref(false);
const localSuiteSettings = reactive({
    playwright_proxy: props.suite.playwright_proxy ?? '',
    proxy_rules: (props.suite.proxy_rules ?? []).map(r => ({ domain: r.domain, proxy: r.proxy })),
    variables: (props.suite.variables ?? []).map(v => ({ key: v.key, value: v.value ?? '' })),
    teams_webhook_url: props.suite.teams_webhook_url ?? '',
    teams_webhook_proxy: props.suite.teams_webhook_proxy ?? '',
    teams_notify_on_success: props.suite.teams_notify_on_success ?? false,
    teams_notify_on_failure: props.suite.teams_notify_on_failure ?? false,
});

watch(() => ({
    playwright_proxy: props.suite.playwright_proxy,
    proxy_rules: props.suite.proxy_rules,
    variables: props.suite.variables,
    teams_webhook_url: props.suite.teams_webhook_url,
    teams_webhook_proxy: props.suite.teams_webhook_proxy,
    teams_notify_on_success: props.suite.teams_notify_on_success,
    teams_notify_on_failure: props.suite.teams_notify_on_failure,
}), (s) => {
    localSuiteSettings.playwright_proxy = s.playwright_proxy ?? '';
    localSuiteSettings.proxy_rules = (s.proxy_rules ?? []).map(r => ({ domain: r.domain, proxy: r.proxy }));
    localSuiteSettings.variables = (s.variables ?? []).map(v => ({ key: v.key, value: v.value ?? '' }));
    localSuiteSettings.teams_webhook_url = s.teams_webhook_url ?? '';
    localSuiteSettings.teams_webhook_proxy = s.teams_webhook_proxy ?? '';
    localSuiteSettings.teams_notify_on_success = s.teams_notify_on_success ?? false;
    localSuiteSettings.teams_notify_on_failure = s.teams_notify_on_failure ?? false;
});

const savingSuiteSetting = ref(false);
const savedSuiteField = ref(null);
let savedSuiteTimer = null;

function saveSuiteField(field) {
    savingSuiteSetting.value = true;
    const oldValue = props.suite[field];
    router.put(
        `/sorify/suites/${props.suite.id}`,
        { [field]: localSuiteSettings[field] },
        {
            preserveState: true,
            preserveScroll: true,
            onError: () => { localSuiteSettings[field] = oldValue; },
            onFinish: () => {
                savingSuiteSetting.value = false;
                savedSuiteField.value = field;
                clearTimeout(savedSuiteTimer);
                savedSuiteTimer = setTimeout(() => { savedSuiteField.value = null; }, 1500);
            },
        },
    );
}

function saveProxyRules() {
    const rules = localSuiteSettings.proxy_rules
        .filter(r => r.domain.trim() && r.proxy.trim())
        .map(r => ({ domain: r.domain.trim(), proxy: r.proxy.trim() }));
    savingSuiteSetting.value = true;
    const oldRules = (props.suite.proxy_rules ?? []).map(r => ({ domain: r.domain, proxy: r.proxy }));
    router.put(
        `/sorify/suites/${props.suite.id}`,
        { proxy_rules: rules },
        {
            preserveState: true,
            preserveScroll: true,
            onError: () => { localSuiteSettings.proxy_rules = oldRules; },
            onFinish: () => {
                savingSuiteSetting.value = false;
                savedSuiteField.value = 'proxy_rules';
                clearTimeout(savedSuiteTimer);
                savedSuiteTimer = setTimeout(() => { savedSuiteField.value = null; }, 1500);
            },
        },
    );
}

function addProxyRule() {
    localSuiteSettings.proxy_rules.push({ domain: '', proxy: '' });
}

function removeProxyRule(index) {
    localSuiteSettings.proxy_rules.splice(index, 1);
    saveProxyRules();
}

function saveVariables() {
    const vars = localSuiteSettings.variables
        .filter(v => v.key.trim())
        .map(v => ({ key: v.key.trim(), value: v.value }));
    savingSuiteSetting.value = true;
    const oldVars = (props.suite.variables ?? []).map(v => ({ key: v.key, value: v.value ?? '' }));
    router.put(
        `/sorify/suites/${props.suite.id}`,
        { variables: vars },
        {
            preserveState: true,
            preserveScroll: true,
            onError: () => { localSuiteSettings.variables = oldVars; },
            onFinish: () => {
                savingSuiteSetting.value = false;
                savedSuiteField.value = 'variables';
                clearTimeout(savedSuiteTimer);
                savedSuiteTimer = setTimeout(() => { savedSuiteField.value = null; }, 1500);
            },
        },
    );
}

function addVariable() {
    localSuiteSettings.variables.push({ key: '', value: '' });
}

function removeVariable(index) {
    localSuiteSettings.variables.splice(index, 1);
    saveVariables();
}

// Inline run settings (Browser, Mode, Timeout, Screenshots, Retries, Keep History)
// Edited directly from the tests table; auto-saved on change.
const localSettings = reactive({
    browser: props.suite.browser ?? 'chromium',
    headless: props.suite.headless ?? true,
    timeout_ms: props.suite.timeout_ms ?? 30000,
    take_screenshot: props.suite.take_screenshot ?? true,
    max_retries: props.suite.max_retries ?? 0,
    history_retention: props.suite.history_retention ?? 5,
});

watch(() => ({
    browser: props.suite.browser,
    headless: props.suite.headless,
    timeout_ms: props.suite.timeout_ms,
    take_screenshot: props.suite.take_screenshot,
    max_retries: props.suite.max_retries,
    history_retention: props.suite.history_retention,
}), (s) => {
    localSettings.browser = s.browser ?? 'chromium';
    localSettings.headless = s.headless ?? true;
    localSettings.timeout_ms = s.timeout_ms ?? 30000;
    localSettings.take_screenshot = s.take_screenshot ?? true;
    localSettings.max_retries = s.max_retries ?? 0;
    localSettings.history_retention = s.history_retention ?? 5;
});

const savingSetting = ref(false);
const savedField = ref(null);
let savedTimer = null;

// Collapsible quick-settings panel (collapsed by default)
const showRunSettings = ref(false);

function updateSuiteSetting(field) {
    savingSetting.value = true;
    const oldValue = props.suite[field];
    router.put(
        `/sorify/suites/${props.suite.id}`,
        { [field]: localSettings[field] },
        {
            preserveState: true,
            preserveScroll: true,
            onError: () => { localSettings[field] = oldValue; },
            onFinish: () => {
                savingSetting.value = false;
                savedField.value = field;
                clearTimeout(savedTimer);
                savedTimer = setTimeout(() => { savedField.value = null; }, 1500);
            },
        },
    );
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

// ── Duplicate suite ────────────────────────────────────────────────────────
const showDuplicateSuiteModal = ref(false);
const duplicateSuiteForm = useForm({
    name: '',
});
const suiteJustDuplicated = ref(false);

function openDuplicateSuiteModal() {
    duplicateSuiteForm.name = '';
    duplicateSuiteForm.clearErrors();
    showDuplicateSuiteModal.value = true;
}

function submitDuplicateSuite() {
    suiteJustDuplicated.value = true;
    duplicateSuiteForm.post(`/sorify/suites/${props.suite.id}/duplicate`, {
        onSuccess: () => { showDuplicateSuiteModal.value = false; },
        onFinish: () => { suiteJustDuplicated.value = false; },
    });
}

// ── Bulk duplicate tests ───────────────────────────────────────────────────
const bulkDuplicating = ref(false);

function bulkDuplicate() {
    if (!hasSelection.value) return;
    bulkDuplicating.value = true;
    router.post(
        `/sorify/suites/${props.suite.id}/tests/bulk/duplicate`,
        { test_ids: [...selectedIds.value] },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => { selectedIds.value = new Set(); },
            onFinish: () => {
                bulkDuplicating.value = false;
                router.reload({ only: ['tests', 'stats'] });
            },
        },
    );
}

// Delete suite
function deleteSuite() {
    if (!confirm(t('testSuiteShow.confirmDeleteSuite', { name: props.suite.name }))) return;
    router.delete(`/sorify/suites/${props.suite.id}`);
}

// Auto-refresh while any test has a run in progress, or while the suite
// itself is being duplicated (tests stream in from a background job).
let refreshTimer = null;

const ACTIVE_RUN_STATUSES = ['running', 'pending'];

const hasActiveTest = computed(() =>
    props.tests.data.some(t => ACTIVE_RUN_STATUSES.includes(t.current_status))
    || props.recentRuns.some(r => ACTIVE_RUN_STATUSES.includes(r.status)),
);

const suiteBeingDuplicated = computed(
    () => props.suite?.duplication_status === 'pending',
);

const suiteDuplicationFailed = computed(
    () => props.suite?.duplication_status === 'failed',
);

function stopRefresh() {
    if (refreshTimer) {
        clearInterval(refreshTimer);
        refreshTimer = null;
    }
}

function startRefresh() {
    stopRefresh();
    if (hasActiveTest.value || suiteBeingDuplicated.value) {
        refreshTimer = setInterval(() => {
            router.reload({ only: ['suite', 'tests', 'stats', 'recentRuns'] });
        }, 2000);
    }
}

onMounted(() => startRefresh());

onUnmounted(() => stopRefresh());

watch([hasActiveTest, suiteBeingDuplicated], () => startRefresh());

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
    return { name: user?.name ?? email, email, avatar_url: user?.avatar_url ?? null };
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
                <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)]">
                    <SuiteName :name="suite.name" />
                </h1>
                <div v-if="suite.description" class="mt-1 opacity-80">
                    <MarkdownRenderer :content="suite.description" density="compact" collapsible :collapsed-lines="10" />
                </div>
                <div v-if="suite.created_by" class="flex items-center gap-2 mt-1.5">
                    <Avatar :name="suite.created_by.name" :email="suite.created_by.email" :avatar-url="suite.created_by.avatar_url" />
                    <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                        {{ t('testSuiteShow.createdBy', { name: suite.created_by.name }) }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0 ml-4">
                <IconButton
                    variant="standard"
                    :label="isBookmarked ? t('testSuiteShow.bookmarkRemove') : t('testSuiteShow.bookmarkAdd')"
                    @click="toggleBookmark"
                >
                    <svg
                        class="w-5 h-5"
                        :class="isBookmarked ? 'text-[var(--md-sys-color-primary)]' : 'text-[var(--md-sys-color-on-surface-variant)]'"
                        :fill="isBookmarked ? 'currentColor' : 'none'"
                        stroke="currentColor"
                        viewBox="0 0 20 20"
                        stroke-width="1.5"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.958a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.446a1 1 0 00-.363 1.118l1.287 3.957c.3.922-.755 1.688-1.538 1.118l-3.367-2.446a1 1 0 00-1.176 0l-3.367 2.446c-.783.57-1.838-.196-1.539-1.118l1.287-3.957a1 1 0 00-.363-1.118L2.062 9.385c-.783-.57-.38-1.81.588-1.81h4.163a1 1 0 00.95-.69l1.286-3.958z"/>
                    </svg>
                </IconButton>
                <Button v-if="can.edit" variant="tonal" @click="openEditModal">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    {{ t('testSuiteShow.edit') }}
                </Button>
                <Button
                    v-if="can.edit"
                    variant="tonal"
                    :disabled="suiteJustDuplicated"
                    @click="openDuplicateSuiteModal"
                    :title="t('testSuiteShow.duplicateSuite')"
                >
                    <svg v-if="suiteJustDuplicated" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/>
                    </svg>
                    {{ suiteJustDuplicated ? t('testSuiteShow.duplicating') : t('testSuiteShow.duplicateSuiteShort') }}
                </Button>
                <Button v-if="can.delete" variant="tonal" @click="deleteSuite" class="!text-[var(--md-sys-color-error)]">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    {{ t('testSuiteShow.delete') }}
                </Button>
            </div>
        </div>

        <!-- Test actions row -->
        <div class="flex items-center gap-2 mb-6">
            <Button
                variant="tonal"
                size="sm"
                :href="`/sorify/suites/${suite.id}/review`"
                :disabled="hasSelection"
                :title="t('testSuiteShow.reviewAllTestsTitle')"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6M9 11h3"/>
                </svg>
                {{ t('testSuiteShow.reviewAllTests') }}
            </Button>
            <Button v-if="can.run" variant="tonal" size="sm" @click="runAll" :disabled="running || hasSelection">
                <svg v-if="running" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                {{ running ? t('testSuiteShow.starting') : t('testSuiteShow.runAllTests') }}
            </Button>
            <Button v-if="can.edit" variant="filled" size="sm" @click="openTestModal" :disabled="hasSelection">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                {{ t('testSuiteShow.newTest') }}
            </Button>
        </div>

        <!-- Suite duplication banner (pending / failed) -->
        <div
            v-if="suiteBeingDuplicated"
            class="mb-6 flex items-start gap-3 bg-[var(--md-sys-color-primary-container)] rounded-[var(--md-sys-shape-corner-medium)] px-5 py-4"
        >
            <svg class="w-4 h-4 text-[var(--md-sys-color-on-primary-container)] mt-0.5 flex-shrink-0 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            <div class="flex-1 min-w-0">
                <p class="md-body-medium font-medium text-[var(--md-sys-color-on-primary-container)]">{{ t('testSuiteShow.duplicationInProgressTitle') }}</p>
                <p class="md-body-small text-[var(--md-sys-color-on-primary-container)] mt-0.5">{{ t('testSuiteShow.duplicationInProgressBody') }}</p>
            </div>
        </div>
        <div
            v-else-if="suiteDuplicationFailed"
            class="mb-6 flex items-start gap-3 bg-[var(--md-sys-color-error-container)] rounded-[var(--md-sys-shape-corner-medium)] px-5 py-4"
        >
            <svg class="w-4 h-4 text-[var(--md-sys-color-on-error-container)] mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="flex-1 min-w-0">
                <p class="md-body-medium font-medium text-[var(--md-sys-color-on-error-container)]">{{ t('testSuiteShow.duplicationFailed') }}</p>
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
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)] mb-1.5">{{ t('testSuiteShow.statStatusBreakdown') }}</p>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="status in STATUS_OPTIONS"
                        :key="status"
                        v-show="(stats.status_counts?.[status] ?? 0) > 0"
                        type="button"
                        @click="filterByStatus(status)"
                        :class="[
                            'inline-flex items-center gap-1 px-1.5 py-0.5 rounded-[var(--md-sys-shape-corner-full)] md-label-small font-medium transition-opacity hover:opacity-80 cursor-pointer',
                            STATUS_BADGE_CLASSES[status],
                            testStatus.includes(status) ? 'ring-2 ring-[var(--md-sys-color-primary)] ring-offset-1 ring-offset-[var(--md-sys-color-surface)]' : '',
                        ]"
                    >
                        {{ stats.status_counts?.[status] ?? 0 }} {{ t(`testSuiteShow.status_${status}`) }}
                    </button>
                    <span v-if="!hasStatusCounts" class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">—</span>
                </div>
            </Card>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Tests list -->
            <div class="lg:col-span-2">
                <Card padding="p-0">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                        <div class="flex items-center gap-2 flex-wrap min-w-0">
                            <input
                                v-if="tests.data.length && (can.delete || can.edit || can.run)"
                                type="checkbox"
                                :checked="allSelected"
                                :indeterminate="someSelected"
                                @change="toggleSelectAll"
                                class="w-4 h-4 flex-shrink-0 rounded-[var(--md-sys-shape-corner-extra-small)] border-[var(--md-sys-color-outline)] accent-[var(--md-sys-color-primary)] cursor-pointer"
                            />
                            <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] flex-shrink-0">{{ t('testSuiteShow.testsHeading') }}</h2>
                            <div v-if="hasSelection" class="flex items-center gap-1.5 flex-wrap">
                                <button v-if="can.edit" @click="bulkSetStatus('active')" :disabled="bulkStatusProcessing"
                                    class="md-label-small px-2.5 py-1 rounded-[var(--md-sys-shape-corner-full)] text-[var(--md-ext-color-on-success-container)] bg-[var(--md-ext-color-success-container)] transition-opacity hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed">
                                    {{ t('testSuiteShow.activate', { count: selectedIds.size }) }}
                                </button>
                                <button v-if="can.edit" @click="bulkSetStatus('disabled')" :disabled="bulkStatusProcessing"
                                    class="md-label-small px-2.5 py-1 rounded-[var(--md-sys-shape-corner-full)] text-[var(--md-ext-color-on-warning-container)] bg-[var(--md-ext-color-warning-container)] transition-opacity hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed">
                                    {{ t('testSuiteShow.deactivate', { count: selectedIds.size }) }}
                                </button>
                                <button v-if="can.edit" @click="bulkDuplicate" :disabled="bulkDuplicating"
                                    class="flex items-center gap-1 md-label-small px-2.5 py-1 rounded-[var(--md-sys-shape-corner-full)] text-[var(--md-sys-color-on-secondary-container)] bg-[var(--md-sys-color-secondary-container)] transition-opacity hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed">
                                    <svg v-if="bulkDuplicating" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                    {{ bulkDuplicating ? t('testSuiteShow.duplicating') : t('testSuiteShow.duplicateCount', { count: selectedIds.size }) }}
                                </button>
                                <button v-if="can.delete" @click="bulkDelete"
                                    class="flex items-center gap-1 md-label-small px-2.5 py-1 rounded-[var(--md-sys-shape-corner-full)] text-[var(--md-sys-color-on-error-container)] bg-[var(--md-sys-color-error-container)] transition-opacity hover:opacity-90">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                    {{ t('testSuiteShow.deleteCount', { count: selectedIds.size }) }}
                                </button>
                                <button v-if="can.run" @click="bulkRun" :disabled="bulkRunning"
                                    class="flex items-center gap-1 md-label-small px-2.5 py-1 rounded-[var(--md-sys-shape-corner-full)] text-[var(--md-sys-color-on-primary)] bg-[var(--md-sys-color-primary)] transition-opacity hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed">
                                    <svg v-if="bulkRunning" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                    {{ bulkRunning ? t('testSuiteShow.starting') : t('testSuiteShow.run', { count: selectedIds.size }) }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Suite settings (collapsible, edited inline, auto-saved on change) -->
                    <div class="border-b border-[var(--md-sys-color-outline-variant)] bg-[var(--md-sys-color-surface-container-lowest)]">
                        <button
                            type="button"
                            @click="showSuiteSettings = !showSuiteSettings"
                            class="w-full flex items-center gap-2 px-5 py-2.5 text-left hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors"
                        >
                            <svg
                                class="w-4 h-4 text-[var(--md-sys-color-on-surface-variant)] transition-transform"
                                :class="{ 'rotate-90': showSuiteSettings }"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            <span class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.suiteSettings') }}</span>

                            <!-- Compact summary when collapsed -->
                            <span v-if="!showSuiteSettings" class="flex items-center gap-1.5 ml-1 flex-wrap">
                                <SettingBadge :label="t('testSuites.badgeTeams')" :active="!!suite.teams_webhook_url" />
                                <SettingBadge :label="t('testSuites.badgeScreenshots')" :active="!!suite.take_screenshot" />
                                <SettingBadge :label="t('testSuites.badgeProxy')" :active="!!(suite.proxy_rules?.length || suite.playwright_proxy)" />
                                <SettingBadge :label="t('testSuites.badgeVariables')" :active="!!suite.variables?.length" />
                                <SettingBadge :label="t('testSuites.badgeSchedule')" :active="!!(suite.schedule && suite.schedule.is_enabled)" />
                            </span>

                            <span v-if="savingSuiteSetting" class="ml-auto flex items-center gap-1 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                {{ t('testSuiteShow.saving') }}
                            </span>
                            <span v-else-if="savedSuiteField" class="ml-auto flex items-center gap-1 md-label-small text-[var(--md-ext-color-success)]">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                </svg>
                                {{ t('testSuiteShow.saved') }}
                            </span>
                        </button>

                        <div v-if="showSuiteSettings" class="px-5 pb-4 pt-2 space-y-5">
                            <!-- MS Teams -->
                            <div>
                                <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)] mb-3">{{ t('testSuiteShow.msTeamsSection') }}</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3">
                                    <div>
                                        <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`suite-teams-webhook-${suite.id}`">{{ t('testSuiteShow.msTeamsWebhookUrl') }}</label>
                                        <input
                                            :id="`suite-teams-webhook-${suite.id}`"
                                            v-model="localSuiteSettings.teams_webhook_url"
                                            @change="saveSuiteField('teams_webhook_url')"
                                            :disabled="!can.edit || savingSuiteSetting"
                                            type="text"
                                            class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-1.5 md-body-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                        />
                                    </div>
                                    <div>
                                        <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`suite-teams-proxy-${suite.id}`">{{ t('testSuiteShow.msTeamsWebhookProxy') }}</label>
                                        <input
                                            :id="`suite-teams-proxy-${suite.id}`"
                                            v-model="localSuiteSettings.teams_webhook_proxy"
                                            @change="saveSuiteField('teams_webhook_proxy')"
                                            :disabled="!can.edit || savingSuiteSetting"
                                            type="text"
                                            placeholder="http://proxy.example.com:8080"
                                            class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-1.5 md-body-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                        />
                                    </div>
                                </div>
                                <div class="flex items-center gap-4 mt-3">
                                    <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                        <input type="checkbox" v-model="localSuiteSettings.teams_notify_on_success" @change="saveSuiteField('teams_notify_on_success')" :disabled="!can.edit || savingSuiteSetting" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer disabled:opacity-60" /> {{ t('testSuiteShow.notifyOnSuccess') }}
                                    </label>
                                    <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                        <input type="checkbox" v-model="localSuiteSettings.teams_notify_on_failure" @change="saveSuiteField('teams_notify_on_failure')" :disabled="!can.edit || savingSuiteSetting" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer disabled:opacity-60" /> {{ t('testSuiteShow.notifyOnFailure') }}
                                    </label>
                                </div>
                            </div>

                            <!-- Proxy -->
                            <div class="pt-2 border-t border-[var(--md-sys-color-outline-variant)]">
                                <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)] mb-3 mt-3">{{ t('testSuiteShow.proxySection') }}</p>
                                <div class="mb-3">
                                    <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`suite-default-proxy-${suite.id}`">{{ t('testSuiteShow.defaultHttpProxy') }}</label>
                                    <input
                                        :id="`suite-default-proxy-${suite.id}`"
                                        v-model="localSuiteSettings.playwright_proxy"
                                        @change="saveSuiteField('playwright_proxy')"
                                        :disabled="!can.edit || savingSuiteSetting"
                                        type="text"
                                        placeholder="http://proxy.example.com:8080"
                                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-1.5 md-body-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                    />
                                    <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)] mt-1 opacity-70">{{ t('testSuiteShow.defaultProxyHint') }}</p>
                                </div>
                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <div class="flex items-center gap-1">
                                            <label class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.perHostProxyRules') }}</label>
                                            <div class="group relative">
                                                <button
                                                    type="button"
                                                    @click="showProxyRulesInfo = !showProxyRulesInfo"
                                                    :aria-label="t('testSuiteShow.proxyRulesInfoTooltip')"
                                                    :aria-expanded="showProxyRulesInfo"
                                                    class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-primary)] transition-colors"
                                                >
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                    </svg>
                                                </button>
                                                <div
                                                    class="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-1.5 z-20 hidden group-hover:flex flex-col items-center whitespace-nowrap"
                                                >
                                                    <div class="px-2.5 py-1.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-inverse-surface)] text-[var(--md-sys-color-inverse-on-surface)] md-label-small shadow-elevation-1">
                                                        {{ t('testSuiteShow.proxyRulesInfoTooltip') }}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <button v-if="can.edit" type="button" @click="addProxyRule" :disabled="savingSuiteSetting" class="md-label-small text-[var(--md-sys-color-primary)] hover:underline disabled:opacity-60">{{ t('testSuiteShow.addRule') }}</button>
                                    </div>
                                    <div v-if="showProxyRulesInfo" class="mb-2 p-3 bg-[var(--md-sys-color-surface-container-high)] border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)]">
                                        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-2">{{ t('testSuiteShow.proxyRulesInfoIntro') }}</p>
                                        <table class="w-full md-body-small text-[var(--md-sys-color-on-surface)] border-collapse">
                                            <thead>
                                                <tr class="text-left text-[var(--md-sys-color-on-surface-variant)]">
                                                    <th class="font-mono font-semibold pr-2 pb-1 align-top w-1/2">{{ t('testSuiteShow.proxyRulesPatternHeader') }}</th>
                                                    <th class="font-medium pr-2 pb-1 align-top">{{ t('testSuiteShow.proxyRulesBehaviorHeader') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr class="border-t border-[var(--md-sys-color-outline-variant)]">
                                                    <td class="font-mono pr-2 py-1.5 align-top"><code class="bg-[var(--md-sys-color-surface-container-lowest)] px-1.5 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">^example\.com$</code></td>
                                                    <td class="pr-2 py-1.5 align-top">{{ t('testSuiteShow.proxyRulesExactHost') }}</td>
                                                </tr>
                                                <tr class="border-t border-[var(--md-sys-color-outline-variant)]">
                                                    <td class="font-mono pr-2 py-1.5 align-top"><code class="bg-[var(--md-sys-color-surface-container-lowest)] px-1.5 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">(^|\.)example\.com$</code></td>
                                                    <td class="pr-2 py-1.5 align-top">{{ t('testSuiteShow.proxyRulesHostOrSubdomain') }}</td>
                                                </tr>
                                                <tr class="border-t border-[var(--md-sys-color-outline-variant)]">
                                                    <td class="font-mono pr-2 py-1.5 align-top"><code class="bg-[var(--md-sys-color-surface-container-lowest)] px-1.5 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">example\.com$</code></td>
                                                    <td class="pr-2 py-1.5 align-top text-[var(--md-sys-color-error)]">{{ t('testSuiteShow.proxyRulesAvoid') }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div v-if="!localSuiteSettings.proxy_rules.length" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] py-1.5 opacity-70">
                                        {{ t('testSuiteShow.noRulesConfigured') }}
                                    </div>
                                    <div v-for="(rule, index) in localSuiteSettings.proxy_rules" :key="index" class="flex items-start gap-2 mb-1.5">
                                        <input
                                            v-model="rule.domain"
                                            @change="saveProxyRules"
                                            :disabled="!can.edit || savingSuiteSetting"
                                            type="text"
                                            placeholder="^example\.com$"
                                            class="flex-1 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                        />
                                        <input
                                            v-model="rule.proxy"
                                            @change="saveProxyRules"
                                            :disabled="!can.edit || savingSuiteSetting"
                                            type="text"
                                            placeholder="http://proxy.example.com:8080"
                                            class="flex-1 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                        />
                                        <button v-if="can.edit" type="button" @click="removeProxyRule(index)" :disabled="savingSuiteSetting" class="p-1.5 text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-error)] transition-colors disabled:opacity-60">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Variables -->
                            <div class="pt-2 border-t border-[var(--md-sys-color-outline-variant)]">
                                <div class="flex items-center justify-between mt-3 mb-1">
                                    <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.variablesSection') }}</p>
                                    <button v-if="can.edit" type="button" @click="addVariable" :disabled="savingSuiteSetting" class="md-label-small text-[var(--md-sys-color-primary)] hover:underline disabled:opacity-60">{{ t('testSuiteShow.addVariable') }}</button>
                                </div>
                                <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-2 opacity-80">{{ t('testSuiteShow.variablesHint') }}</p>
                                <div class="mb-2 flex items-center gap-2">
                                    <code class="md-body-small font-mono bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">variables.KEY</code>
                                </div>
                                <div v-if="!localSuiteSettings.variables.length" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] py-1.5 opacity-70">
                                    {{ t('testSuiteShow.noVariablesConfigured') }}
                                </div>
                                <div v-for="(variable, index) in localSuiteSettings.variables" :key="index" class="flex items-start gap-2 mb-1.5">
                                    <input
                                        v-model="variable.key"
                                        @change="saveVariables"
                                        :disabled="!can.edit || savingSuiteSetting"
                                        type="text"
                                        placeholder="VARIABLE_NAME"
                                        class="w-2/5 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                    />
                                    <input
                                        v-model="variable.value"
                                        @change="saveVariables"
                                        :disabled="!can.edit || savingSuiteSetting"
                                        type="text"
                                        placeholder="value"
                                        class="flex-1 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                    />
                                    <button v-if="can.edit" type="button" @click="removeVariable(index)" :disabled="savingSuiteSetting" class="p-1.5 text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-error)] transition-colors disabled:opacity-60">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Schedule -->
                            <div class="pt-2 border-t border-[var(--md-sys-color-outline-variant)]">
                                <div class="flex items-center justify-between mt-3 mb-3">
                                    <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.scheduleHeading') }}</p>
                                    <Button v-if="can.manageSchedule && suite.schedule" variant="text" size="sm" @click="removeSchedule" class="!text-[var(--md-sys-color-error)]">
                                        {{ t('testSuiteShow.remove') }}
                                    </Button>
                                </div>
                                <div v-if="suite.schedule" class="flex items-center gap-2 mb-3">
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
                                <p v-if="suite.schedule" class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-70 mb-2">{{ t('testSuiteShow.timezoneLabel', { tz: suite.schedule.timezone }) }} · {{ t('testSuiteShow.nextRun', { date: formatDate(suite.schedule.next_run_at) }) }}</p>
                                <div v-if="can.manageSchedule" class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3">
                                    <div>
                                        <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`suite-cron-${suite.id}`">{{ t('testSuiteShow.crontabExpression') }}</label>
                                        <input
                                            :id="`suite-cron-${suite.id}`"
                                            v-model="scheduleForm.cron_expression"
                                            @change="saveSchedule"
                                            @focus="scheduleError = null"
                                            :disabled="!can.edit || savingSuiteSetting"
                                            type="text"
                                            placeholder="0 */6 * * *"
                                            class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                        />
                                        <p v-if="scheduleError" class="mt-1 md-label-small text-[var(--md-sys-color-error)]">{{ scheduleError }}</p>
                                    </div>
                                    <div>
                                        <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`suite-tz-${suite.id}`">{{ t('testSuiteShow.timezoneField') }}</label>
                                        <input
                                            :id="`suite-tz-${suite.id}`"
                                            v-model="scheduleForm.timezone"
                                            @change="saveSchedule"
                                            @focus="scheduleError = null"
                                            :disabled="!can.edit || savingSuiteSetting"
                                            type="text"
                                            placeholder="UTC"
                                            class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3 py-1.5 md-body-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                        />
                                    </div>
                                </div>
                                <div v-if="can.manageSchedule" class="mt-3">
                                    <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                        <input type="checkbox" v-model="scheduleForm.is_enabled" @change="saveSchedule" :disabled="savingSuiteSetting" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer disabled:opacity-60" /> {{ t('testSuiteShow.enabled') }}
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick run settings (collapsible, edited inline, auto-saved on change) -->
                    <div class="border-b border-[var(--md-sys-color-outline-variant)] bg-[var(--md-sys-color-surface-container-lowest)]">
                        <button
                            type="button"
                            @click="showRunSettings = !showRunSettings"
                            class="w-full flex items-center gap-2 px-5 py-2.5 text-left hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors"
                        >
                            <svg
                                class="w-4 h-4 text-[var(--md-sys-color-on-surface-variant)] transition-transform"
                                :class="{ 'rotate-90': showRunSettings }"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            <span class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.runSettings') }}</span>

                            <!-- Compact summary when collapsed -->
                            <span v-if="!showRunSettings" class="flex items-center gap-1.5 ml-1 flex-wrap">
                                <SettingBadge :label="localSettings.browser" :active="true" />
                                <SettingBadge :label="localSettings.headless ? t('testSuiteShow.headless') : t('testSuiteShow.headedVisible')" :active="localSettings.headless" />
                                <SettingBadge :label="t('testSuiteShow.timeoutShort', { value: localSettings.timeout_ms >= 60000 ? `${Math.round(localSettings.timeout_ms / 60000)}m` : `${localSettings.timeout_ms / 1000}s` })" :active="true" />
                                <SettingBadge :label="t('testSuiteShow.screenshots')" :active="localSettings.take_screenshot" />
                                <SettingBadge :label="t('testSuiteShow.retriesShort', { value: localSettings.max_retries === 0 ? t('testSuites.noRetries') : `${localSettings.max_retries}×` })" :active="!!localSettings.max_retries" />
                                <SettingBadge :label="t('testSuiteShow.keepRunsShort', { count: localSettings.history_retention })" :active="true" />
                            </span>

                            <span v-if="savingSetting" class="ml-auto flex items-center gap-1 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                {{ t('testSuiteShow.saving') }}
                            </span>
                            <span v-else-if="savedField" class="ml-auto flex items-center gap-1 md-label-small text-[var(--md-ext-color-success)]">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                </svg>
                                {{ t('testSuiteShow.saved') }}
                            </span>
                        </button>

                        <div v-if="showRunSettings" class="px-5 pb-3 pt-1 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">
                            <!-- Browser -->
                            <div class="flex items-center justify-between gap-3">
                                <label class="md-label-small text-[var(--md-sys-color-on-surface-variant)]" :for="`setting-browser-${suite.id}`">{{ t('testSuiteShow.browser') }}</label>
                                <select
                                    :id="`setting-browser-${suite.id}`"
                                    v-model="localSettings.browser"
                                    @change="updateSuiteSetting('browser')"
                                    :disabled="!can.edit || savingSetting"
                                    class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-extra-small)] w-36 px-2 py-1 md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                >
                                    <option value="chromium">Chromium</option>
                                    <option value="firefox">Firefox</option>
                                    <option value="webkit">WebKit</option>
                                </select>
                            </div>

                            <!-- Mode -->
                            <div class="flex items-center justify-between gap-3">
                                <label class="md-label-small text-[var(--md-sys-color-on-surface-variant)]" :for="`setting-mode-${suite.id}`">{{ t('testSuiteShow.mode') }}</label>
                                <select
                                    :id="`setting-mode-${suite.id}`"
                                    v-model="localSettings.headless"
                                    @change="updateSuiteSetting('headless')"
                                    :disabled="!can.edit || savingSetting"
                                    class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-extra-small)] w-36 px-2 py-1 md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                >
                                    <option :value="true">{{ t('testSuiteShow.headless') }}</option>
                                    <option :value="false">{{ t('testSuiteShow.headedVisible') }}</option>
                                </select>
                            </div>

                            <!-- Timeout -->
                            <div class="flex items-center justify-between gap-3">
                                <label class="md-label-small text-[var(--md-sys-color-on-surface-variant)]" :for="`setting-timeout-${suite.id}`">{{ t('testSuiteShow.timeout') }}</label>
                                <select
                                    :id="`setting-timeout-${suite.id}`"
                                    v-model="localSettings.timeout_ms"
                                    @change="updateSuiteSetting('timeout_ms')"
                                    :disabled="!can.edit || savingSetting"
                                    class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-extra-small)] w-36 px-2 py-1 md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                >
                                    <option :value="10000">{{ t('testSuiteShow.tenSeconds') }}</option>
                                    <option :value="30000">{{ t('testSuiteShow.thirtySeconds') }}</option>
                                    <option :value="60000">{{ t('testSuiteShow.sixtySeconds') }}</option>
                                    <option :value="120000">{{ t('testSuiteShow.twoMinutes') }}</option>
                                    <option :value="300000">{{ t('testSuiteShow.fiveMinutes') }}</option>
                                    <option :value="600000">{{ t('testSuiteShow.tenMinutes') }}</option>
                                </select>
                            </div>

                            <!-- Screenshots -->
                            <div class="flex items-center justify-between gap-3">
                                <label class="md-label-small text-[var(--md-sys-color-on-surface-variant)]" :for="`setting-screenshots-${suite.id}`">{{ t('testSuiteShow.screenshots') }}</label>
                                <select
                                    :id="`setting-screenshots-${suite.id}`"
                                    v-model="localSettings.take_screenshot"
                                    @change="updateSuiteSetting('take_screenshot')"
                                    :disabled="!can.edit || savingSetting"
                                    class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-extra-small)] w-36 px-2 py-1 md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                >
                                    <option :value="true">{{ t('testSuiteShow.enabled') }}</option>
                                    <option :value="false">{{ t('testSuiteShow.screenshotsDisabled') }}</option>
                                </select>
                            </div>

                            <!-- Retries -->
                            <div class="flex items-center justify-between gap-3">
                                <label class="md-label-small text-[var(--md-sys-color-on-surface-variant)]" :for="`setting-retries-${suite.id}`">{{ t('testSuiteShow.retries') }}</label>
                                <select
                                    :id="`setting-retries-${suite.id}`"
                                    v-model="localSettings.max_retries"
                                    @change="updateSuiteSetting('max_retries')"
                                    :disabled="!can.edit || savingSetting"
                                    class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-extra-small)] w-36 px-2 py-1 md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                >
                                    <option :value="0">{{ t('testSuites.noRetries') }}</option>
                                    <option :value="1">{{ t('testSuites.retryOnce') }}</option>
                                    <option :value="2">{{ t('testSuites.retryTwice') }}</option>
                                    <option :value="3">{{ t('testSuites.retry3Times') }}</option>
                                </select>
                            </div>

                            <!-- Keep History -->
                            <div class="flex items-center justify-between gap-3">
                                <label class="md-label-small text-[var(--md-sys-color-on-surface-variant)]" :for="`setting-history-${suite.id}`">{{ t('testSuiteShow.keepHistory') }}</label>
                                <select
                                    :id="`setting-history-${suite.id}`"
                                    v-model="localSettings.history_retention"
                                    @change="updateSuiteSetting('history_retention')"
                                    :disabled="!can.edit || savingSetting"
                                    class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-extra-small)] w-36 px-2 py-1 md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                                >
                                    <option :value="3">{{ t('testSuites.last3Runs') }}</option>
                                    <option :value="5">{{ t('testSuites.last5Runs') }}</option>
                                    <option :value="10">{{ t('testSuites.last10Runs') }}</option>
                                </select>
                            </div>
                        </div>
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

                        <!-- Sort: field select + direction toggle, grouped as one control -->
                        <div class="flex items-stretch">
                            <select
                                v-model="testSort"
                                class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] border-r-0 rounded-l-[var(--md-sys-shape-corner-small)] px-3 py-2 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                            >
                                <option value="run_date">{{ t('testSuiteShow.sortRunDate') }}</option>
                                <optgroup :label="t('testSuiteShow.sortGroupRunStatus')">
                                    <option value="passed">{{ t('testSuiteShow.sortPassed') }}</option>
                                    <option value="errors">{{ t('testSuiteShow.sortErrors') }}</option>
                                    <option value="running">{{ t('testSuiteShow.sortRunning') }}</option>
                                </optgroup>
                                <optgroup :label="t('testSuiteShow.sortGroupActiveDisabled')">
                                    <option value="status">{{ t('testSuiteShow.sortStatus') }}</option>
                                </optgroup>
                                <optgroup :label="t('testSuiteShow.sortGroupDuration')">
                                    <option value="duration">{{ t('testSuiteShow.sortDuration') }}</option>
                                </optgroup>
                                <optgroup :label="t('testSuiteShow.sortGroupScreenshots')">
                                    <option value="has_screenshots">{{ t('testSuiteShow.sortHasScreenshots') }}</option>
                                </optgroup>
                                <optgroup :label="t('testSuiteShow.sortGroupCreatedUpdated')">
                                    <option value="created">{{ t('testSuiteShow.sortCreated') }}</option>
                                    <option value="updated">{{ t('testSuiteShow.sortUpdated') }}</option>
                                </optgroup>
                            </select>
                            <button
                                type="button"
                                @click="testSortDir = testSortDir === 'asc' ? 'desc' : 'asc'"
                                class="flex items-center justify-center bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-r-[var(--md-sys-shape-corner-small)] px-2.5 text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container-high)] hover:text-[var(--md-sys-color-on-surface)] transition-colors focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                                :title="testSortDir === 'asc' ? t('testSuiteShow.sortAscending') : t('testSuiteShow.sortDescending')"
                            >
                                <svg v-if="testSortDir === 'asc'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19V5M5 12l7-7 7 7"/>
                                </svg>
                                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v14M19 12l-7 7-7-7"/>
                                </svg>
                            </button>
                        </div>

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
                        <template v-if="testSearch || testStatus.length">
                            {{ t('testSuiteShow.noMatchSearch') }}
                        </template>
                        <template v-else-if="tests.total > 0">
                            <p>{{ t('testSuiteShow.noneOnThisPage') }}</p>
                            <button
                                type="button"
                                @click="reloadTests({ page: 1 })"
                                class="mt-2 inline-flex items-center text-[var(--md-sys-color-primary)] hover:underline"
                            >
                                {{ t('testSuiteShow.goToFirstPage') }}
                            </button>
                        </template>
                        <template v-else>
                            {{ t('testSuiteShow.noneYet') }}
                        </template>
                    </div>

                    <div v-else>
                        <div
                            v-for="test in tests.data"
                            :key="test.id"
                            :class="[
                                'group/test relative pl-5 pr-4 py-4 transition-colors flex items-start gap-3 border-b border-[var(--md-sys-color-outline-variant)] last:border-b-0 hover:bg-[var(--md-sys-color-surface-container-low)]',
                                test.status === 'disabled' ? 'opacity-60' : '',
                            ]"
                        >
                            <!-- Left status accent bar (color-coded by latest run status) -->
                            <span
                                class="absolute left-0 top-3 bottom-3 w-1 rounded-full transition-colors"
                                :class="statusAccentClass(test.current_status ?? (test.status === 'disabled' ? 'disabled' : null))"
                                :aria-hidden="true"
                            />

                            <!-- Row checkbox -->
                            <input
                                v-if="can.delete || can.edit || can.run"
                                type="checkbox"
                                :checked="selectedIds.has(test.id)"
                                @change="toggleSelect(test.id)"
                                class="mt-1 w-4 h-4 flex-shrink-0 rounded-[var(--md-sys-shape-corner-extra-small)] border-[var(--md-sys-color-outline)] accent-[var(--md-sys-color-primary)] cursor-pointer"
                            />

                            <!-- Test info: 3-line layout (title / description / meta) -->
                            <div class="min-w-0 flex-1 space-y-1">
                                <!-- Title line: chip first (fixed-width), then name, then disabled badge -->
                                <div class="flex items-center gap-2 flex-wrap">
                                    <Chip
                                        :status="test.current_status || 'never_ran'"
                                        :fixed="true"
                                        :label="test.current_status ? null : t('testSuiteShow.neverRan')"
                                    />
                                    <Link
                                        :href="`/sorify/suites/${suite.id}/tests/${test.id}`"
                                        class="md-title-small font-medium text-[var(--md-sys-color-on-surface)] hover:text-[var(--md-sys-color-primary)] hover:underline transition-colors"
                                    >
                                        {{ test.name }}
                                    </Link>
                                    <span
                                        v-if="test.status === 'disabled'"
                                        class="md-label-small px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] text-[var(--md-ext-color-on-warning-container)] bg-[var(--md-ext-color-warning-container)]"
                                    >{{ t('testSuiteShow.disabled') }}</span>
                                </div>

                                <!-- Description line with uploader avatar on the left -->
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
                                    <p v-if="test.description" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] truncate flex-1 min-w-0">{{ test.description }}</p>
                                </div>

                                <!-- Meta line: latest runs · screenshots · expand (uploader moved to the right column) -->
                                <div v-if="test.recent_runs?.length" class="flex flex-col gap-1 pt-0.5">
                                    <span class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.latestRuns') }}</span>
                                    <div class="flex flex-col gap-1">
                                        <RunPill :run="test.recent_runs[0]" @open-lightbox="lightbox.open" />
                                        <div v-if="expandedRuns.has(test.id)" class="flex flex-col gap-1">
                                            <RunPill
                                                v-for="run in test.recent_runs.slice(1)"
                                                :key="run.run_id"
                                                :run="run"
                                                @open-lightbox="lightbox.open"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right column: run action + more runs (top-aligned) -->
                            <div class="flex flex-col items-end gap-2 flex-shrink-0 self-start">
                                <!-- Per-row Run button: always visible -->
                                <button
                                    v-if="can.run"
                                    @click="runTest(test.id)"
                                    :disabled="runningIds.has(test.id) || test.status === 'disabled'"
                                    :class="[
                                        'flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-primary)] bg-[var(--md-sys-color-primary)] px-3 py-1.5 rounded-[var(--md-sys-shape-corner-small)] transition-all hover:brightness-90 active:brightness-95 disabled:opacity-40 disabled:cursor-not-allowed',
                                    ]"
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
                                <button
                                    v-if="test.recent_runs?.length > 1"
                                    type="button"
                                    class="md-label-small text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-primary)] hover:underline"
                                    @click="toggleRunsExpanded(test.id)"
                                >
                                    {{ expandedRuns.has(test.id) ? t('testSuiteShow.hide') : t('testSuiteShow.moreRuns', { count: test.recent_runs.length - 1 }) }}
                                </button>

                                <!-- Created / Updated timestamps (subtle, hover for absolute date) -->
                                <div v-if="test.updated_at || test.created_at" class="flex items-center gap-2 mt-1">
                                    <span v-if="test.updated_at" class="relative group/tip">
                                        <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-60 cursor-default whitespace-nowrap">{{ t('testSuiteShow.updatedAgo', { time: formatRelativeTime(test.updated_at) }) }}</span>
                                        <span class="pointer-events-none absolute bottom-full right-0 mb-1.5 px-2 py-1 rounded-[var(--md-sys-shape-corner-extra-small)] bg-gray-900 text-white md-label-small whitespace-nowrap opacity-0 group-hover/tip:opacity-100 transition-opacity duration-150 z-10">{{ formatDate(test.updated_at) }}</span>
                                    </span>
                                    <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-30">·</span>
                                    <span v-if="test.created_at" class="relative group/tip">
                                        <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-60 cursor-default whitespace-nowrap">{{ t('testSuiteShow.createdAgo', { time: formatRelativeTime(test.created_at) }) }}</span>
                                        <span class="pointer-events-none absolute bottom-full right-0 mb-1.5 px-2 py-1 rounded-[var(--md-sys-shape-corner-extra-small)] bg-gray-900 text-white md-label-small whitespace-nowrap opacity-0 group-hover/tip:opacity-100 transition-opacity duration-150 z-10">{{ formatDate(test.created_at) }}</span>
                                    </span>
                                </div>
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
                                    @click="link.url && router.get(link.url, { search: testSearch, per_page: testPerPage, sort: testSort, sort_dir: testSortDir, status: testStatus }, { preserveState: true, preserveScroll: true, replace: true })"
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
                <!-- Users -->
                <Card padding="p-0" class="mt-6">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)] flex items-center justify-between">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">{{ t('testSuiteShow.usersHeading') }}</h2>
                        <Button v-if="can.manageUsers" variant="tonal" size="sm" @click="openManageUsersModal">
                            {{ t('testSuiteShow.manageUsers') }}
                        </Button>
                    </div>
                    <div class="px-5 py-4">
                        <AvatarGroup :users="suite.members ?? []" :suite-id="suite.id" :max="20" />
                    </div>
                </Card>

                <!-- CI Webhook -->
                <Card padding="p-0" class="mt-6">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)] flex items-center justify-between">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">{{ t('testSuiteShow.ciWebhookHeading') }}</h2>
                        <Button v-if="can.edit" variant="tonal" size="sm" @click="regenerateWebhook" class="!text-[var(--md-sys-color-error)]">
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
                            class="relative pl-4 pr-5 py-3 hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors border-b border-[var(--md-sys-color-outline-variant)] last:border-b-0 group/run"
                        >
                            <!-- Status accent bar -->
                            <span
                                class="absolute left-0 top-0 bottom-0 w-1"
                                :class="{
                                    'bg-[var(--md-ext-color-success)]': ['passed', 'completed', 'active'].includes(run.status),
                                    'bg-[var(--md-sys-color-error)]': run.status === 'failed',
                                    'bg-[var(--md-ext-color-warning)]': ['error', 'timeout'].includes(run.status),
                                    'bg-[var(--md-sys-color-primary)]': ['running', 'pending'].includes(run.status),
                                    'bg-[var(--md-sys-color-outline-variant)]': ['cancelled', 'disabled', 'skipped'].includes(run.status),
                                }"
                            />

                            <!-- Main row: info on left, screenshots floated right -->
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1 space-y-0.5">
                                    <!-- Line 1: chip + run title -->
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <Chip :status="run.status" :fixed="true" />
                                        <span class="group/title relative min-w-0">
                                            <Link
                                                :href="`/sorify/runs/${run.id}`"
                                                class="md-title-small font-medium text-[var(--md-sys-color-on-surface)] hover:text-[var(--md-sys-color-primary)] hover:underline transition-colors truncate block"
                                            >
                                                {{ (run.test_names ?? []).join(', ') || t('testSuiteShow.runTitle', { id: run.id }) }}
                                            </Link>
                                            <div
                                                v-if="(run.test_names ?? []).length"
                                                class="pointer-events-none absolute left-0 bottom-full mb-1.5 z-20 hidden group-hover/title:flex flex-col items-start rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-inverse-surface)] text-[var(--md-sys-color-inverse-on-surface)] md-label-small shadow-elevation-1 px-2.5 py-1.5 max-w-xs"
                                            >
                                                <p
                                                    v-for="(name, i) in (run.test_names ?? []).slice(0, 5)"
                                                    :key="i"
                                                    class="truncate max-w-full"
                                                >{{ name }}</p>
                                                <p v-if="(run.test_names ?? []).length > 5" class="opacity-70 mt-0.5">{{ (run.test_names ?? []).length - 5 }} more titles</p>
                                            </div>
                                        </span>
                                    </div>
                                    <!-- Line 2: avatar · passed/total · failed · duration · time ago -->
                                    <div class="flex items-center gap-2.5 flex-wrap">
                                        <!-- Avatar (with placeholder fallback) -->
                                        <Avatar
                                            v-if="run.triggered_by_user"
                                            size="sm"
                                            :name="run.triggered_by_user.name"
                                            :email="run.triggered_by_user.email"
                                            :avatar-url="run.triggered_by_user.avatar_url"
                                        />
                                        <div
                                            v-else
                                            class="w-5 h-5 rounded-full ring-2 ring-[var(--md-sys-color-surface-container-low)] bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)] flex items-center justify-center flex-shrink-0"
                                        >
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                            </svg>
                                        </div>
                                        <Link
                                            v-if="run.total_tests != null"
                                            :href="`/sorify/runs/${run.id}`"
                                            class="md-label-small text-[var(--md-ext-color-success)] font-medium hover:underline"
                                        >
                                            {{ t('testSuiteShow.passedOfTotal', { passed: run.passed_count ?? 0, total: run.total_tests }) }}
                                        </Link>
                                        <span v-if="run.failed_count" class="md-label-small text-[var(--md-sys-color-error)] font-medium">
                                            {{ t('testSuiteShow.failed', { count: run.failed_count }) }}
                                        </span>
                                        <span class="relative group/tip md-label-small">
                                            <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] opacity-60 cursor-default">{{ formatRelativeTime(run.created_at) }}<span v-if="run.duration_ms != null"> ({{ formatDuration(run.duration_ms) }})</span></span>
                                            <span class="pointer-events-none absolute bottom-full left-0 mb-1.5 px-2 py-1 rounded-[var(--md-sys-shape-corner-extra-small)] bg-gray-900 text-white md-label-small whitespace-nowrap opacity-0 group-hover/tip:opacity-100 transition-opacity duration-150 z-10">{{ formatDate(run.created_at) }}</span>
                                        </span>
                                    </div>
                                </div>
                                <!-- Screenshots floated to the right -->
                                <div v-if="run.screenshots?.length" class="flex-shrink-0 self-center">
                                    <ScreenshotThumbs :screenshots="run.screenshots" :limit="3" @open="lightbox.open" />
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Edit Suite Modal (name + description) -->
        <Modal :show="showEditModal" :title="t('testSuiteShow.editSuiteModalTitle')" max-width="max-w-md" @close="showEditModal = false">
                    <form @submit.prevent="submitEdit" class="px-6 py-5 space-y-4 min-h-[260px] flex flex-col">
                        <TextField v-model="editForm.name" :label="t('testSuiteShow.suiteNameLabel')" required :error="editForm.errors.name" />
                        <TextField v-model="editForm.description" :label="t('testSuiteShow.descriptionLabel')" type="textarea" :rows="6" mono class="flex-1" :hint="t('testSuiteShow.markdownSupported')" />
                        <div class="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="text" @click="showEditModal = false">{{ t('testSuiteShow.cancel') }}</Button>
                            <Button type="submit" variant="filled" :disabled="editForm.processing">
                                {{ editForm.processing ? t('testSuiteShow.saving') : t('testSuiteShow.saveChanges') }}
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
                            mono
                            :placeholder="t('testSuiteShow.testDescriptionPlaceholder')"
                            :hint="t('testSuiteShow.markdownSupported')"
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

        <!-- Duplicate Suite Modal -->
        <Modal :show="showDuplicateSuiteModal" :title="t('testSuiteShow.duplicateSuite')" max-width="max-w-md" @close="showDuplicateSuiteModal = false">
                    <form @submit.prevent="submitDuplicateSuite" class="px-6 py-5 space-y-4">
                        <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                            {{ t('testSuiteShow.confirmDuplicateSuite', { name: suite.name }) }}
                        </p>
                        <TextField
                            v-model="duplicateSuiteForm.name"
                            :label="t('testSuiteShow.duplicationPromptLabel')"
                            :placeholder="t('testSuiteShow.duplicationPromptPlaceholder')"
                            :error="duplicateSuiteForm.errors.name"
                        />
                        <div class="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="text" @click="showDuplicateSuiteModal = false">{{ t('testSuiteShow.cancel') }}</Button>
                            <Button type="submit" variant="filled" :disabled="duplicateSuiteForm.processing">
                                {{ duplicateSuiteForm.processing ? t('testSuiteShow.duplicating') : t('testSuiteShow.duplicateSuiteShort') }}
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

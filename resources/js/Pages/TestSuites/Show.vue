<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CopyableSecret from '@/Components/CopyableSecret.vue';
import { Card, Chip, Button, TextField, Autocomplete, Modal, SuiteName, AvatarGroup, RanBy, SettingBadge } from '@/Components/ui';
import { formatDate } from '@/utils/date';

const props = defineProps({
    suite: { type: Object, required: true },
    stats: { type: Object, default: () => ({}) },
    tests: { type: Array, default: () => [] },
    recentRuns: { type: Array, default: () => [] },
    webhookUrl: { type: String, default: null },
    members: { type: Array, default: () => [] },
    candidates: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    can: { type: Object, default: () => ({ edit: false, delete: false, run: false, manageUsers: false, manageSchedule: false }) },
});

// CI webhook
const statusUrlTemplate = computed(() => props.webhookUrl ? props.webhookUrl.replace(/\/trigger$/, '/runs/{run}/status') : null);

function regenerateWebhook() {
    if (!confirm('This will invalidate the current webhook URL. Any CI configuration using the old URL will stop working. Continue?')) return;
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
    if (!confirm('Remove the schedule for this suite? It will no longer run automatically.')) return;
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
    if (!confirm(`Remove ${member.name} from this suite?`)) return;
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
    if (!confirm(`Delete suite "${props.suite.name}"? This cannot be undone.`)) return;
    router.delete(`/sorify/suites/${props.suite.id}`);
}

// Auto-refresh while any test has a run in progress
let refreshTimer = null;

const hasActiveTest = computed(() =>
    props.tests.some(t => t.current_status === 'running' || t.current_status === 'pending'),
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
const allSelected = computed(() => props.tests.length > 0 && selectedIds.value.size === props.tests.length);
const someSelected = computed(() => hasSelection.value && !allSelected.value);

function toggleSelect(id) {
    const next = new Set(selectedIds.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selectedIds.value = next;
}

function toggleSelectAll() {
    if (allSelected.value) selectedIds.value = new Set();
    else selectedIds.value = new Set(props.tests.map(t => t.id));
}

function bulkDelete() {
    if (!confirm(`Delete ${selectedIds.value.size} test(s)? This cannot be undone.`)) return;
    router.delete(`/sorify/suites/${props.suite.id}/tests/bulk`, {
        data: { test_ids: [...selectedIds.value] },
        onSuccess: () => { selectedIds.value = new Set(); },
    });
}

function formatDuration(ms) {
    if (!ms && ms !== 0) return '—';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}

const RUN_DOT_CLASS = {
    passed: 'bg-[var(--md-ext-color-success)]',
    failed: 'bg-[var(--md-sys-color-error)]',
    error: 'bg-[var(--md-ext-color-warning)]',
    timeout: 'bg-[var(--md-ext-color-warning)]',
    running: 'bg-[var(--md-sys-color-primary)]',
    pending: 'bg-[var(--md-sys-color-primary)]',
    cancelled: 'bg-[var(--md-sys-color-on-surface-variant)]',
    skipped: 'bg-[var(--md-sys-color-on-surface-variant)]',
};
</script>

<template>
    <AppLayout>
        <Head :title="suite.name" />

        <!-- Suite header -->
        <div class="flex items-start justify-between mb-6">
            <div>
                <div class="flex items-center gap-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1">
                    <Link href="/sorify/suites" class="hover:text-[var(--md-sys-color-on-surface)] transition-colors">Test Suites</Link>
                    <span>/</span>
                    <span class="text-[var(--md-sys-color-on-surface)]"><SuiteName :name="suite.name" /></span>
                </div>
                <span class="inline-block md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-primary-container)] bg-[var(--md-sys-color-primary-container)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] mb-1.5">Test Suite</span>
                <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)]"><SuiteName :name="suite.name" /></h1>
                <p v-if="suite.proxy_rules?.length" class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">Proxy: {{ suite.proxy_rules.length }} domain rule(s)<span v-if="suite.playwright_proxy"> + default {{ suite.playwright_proxy }}</span></p>
                <p v-else-if="suite.playwright_proxy" class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">Proxy: {{ suite.playwright_proxy }}</p>
                <p v-if="suite.description" class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1 whitespace-pre-line">{{ suite.description }}</p>
                <p v-if="suite.created_by" class="md-label-small text-[var(--md-sys-color-on-surface-variant)] mt-1">
                    Created by <span>{{ suite.created_by.name }}</span>
                </p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0 ml-4">
                <Button v-if="can.delete" variant="text" @click="deleteSuite" class="!text-[var(--md-sys-color-error)]">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    Delete
                </Button>
                <Button v-if="can.edit" variant="tonal" @click="openEditModal">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    Edit
                </Button>
                <Button v-if="can.manageUsers" variant="tonal" @click="openManageUsersModal">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-3.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 10-4-4"/>
                    </svg>
                    Manage Users
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
                    {{ running ? 'Starting...' : 'Run All Tests' }}
                </Button>
            </div>
        </div>

        <!-- Stats row -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
            <Card padding="px-4 py-3">
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">Tests</p>
                <p class="md-title-large text-[var(--md-sys-color-on-surface)] mt-1">{{ stats.test_count ?? tests.length }}</p>
            </Card>
            <Card padding="px-4 py-3">
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">Runs</p>
                <p class="md-title-large text-[var(--md-sys-color-on-surface)] mt-1">{{ stats.run_count ?? recentRuns.length }}</p>
            </Card>
            <Card padding="px-4 py-3">
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">Pass Rate</p>
                <p class="md-title-large mt-1"
                    :class="stats.pass_rate >= 90 ? 'text-[var(--md-ext-color-success)]' : stats.pass_rate >= 70 ? 'text-[var(--md-ext-color-warning)]' : stats.pass_rate != null ? 'text-[var(--md-sys-color-error)]' : 'text-[var(--md-sys-color-on-surface-variant)]'"
                >
                    {{ stats.pass_rate != null ? `${Math.round(stats.pass_rate)}%` : '—' }}
                </p>
            </Card>
            <Card padding="px-4 py-3">
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)]">Proxy</p>
                <p class="md-body-medium font-medium text-[var(--md-sys-color-on-surface)] mt-1 truncate">
                    {{ suite.proxy_rules?.length ? `${suite.proxy_rules.length} rule(s)` : (suite.playwright_proxy || '—') }}
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
                                v-if="tests.length && can.delete"
                                type="checkbox"
                                :checked="allSelected"
                                :indeterminate="someSelected"
                                @change="toggleSelectAll"
                                class="w-4 h-4 rounded-[var(--md-sys-shape-corner-extra-small)] border-[var(--md-sys-color-outline)] accent-[var(--md-sys-color-primary)] cursor-pointer"
                            />
                            <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">Tests</h2>
                            <Button v-if="hasSelection && can.delete" variant="text" size="sm" @click="bulkDelete" class="!text-[var(--md-sys-color-error)]">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                Delete ({{ selectedIds.size }})
                            </Button>
                        </div>
                        <Button v-if="can.edit" variant="filled" size="sm" @click="openTestModal">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            New Test
                        </Button>
                    </div>

                    <div v-if="!tests.length" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                        No tests yet. Add your first test.
                    </div>

                    <div v-else>
                        <div
                            v-for="test in tests"
                            :key="test.id"
                            :class="['px-5 py-4 hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors flex items-start justify-between gap-3 border-b border-[var(--md-sys-color-outline-variant)] last:border-b-0', test.status === 'disabled' ? 'opacity-60' : '']"
                        >
                            <!-- Row checkbox -->
                            <input
                                v-if="can.delete"
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
                                <div v-if="test.uploaded_by" class="mt-1">
                                    <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                        By <span>{{ test.uploaded_by }}</span>
                                    </p>
                                </div>
                                <div v-if="test.recent_runs?.length" class="flex items-center gap-2.5 mt-1.5 flex-wrap">
                                    <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">Latest runs:</span>
                                    <Link
                                        v-for="run in test.recent_runs"
                                        :key="run.run_id"
                                        :href="`/sorify/runs/${run.run_id}`"
                                        :title="run.status"
                                        class="inline-flex items-center gap-1 md-label-small text-[var(--md-sys-color-primary)] hover:underline"
                                    >
                                        <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="[RUN_DOT_CLASS[run.status] ?? 'bg-[var(--md-sys-color-on-surface-variant)]', run.status === 'running' ? 'animate-pulse' : '']" />
                                        {{ run.status }} · {{ formatDate(run.created_at) }}
                                    </Link>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <!-- Disable/Enable toggle -->
                                <button
                                    v-if="can.edit"
                                    @click="toggleStatus(test)"
                                    :disabled="togglingIds.has(test.id)"
                                    :title="test.status === 'disabled' ? 'Click to enable' : 'Click to disable'"
                                    :class="[
                                        'md-label-small px-2 py-1 rounded-[var(--md-sys-shape-corner-extra-small)] transition-colors disabled:opacity-50 disabled:cursor-not-allowed',
                                        test.status === 'disabled'
                                            ? 'text-[var(--md-ext-color-on-warning-container)] bg-[var(--md-ext-color-warning-container)]'
                                            : 'text-[var(--md-ext-color-on-success-container)] bg-[var(--md-ext-color-success-container)]',
                                    ]"
                                >
                                    {{ togglingIds.has(test.id) ? '...' : (test.status === 'disabled' ? 'Disabled' : 'Active') }}
                                </button>

                                <!-- Per-row Run button -->
                                <button
                                    v-if="can.run"
                                    @click="runTest(test.id)"
                                    :disabled="runningIds.has(test.id) || test.status === 'disabled'"
                                    class="flex items-center gap-1 md-label-small text-[var(--md-sys-color-on-primary)] bg-[var(--md-sys-color-primary)] px-2 py-1 rounded-[var(--md-sys-shape-corner-extra-small)] transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    title="Run this test"
                                >
                                    <svg v-if="runningIds.has(test.id)" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                    </svg>
                                    <svg v-else class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    {{ runningIds.has(test.id) ? '...' : 'Run' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>

            <div>
                <!-- Settings summary -->
                <Card padding="p-0">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">Settings</h2>
                    </div>
                    <div class="px-5 py-4 flex flex-wrap gap-1.5">
                        <SettingBadge label="Teams" :active="!!suite.teams_webhook_url" />
                        <SettingBadge label="Screenshots" :active="!!suite.take_screenshot" />
                        <SettingBadge label="Proxy" :active="!!(suite.proxy_rules?.length || suite.playwright_proxy)" />
                        <SettingBadge label="Schedule" :active="!!(suite.schedule && suite.schedule.is_enabled)" />
                        <SettingBadge :label="suite.max_retries ? `Retries (${suite.max_retries})` : 'Retries'" :active="!!suite.max_retries" />
                    </div>
                </Card>

                <!-- Users -->
                <Card padding="p-0" class="mt-6">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">Users</h2>
                    </div>
                    <div class="px-5 py-4">
                        <AvatarGroup :users="suite.members ?? []" :suite-id="suite.id" :max="20" />
                    </div>
                </Card>

                <!-- CI Webhook -->
                <Card padding="p-0" class="mt-6">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)] flex items-center justify-between">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">CI Webhook</h2>
                        <Button v-if="can.edit" variant="text" size="sm" @click="regenerateWebhook" class="!text-[var(--md-sys-color-error)]">
                            Regenerate
                        </Button>
                    </div>
                    <div class="px-5 py-4">
                        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-3">POST to this URL from CI (e.g. GitHub Actions) to trigger a run of this suite.</p>
                        <CopyableSecret v-if="webhookUrl" :value="webhookUrl" />
                        <p v-else class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">No webhook configured.</p>
                        <div v-if="webhookUrl" class="mt-3 pt-3 border-t border-[var(--md-sys-color-outline-variant)]">
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-2">
                                GET this URL (swap <code class="font-mono bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface)] px-1 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">{run}</code> for the <code class="font-mono bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface)] px-1 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">run_id</code> returned by the trigger call, also available as <code class="font-mono bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface)] px-1 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">status_url</code> in that response) to poll for pass/fail so your CI job doesn't just stop at the initial 202 Accepted.
                            </p>
                            <CopyableSecret :value="statusUrlTemplate" />
                        </div>
                    </div>
                </Card>

                <!-- Schedule -->
                <Card padding="p-0" class="mt-6">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)] flex items-center justify-between">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">Schedule</h2>
                        <div v-if="can.manageSchedule" class="flex items-center gap-1">
                            <Button v-if="suite.schedule" variant="text" size="sm" @click="removeSchedule" class="!text-[var(--md-sys-color-error)]">
                                Remove
                            </Button>
                            <Button variant="text" size="sm" @click="openScheduleModal">
                                {{ suite.schedule ? 'Edit' : 'Add' }}
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
                                    {{ suite.schedule.is_enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </div>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">Timezone: {{ suite.schedule.timezone }}</p>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">Next run: {{ formatDate(suite.schedule.next_run_at) }}</p>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">Last run: {{ formatDate(suite.schedule.last_run_at) }}</p>
                        </template>
                        <p v-else class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">No schedule configured. Add a crontab expression to run this suite automatically.</p>
                    </div>
                </Card>

                <!-- Recent runs -->
                <Card padding="p-0" class="mt-6">
                    <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">Recent Runs</h2>
                    </div>

                    <div v-if="!recentRuns.length" class="px-5 py-6 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                        No runs yet.
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
                                    View Run &rarr;
                                </Link>
                            </div>
                            <div class="flex items-center gap-3 md-body-small text-[var(--md-sys-color-on-surface-variant)]">
                                <span class="text-[var(--md-ext-color-success)]">{{ run.passed_count ?? 0 }} passed</span>
                                <span class="text-[var(--md-sys-color-error)]">{{ run.failed_count ?? 0 }} failed</span>
                                <span>{{ formatDuration(run.duration_ms) }}</span>
                            </div>
                            <div class="flex items-center justify-between mt-0.5">
                                <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ formatDate(run.created_at) }}</p>
                                <RanBy :triggered-by="run.triggered_by" :triggered-by-user="run.triggered_by_user" />
                            </div>
                        </div>
                    </div>
                </Card>
            </div>
        </div>

        <!-- Edit Suite Modal -->
        <Modal :show="showEditModal" title="Edit Suite" max-width="max-w-lg" @close="showEditModal = false">
                    <form @submit.prevent="submitEdit" class="px-6 py-5 space-y-4">
                        <TextField v-model="editForm.name" label="Suite Name" required :error="editForm.errors.name" />
                        <TextField v-model="editForm.description" label="Description" type="textarea" :rows="3" />
                        <TextField
                            v-model="editForm.playwright_proxy"
                            label="Default HTTP Proxy"
                            placeholder="http://proxy.example.com:8080"
                            hint="Used for any request that doesn't match a per-host rule below. Leave empty for direct connection."
                            :error="editForm.errors.playwright_proxy"
                        />
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)]">Per-Host Proxy Rules</label>
                                <Button type="button" variant="text" size="sm" @click="addProxyRule">+ Add Rule</Button>
                            </div>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-1">
                                Routes requests through a specific proxy when the hostname matches a regular expression, overriding the default above. Checked on every request, including each hop of a redirect chain.
                            </p>
                            <ul class="md-body-small text-[var(--md-sys-color-on-surface-variant)] list-disc pl-5 mb-2 space-y-0.5">
                                <li><code class="font-mono">^example\.com$</code> — <strong>exact host only</strong>, e.g. matches <code class="font-mono">example.com</code> but not <code class="font-mono">foo.example.com</code>.</li>
                                <li><code class="font-mono">(^|\.)example\.com$</code> — <strong>host or any subdomain</strong>, e.g. matches <code class="font-mono">example.com</code> and <code class="font-mono">foo.example.com</code>, but not <code class="font-mono">notexample.com</code>.</li>
                                <li><code class="font-mono">example\.com$</code> — avoid: also matches unrelated hosts like <code class="font-mono">notexample.com</code>.</li>
                            </ul>
                            <div v-if="!editForm.proxy_rules.length" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] py-2">
                                No rules configured — all requests use the default proxy above (or connect directly).
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
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">Browser</label>
                                <select v-model="editForm.browser" class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent">
                                    <option value="chromium">Chromium</option>
                                    <option value="firefox">Firefox</option>
                                    <option value="webkit">WebKit</option>
                                </select>
                                <p v-if="editForm.errors.browser" class="text-[var(--md-sys-color-error)] md-body-small mt-1.5">{{ editForm.errors.browser }}</p>
                            </div>
                            <div>
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">Mode</label>
                                <select v-model="editForm.headless" class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent">
                                    <option :value="true">Headless</option>
                                    <option :value="false">Headed (visible)</option>
                                </select>
                                <p v-if="editForm.errors.headless" class="text-[var(--md-sys-color-error)] md-body-small mt-1.5">{{ editForm.errors.headless }}</p>
                            </div>
                            <div>
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">Keep History</label>
                                <select v-model="editForm.history_retention" class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent">
                                    <option :value="3">Last 3 runs</option>
                                    <option :value="5">Last 5 runs</option>
                                    <option :value="10">Last 10 runs</option>
                                </select>
                                <p v-if="editForm.errors.history_retention" class="text-[var(--md-sys-color-error)] md-body-small mt-1.5">{{ editForm.errors.history_retention }}</p>
                            </div>
                        </div>
                        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">Older runs (and their screenshots) are deleted automatically per test.</p>
                        <div class="grid grid-cols-3 gap-4">
                            <div>
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">Timeout</label>
                                <select v-model="editForm.timeout_ms" class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent">
                                    <option :value="10000">10 seconds</option>
                                    <option :value="30000">30 seconds</option>
                                    <option :value="60000">60 seconds</option>
                                    <option :value="120000">2 minutes</option>
                                </select>
                                <p v-if="editForm.errors.timeout_ms" class="text-[var(--md-sys-color-error)] md-body-small mt-1.5">{{ editForm.errors.timeout_ms }}</p>
                            </div>
                            <div>
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">Screenshots</label>
                                <select v-model="editForm.take_screenshot" class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent">
                                    <option :value="true">Enabled</option>
                                    <option :value="false">Disabled (faster runs)</option>
                                </select>
                                <p v-if="editForm.errors.take_screenshot" class="text-[var(--md-sys-color-error)] md-body-small mt-1.5">{{ editForm.errors.take_screenshot }}</p>
                            </div>
                            <div>
                                <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">Retries</label>
                                <select v-model="editForm.max_retries" class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 text-[var(--md-sys-color-on-surface)] md-body-medium focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent">
                                    <option :value="0">No retries</option>
                                    <option :value="1">Retry once</option>
                                    <option :value="2">Retry twice</option>
                                    <option :value="3">Retry 3 times</option>
                                </select>
                                <p v-if="editForm.errors.max_retries" class="text-[var(--md-sys-color-error)] md-body-small mt-1.5">{{ editForm.errors.max_retries }}</p>
                            </div>
                        </div>
                        <div class="pt-2 border-t border-[var(--md-sys-color-outline-variant)]">
                            <TextField
                                v-model="editForm.teams_webhook_url"
                                label="MS Teams Webhook URL"
                                hint="Incoming webhook or workflow URL for a Teams channel. Leave empty to disable."
                                :error="editForm.errors.teams_webhook_url"
                            />
                            <TextField
                                v-model="editForm.teams_webhook_proxy"
                                label="MS Teams Webhook Proxy"
                                hint="Optional proxy used only for the Teams webhook call. Independent of the HTTP Proxy above. Leave empty for direct connection."
                                placeholder="http://proxy.example.com:8080"
                                class="mt-4"
                                :error="editForm.errors.teams_webhook_proxy"
                            />
                            <div class="flex items-center gap-4 mt-2.5">
                                <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                    <input type="checkbox" v-model="editForm.teams_notify_on_success" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer" /> Notify on success
                                </label>
                                <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                                    <input type="checkbox" v-model="editForm.teams_notify_on_failure" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer" /> Notify on failure
                                </label>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="text" @click="showEditModal = false">Cancel</Button>
                            <Button type="submit" variant="filled" :disabled="editForm.processing">
                                {{ editForm.processing ? 'Saving...' : 'Save Changes' }}
                            </Button>
                        </div>
                    </form>
        </Modal>

        <!-- Schedule Modal -->
        <Modal :show="showScheduleModal" title="Schedule" max-width="max-w-md" @close="showScheduleModal = false">
                    <form @submit.prevent="submitSchedule" class="px-6 py-5 space-y-4">
                        <TextField
                            v-model="scheduleForm.cron_expression"
                            label="Crontab expression"
                            placeholder="0 */6 * * *"
                            hint="Standard 5-field crontab syntax (minute hour day month weekday), e.g. '0 */6 * * *' runs every 6 hours."
                            required
                            :error="scheduleForm.errors.cron_expression"
                        />
                        <TextField
                            v-model="scheduleForm.timezone"
                            label="Timezone"
                            placeholder="UTC"
                            hint="e.g. UTC, Asia/Tokyo, America/New_York"
                            :error="scheduleForm.errors.timezone"
                        />
                        <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                            <input type="checkbox" v-model="scheduleForm.is_enabled" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer" /> Enabled
                        </label>
                        <div class="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="text" @click="showScheduleModal = false">Cancel</Button>
                            <Button type="submit" variant="filled" :disabled="scheduleForm.processing">
                                {{ scheduleForm.processing ? 'Saving...' : 'Save Schedule' }}
                            </Button>
                        </div>
                    </form>
        </Modal>

        <!-- Manage Users Modal -->
        <Modal :show="showManageUsersModal" title="Manage Users" max-width="max-w-2xl" @close="showManageUsersModal = false">
                    <div class="px-6 py-5 space-y-4">
                        <table class="w-full">
                            <thead>
                                <tr class="text-left border-b border-[var(--md-sys-color-outline-variant)]">
                                    <th class="pb-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">User</th>
                                    <th class="pb-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider text-center">View</th>
                                    <th class="pb-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider text-center">Edit</th>
                                    <th class="pb-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider text-center">Delete</th>
                                    <th class="pb-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider text-center">Run</th>
                                    <th class="pb-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                                <tr v-for="member in members" :key="member.id">
                                    <td class="py-2 md-body-medium text-[var(--md-sys-color-on-surface)]">
                                        {{ member.name }}
                                        <span v-if="member.is_view_only" class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">(view only)</span>
                                        <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ member.email }}</p>
                                    </td>
                                    <td class="py-2 text-center">
                                        <input type="checkbox" v-model="member.can_view" @change="updateMemberPrivilege(member, 'can_view')" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer" />
                                    </td>
                                    <td class="py-2 text-center">
                                        <input type="checkbox" v-model="member.can_edit" :disabled="member.is_view_only" :title="member.is_view_only ? 'This user is view-only; edit is unavailable.' : null" @change="updateMemberPrivilege(member, 'can_edit')" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer disabled:cursor-not-allowed disabled:opacity-40" />
                                    </td>
                                    <td class="py-2 text-center">
                                        <input type="checkbox" v-model="member.can_delete" :disabled="member.is_view_only" :title="member.is_view_only ? 'This user is view-only; delete is unavailable.' : null" @change="updateMemberPrivilege(member, 'can_delete')" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer disabled:cursor-not-allowed disabled:opacity-40" />
                                    </td>
                                    <td class="py-2 text-center">
                                        <input type="checkbox" v-model="member.can_run" :disabled="member.is_view_only" :title="member.is_view_only ? 'This user is view-only; run is unavailable.' : null" @change="updateMemberPrivilege(member, 'can_run')" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer disabled:cursor-not-allowed disabled:opacity-40" />
                                    </td>
                                    <td class="py-2 text-right">
                                        <button @click="removeMember(member)" class="md-label-small text-[var(--md-sys-color-error)] hover:underline">Remove</button>
                                    </td>
                                </tr>
                                <tr v-if="!members.length">
                                    <td colspan="6" class="py-4 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">No users have been added yet.</td>
                                </tr>
                            </tbody>
                        </table>

                        <p v-if="newMemberForm.errors.member" class="text-[var(--md-sys-color-error)] md-body-small">{{ newMemberForm.errors.member }}</p>

                        <form v-if="candidates.length" @submit.prevent="submitAddMember" class="flex items-end gap-3 pt-2 border-t border-[var(--md-sys-color-outline-variant)]">
                            <Autocomplete
                                v-model="newMemberForm.user_id"
                                :options="candidates"
                                value-key="id"
                                :emit-on-input="false"
                                label="Add user"
                                placeholder="Search by name or email…"
                                :error="newMemberForm.errors.user_id"
                                class="w-64"
                            />
                            <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)] pb-2.5">
                                <input type="checkbox" v-model="newMemberForm.can_view" class="w-4 h-4 accent-[var(--md-sys-color-primary)]" /> View
                            </label>
                            <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)] pb-2.5">
                                <input type="checkbox" v-model="newMemberForm.can_edit" :disabled="selectedCandidateIsViewOnly" class="w-4 h-4 accent-[var(--md-sys-color-primary)] disabled:opacity-40" /> Edit
                            </label>
                            <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)] pb-2.5">
                                <input type="checkbox" v-model="newMemberForm.can_delete" :disabled="selectedCandidateIsViewOnly" class="w-4 h-4 accent-[var(--md-sys-color-primary)] disabled:opacity-40" /> Delete
                            </label>
                            <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)] pb-2.5">
                                <input type="checkbox" v-model="newMemberForm.can_run" :disabled="selectedCandidateIsViewOnly" class="w-4 h-4 accent-[var(--md-sys-color-primary)] disabled:opacity-40" /> Run
                            </label>
                            <Button type="submit" variant="filled" size="sm" :disabled="newMemberForm.processing" class="mb-2.5">Add</Button>
                        </form>
                        <p v-else class="md-body-small text-[var(--md-sys-color-on-surface-variant)] pt-2 border-t border-[var(--md-sys-color-outline-variant)]">All users already have access to this suite.</p>
                    </div>
                    <template #footer>
                        <Button variant="text" @click="showManageUsersModal = false">Close</Button>
                    </template>
        </Modal>

        <!-- New Test Modal -->
        <Modal :show="showTestModal" title="New Test" max-width="max-w-md" @close="showTestModal = false">
                    <form @submit.prevent="submitTest" class="px-6 py-5 space-y-4">
                        <TextField v-model="testForm.name" label="Test Name *" placeholder="Login flow test" required :error="testForm.errors.name" />
                        <TextField
                            v-model="testForm.description"
                            label="Description"
                            type="textarea"
                            :rows="3"
                            placeholder="Describe what this test checks..."
                            :error="testForm.errors.description"
                        />
                        <Autocomplete
                            v-model="testForm.uploaded_by"
                            :options="users"
                            label="Uploaded by"
                            :error="testForm.errors.uploaded_by"
                        />
                        <div class="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="text" @click="showTestModal = false">Cancel</Button>
                            <Button type="submit" variant="filled" :disabled="testForm.processing">
                                {{ testForm.processing ? 'Creating...' : 'Create Test' }}
                            </Button>
                        </div>
                    </form>
        </Modal>
    </AppLayout>
</template>

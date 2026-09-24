<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import TestCodeEditor from '@/Components/TestCodeEditor.vue';
import CopyButton from '@/Components/CopyButton.vue';
import ScreenshotGallery from '@/Components/ScreenshotGallery.vue';
import { Card, Chip, Button, TextField, Autocomplete, Breadcrumb, SuiteName, RanBy, Avatar, ScreenshotThumbs, ScreenshotLightbox, Pagination, MarkdownRenderer, Tooltip } from '@/Components/ui';
import { formatDate } from '@/utils/date';
import { useScreenshotLightbox } from '@/composables/useScreenshotLightbox';
import { openAgentDrawer } from '@/composables/useAgentDrawer.js';
import AiButton from '@/Components/Agent/AiButton.vue';
import GatewayPromptButton from '@/Components/Agent/GatewayPromptButton.vue';
import { FlaskConical, Copy, LoaderCircle, Trash2, Play, CircleAlert, X, ChevronRight, ChevronDown, ChevronUp, ArrowLeft, ArrowRight, History, Code, Activity, Bot } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    suite: { type: Object, required: true },
    test: { type: Object, required: true },
    users: { type: Array, default: () => [] },
    history: { type: Object, default: () => ({ data: [], links: [], meta: {} }) },
    codeVersions: { type: Object, default: () => ({ data: [], links: [], meta: {} }) },
    codeVersionRetention: { type: Number, default: 10 },
    can: { type: Object, default: () => ({}) },
});

// Edit form
const editForm = useForm({
    name: props.test.name ?? '',
    description: props.test.description ?? '',
    uploaded_by: props.test.uploaded_by ?? '',
});

const editMode = ref(false);

// Suite variable chips: only the first few are shown until expanded.
const VARIABLE_PREVIEW_COUNT = 6;
const showAllVariables = ref(false);
const visibleVariables = computed(() => {
    const variables = props.suite.variables ?? [];
    return showAllVariables.value ? variables : variables.slice(0, VARIABLE_PREVIEW_COUNT);
});

// Inline editing of suite variables — the same PUT the suite settings
// page uses, so server-side validation and authorization are identical.
const variablesEditable = ref(false);
const variableRows = ref([]);
const variablesForm = useForm({ variables: [] });
const variablesSaved = ref(false);
let variablesSavedTimer = null;

function startVariableEdit() {
    variableRows.value = (props.suite.variables ?? []).map(v => ({ key: v.key, value: v.value ?? '' }));
    variablesForm.clearErrors();
    variablesEditable.value = true;
}

function addVariableRow() {
    variableRows.value.push({ key: '', value: '' });
}

function removeVariableRow(index) {
    variableRows.value.splice(index, 1);
}

const variablesError = computed(() => Object.values(variablesForm.errors ?? {})[0] ?? null);

function saveVariables() {
    variablesForm.variables = variableRows.value
        .filter(v => v.key.trim())
        .map(v => ({ key: v.key.trim(), value: v.value }));

    variablesForm.put(`/sorify/suites/${props.suite.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            variablesEditable.value = false;
            variablesSaved.value = true;
            clearTimeout(variablesSavedTimer);
            variablesSavedTimer = setTimeout(() => { variablesSaved.value = false; }, 2000);
        },
    });
}

function uploader(email) {
    const user = props.users.find(u => u.email === email);
    return { name: user?.name ?? email, email, avatar_url: user?.avatar_url ?? null };
}

function saveEdit() {
    editForm.put(`/sorify/suites/${props.suite.id}/tests/${props.test.id}`, {
        onSuccess: () => { editMode.value = false; },
    });
}

// Code editor
const codeForm = useForm({
    playwright_code: props.test.playwright_code ?? '',
});

const codeEditable = ref(false);

// ── AI actions ───────────────────────────────────────────────────────────────
// The drawer's context block is capped server-side (8000 chars), so the
// code is included truncated; the agent can fetch the full test through
// its tools when it needs more.
function aiCodeContext() {
    const code = codeForm.playwright_code ?? '';

    return JSON.stringify({
        page: 'test_detail',
        suite_id: props.suite.id,
        suite_name: props.suite.name,
        test_id: props.test.id,
        test_name: props.test.name,
        description: props.test.description ?? null,
        playwright_code: code.length > 6000 ? `${code.slice(0, 6000)}\n… (truncated)` : code,
    }, null, 2);
}

function explainCode() {
    openAgentDrawer({
        context: aiCodeContext(),
        message: t('agent.prompts.explainCode', { name: props.test.name }),
    });
}

function updateDescription() {
    openAgentDrawer({
        context: aiCodeContext(),
        message: t('agent.prompts.updateDescription', { name: props.test.name }),
    });
}

// Gateway copy button: the same prompts the AI buttons send to the
// in-app agent, but copied as a /sorify:gateway command for a local
// coding agent. This test's page is included so the local agent can
// look everything up through its Sorify tools.
const gatewayTestUrl = computed(() => `${window.location.origin}/sorify/suites/${props.suite.id}/tests/${props.test.id}`);

// Attribution of the current code: which surface last wrote it, with the
// AI model name when known.
const codeSourceLabel = computed(() => {
    if (props.test.code_source === 'agent') return t('testShow.sourceAgent');
    if (props.test.code_source === 'mcp') return t('testShow.sourceMcp');
    if (props.test.code_source === 'manual') return t('testShow.sourceManual');

    return '';
});
const codeSaved = ref(false);

function saveCode() {
    codeForm.put(`/sorify/suites/${props.suite.id}/tests/${props.test.id}/code`, {
        onSuccess: () => {
            codeEditable.value = false;
            codeSaved.value = true;
            setTimeout(() => { codeSaved.value = false; }, 2000);
        },
    });
}

// Version history — each row expands its own code block independently.
const expandedVersions = ref(new Set());
const restoringVersionId = ref(null);

function toggleVersion(id) {
    if (expandedVersions.value.has(id)) {
        expandedVersions.value.delete(id);
    } else {
        expandedVersions.value.add(id);
    }
}

function isVersionExpanded(id) {
    return expandedVersions.value.has(id);
}

function restoreVersion(version) {
    if (!confirm(t('testShow.confirmRestore', { version: version.version_number }))) return;

    restoringVersionId.value = version.id;
    router.post(
        `/sorify/suites/${props.suite.id}/tests/${props.test.id}/code-versions/${version.id}/restore`,
        {},
        {
            onSuccess: () => {
                codeForm.playwright_code = props.test.playwright_code ?? '';
            },
            onFinish: () => { restoringVersionId.value = null; },
        },
    );
}

// Run this test
const running = ref(false);
const runError = ref(null);

function deleteTest() {
    if (!confirm(t('testShow.confirmDelete', { name: props.test.name }))) return;
    router.delete(`/sorify/suites/${props.suite.id}/tests/${props.test.id}`);
}

// ── Duplicate test ─────────────────────────────────────────────────────────
const duplicating = ref(false);

function duplicateTest() {
    duplicating.value = true;
    router.post(
        `/sorify/suites/${props.suite.id}/tests/${props.test.id}/duplicate`,
        {},
        {
            onFinish: () => { duplicating.value = false; },
        },
    );
}

function runTest() {
    running.value = true;
    runError.value = null;
    router.post(
        `/sorify/suites/${props.suite.id}/runs`,
        { test_ids: [props.test.id] },
        {
            async: true,
            onError: () => { runError.value = t('testShow.runFailedToStart'); },
            onFinish: () => { running.value = false; },
        },
    );
}

function formatDuration(ms) {
    if (!ms && ms !== 0) return '—';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}

// Screenshot lightbox
const lightbox = useScreenshotLightbox();

// History navigation — `history.data` is ordered newest-first (index 0 = most recent) within the current page.
const activeHistoryIndex = ref(null);
const showHistoryStdout = ref(false);

const activeHistoryItem = computed(() =>
    activeHistoryIndex.value !== null ? props.history.data[activeHistoryIndex.value] : null,
);

function openHistory(index) {
    activeHistoryIndex.value = index;
    showHistoryStdout.value = false;
}

function closeHistory() {
    activeHistoryIndex.value = null;
}

function prevHistory() {
    if (activeHistoryIndex.value < props.history.data.length - 1) {
        activeHistoryIndex.value++;
        showHistoryStdout.value = false;
    }
}

function nextHistory() {
    if (activeHistoryIndex.value > 0) {
        activeHistoryIndex.value--;
        showHistoryStdout.value = false;
    }
}

function onHistoryKeydown(e) {
    if (activeHistoryIndex.value === null) return;
    if (e.key === 'ArrowLeft') prevHistory();
    if (e.key === 'ArrowRight') nextHistory();
    if (e.key === 'Escape') closeHistory();
}
</script>

<template>
    <AppLayout>
        <Head :title="test.name" />

        <!-- Test header -->
        <div class="flex items-start justify-between mb-6">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-1.5">
                    <span class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-ext-color-on-success-container)] bg-[var(--md-ext-color-success-container)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] flex-shrink-0">{{ t('testShow.testCase') }}</span>
                    <Breadcrumb :crumbs="[
                        { label: t('testSuites.title'), href: '/sorify/suites' },
                        { label: suite.name, href: `/sorify/suites/${suite.id}`, suite: true },
                        { label: test.name },
                    ]">
                        <template #crumb="{ crumb }">
                            <SuiteName v-if="crumb.suite" :name="crumb.label" />
                            <template v-else>{{ crumb.label }}</template>
                        </template>
                    </Breadcrumb>
                </div>
                <template v-if="!editMode">
                    <span v-if="test.uploaded_by" class="inline-flex items-center gap-1.5 mb-1.5">
                        <Avatar :name="uploader(test.uploaded_by).name" :email="uploader(test.uploaded_by).email" :avatar-url="uploader(test.uploaded_by).avatar_url" />
                        <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('testShow.uploadedBy', { name: uploader(test.uploaded_by).name }) }}</span>
                    </span>
                    <div class="flex items-center gap-3 flex-wrap">
                        <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5">
                            <FlaskConical :size="26" :style="{ color: 'var(--md-sys-color-tertiary)' }" />
                            {{ test.name }}
                        </h1>
                        <Chip v-if="test.status" :status="test.status" />
                    </div>
                </template>

                <template v-else>
                    <form @submit.prevent="saveEdit" class="space-y-3 max-w-xl">
                        <TextField v-model="editForm.name" :label="t('testShow.testName')" :error="editForm.errors.name" />
                        <TextField v-model="editForm.description" :label="t('testShow.description')" type="textarea" :rows="4" mono :hint="t('testShow.markdownSupported')" :error="editForm.errors.description" />
                        <Autocomplete v-model="editForm.uploaded_by" :options="users" :label="t('testShow.uploadedByLabel')" :error="editForm.errors.uploaded_by" />
                        <div class="flex gap-2">
                            <Button type="submit" variant="filled" size="sm" :disabled="editForm.processing">
                                {{ editForm.processing ? t('testShow.saving') : t('testShow.save') }}
                            </Button>
                            <Button type="button" variant="text" size="sm" @click="editMode = false">{{ t('testShow.cancel') }}</Button>
                        </div>
                    </form>
                </template>
            </div>

            <div class="flex items-center gap-2 ml-4 flex-shrink-0">
                <Button v-if="!editMode" variant="tonal" @click="editMode = true">{{ t('testShow.edit') }}</Button>
                <Button v-if="!editMode" variant="tonal" :disabled="duplicating" @click="duplicateTest">
                    <template #leading>
                        <LoaderCircle v-if="duplicating" :size="16" class="animate-spin" />
                        <Copy v-else :size="16" />
                    </template>
                    {{ duplicating ? t('testShow.duplicating') : t('testShow.duplicate') }}
                </Button>
                <Button v-if="!editMode" variant="tonal" @click="deleteTest" class="!text-[var(--md-sys-color-error)]">
                    <template #leading>
                        <Trash2 :size="16" />
                    </template>
                    {{ t('testShow.delete') }}
                </Button>
                <Button
                    variant="filled"
                    @click="runTest"
                    :disabled="running || !test.playwright_code"
                    :title="!test.playwright_code ? t('testShow.noCodeTitle') : t('testShow.runThisTest')"
                >
                    <template #leading>
                        <LoaderCircle v-if="running" :size="16" class="animate-spin" />
                        <Play v-else :size="16" />
                    </template>
                    {{ running ? t('testShow.starting') : t('testShow.runTest') }}
                </Button>
            </div>
        </div>

        <!-- Run error banner -->
        <div v-if="runError" class="mb-6 flex items-start gap-3 bg-[var(--md-sys-color-error-container)] rounded-[var(--md-sys-shape-corner-medium)] px-5 py-4">
            <CircleAlert :size="16" :style="{ color: 'var(--md-sys-color-on-error-container)' }" class="mt-0.5 flex-shrink-0" />
            <div class="flex-1 min-w-0">
                <p class="md-body-medium font-medium text-[var(--md-sys-color-on-error-container)]">{{ t('testShow.runFailedTitle') }}</p>
                <p class="md-body-small text-[var(--md-sys-color-on-error-container)] mt-0.5">{{ runError }}</p>
            </div>
            <button @click="runError = null" class="text-[var(--md-sys-color-on-error-container)] flex-shrink-0 transition-colors">
                <X :size="16" />
            </button>
        </div>

        <!-- Description (left) + Playwright code (right) — review-page layout.
             Square corners: the header/columns run flush to the card's
             edges without clipping. -->
        <Card padding="p-0" class="mb-6 overflow-hidden" square>
            <div class="grid grid-cols-1 lg:grid-cols-5">
                <!-- Left: description, always fully expanded. -->
                <div class="lg:col-span-2 px-5 py-4 border-b lg:border-b-0 lg:border-r border-[var(--md-sys-color-outline-variant)] bg-[var(--md-sys-color-surface-container-lowest)] min-w-0">
                    <div class="flex items-center justify-between gap-2 mb-1.5">
                        <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">{{ t('testShow.description') }}</p>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <AiButton :label="t('agent.buttons.updateDescription')" size="xs" @click="updateDescription" />
                            <GatewayPromptButton :message="t('agent.prompts.updateDescription', { name: test.name })" :url="gatewayTestUrl" size="xs" />
                        </div>
                    </div>
                    <MarkdownRenderer v-if="test.description" :content="test.description" density="compact" />
                    <p v-else class="md-body-small italic text-[var(--md-sys-color-on-surface-variant)]">{{ t('testShow.noDescription') }}</p>
                </div>

                <!-- Right: Playwright code editor -->
                <div class="lg:col-span-3 min-w-0">
                    <div class="flex items-center justify-between px-5 py-3 border-b border-[var(--md-sys-color-outline-variant)]">
                        <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] flex items-center gap-2 min-w-0">
                            <Code :size="18" :style="{ color: 'var(--md-sys-color-primary)' }" class="flex-shrink-0" />
                            {{ t('testShow.playwrightCode') }}
                            <!-- Model badge: truncated to fit; full info on hover (below, into the card body) -->
                            <Tooltip
                                v-if="test.code_ai_model"
                                :text="`${t('testShow.codeByTooltip')}: ${test.code_ai_model} · ${codeSourceLabel}`"
                                placement="bottom"
                                class="min-w-0"
                            >
                                <span class="inline-flex items-center gap-1 min-w-0 max-w-[10rem] md-label-small font-mono text-[var(--md-sys-color-on-tertiary-container)] bg-[var(--md-sys-color-tertiary-container)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">
                                    <Bot :size="12" class="flex-shrink-0" />
                                    <span class="truncate">{{ test.code_ai_model }} · {{ codeSourceLabel }}</span>
                                </span>
                            </Tooltip>
                            <span
                                v-else-if="test.code_source"
                                class="md-label-small text-[var(--md-sys-color-on-surface-variant)] bg-[var(--md-sys-color-surface-container-high)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]"
                            >{{ codeSourceLabel }}</span>
                        </h2>
                        <div class="flex items-center gap-2">
                            <span v-if="codeSaved" class="md-label-small text-[var(--md-ext-color-success)]">{{ t('testShow.saved') }}</span>
                            <AiButton :label="t('agent.buttons.explainCode')" size="xs" @click="explainCode" />
                            <GatewayPromptButton :message="t('agent.prompts.explainCode', { name: test.name })" :url="gatewayTestUrl" size="xs" />
                            <CopyButton v-if="codeForm.playwright_code" :value="codeForm.playwright_code" :label="t('testShow.copyCode')" />
                            <template v-if="!codeEditable">
                                <Button variant="tonal" size="sm" @click="codeEditable = true">{{ t('testShow.edit') }}</Button>
                            </template>
                            <template v-else>
                                <Button variant="filled" size="sm" @click="saveCode" :disabled="codeForm.processing">
                                    {{ codeForm.processing ? t('testShow.saving') : t('testShow.saveCode') }}
                                </Button>
                                <Button variant="text" size="sm" @click="codeEditable = false">{{ t('testShow.cancel') }}
                                </Button>
                            </template>
                        </div>
                    </div>
        
                    <!-- Suite variables available in this test's scope -->
                    <div v-if="can.edit || (suite.variables ?? []).length" class="px-5 py-3 border-b border-[var(--md-sys-color-outline-variant)] bg-[var(--md-sys-color-surface-container-lowest)]">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">{{ t('testShow.suiteVariables') }}</p>
                            <div class="flex items-center gap-1.5">
                                <span v-if="variablesSaved" class="md-label-small text-[var(--md-ext-color-success)]">{{ t('testShow.saved') }}</span>
                                <template v-if="!variablesEditable">
                                    <Button v-if="can.edit" variant="tonal" size="sm" @click="startVariableEdit">{{ t('testShow.editVariables') }}</Button>
                                </template>
                                <template v-else>
                                    <Button variant="text" size="sm" @click="addVariableRow">{{ t('testShow.addVariable') }}</Button>
                                    <Button variant="filled" size="sm" :disabled="variablesForm.processing" @click="saveVariables">
                                        {{ variablesForm.processing ? t('testShow.saving') : t('testShow.saveVariables') }}
                                    </Button>
                                    <Button variant="text" size="sm" @click="variablesEditable = false">{{ t('testShow.cancel') }}</Button>
                                </template>
                            </div>
                        </div>

                        <!-- Read-only chips -->
                        <template v-if="!variablesEditable">
                            <div class="grid grid-cols-2 gap-1.5 mb-1.5">
                                <div
                                    v-for="variable in visibleVariables"
                                    :key="variable.key"
                                    class="flex items-center gap-1.5 min-w-0 bg-[var(--md-sys-color-surface-container-high)] border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-extra-small)] px-2 py-1"
                                >
                                    <code class="md-label-small font-mono font-semibold text-[var(--md-sys-color-primary)] truncate">{{ variable.key }}</code>
                                    <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">=</span>
                                    <!-- Truncated in the chip; the full key = value shows on hover -->
                                    <Tooltip :text="`${variable.key} = ${variable.value || '∅'}`" class="min-w-0 flex-1">
                                        <code class="md-label-small font-mono text-[var(--md-sys-color-on-surface-variant)] truncate">{{ variable.value || '∅' }}</code>
                                    </Tooltip>
                                </div>
                            </div>
                            <p v-if="!(suite.variables ?? []).length" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] opacity-70">
                                {{ t('testShow.variablesHint') }}
                            </p>
                            <button
                                v-if="(suite.variables ?? []).length > VARIABLE_PREVIEW_COUNT"
                                @click="showAllVariables = !showAllVariables"
                                class="inline-flex items-center gap-1 md-label-small text-[var(--md-sys-color-primary)] hover:underline"
                            >
                                {{ showAllVariables
                                    ? t('testShow.showFewerVariables')
                                    : t('testShow.showAllVariables', { count: suite.variables.length }) }}
                                <component :is="showAllVariables ? ChevronUp : ChevronDown" :size="14" />
                            </button>
                        </template>

                        <!-- Inline editor -->
                        <template v-else>
                            <p v-if="!variableRows.length" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] opacity-70 mb-1.5">
                                {{ t('testShow.noVariablesYet') }}
                            </p>
                            <div v-for="(row, index) in variableRows" :key="index" class="flex items-start gap-2 mb-1.5">
                                <input
                                    v-model="row.key"
                                    type="text"
                                    placeholder="VARIABLE_NAME"
                                    class="w-2/5 bg-[var(--md-sys-color-surface)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                                />
                                <input
                                    v-model="row.value"
                                    type="text"
                                    placeholder="value"
                                    class="flex-1 bg-[var(--md-sys-color-surface)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                                />
                                <button
                                    type="button"
                                    @click="removeVariableRow(index)"
                                    class="p-1.5 text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-error)] transition-colors"
                                >
                                    <Trash2 :size="14" />
                                </button>
                            </div>
                            <p v-if="variablesError" class="md-body-small text-[var(--md-sys-color-error)]">{{ variablesError }}</p>
                        </template>
                    </div>
        
                    <div class="p-1">
                        <TestCodeEditor
                            v-model:code="codeForm.playwright_code"
                            :editable="codeEditable"
                            :variables="suite.variables ?? []"
                        />
                        <p v-if="codeForm.errors.playwright_code" class="text-[var(--md-sys-color-error)] md-body-small px-4 pt-2">{{ codeForm.errors.playwright_code }}</p>
                    </div>
                </div>
            </div>
        </Card>

        <!-- Run history -->
        <Card padding="p-0" class="mb-6">
            <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] flex items-center gap-2">
                    <Activity :size="18" :style="{ color: 'var(--md-ext-color-success)' }" />
                    {{ t('testShow.runHistory') }}
                </h2>
                <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)] mt-0.5">
                    {{ t('testShow.historyRetentionCaption', { count: suite.history_retention ?? 5 }) }}
                    <Link :href="`/sorify/suites/${suite.id}`" class="text-[var(--md-sys-color-primary)] hover:underline">{{ t('testShow.historyRetentionLink') }}</Link>
                </p>
            </div>

            <div v-if="!history.data.length" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                <Activity :size="32" class="mx-auto mb-3 opacity-40" />
                {{ t('testShow.noRunsYet') }}
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-[var(--md-sys-color-surface-container-low)]">
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testShow.colDate') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testShow.colStatus') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testShow.colDuration') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testShow.colScreenshots') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testShow.colRanBy') }}</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <tr
                            v-for="(item, index) in history.data"
                            :key="item.id"
                            class="hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors cursor-pointer"
                            :class="{ 'bg-[var(--md-sys-color-surface-container-low)]': activeHistoryIndex === index }"
                            @click="openHistory(index)"
                        >
                            <td class="px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ formatDate(item.created_at) }}</td>
                            <td class="px-5 py-3">
                                <Chip :status="item.status" />
                            </td>
                            <td class="px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ formatDuration(item.duration_ms) }}</td>
                            <td class="px-5 py-3" @click.stop>
                                <ScreenshotThumbs :screenshots="item.screenshots ?? []" @open="lightbox.open" />
                            </td>
                            <td class="px-5 py-3" @click.stop>
                                <RanBy :triggered-by="item.triggered_by" :triggered-by-user="item.triggered_by_user" :ci-ip="item.ci_ip" :ci-user-agent="item.ci_user_agent" />
                            </td>
                            <td class="px-5 py-3 text-right">
                                <Link
                                    v-if="item.run_id"
                                    :href="`/sorify/runs/${item.run_id}`"
                                    class="md-label-small text-[var(--md-sys-color-primary)] hover:underline"
                                    @click.stop
                                >
                                    {{ t('testSuiteShow.viewRun') }}<span v-if="item.run_total_tests != null"> ({{ item.run_total_tests }} {{ item.run_total_tests === 1 ? t('testSuiteShow.test') : t('testSuiteShow.testsPlural') }})</span>
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination
                v-if="history.data.length"
                :paginator="history"
                :label="t('testShow.showingHistory', { from: history.from ?? 0, to: history.to ?? 0, total: history.total })"
            />

            <!-- History detail panel -->
            <div
                v-if="activeHistoryItem"
                class="border-t border-[var(--md-sys-color-outline-variant)] px-5 py-5"
                tabindex="-1"
                @keydown="onHistoryKeydown"
            >
                <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                    <div class="flex items-center gap-3">
                        <Chip :status="activeHistoryItem.status" />
                        <span class="md-body-medium text-[var(--md-sys-color-on-surface)]">{{ formatDate(activeHistoryItem.created_at) }}</span>
                        <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ formatDuration(activeHistoryItem.duration_ms) }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] mr-1">{{ activeHistoryIndex + 1 }} / {{ history.data.length }}</span>
                        <button
                            :disabled="activeHistoryIndex >= history.data.length - 1"
                            @click="prevHistory"
                            :title="t('testShow.olderRun')"
                            class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-small)] p-1.5 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                        >
                            <ArrowLeft :size="16" />
                        </button>
                        <button
                            :disabled="activeHistoryIndex <= 0"
                            @click="nextHistory"
                            :title="t('testShow.newerRun')"
                            class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-small)] p-1.5 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                        >
                            <ArrowRight :size="16" />
                        </button>
                        <Link
                            v-if="activeHistoryItem.run_id"
                            :href="`/sorify/runs/${activeHistoryItem.run_id}`"
                            class="md-label-small text-[var(--md-sys-color-primary)] hover:underline ml-1"
                        >
                            {{ t('testShow.openFullRun') }}
                        </Link>
                        <button
                            @click="closeHistory"
                            :title="t('testShow.close')"
                            class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-small)] p-1.5 transition-colors ml-1"
                        >
                            <X :size="16" />
                        </button>
                    </div>
                </div>

                <!-- Error message -->
                <div v-if="activeHistoryItem.error_message" class="mt-2">
                    <p class="md-label-small font-medium text-[var(--md-sys-color-error)] uppercase tracking-wider mb-2">{{ t('testShow.error') }}</p>
                    <pre class="text-[var(--md-sys-color-on-error-container)] md-body-small bg-[var(--md-sys-color-error-container)] rounded-[var(--md-sys-shape-corner-small)] p-3 overflow-x-auto whitespace-pre-wrap">{{ activeHistoryItem.error_message }}</pre>
                </div>

                <!-- Stack trace -->
                <div v-if="activeHistoryItem.error_stack" class="mt-3">
                    <p class="md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider mb-2">{{ t('testShow.stackTrace') }}</p>
                    <pre class="text-[var(--md-sys-color-on-surface-variant)] md-body-small bg-code border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)] p-3 overflow-x-auto whitespace-pre font-mono">{{ activeHistoryItem.error_stack }}</pre>
                </div>

                <!-- Stdout -->
                <div v-if="activeHistoryItem.stdout" class="mt-3">
                    <button
                        class="flex items-center gap-2 md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider mb-2 hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                        @click="showHistoryStdout = !showHistoryStdout"
                    >
                        <ChevronRight
                            :size="14"
                            class="transition-transform"
                            :class="{ 'rotate-90': showHistoryStdout }"
                        />
                        {{ t('testShow.stdoutLogs') }}
                    </button>
                    <pre v-if="showHistoryStdout" class="text-[var(--md-sys-color-on-surface-variant)] md-body-small bg-code border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)] p-3 overflow-x-auto whitespace-pre font-mono max-h-64">{{ activeHistoryItem.stdout }}</pre>
                </div>

                <!-- Screenshots -->
                <div v-if="activeHistoryItem.screenshots?.length" class="mt-4">
                    <p class="md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider mb-3">{{ t('testShow.screenshots') }}</p>
                    <ScreenshotGallery :screenshots="activeHistoryItem.screenshots" />
                </div>

                <!-- Empty state -->
                <div
                    v-if="!activeHistoryItem.error_message && !activeHistoryItem.stdout && !activeHistoryItem.screenshots?.length"
                    class="mt-2 md-body-small text-[var(--md-sys-color-on-surface-variant)] italic"
                >
                    {{ t('testShow.noAdditionalDetails') }}
                </div>
            </div>
        </Card>

        <!-- Version history -->
        <Card padding="p-0">
            <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] flex items-center gap-2">
                    <History :size="18" :style="{ color: 'var(--md-sys-color-tertiary)' }" />
                    {{ t('testShow.versionHistory') }}
                </h2>
                <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)] mt-0.5">
                    {{ t('testShow.versionRetentionCaption', { count: codeVersionRetention }) }}
                </p>
            </div>

            <div v-if="!codeVersions.data.length" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                <History :size="32" class="mx-auto mb-3 opacity-40" />
                {{ t('testShow.noVersionsYet') }}
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-[var(--md-sys-color-surface-container-low)]">
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testShow.colVersion') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testShow.colSource') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testShow.colAiModel') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testShow.colSavedBy') }}</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('testShow.colDate') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <template v-for="version in codeVersions.data" :key="version.id">
                            <tr
                                class="hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors cursor-pointer"
                                :class="{ 'bg-[var(--md-sys-color-surface-container-low)]': isVersionExpanded(version.id) }"
                                @click="toggleVersion(version.id)"
                            >
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center gap-2">
                                        <ChevronRight
                                            :size="14"
                                            class="text-[var(--md-sys-color-on-surface-variant)] transition-transform"
                                            :class="{ 'rotate-90': isVersionExpanded(version.id) }"
                                        />
                                        <span class="inline-block md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-primary-container)] bg-[var(--md-sys-color-primary-container)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">
                                            v{{ version.version_number }}
                                        </span>
                                    </span>
                                </td>
                                <td class="px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ version.source }}</td>
                                <td class="px-5 py-3 md-label-small font-mono text-[var(--md-sys-color-on-surface-variant)]">
                                    <span v-if="version.ai_model" class="inline-flex items-center gap-1 text-[var(--md-sys-color-on-tertiary-container)]">
                                        <Bot :size="12" class="flex-shrink-0" />{{ version.ai_model }}
                                    </span>
                                    <template v-else>—</template>
                                </td>
                                <td class="px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ version.created_by ?? '—' }}</td>
                                <td class="px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ formatDate(version.created_at) }}</td>
                            </tr>
                            <tr v-if="isVersionExpanded(version.id)">
                                <td colspan="5" class="px-5 pb-5 bg-[var(--md-sys-color-surface-container-lowest)]">
                                    <div class="flex items-center justify-end gap-2 mt-3 mb-3">
                                        <CopyButton :value="version.playwright_code" :label="t('testShow.copyCode')" />
                                        <Button
                                            variant="tonal"
                                            size="sm"
                                            @click="restoreVersion(version)"
                                            :disabled="restoringVersionId === version.id"
                                        >
                                            {{ restoringVersionId === version.id ? t('testShow.restoring') : t('testShow.restoreVersion') }}
                                        </Button>
                                    </div>
                                    <TestCodeEditor :code="version.playwright_code" :editable="false" />
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <Pagination
                v-if="codeVersions.data.length"
                :paginator="codeVersions"
                :label="t('testShow.showingVersions', { from: codeVersions.from ?? 0, to: codeVersions.to ?? 0, total: codeVersions.total })"
            />
        </Card>

        <!-- Run history screenshot lightbox -->
        <ScreenshotLightbox
            :shots="lightbox.shots.value"
            :index="lightbox.index.value"
            @close="lightbox.close"
            @update:index="lightbox.setIndex"
        />
    </AppLayout>
</template>

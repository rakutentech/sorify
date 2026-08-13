<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import TestCodeEditor from '@/Components/TestCodeEditor.vue';
import ScreenshotGallery from '@/Components/ScreenshotGallery.vue';
import { Card, Chip, Button, TextField, Autocomplete } from '@/Components/ui';
import { formatDate } from '@/utils/date';

const props = defineProps({
    suite: { type: Object, required: true },
    test: { type: Object, required: true },
    users: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    codeVersions: { type: Array, default: () => [] },
});

// Edit form
const editForm = useForm({
    name: props.test.name ?? '',
    description: props.test.description ?? '',
    uploaded_by: props.test.uploaded_by ?? '',
});

const editMode = ref(false);

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

// Version history navigation — `codeVersions` is ordered newest-first (index 0 = most recent).
const activeVersionIndex = ref(null);
const restoring = ref(false);

const activeVersionItem = computed(() =>
    activeVersionIndex.value !== null ? props.codeVersions[activeVersionIndex.value] : null,
);

function openVersion(index) {
    activeVersionIndex.value = index;
}

function closeVersion() {
    activeVersionIndex.value = null;
}

function prevVersion() {
    if (activeVersionIndex.value < props.codeVersions.length - 1) {
        activeVersionIndex.value++;
    }
}

function nextVersion() {
    if (activeVersionIndex.value > 0) {
        activeVersionIndex.value--;
    }
}

function onVersionKeydown(e) {
    if (activeVersionIndex.value === null) return;
    if (e.key === 'ArrowLeft') prevVersion();
    if (e.key === 'ArrowRight') nextVersion();
    if (e.key === 'Escape') closeVersion();
}

function restoreVersion(version) {
    if (!confirm(`Restore version v${version.version_number}? The current code will be kept as a new version.`)) return;

    restoring.value = true;
    router.post(
        `/sorify/suites/${props.suite.id}/tests/${props.test.id}/code-versions/${version.id}/restore`,
        {},
        {
            onSuccess: () => {
                codeForm.playwright_code = props.test.playwright_code ?? '';
                closeVersion();
            },
            onFinish: () => { restoring.value = false; },
        },
    );
}

// Run this test
const running = ref(false);
const runError = ref(null);

function deleteTest() {
    if (!confirm(`Delete test "${props.test.name}"? This cannot be undone.`)) return;
    router.delete(`/sorify/suites/${props.suite.id}/tests/${props.test.id}`);
}

function runTest() {
    running.value = true;
    runError.value = null;
    router.post(
        `/sorify/suites/${props.suite.id}/runs`,
        { test_ids: [props.test.id] },
        {
            onSuccess: () => { running.value = false; },
            onError:   () => {
                running.value = false;
                runError.value = 'Failed to start test run.';
            },
        },
    );
}

function formatDuration(ms) {
    if (!ms && ms !== 0) return '—';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}

// History navigation — `history` is ordered newest-first (index 0 = most recent).
const activeHistoryIndex = ref(null);
const showHistoryStdout = ref(false);

const activeHistoryItem = computed(() =>
    activeHistoryIndex.value !== null ? props.history[activeHistoryIndex.value] : null,
);

function openHistory(index) {
    activeHistoryIndex.value = index;
    showHistoryStdout.value = false;
}

function closeHistory() {
    activeHistoryIndex.value = null;
}

function prevHistory() {
    if (activeHistoryIndex.value < props.history.length - 1) {
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

        <!-- Breadcrumb -->
        <div class="flex items-center gap-2 md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-4">
            <Link href="/sorify/suites" class="hover:text-[var(--md-sys-color-on-surface)] transition-colors">Test Suites</Link>
            <span>/</span>
            <Link :href="`/sorify/suites/${suite.id}`" class="hover:text-[var(--md-sys-color-on-surface)] transition-colors">{{ suite.name }}</Link>
            <span>/</span>
            <span class="text-[var(--md-sys-color-on-surface)]">{{ test.name }}</span>
        </div>

        <!-- Test header -->
        <div class="flex items-start justify-between mb-6">
            <div class="flex-1 min-w-0">
                <template v-if="!editMode">
                    <span class="inline-block md-label-small font-semibold uppercase tracking-wider text-[var(--md-ext-color-on-success-container)] bg-[var(--md-ext-color-success-container)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)] mb-1.5">Test Case</span>
                    <div class="flex items-center gap-3 flex-wrap">
                        <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)]">{{ test.name }}</h1>
                        <Chip v-if="test.status" :status="test.status" />
                    </div>
                    <p v-if="test.description" class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">{{ test.description }}</p>
                    <p v-if="test.uploaded_by" class="md-label-small text-[var(--md-sys-color-on-surface-variant)] mt-1">
                        Uploaded by <span class="font-medium">{{ test.uploaded_by }}</span>
                    </p>
                </template>

                <template v-else>
                    <form @submit.prevent="saveEdit" class="space-y-3 max-w-xl">
                        <TextField v-model="editForm.name" label="Test name" :error="editForm.errors.name" />
                        <TextField v-model="editForm.description" label="Description" type="textarea" :rows="2" :error="editForm.errors.description" />
                        <Autocomplete v-model="editForm.uploaded_by" :options="users" label="Uploaded by" :error="editForm.errors.uploaded_by" />
                        <div class="flex gap-2">
                            <Button type="submit" variant="filled" size="sm" :disabled="editForm.processing">
                                {{ editForm.processing ? 'Saving...' : 'Save' }}
                            </Button>
                            <Button type="button" variant="text" size="sm" @click="editMode = false">Cancel</Button>
                        </div>
                    </form>
                </template>
            </div>

            <div class="flex items-center gap-2 ml-4 flex-shrink-0">
                <Button v-if="!editMode" variant="text" @click="deleteTest" class="text-[var(--md-sys-color-error)]">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    Delete
                </Button>
                <Button v-if="!editMode" variant="tonal" @click="editMode = true">Edit</Button>
                <Button
                    variant="filled"
                    @click="runTest"
                    :disabled="running || !test.playwright_code"
                    :title="!test.playwright_code ? 'No Playwright code — upload code first' : 'Run this test'"
                >
                    <svg v-if="running" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    {{ running ? 'Starting...' : 'Run Test' }}
                </Button>
            </div>
        </div>

        <!-- Run error banner -->
        <div v-if="runError" class="mb-6 flex items-start gap-3 bg-[var(--md-sys-color-error-container)] rounded-[var(--md-sys-shape-corner-medium)] px-5 py-4">
            <svg class="w-4 h-4 text-[var(--md-sys-color-on-error-container)] mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div class="flex-1 min-w-0">
                <p class="md-body-medium font-medium text-[var(--md-sys-color-on-error-container)]">Run failed to start</p>
                <p class="md-body-small text-[var(--md-sys-color-on-error-container)] mt-0.5">{{ runError }}</p>
            </div>
            <button @click="runError = null" class="text-[var(--md-sys-color-on-error-container)] flex-shrink-0 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Code editor section -->
        <Card padding="p-0" class="mb-6">
            <div class="flex items-center justify-between px-5 py-3 border-b border-[var(--md-sys-color-outline-variant)]">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">Playwright Code</h2>
                <div class="flex items-center gap-2">
                    <span v-if="codeSaved" class="md-label-small text-[var(--md-ext-color-success)]">Saved!</span>
                    <template v-if="!codeEditable">
                        <Button variant="tonal" size="sm" @click="codeEditable = true">Edit</Button>
                    </template>
                    <template v-else>
                        <Button variant="filled" size="sm" @click="saveCode" :disabled="codeForm.processing">
                            {{ codeForm.processing ? 'Saving...' : 'Save Code' }}
                        </Button>
                        <Button variant="text" size="sm" @click="codeEditable = false">Cancel</Button>
                    </template>
                </div>
            </div>
            <div class="p-1">
                <TestCodeEditor
                    v-model:code="codeForm.playwright_code"
                    :editable="codeEditable"
                />
                <p v-if="codeForm.errors.playwright_code" class="text-[var(--md-sys-color-error)] md-body-small px-4 pt-2">{{ codeForm.errors.playwright_code }}</p>
            </div>
        </Card>

        <!-- Version history -->
        <Card padding="p-0" class="mb-6">
            <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">Version History</h2>
            </div>

            <div v-if="!codeVersions.length" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                No previous versions yet.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-[var(--md-sys-color-surface-container-low)]">
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Version</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Source</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Saved by</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <tr
                            v-for="(version, index) in codeVersions"
                            :key="version.id"
                            class="hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors cursor-pointer"
                            :class="{ 'bg-[var(--md-sys-color-surface-container-low)]': activeVersionIndex === index }"
                            @click="openVersion(index)"
                        >
                            <td class="px-5 py-3">
                                <span class="inline-block md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-primary-container)] bg-[var(--md-sys-color-primary-container)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">
                                    v{{ version.version_number }}
                                </span>
                            </td>
                            <td class="px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ version.source }}</td>
                            <td class="px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ version.created_by ?? '—' }}</td>
                            <td class="px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ formatDate(version.created_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Version detail panel -->
            <div
                v-if="activeVersionItem"
                class="border-t border-[var(--md-sys-color-outline-variant)] px-5 py-5"
                tabindex="-1"
                @keydown="onVersionKeydown"
            >
                <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                    <div class="flex items-center gap-3">
                        <span class="inline-block md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-primary-container)] bg-[var(--md-sys-color-primary-container)] px-2 py-0.5 rounded-[var(--md-sys-shape-corner-extra-small)]">
                            v{{ activeVersionItem.version_number }}
                        </span>
                        <span class="md-body-medium text-[var(--md-sys-color-on-surface)]">{{ formatDate(activeVersionItem.created_at) }}</span>
                        <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ activeVersionItem.source }} &middot; {{ activeVersionItem.created_by ?? 'unknown' }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] mr-1">{{ activeVersionIndex + 1 }} / {{ codeVersions.length }}</span>
                        <button
                            :disabled="activeVersionIndex >= codeVersions.length - 1"
                            @click="prevVersion"
                            title="Older version"
                            class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-small)] p-1.5 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </button>
                        <button
                            :disabled="activeVersionIndex <= 0"
                            @click="nextVersion"
                            title="Newer version"
                            class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-small)] p-1.5 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>
                        <Button
                            variant="tonal"
                            size="sm"
                            @click="restoreVersion(activeVersionItem)"
                            :disabled="restoring"
                        >
                            {{ restoring ? 'Restoring...' : 'Restore this version' }}
                        </Button>
                        <button
                            @click="closeVersion"
                            title="Close"
                            class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-small)] p-1.5 transition-colors ml-1"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <TestCodeEditor :code="activeVersionItem.playwright_code" :editable="false" />
            </div>
        </Card>

        <!-- Run history -->
        <Card padding="p-0">
            <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">Run History</h2>
            </div>

            <div v-if="!history.length" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                No runs yet.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-[var(--md-sys-color-surface-container-low)]">
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Date</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Status</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Duration</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <tr
                            v-for="(item, index) in history"
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
                            <td class="px-5 py-3 text-right">
                                <Link
                                    v-if="item.run_id"
                                    :href="`/sorify/runs/${item.run_id}`"
                                    class="md-label-small text-[var(--md-sys-color-primary)] hover:underline"
                                    @click.stop
                                >
                                    View Run &rarr;
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

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
                        <span class="md-label-small text-[var(--md-sys-color-on-surface-variant)] mr-1">{{ activeHistoryIndex + 1 }} / {{ history.length }}</span>
                        <button
                            :disabled="activeHistoryIndex >= history.length - 1"
                            @click="prevHistory"
                            title="Older run"
                            class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-small)] p-1.5 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </button>
                        <button
                            :disabled="activeHistoryIndex <= 0"
                            @click="nextHistory"
                            title="Newer run"
                            class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-small)] p-1.5 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>
                        <Link
                            v-if="activeHistoryItem.run_id"
                            :href="`/sorify/runs/${activeHistoryItem.run_id}`"
                            class="md-label-small text-[var(--md-sys-color-primary)] hover:underline ml-1"
                        >
                            Open full run &rarr;
                        </Link>
                        <button
                            @click="closeHistory"
                            title="Close"
                            class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] bg-[var(--md-sys-color-surface-container-high)] rounded-[var(--md-sys-shape-corner-small)] p-1.5 transition-colors ml-1"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Error message -->
                <div v-if="activeHistoryItem.error_message" class="mt-2">
                    <p class="md-label-small font-medium text-[var(--md-sys-color-error)] uppercase tracking-wider mb-2">Error</p>
                    <pre class="text-[var(--md-sys-color-on-error-container)] md-body-small bg-[var(--md-sys-color-error-container)] rounded-[var(--md-sys-shape-corner-small)] p-3 overflow-x-auto whitespace-pre-wrap">{{ activeHistoryItem.error_message }}</pre>
                </div>

                <!-- Stack trace -->
                <div v-if="activeHistoryItem.error_stack" class="mt-3">
                    <p class="md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider mb-2">Stack Trace</p>
                    <pre class="text-[var(--md-sys-color-on-surface-variant)] md-body-small bg-code border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)] p-3 overflow-x-auto whitespace-pre font-mono">{{ activeHistoryItem.error_stack }}</pre>
                </div>

                <!-- Stdout -->
                <div v-if="activeHistoryItem.stdout" class="mt-3">
                    <button
                        class="flex items-center gap-2 md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider mb-2 hover:text-[var(--md-sys-color-on-surface)] transition-colors"
                        @click="showHistoryStdout = !showHistoryStdout"
                    >
                        <svg
                            class="w-3.5 h-3.5 transition-transform"
                            :class="{ 'rotate-90': showHistoryStdout }"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                        Stdout / Logs
                    </button>
                    <pre v-if="showHistoryStdout" class="text-[var(--md-sys-color-on-surface-variant)] md-body-small bg-code border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)] p-3 overflow-x-auto whitespace-pre font-mono max-h-64">{{ activeHistoryItem.stdout }}</pre>
                </div>

                <!-- Screenshots -->
                <div v-if="activeHistoryItem.screenshots?.length" class="mt-4">
                    <p class="md-label-small font-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider mb-3">Screenshots</p>
                    <ScreenshotGallery :screenshots="activeHistoryItem.screenshots" />
                </div>

                <!-- Empty state -->
                <div
                    v-if="!activeHistoryItem.error_message && !activeHistoryItem.stdout && !activeHistoryItem.screenshots?.length"
                    class="mt-2 md-body-small text-[var(--md-sys-color-on-surface-variant)] italic"
                >
                    No additional details for this run.
                </div>
            </div>
        </Card>
    </AppLayout>
</template>

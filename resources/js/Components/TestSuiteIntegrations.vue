<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Workflow, Globe, Trash2, LoaderCircle, Check, CircleAlert } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    suite: { type: Object, required: true },
    canEdit: { type: Boolean, default: false },
    githubConfigured: { type: Boolean, default: true },
    githubApps: { type: Array, default: () => [] },
});

let keySeq = 0;

function nextKey() {
    keySeq += 1;

    return `integration-${Date.now()}-${keySeq}`;
}

function toCard(integration) {
    const card = {
        key: nextKey(),
        id: integration.id,
        type: integration.type,
        label: integration.label ?? '',
        enabled: integration.enabled ?? true,
        trigger_before: integration.trigger_before ?? false,
        trigger_after: integration.trigger_after ?? false,
        // Set when an admin force-disabled the integration (e.g. its
        // GitHub App was deleted) — rendered as a warning on the card.
        disabled_note: integration.disabled_note ?? null,
    };

    if (integration.type === 'http_request') {
        card.url = integration.config?.url ?? '';
        card.method = integration.config?.method ?? 'POST';
        card.inputs = Object.entries(integration.config?.inputs ?? {}).map(([name, value]) => ({
            name,
            value: value ?? '',
        }));
        card.headers = Object.entries(integration.config?.headers ?? {}).map(([name, value]) => ({
            name,
            value: value ?? '',
        }));
        card.body = integration.config?.body ?? '';
        card.proxy = integration.config?.proxy ?? '';
    } else {
        card.repository = integration.config?.repository ?? '';
        card.workflow = integration.config?.workflow ?? '';
        card.ref = integration.config?.ref ?? '';
        // Which GitHub App to dispatch as. Older cards without an app fall
        // back to the first configured one, and persist it on the next save.
        card.github_app_id = integration.github_app_id ?? props.githubApps[0]?.id ?? null;
        card.inputs = Object.entries(integration.config?.inputs ?? {}).map(([name, value]) => ({
            name,
            value: value ?? '',
        }));
    }

    return card;
}

const cards = ref((props.suite.integrations ?? []).map(toCard));

// Cards are managed locally and saved via plain JSON requests (no Inertia
// round-trip), so saves never clobber fields the user is still typing in.
// Server props are only re-adopted when no save is pending — e.g. the page
// auto-refresh during a run, or edits made elsewhere (another tab, MCP).
// A card the user has touched but not persisted yet (typing without
// blurring, or an incomplete new card) never re-adopts, otherwise the 2s
// auto-refresh while a run is active would keep wiping it.
watch(() => props.suite.integrations, (integrations) => {
    if (saving.value || saveTimers.size > 0) return;
    if (cards.value.some(c => c.dirty || c.id === null)) return;

    cards.value = (integrations ?? []).map(toCard);
});

function markDirty(card) {
    card.dirty = true;
}

const saving = ref(false);
const saved = ref(false);
let savedTimer = null;

function flashSaved() {
    saved.value = true;
    clearTimeout(savedTimer);
    savedTimer = setTimeout(() => { saved.value = false; }, 1500);
}

// Debounced auto-save: sequential field edits (repository → workflow → ref)
// collapse into one save instead of firing on every blur.
const saveTimers = new Map();

function scheduleSave(card) {
    if (!props.canEdit) return;

    markDirty(card);

    clearTimeout(saveTimers.get(card.key));
    saveTimers.set(card.key, setTimeout(() => {
        saveTimers.delete(card.key);
        saveCard(card);
    }, 600));
}

function payloadOf(card) {
    const payload = {
        type: card.type,
        label: card.label.trim() || null,
        enabled: !!card.enabled,
        trigger_before: !!card.trigger_before,
        trigger_after: !!card.trigger_after,
    };

    if (card.type === 'http_request') {
        payload.url = card.url.trim();
        payload.method = card.method;
        payload.inputs = card.inputs
            .filter(i => i.name.trim() !== '')
            .map(i => ({ name: i.name.trim(), value: i.value ?? '' }));
        payload.headers = card.headers
            .filter(h => h.name.trim() !== '')
            .map(h => ({ name: h.name.trim(), value: h.value ?? '' }));
        payload.body = card.body.trim() || null;
        payload.proxy = card.proxy.trim() || null;
    } else {
        payload.repository = card.repository.trim();
        payload.workflow = card.workflow.trim();
        payload.ref = card.ref.trim() || null;
        payload.github_app_id = card.github_app_id ?? null;
        payload.inputs = card.inputs
            .filter(i => i.name.trim() !== '')
            .map(i => ({ name: i.name.trim(), value: i.value }));
    }

    return payload;
}

// Required fields per type — the save is held back until they are filled so
// the card can be completed field by field. Any absolute http(s) URL passes
// (localhost hosts included); the server re-validates on save.
function cardComplete(card) {
    if (card.type === 'http_request') {
        return /^https?:\/\/\S+$/.test(card.url.trim());
    }

    return /^[A-Za-z0-9_.\-]+\/[A-Za-z0-9_.\-]+$/.test(card.repository.trim())
        && card.workflow.trim() !== '';
}

function csrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function saveCard(card) {
    if (!props.canEdit || !cardComplete(card)) return;

    saving.value = true;

    const create = card.id === null;
    const url = `/sorify/suites/${props.suite.id}/integrations${create ? '' : `/${card.id}`}`;

    try {
        const response = await fetch(url, {
            method: create ? 'POST' : 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify(payloadOf(card)),
        });

        if (! response.ok) {
            restoreCard(card);
        } else {
            const data = await response.json();
            card.id = data.id;
            card.dirty = false;
            flashSaved();
        }
    } catch {
        restoreCard(card);
    } finally {
        saving.value = false;
    }
}

function restoreCard(card) {
    const serverCard = (props.suite.integrations ?? []).find(i => i.id === card.id);

    if (serverCard) {
        Object.assign(card, toCard(serverCard), { id: card.id, key: card.key });
        card.dirty = false;
    }
}

function addIntegration(type) {
    if (type === 'http_request') {
        cards.value.push({
            key: nextKey(),
            id: null,
            type: 'http_request',
            label: '',
            url: '',
            method: 'POST',
            inputs: [],
            headers: [],
            body: '',
            proxy: '',
            enabled: true,
            trigger_before: false,
            trigger_after: false,
        });
    } else {
        cards.value.push({
            key: nextKey(),
            id: null,
            type: 'github_action',
            label: '',
            repository: '',
            workflow: '',
            ref: '',
            github_app_id: props.githubApps[0]?.id ?? null,
            inputs: [],
            enabled: true,
            trigger_before: false,
            trigger_after: false,
        });
    }
}

async function removeIntegration(card) {
    if (card.id !== null) {
        if (!confirm(t('testSuiteShow.confirmRemoveIntegration'))) return;

        clearTimeout(saveTimers.get(card.key));
        saveTimers.delete(card.key);

        try {
            await fetch(`/sorify/suites/${props.suite.id}/integrations/${card.id}`, {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
            });
            flashSaved();
        } catch {
            return; // Keep the card if the delete failed.
        }
    }

    const index = cards.value.indexOf(card);
    if (index >= 0) cards.value.splice(index, 1);
}

function addInput(card) {
    card.inputs.push({ name: '', value: '' });
}

function removeInput(card, index) {
    card.inputs.splice(index, 1);
    scheduleSave(card);
}

function addHeader(card) {
    card.headers.push({ name: '', value: '' });
}

function removeHeader(card, index) {
    card.headers.splice(index, 1);
    scheduleSave(card);
}

// Context Sorify injects into every GitHub Actions dispatch — reserved,
// read-only, shown so users know they don't need to add these themselves.
const SORIFY_CONTEXT = [
    { name: 'sorify_run_id', hint: 'sorifyCtxRunId' },
    { name: 'sorify_suite_id', hint: 'sorifyCtxSuiteId' },
    { name: 'sorify_run_url', hint: 'sorifyCtxRunUrl' },
    { name: 'sorify_run_status', hint: 'sorifyCtxRunStatus' },
    { name: 'sorify_passed_count', hint: 'sorifyCtxPassed' },
    { name: 'sorify_failed_count', hint: 'sorifyCtxFailed' },
    { name: 'sorify_error_count', hint: 'sorifyCtxError' },
];

function cardTitle(card) {
    if (card.label) return card.label;

    if (card.type === 'http_request') {
        return card.url || t('testSuiteShow.httpRequest');
    }

    return card.repository || t('testSuiteShow.githubAction');
}
</script>

<template>
    <div class="pt-2 border-t border-[var(--md-sys-color-outline-variant)]">
        <div class="flex items-center justify-between mt-3 mb-1">
            <p class="md-label-small font-semibold uppercase tracking-wider text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.integrationsSection') }}</p>
            <div class="flex items-center gap-3">
                <span v-if="saving" class="flex items-center gap-1 md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                    <LoaderCircle :size="12" class="animate-spin" />
                    {{ t('testSuiteShow.saving') }}
                </span>
                <span v-else-if="saved" class="flex items-center gap-1 md-label-small text-[var(--md-ext-color-success)]">
                    <Check :size="12" />
                    {{ t('testSuiteShow.saved') }}
                </span>
                <button v-if="canEdit" type="button" @click="addIntegration('github_action')" :disabled="saving" class="md-label-small text-[var(--md-sys-color-primary)] hover:underline disabled:opacity-60">{{ t('testSuiteShow.addGithubIntegration') }}</button>
                <button v-if="canEdit" type="button" @click="addIntegration('http_request')" :disabled="saving" class="md-label-small text-[var(--md-sys-color-primary)] hover:underline disabled:opacity-60">{{ t('testSuiteShow.addHttpIntegration') }}</button>
            </div>
        </div>
        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-2 opacity-80">{{ t('testSuiteShow.integrationsHint') }}</p>

        <div
            v-if="githubConfigured === false && cards.some(c => c.type === 'github_action')"
            class="mb-3 p-2.5 flex items-start gap-2 bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)] border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)]"
        >
            <CircleAlert :size="14" class="mt-0.5 flex-shrink-0" />
            <p class="md-body-small">{{ t('testSuiteShow.githubActionsNotConfigured') }}</p>
        </div>

        <div v-if="!cards.length" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] py-1.5 opacity-70">
            {{ t('testSuiteShow.noIntegrations') }}
        </div>

        <div
            v-for="card in cards"
            :key="card.key"
            :class="[
                'mb-3 p-3 border rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)]',
                card.enabled ? 'border-[var(--md-sys-color-outline-variant)]' : 'border-[var(--md-sys-color-outline)] opacity-70',
            ]"
        >
            <!-- Force-disabled warning (e.g. its GitHub App was deleted) -->
            <div
                v-if="card.disabled_note"
                class="mb-3 p-2.5 flex items-start gap-2 bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)] border border-[var(--md-sys-color-outline-variant)] rounded-[var(--md-sys-shape-corner-small)]"
            >
                <CircleAlert :size="14" class="mt-0.5 flex-shrink-0" />
                <p class="md-body-small">{{ card.disabled_note }}</p>
            </div>

            <!-- Card header: type icon, label, type badge, enabled toggle, remove -->
            <div class="flex items-center gap-2 mb-3">
                <Workflow v-if="card.type === 'github_action'" :size="16" :style="{ color: 'var(--md-sys-color-tertiary)' }" />
                <Globe v-else :size="16" :style="{ color: 'var(--md-sys-color-tertiary)' }" />
                <span class="md-label-small font-semibold text-[var(--md-sys-color-on-surface)] truncate">{{ cardTitle(card) }}</span>
                <span
                    :class="[
                        'flex-shrink-0 md-label-small px-1.5 py-0.5 rounded-[var(--md-sys-shape-corner-full)] border',
                        card.type === 'github_action'
                            ? 'text-[var(--md-sys-color-on-secondary-container)] bg-[var(--md-sys-color-secondary-container)] border-transparent'
                            : 'text-[var(--md-sys-color-on-tertiary-container)] bg-[var(--md-sys-color-tertiary-container)] border-transparent',
                    ]"
                >
                    {{ card.type === 'github_action' ? t('testSuiteShow.githubAction') : t('testSuiteShow.httpRequest') }}
                </span>
                <div class="ml-auto flex items-center gap-1">
                    <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)] cursor-pointer mr-1" :title="t('testSuiteShow.integrationEnabled')">
                        <input
                            type="checkbox"
                            v-model="card.enabled"
                            @change="scheduleSave(card)"
                            :disabled="!canEdit || card.id === null"
                            class="w-3.5 h-3.5 accent-[var(--md-sys-color-primary)] cursor-pointer disabled:opacity-60"
                        />
                        {{ t('testSuiteShow.integrationEnabled') }}
                    </label>
                    <button
                        v-if="canEdit"
                        type="button"
                        @click="removeIntegration(card)"
                        class="p-1.5 text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-error)] transition-colors"
                        :title="t('testSuiteShow.removeIntegration')"
                    >
                        <Trash2 :size="14" />
                    </button>
                </div>
            </div>

            <!-- Config: GitHub Action -->
            <div v-if="card.type === 'github_action'" class="grid grid-cols-1 sm:grid-cols-4 gap-x-4 gap-y-3">
                <div class="sm:col-span-2">
                    <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`integration-repository-${card.key}`">{{ t('testSuiteShow.integrationRepository') }}</label>
                    <input
                        :id="`integration-repository-${card.key}`"
                        v-model="card.repository"
                        @change="scheduleSave(card)"
                        @input="markDirty(card)"
                        :disabled="!canEdit || saving"
                        type="text"
                        placeholder="owner/repo"
                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                    />
                </div>
                <div>
                    <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`integration-workflow-${card.key}`">{{ t('testSuiteShow.integrationWorkflow') }}</label>
                    <input
                        :id="`integration-workflow-${card.key}`"
                        v-model="card.workflow"
                        @change="scheduleSave(card)"
                        @input="markDirty(card)"
                        :disabled="!canEdit || saving"
                        type="text"
                        placeholder="deploy.yml"
                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                    />
                </div>
                <div>
                    <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`integration-ref-${card.key}`">{{ t('testSuiteShow.integrationRef') }}</label>
                    <input
                        :id="`integration-ref-${card.key}`"
                        v-model="card.ref"
                        @change="scheduleSave(card)"
                        @input="markDirty(card)"
                        :disabled="!canEdit || saving"
                        type="text"
                        :placeholder="t('testSuiteShow.integrationRefPlaceholder')"
                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                    />
                </div>
                <div v-if="githubApps.length">
                    <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`integration-app-${card.key}`" :title="t('testSuiteShow.integrationGithubAppHint')">{{ t('testSuiteShow.integrationGithubApp') }}</label>
                    <select
                        :id="`integration-app-${card.key}`"
                        v-model="card.github_app_id"
                        @change="scheduleSave(card)"
                        :disabled="!canEdit || saving"
                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                        <option v-for="app in githubApps" :key="app.id" :value="app.id">{{ app.name }}</option>
                    </select>
                </div>
            </div>

            <!-- Config: HTTP request -->
            <div v-else class="grid grid-cols-1 sm:grid-cols-4 gap-x-4 gap-y-3">
                <div class="sm:col-span-2">
                    <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`integration-url-${card.key}`">{{ t('testSuiteShow.integrationUrl') }}</label>
                    <input
                        :id="`integration-url-${card.key}`"
                        v-model="card.url"
                        @change="scheduleSave(card)"
                        @input="markDirty(card)"
                        :disabled="!canEdit || saving"
                        type="text"
                        placeholder="https://example.com/api/deploy?env=prod"
                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                    />
                </div>
                <div>
                    <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`integration-method-${card.key}`">{{ t('testSuiteShow.integrationMethod') }}</label>
                    <select
                        :id="`integration-method-${card.key}`"
                        v-model="card.method"
                        @change="scheduleSave(card)"
                        :disabled="!canEdit || saving"
                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                        <option>GET</option>
                        <option>POST</option>
                        <option>PUT</option>
                        <option>DELETE</option>
                    </select>
                </div>
                <div>
                    <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`integration-http-proxy-${card.key}`">{{ t('testSuiteShow.integrationProxy') }}</label>
                    <input
                        :id="`integration-http-proxy-${card.key}`"
                        v-model="card.proxy"
                        @change="scheduleSave(card)"
                        @input="markDirty(card)"
                        :disabled="!canEdit || saving"
                        type="text"
                        placeholder="http://proxy.example.com:8080"
                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                    />
                </div>

                <!-- Raw JSON body (POST / PUT only) -->
                <div v-if="card.method === 'POST' || card.method === 'PUT'" class="sm:col-span-4">
                    <label class="block md-label-small text-[var(--md-sys-color-on-surface-variant)] mb-1" :for="`integration-body-${card.key}`">{{ t('testSuiteShow.integrationBody') }}</label>
                    <textarea
                        :id="`integration-body-${card.key}`"
                        v-model="card.body"
                        @change="scheduleSave(card)"
                        @input="markDirty(card)"
                        :disabled="!canEdit || saving"
                        rows="4"
                        placeholder='{"deploy": true, "environment": "staging"}'
                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed resize-y"
                    />
                    <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] opacity-70 mt-1">
                        {{ t('testSuiteShow.httpBodyHint') }}
                    </p>
                </div>
            </div>

            <!-- Inputs -->
            <div class="mt-3">
                <div class="flex items-center justify-between mb-1">
                    <label class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                        {{ card.type === 'http_request' ? t('testSuiteShow.httpRequestInputs') : t('testSuiteShow.integrationInputs') }}
                    </label>
                    <button v-if="canEdit" type="button" @click="addInput(card)" :disabled="saving" class="md-label-small text-[var(--md-sys-color-primary)] hover:underline disabled:opacity-60">{{ t('testSuiteShow.addInput') }}</button>
                </div>

                <!-- Sorify context (always injected, read-only) -->
                <div v-if="card.type === 'github_action'" class="mb-2 p-2.5 rounded-[var(--md-sys-shape-corner-small)] border border-dashed border-[var(--md-sys-color-outline-variant)]">
                    <p class="md-label-small font-semibold text-[var(--md-sys-color-on-surface-variant)] mb-1.5">{{ t('testSuiteShow.sorifyContextHeading') }}</p>
                    <div v-for="ctx in SORIFY_CONTEXT" :key="ctx.name" class="flex items-center gap-2 mb-1">
                        <input
                            :value="ctx.name"
                            disabled
                            type="text"
                            class="w-2/5 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface-variant)] cursor-not-allowed opacity-60"
                        />
                        <input
                            :value="t('testSuiteShow.' + ctx.hint)"
                            disabled
                            type="text"
                            class="flex-1 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small text-[var(--md-sys-color-on-surface-variant)] cursor-not-allowed opacity-60"
                        />
                    </div>
                    <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] opacity-70 mt-1.5">
                        {{ t('testSuiteShow.sorifyContextHint') }}
                    </p>
                </div>

                <div v-if="!card.inputs.length" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] py-1 opacity-70">
                    {{ t('testSuiteShow.noInputs') }}
                </div>
                <div v-for="(input, index) in card.inputs" :key="index" class="flex items-center gap-2 mb-1.5">
                    <input
                        v-model="input.name"
                        @change="scheduleSave(card)"
                        @input="markDirty(card)"
                        :disabled="!canEdit || saving"
                        type="text"
                        placeholder="input_name"
                        class="w-2/5 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                    />
                    <input
                        v-model="input.value"
                        @change="scheduleSave(card)"
                        @input="markDirty(card)"
                        :disabled="!canEdit || saving"
                        type="text"
                        placeholder="value"
                        class="flex-1 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                    />
                    <button v-if="canEdit" type="button" @click="removeInput(card, index)" :disabled="saving" class="p-1.5 text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-error)] transition-colors disabled:opacity-60">
                        <Trash2 :size="14" />
                    </button>
                </div>
                <p v-if="card.type === 'http_request'" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] opacity-70">
                    {{ card.method === 'GET' ? t('testSuiteShow.httpInputsHintGet') : t('testSuiteShow.httpInputsHintBody') }}
                </p>
            </div>

            <!-- Headers (http_request only) -->
            <div v-if="card.type === 'http_request'" class="mt-3">
                <div class="flex items-center justify-between mb-1">
                    <label class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('testSuiteShow.integrationHeaders') }}</label>
                    <button v-if="canEdit" type="button" @click="addHeader(card)" :disabled="saving" class="md-label-small text-[var(--md-sys-color-primary)] hover:underline disabled:opacity-60">{{ t('testSuiteShow.addHeader') }}</button>
                </div>
                <div v-if="!card.headers.length" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] py-1 opacity-70">
                    {{ t('testSuiteShow.noHeaders') }}
                </div>
                <div v-for="(header, index) in card.headers" :key="index" class="flex items-center gap-2 mb-1.5">
                    <input
                        v-model="header.name"
                        @change="scheduleSave(card)"
                        @input="markDirty(card)"
                        :disabled="!canEdit || saving"
                        type="text"
                        placeholder="X-API-Key"
                        class="w-2/5 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                    />
                    <input
                        v-model="header.value"
                        @change="scheduleSave(card)"
                        @input="markDirty(card)"
                        :disabled="!canEdit || saving"
                        type="text"
                        placeholder="value"
                        class="flex-1 bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2.5 py-1.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent disabled:opacity-60 disabled:cursor-not-allowed"
                    />
                    <button v-if="canEdit" type="button" @click="removeHeader(card, index)" :disabled="saving" class="p-1.5 text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-error)] transition-colors disabled:opacity-60">
                        <Trash2 :size="14" />
                    </button>
                </div>
            </div>

            <!-- Trigger phase checkboxes -->
            <div class="flex items-center gap-4 mt-3 pt-3 border-t border-[var(--md-sys-color-outline-variant)] flex-wrap">
                <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]" :title="t('testSuiteShow.triggerBeforeHint')">
                    <input
                        type="checkbox"
                        v-model="card.trigger_before"
                        @change="scheduleSave(card)"
                        :disabled="!canEdit || saving"
                        class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer disabled:opacity-60"
                    />
                    {{ t('testSuiteShow.triggerBefore') }}
                </label>
                <label class="flex items-center gap-1.5 md-label-small text-[var(--md-sys-color-on-surface-variant)]" :title="t('testSuiteShow.triggerAfterHint')">
                    <input
                        type="checkbox"
                        v-model="card.trigger_after"
                        @change="scheduleSave(card)"
                        :disabled="!canEdit || saving"
                        class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer disabled:opacity-60"
                    />
                    {{ t('testSuiteShow.triggerAfter') }}
                </label>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Button, Card } from '@/Components/ui';
import { Bot, LoaderCircle, Lock, PlugZap, Plus, RefreshCw, Trash2, X } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    profiles: { type: Array, default: () => [] },
});

const blankForm = () => ({
    id: null,
    name: '',
    base_url: '',
    api_token: '',
    proxy_url: '',
    default_model: '',
    system_prompt: '',
    history_retention_days: 30,
    token_configured: false,
});

const editing = ref(null);
const saving = ref(false);
const deletingId = ref(null);
const testing = ref(false);
const testResult = ref(null);
const error = ref(null);

const models = ref([]);
const loadingModels = ref(false);

const editingExisting = computed(() => editing.value !== null && editing.value.id !== null);

/**
 * Model options for the picker: the fetched list, keeping any custom value
 * (e.g. a model the endpoint does not list) selectable.
 */
const modelOptions = computed(() => {
    const options = [...models.value];

    const current = editing.value?.default_model;

    if (! isBlank(current) && ! options.includes(current)) {
        options.unshift(current);
    }

    return options;
});

function csrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function isBlank(value) {
    return value === null || value === undefined || String(value).trim() === '';
}

/**
 * Fetch the endpoint's model list (OpenAI-compatible GET /models). Uses the
 * stored token of the profile being edited when the token field is blank,
 * so models auto-load right after opening an existing profile.
 */
async function loadModels() {
    if (! editing.value || isBlank(editing.value.base_url)) return;
    if (!editingExisting.value && isBlank(editing.value.api_token)) return;

    loadingModels.value = true;

    try {
        const response = await fetch('/sorify/agent/models', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                profile_id: editingExisting.value ? editing.value.id : null,
                base_url: isBlank(editing.value.base_url) ? null : editing.value.base_url,
                api_token: isBlank(editing.value.api_token) ? null : editing.value.api_token,
                proxy_url: isBlank(editing.value.proxy_url) ? null : editing.value.proxy_url,
            }),
        });

        const data = await response.json();
        models.value = data.models ?? [];

        // Auto-fill: default to the first model the endpoint offers.
        if (models.value.length > 0 && isBlank(editing.value.default_model)) {
            editing.value.default_model = models.value[0];
        }
    } catch {
        models.value = [];
    } finally {
        loadingModels.value = false;
    }
}

function startCreate() {
    editing.value = blankForm();
    models.value = [];
    testResult.value = null;
    error.value = null;
}

function startEdit(profile) {
    editing.value = {
        ...blankForm(),
        ...profile,
        api_token: '',
    };
    models.value = [];
    testResult.value = null;
    error.value = null;

    // Auto-load the endpoint's model list (uses the stored token).
    if (profile.token_configured) {
        loadModels();
    }
}

function cancelEdit() {
    editing.value = null;
    models.value = [];
    testResult.value = null;
    error.value = null;
}

async function save() {
    if (isBlank(editing.value.name) || isBlank(editing.value.base_url)) return;

    saving.value = true;
    error.value = null;

    const payload = {
        name: editing.value.name,
        base_url: editing.value.base_url,
        api_token: isBlank(editing.value.api_token) ? null : editing.value.api_token,
        proxy_url: isBlank(editing.value.proxy_url) ? null : editing.value.proxy_url,
        default_model: isBlank(editing.value.default_model) ? null : editing.value.default_model,
        system_prompt: isBlank(editing.value.system_prompt) ? null : editing.value.system_prompt,
        history_retention_days: editing.value.history_retention_days,
    };

    try {
        const response = await fetch(
            editingExisting.value ? `/sorify/agent/profiles/${editing.value.id}` : '/sorify/agent/profiles',
            {
                method: editingExisting.value ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(payload),
            },
        );

        if (! response.ok) {
            const data = await response.json().catch(() => ({}));
            error.value = Object.values(data.errors ?? {})[0]?.[0] ?? data.message ?? 'Save failed.';

            return;
        }

        cancelEdit();
        routerReload();
    } catch (e) {
        error.value = e.message;
    } finally {
        saving.value = false;
    }
}

async function testConnection() {
    testing.value = true;
    testResult.value = null;

    try {
        const response = await fetch('/sorify/agent/profiles/test-connection', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                // For an existing profile, blank fields fall back to the
                // stored values server-side (the token is write-only).
                profile_id: editingExisting.value ? editing.value.id : null,
                base_url: isBlank(editing.value.base_url) ? null : editing.value.base_url,
                api_token: isBlank(editing.value.api_token) ? null : editing.value.api_token,
                proxy_url: isBlank(editing.value.proxy_url) ? null : editing.value.proxy_url,
            }),
        });

        testResult.value = await response.json();

        // Connection verified — pull the model list right away so the
        // default model fills in automatically.
        if (testResult.value?.ok) {
            await loadModels();
        }
    } catch (e) {
        testResult.value = { ok: false, error: e.message };
    } finally {
        testing.value = false;
    }
}

async function remove(profile) {
    if (! confirm(t('agent.profiles.confirmDelete', { name: profile.name }))) return;

    deletingId.value = profile.id;

    try {
        await fetch(`/sorify/agent/profiles/${profile.id}`, {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        });

        if (editing.value?.id === profile.id) cancelEdit();

        routerReload();
    } finally {
        deletingId.value = null;
    }
}

// Reload page props after mutations so the list reflects the database.
function routerReload() {
    router.reload({ preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Card padding="p-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] flex items-center gap-2">
                <Bot :size="20" :style="{ color: 'var(--md-sys-color-primary)' }" />
                {{ t('agent.profiles.title') }}
            </h2>
            <Button v-if="!editing" variant="tonal" size="sm" @click="startCreate">
                <template #leading><Plus :size="16" /></template>
                {{ t('agent.profiles.add') }}
            </Button>
        </div>

        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-4">
            {{ t('agent.profiles.hint') }}
        </p>

        <div class="flex items-start gap-2.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-highest)] px-4 py-3 mb-4">
            <Lock :size="16" :style="{ color: 'var(--md-sys-color-primary)' }" class="flex-shrink-0 mt-0.5" />
            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.profiles.privacyNote') }}</p>
        </div>

        <!-- Edit / create form -->
        <div v-if="editing" class="rounded-[var(--md-sys-shape-corner-medium)] bg-[var(--md-sys-color-surface-container-highest)] p-4 space-y-4 mb-4">
            <div class="flex items-center justify-between">
                <h3 class="md-label-large text-[var(--md-sys-color-on-surface)]">
                    {{ editingExisting ? t('agent.profiles.editProfile') : t('agent.profiles.newProfile') }}
                </h3>
                <button class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)]" @click="cancelEdit">
                    <X :size="16" />
                </button>
            </div>

            <label class="block">
                <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('agent.profiles.name') }}</span>
                <input
                    v-model="editing.name"
                    type="text"
                    class="mt-1 w-full md-body-large rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)]"
                    :placeholder="t('agent.profiles.namePlaceholder')"
                >
            </label>

            <label class="block">
                <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('agent.profiles.baseUrl') }}</span>
                <input
                    v-model="editing.base_url"
                    type="text"
                    class="mt-1 w-full md-body-large rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)]"
                    :placeholder="t('agent.profiles.baseUrlPlaceholder')"
                >
                <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.profiles.baseUrlHint') }}</span>
            </label>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('agent.profiles.apiToken') }}</span>
                    <input
                        v-model="editing.api_token"
                        type="password"
                        autocomplete="off"
                        class="mt-1 w-full md-body-large rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)]"
                        :placeholder="editing.token_configured ? t('agent.profiles.tokenConfigured') : t('agent.profiles.apiTokenPlaceholder')"
                    >
                    <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.profiles.apiTokenHint') }}</span>
                </label>

                <label class="block">
                    <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('agent.profiles.proxyUrl') }}</span>
                    <input
                        v-model="editing.proxy_url"
                        type="text"
                        class="mt-1 w-full md-body-large rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)]"
                        :placeholder="t('agent.profiles.proxyUrlPlaceholder')"
                    >
                    <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.profiles.proxyUrlHint') }}</span>
                </label>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <label class="block">
                    <span class="flex items-center gap-1.5">
                        <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('agent.profiles.defaultModel') }}</span>
                        <button
                            type="button"
                            class="p-1 rounded-full text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)] transition-colors disabled:opacity-50"
                            :title="t('agent.profiles.refreshModels')"
                            :disabled="loadingModels"
                            @click="loadModels"
                        >
                            <LoaderCircle v-if="loadingModels" :size="12" class="animate-spin" />
                            <RefreshCw v-else :size="12" />
                        </button>
                    </span>
                    <select
                        v-if="modelOptions.length > 0"
                        v-model="editing.default_model"
                        class="mt-1 w-full md-body-large bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface)] rounded-[var(--md-sys-shape-corner-extra-small)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)]"
                    >
                        <option value="">{{ t('agent.profiles.notSet') }}</option>
                        <option v-for="model in modelOptions" :key="model" :value="model">{{ model }}</option>
                    </select>
                    <input
                        v-else
                        v-model="editing.default_model"
                        type="text"
                        class="mt-1 w-full md-body-large rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)]"
                        :placeholder="t('agent.profiles.defaultModelPlaceholder')"
                    >
                    <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.profiles.defaultModelHint') }}</span>
                </label>

                <label class="block">
                    <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('agent.profiles.retention') }}</span>
                    <select
                        v-model="editing.history_retention_days"
                        class="mt-1 w-full md-body-large rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)]"
                    >
                        <option :value="7">{{ t('agent.profiles.retentionDays', { count: 7 }) }}</option>
                        <option :value="30">{{ t('agent.profiles.retentionDays', { count: 30 }) }}</option>
                        <option :value="90">{{ t('agent.profiles.retentionDays', { count: 90 }) }}</option>
                        <option :value="365">{{ t('agent.profiles.retentionDays', { count: 365 }) }}</option>
                    </select>
                    <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.profiles.retentionHint') }}</span>
                </label>
            </div>

            <label class="block">
                <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('agent.profiles.systemPrompt') }}</span>
                <textarea
                    v-model="editing.system_prompt"
                    rows="3"
                    class="mt-1 w-full md-body-medium rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)] resize-y"
                    :placeholder="t('agent.profiles.systemPromptPlaceholder')"
                />
                <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('agent.profiles.systemPromptHint') }}</span>
            </label>

            <div
                v-if="testResult"
                class="md-body-small rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5"
                :class="testResult.ok
                    ? 'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]'
                    : 'bg-[var(--md-sys-color-error-container)] text-[var(--md-sys-color-on-error-container)]'"
            >
                <template v-if="testResult.ok">
                    {{ t('agent.profiles.testOk', { latency: testResult.latency_ms, count: testResult.models_count }) }}
                </template>
                <template v-else>
                    {{ t('agent.profiles.testFailed') }}: {{ testResult.error }}
                </template>
            </div>

            <p v-if="error" class="md-body-small text-[var(--md-sys-color-error)]">{{ error }}</p>

            <div class="flex items-center gap-2">
                <Button variant="filled" size="sm" :disabled="saving || isBlank(editing.name) || isBlank(editing.base_url)" @click="save">
                    <LoaderCircle v-if="saving" :size="16" class="animate-spin" />
                    <template v-else>{{ t('agent.profiles.save') }}</template>
                </Button>
                <Button variant="text" size="sm" :disabled="testing || isBlank(editing.base_url)" @click="testConnection">
                    <template #leading>
                        <PlugZap v-if="!testing" :size="16" />
                        <LoaderCircle v-else :size="16" class="animate-spin" />
                    </template>
                    {{ testing ? t('agent.profiles.testing') : t('agent.profiles.testConnection') }}
                </Button>
            </div>
        </div>

        <!-- Profile list -->
        <div v-if="profiles.length === 0 && !editing" class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">
            {{ t('agent.profiles.empty') }}
        </div>

        <ul v-else class="space-y-2">
            <li
                v-for="profile in profiles"
                :key="profile.id"
                class="flex items-center gap-3 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-highest)] px-4 py-3"
            >
                <div class="flex-1 min-w-0">
                    <p class="md-label-large text-[var(--md-sys-color-on-surface)] truncate">{{ profile.name }}</p>
                    <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] truncate">
                        {{ profile.base_url }}{{ profile.default_model ? ` · ${profile.default_model}` : '' }}
                    </p>
                </div>
                <Button variant="text" size="sm" @click="startEdit(profile)">{{ t('agent.profiles.edit') }}</Button>
                <button
                    class="p-1.5 rounded-full text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-error)] transition-colors disabled:opacity-50"
                    :title="t('agent.profiles.delete')"
                    :disabled="deletingId === profile.id"
                    @click="remove(profile)"
                >
                    <LoaderCircle v-if="deletingId === profile.id" :size="16" class="animate-spin" />
                    <Trash2 v-else :size="16" />
                </button>
            </li>
        </ul>
    </Card>
</template>

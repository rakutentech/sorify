<script setup>
import { ref, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button, TextField, Modal, Tooltip } from '@/Components/ui';
import { ShieldCheck, Plus, Trash2, Pencil, Globe, Workflow, KeyRound, Users, Cable, Power, ChevronDown, CircleHelp, LoaderCircle, CircleCheck, CircleAlert } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    apps: Array,
    defaultRedirectUri: String,
});

const showFormModal = ref(false);
const showInstructions = ref(false);
const editingApp = ref(null);

const form = useForm({
    name: '',
    base_url: '',
    client_id: '',
    client_secret: '',
    redirect_uri: '',
    proxy: '',
    app_id: '',
    private_key: '',
    sign_in_enabled: true,
    actions_enabled: true,
});

// Live connection check for the Base URL / Proxy fields: debounced,
// admin-only endpoint, runs while the modal is open.
const connection = ref({ state: 'idle', status: null, detail: null });

function connectionTargetValid() {
    const base = (form.base_url || '').trim();

    return base === '' || /^https?:\/\/.+/i.test(base);
}

async function runConnectionTest() {
    if (! showFormModal.value) return;

    if (! connectionTargetValid()) {
        connection.value = { state: 'skipped', status: null, detail: null };
        return;
    }

    connection.value = { state: 'testing', status: null, detail: null };

    try {
        const response = await fetch('/sorify/admin/github-apps/test-connection', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': (document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1]
                    ? decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1])
                    : '',
            },
            body: JSON.stringify({ base_url: form.base_url, proxy: form.proxy }),
        });

        const data = await response.json();
        connection.value = data.ok
            ? { state: 'ok', status: data.status, detail: data.url }
            : { state: 'failed', status: data.status ?? null, detail: data.error || data.url };
    } catch (e) {
        connection.value = { state: 'failed', status: null, detail: e.message };
    }
}

function debounce(fn, delay) {
    let timer = null;

    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
}

const scheduleConnectionTest = debounce(runConnectionTest, 800);

watch(() => [form.base_url, form.proxy], () => {
    if (showFormModal.value) scheduleConnectionTest();
});

watch(showFormModal, (open) => {
    if (open) runConnectionTest();
});

function openCreateModal() {
    editingApp.value = null;
    form.reset();
    form.defaults();
    showFormModal.value = true;
}

// Placeholder for a secret field: when editing and a value is already
// stored, hint that blank keeps it; when editing and none is stored yet,
// say so; otherwise fall back to the create-mode placeholder (if any).
function secretPlaceholder(flag, createPlaceholderKey, createPlaceholderText = null) {
    if (!editingApp.value) {
        return createPlaceholderKey ? t(createPlaceholderKey) : createPlaceholderText;
    }

    return editingApp.value[flag]
        ? t('adminGithubApps.secretStoredPlaceholder')
        : t('adminGithubApps.secretNotSetPlaceholder');
}

function openEditModal(app) {
    editingApp.value = app;
    form.reset();
    Object.assign(form, {
        name: app.name,
        base_url: app.base_url,
        client_id: app.client_id,
        // Secrets are never echoed back — blank keeps the stored value.
        client_secret: '',
        redirect_uri: app.redirect_uri,
        proxy: app.proxy,
        app_id: app.app_id,
        private_key: '',
        sign_in_enabled: app.sign_in_enabled,
        actions_enabled: app.actions_enabled,
    });
    showFormModal.value = true;
}

function submitForm() {
    if (editingApp.value) {
        form.put(`/sorify/admin/github-apps/${editingApp.value.id}`, {
            onSuccess: () => { showFormModal.value = false; },
        });
    } else {
        form.post('/sorify/admin/github-apps', {
            onSuccess: () => { showFormModal.value = false; },
        });
    }
}

function deleteApp(app) {
    if (!confirm(t('adminGithubApps.confirmDelete', { name: app.name }))) return;

    router.delete(`/sorify/admin/github-apps/${app.id}`);
}

function toggle(app, field) {
    router.put(`/sorify/admin/github-apps/${app.id}`, {
        name: app.name,
        base_url: app.base_url,
        client_id: app.client_id,
        redirect_uri: app.redirect_uri,
        proxy: app.proxy,
        app_id: app.app_id,
        sign_in_enabled: field === 'sign_in_enabled' ? !app.sign_in_enabled : app.sign_in_enabled,
        actions_enabled: field === 'actions_enabled' ? !app.actions_enabled : app.actions_enabled,
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('adminGithubApps.pageTitle')" />

        <div class="space-y-5">
            <div class="flex items-center justify-between">
                <h1 class="md-title-large text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5"><ShieldCheck :size="26" :style="{ color: 'var(--md-sys-color-error)' }" />{{ t('adminGithubApps.heading') }}</h1>
                <div class="flex items-center gap-2">
                    <Button variant="text" @click="showInstructions = !showInstructions"><template #leading><CircleHelp :size="16" /></template>{{ t('adminGithubApps.instructions') }}</Button>
                    <Button variant="filled" @click="openCreateModal"><template #leading><Plus :size="16" /></template>{{ t('adminGithubApps.addApp') }}</Button>
                </div>
            </div>

            <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                {{ t('adminGithubApps.intro') }}
            </p>

            <!-- Registration instructions -->
            <Card v-if="showInstructions" padding="p-5">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] mb-3">{{ t('adminGithubApps.instructionsHeading') }}</h2>
                <ol class="list-decimal space-y-3 ml-5">
                    <li>
                        <span class="md-body-medium font-semibold text-[var(--md-sys-color-on-surface)]">{{ t('adminGithubApps.step1Title') }}</span>
                        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('adminGithubApps.step1Detail') }}</p>
                    </li>
                    <li>
                        <span class="md-body-medium font-semibold text-[var(--md-sys-color-on-surface)]">{{ t('adminGithubApps.step2Title') }}</span>
                        <table class="mt-1.5 md-body-small">
                            <tr>
                                <td class="pr-6 py-1 text-[var(--md-sys-color-on-surface)] whitespace-nowrap">Homepage URL</td>
                                <td class="py-1 text-[var(--md-sys-color-on-surface-variant)]">{{ t('adminGithubApps.homepageUrlValue') }}</td>
                            </tr>
                            <tr>
                                <td class="pr-6 py-1 text-[var(--md-sys-color-on-surface)] whitespace-nowrap">Callback URL</td>
                                <td class="py-1 text-[var(--md-sys-color-on-surface-variant)] font-mono break-all">{{ defaultRedirectUri }}</td>
                            </tr>
                        </table>
                    </li>
                    <li>
                        <span class="md-body-medium font-semibold text-[var(--md-sys-color-on-surface)]">{{ t('adminGithubApps.step3Title') }}</span>
                        <table class="mt-1.5 md-body-small">
                            <tr>
                                <td class="pr-6 py-1 text-[var(--md-sys-color-on-surface)] whitespace-nowrap">Repository permissions</td>
                                <td class="pr-6 py-1 text-[var(--md-sys-color-on-surface)] whitespace-nowrap">Actions</td>
                                <td class="py-1 font-semibold text-[var(--md-sys-color-on-surface)] whitespace-nowrap">Read and write</td>
                            </tr>
                            <tr>
                                <td class="pr-6 py-1 text-[var(--md-sys-color-on-surface)] whitespace-nowrap">Account permissions</td>
                                <td class="pr-6 py-1 text-[var(--md-sys-color-on-surface)] whitespace-nowrap">Email addresses</td>
                                <td class="py-1 font-semibold text-[var(--md-sys-color-on-surface)] whitespace-nowrap">Read-only</td>
                            </tr>
                        </table>
                        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('adminGithubApps.step3Note') }}</p>
                    </li>
                    <li>
                        <span class="md-body-medium font-semibold text-[var(--md-sys-color-on-surface)]">{{ t('adminGithubApps.step4Title') }}</span>
                        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('adminGithubApps.step4Detail') }}</p>
                    </li>
                    <li>
                        <span class="md-body-medium font-semibold text-[var(--md-sys-color-on-surface)]">{{ t('adminGithubApps.step5Title') }}</span>
                        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('adminGithubApps.step5Detail') }}</p>
                    </li>
                </ol>
                <div class="mt-4">
                    <span class="md-body-medium font-semibold text-[var(--md-sys-color-on-surface)]">{{ t('adminGithubApps.workflowHeading') }}</span>
                    <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('adminGithubApps.workflowNote') }}</p>
                    <pre v-pre class="mt-1.5 p-3 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] text-xs font-mono text-[var(--md-sys-color-on-surface)] overflow-x-auto"><code>on:
  workflow_dispatch:
    inputs:
      # Injected by Sorify — do not edit
      sorify_run_id:
        type: string
      sorify_suite_id:
        type: string
      # Add custom inputs from the suite's settings page

jobs:
  sorify:
    name: Sorify
    steps:
      - name: Show Sorify context
        run: |
          echo "sorify_run_id=${{ github.event.inputs.sorify_run_id }}"</code></pre>
                </div>
            </Card>

            <!-- Apps Table -->
            <Card padding="p-0" class="overflow-hidden">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-[var(--md-sys-color-outline-variant)] text-left bg-[var(--md-sys-color-surface-container-low)]">
                            <th class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider"><span class="inline-flex items-center gap-1"><Globe :size="13" />{{ t('adminGithubApps.colApp') }}</span></th>
                            <th class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('adminGithubApps.colUses') }}</th>
                            <th class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider"><span class="inline-flex items-center gap-1"><Users :size="13" />{{ t('adminGithubApps.colUsers') }}</span></th>
                            <th class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider"><span class="inline-flex items-center gap-1"><Cable :size="13" />{{ t('adminGithubApps.colIntegrations') }}</span></th>
                            <th class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">{{ t('adminGithubApps.colActions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <tr v-for="app in apps" :key="app.id" class="hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors">
                            <td class="px-4 py-3">
                                <div class="md-body-medium font-medium text-[var(--md-sys-color-on-surface)]">{{ app.name }}</div>
                                <div class="md-body-small text-[var(--md-sys-color-on-surface-variant)] font-mono">{{ app.base_url || 'github.com' }}</div>
                            </td>
                            <td class="px-4 py-3 space-x-3 whitespace-nowrap">
                                <Tooltip :text="t('adminGithubApps.signInSwitchHint')">
                                    <label class="inline-flex items-center gap-1.5 md-label-small cursor-pointer select-none" :class="app.sign_in_enabled ? 'text-[var(--md-ext-color-success)]' : 'text-[var(--md-sys-color-on-surface-variant)] opacity-60'">
                                        <input type="checkbox" :checked="app.sign_in_enabled" @change="toggle(app, 'sign_in_enabled')" class="w-3.5 h-3.5 accent-[var(--md-sys-color-primary)] cursor-pointer" />
                                        {{ t('adminGithubApps.signIn') }}
                                    </label>
                                </Tooltip>
                                <Tooltip :text="t('adminGithubApps.actionsSwitchHint')">
                                    <label class="inline-flex items-center gap-1.5 md-label-small cursor-pointer select-none" :class="app.actions_enabled ? 'text-[var(--md-ext-color-success)]' : 'text-[var(--md-sys-color-on-surface-variant)] opacity-60'">
                                        <input type="checkbox" :checked="app.actions_enabled" @change="toggle(app, 'actions_enabled')" class="w-3.5 h-3.5 accent-[var(--md-sys-color-primary)] cursor-pointer" />
                                        {{ t('adminGithubApps.actions') }}
                                    </label>
                                </Tooltip>
                                <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] opacity-70 mt-0.5">
                                    {{ app.can_sign_in && app.can_dispatch ? t('adminGithubApps.bothReady') : app.can_sign_in ? t('adminGithubApps.signInReady') : app.can_dispatch ? t('adminGithubApps.actionsReady') : t('adminGithubApps.missingCredentials') }}
                                </p>
                            </td>
                            <td class="px-4 py-3 md-body-medium text-[var(--md-sys-color-on-surface)]">{{ app.users_count }}</td>
                            <td class="px-4 py-3 md-body-medium text-[var(--md-sys-color-on-surface)]">{{ app.active_integrations_count }}</td>
                            <td class="px-4 py-3 space-x-3">
                                <button
                                    @click="openEditModal(app)"
                                    class="md-label-small text-[var(--md-sys-color-primary)] hover:underline transition-colors inline-flex items-center gap-1"
                                    :title="t('adminGithubApps.edit')"
                                >
                                    <Pencil :size="13" />
                                    {{ t('adminGithubApps.edit') }}
                                </button>
                                <button
                                    @click="deleteApp(app)"
                                    class="md-label-small text-[var(--md-sys-color-error)] hover:underline transition-colors inline-flex items-center gap-1"
                                    :title="t('adminGithubApps.delete')"
                                >
                                    <Trash2 :size="13" :style="{ color: 'var(--md-sys-color-error)' }" />
                                    {{ t('adminGithubApps.delete') }}
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!apps.length">
                            <td colspan="5" class="px-4 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                                {{ t('adminGithubApps.noApps') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>

        <!-- Create / Edit Modal -->
        <Modal :show="showFormModal" :title="editingApp ? t('adminGithubApps.editModalTitle', { name: editingApp.name }) : t('adminGithubApps.addModalTitle')" max-width="max-w-lg" @close="showFormModal = false">
            <form @submit.prevent="submitForm" class="px-6 py-5 space-y-4">
                <TextField v-model="form.name" :label="t('adminGithubApps.name')" required :error="form.errors.name" :placeholder="t('adminGithubApps.namePlaceholder')" />
                <TextField v-model="form.base_url" :label="t('adminGithubApps.baseUrl')" :error="form.errors.base_url" :placeholder="t('adminGithubApps.baseUrlPlaceholder')" />
                <div>
                    <TextField v-model="form.proxy" :label="t('adminGithubApps.proxy')" :error="form.errors.proxy" placeholder="http://proxy.example.com:8080" />
                    <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] opacity-70 mt-1">
                        {{ t('adminGithubApps.proxyHint') }}
                    </p>
                </div>

                <!-- Live connection check result -->
                <div v-if="connection.state !== 'idle'" class="h-14 flex items-center gap-2 p-2.5 rounded-[var(--md-sys-shape-corner-small)] border overflow-hidden" :class="connection.state === 'ok' ? 'bg-[var(--md-ext-color-success-container)]/40 border-[var(--md-ext-color-success)]/50 text-[var(--md-ext-color-success)]' : connection.state === 'testing' ? 'bg-[var(--md-sys-color-surface-container)] border-[var(--md-sys-color-outline-variant)] text-[var(--md-sys-color-on-surface-variant)]' : 'bg-[var(--md-sys-color-error-container)]/40 border-[var(--md-sys-color-error)]/50 text-[var(--md-sys-color-error)]'">
                    <LoaderCircle v-if="connection.state === 'testing'" :size="14" class="animate-spin flex-shrink-0" />
                    <CircleCheck v-else-if="connection.state === 'ok'" :size="14" class="flex-shrink-0" />
                    <CircleAlert v-else :size="14" class="flex-shrink-0" />
                    <p class="md-body-small line-clamp-2">
                        <template v-if="connection.state === 'testing'">{{ t('adminGithubApps.connectionTesting') }}</template>
                        <template v-else-if="connection.state === 'ok'">{{ t('adminGithubApps.connectionOk', { status: connection.status }) }}<span class="opacity-70 font-mono"> — {{ connection.detail }}</span></template>
                        <template v-else-if="connection.state === 'skipped'">{{ t('adminGithubApps.connectionSkipped') }}</template>
                        <template v-else>{{ t('adminGithubApps.connectionFailed', { detail: connection.detail || (connection.status ? 'HTTP ' + connection.status : 'unknown') }) }}</template>
                    </p>
                </div>
                <TextField v-model="form.client_id" :label="t('adminGithubApps.clientId')" required :error="form.errors.client_id" placeholder="Iv1.… / Ov…" />
                <TextField
                    v-model="form.client_secret"
                    :label="t('adminGithubApps.clientSecret')"
                    type="password"
                    autocomplete="new-password"
                    :required="!editingApp"
                    :error="form.errors.client_secret"
                    :placeholder="secretPlaceholder('has_client_secret', 'adminGithubApps.clientSecretPlaceholder')"
                />
                <TextField v-model="form.redirect_uri" :label="t('adminGithubApps.redirectUri')" :error="form.errors.redirect_uri" :hint="defaultRedirectUri" :placeholder="t('adminGithubApps.redirectUriPlaceholder')" />
                <TextField v-model="form.app_id" :label="t('adminGithubApps.appId')" :error="form.errors.app_id" :placeholder="t('adminGithubApps.appIdPlaceholder')" />

                <div>
                    <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5" for="github-app-private-key">{{ t('adminGithubApps.privateKey') }}</label>
                    <textarea
                        id="github-app-private-key"
                        v-model="form.private_key"
                        rows="4"
                        class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 md-body-small font-mono text-[var(--md-sys-color-on-surface)] placeholder:text-[var(--md-sys-color-on-surface-variant)] placeholder:opacity-60 focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                        :placeholder="secretPlaceholder('has_private_key', null, '-----BEGIN RSA PRIVATE KEY-----\\n…')"
                    />
                    <p v-if="form.errors.private_key" class="md-body-small text-[var(--md-sys-color-error)] mt-1.5">{{ form.errors.private_key }}</p>
                    <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] opacity-70 mt-1.5">
                        {{ t('adminGithubApps.privateKeyHint') }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-6 pt-1">
                    <label class="flex items-center gap-2 md-body-medium text-[var(--md-sys-color-on-surface)] cursor-pointer">
                        <input type="checkbox" v-model="form.sign_in_enabled" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer" />
                        {{ t('adminGithubApps.signInEnabled') }}
                    </label>
                    <label class="flex items-center gap-2 md-body-medium text-[var(--md-sys-color-on-surface)] cursor-pointer">
                        <input type="checkbox" v-model="form.actions_enabled" class="w-4 h-4 accent-[var(--md-sys-color-primary)] cursor-pointer" />
                        {{ t('adminGithubApps.actionsEnabled') }}
                    </label>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <Button type="button" variant="text" @click="showFormModal = false">{{ t('adminGithubApps.cancel') }}</Button>
                    <Button type="submit" variant="filled" :disabled="form.processing">
                        <span v-if="form.processing">{{ t('adminGithubApps.saving') }}</span>
                        <span v-else>{{ editingApp ? t('adminGithubApps.save') : t('adminGithubApps.create') }}</span>
                    </Button>
                </div>
            </form>
        </Modal>
    </AppLayout>
</template>

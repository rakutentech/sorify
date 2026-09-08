<script setup>
import { ref, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button } from '@/Components/ui';
import { Cpu, CheckCircle2, XCircle, AlertTriangle, Info, RefreshCw, Hammer, Monitor, PackageOpen } from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    mode: String,
    readiness: Array,
    image: String,
    lastBuild: Object,
});

const selectedMode = ref(props.mode);
const checks = ref(props.readiness ?? []);
const checking = ref(false);
const saving = ref(false);
const building = ref(false);
const errorBanner = ref(null);
const infoBanner = ref(null);

const isReady = (list) => !list.some((c) => c.blocking && c.status === 'fail');

async function recheck() {
    checking.value = true;
    try {
        const res = await fetch('/sorify/admin/system/readiness?fresh=1');
        const data = await res.json();
        checks.value = data.checks ?? [];
    } finally {
        checking.value = false;
    }
}

async function saveMode() {
    if (selectedMode.value === props.mode) return;

    saving.value = true;
    errorBanner.value = null;
    infoBanner.value = null;

    try {
        const res = await fetch('/sorify/admin/system/mode', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ execution_mode: selectedMode.value }),
        });
        const data = await res.json();

        if (res.ok) {
            window.location.reload();
        } else {
            errorBanner.value = data.message || 'Failed to switch mode.';
            if (Array.isArray(data.checks)) checks.value = data.checks;
            selectedMode.value = props.mode;
        }
    } finally {
        saving.value = false;
    }
}

async function buildImage() {
    building.value = true;
    infoBanner.value = null;
    try {
        const res = await fetch('/sorify/admin/system/build-image', { method: 'POST', headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        infoBanner.value = data.message || 'Build started.';
    } finally {
        building.value = false;
    }
}

const statusMeta = {
    pass: { icon: CheckCircle2, cls: 'text-[var(--md-sys-color-primary)]', label: 'adminSystem.statusPass' },
    fail: { icon: XCircle, cls: 'text-[var(--md-sys-color-error)]', label: 'adminSystem.statusFail' },
    warn: { icon: AlertTriangle, cls: 'text-[var(--md-sys-color-tertiary)]', label: 'adminSystem.statusWarn' },
    info: { icon: Info, cls: 'text-[var(--md-sys-color-on-surface-variant)]', label: 'adminSystem.statusInfo' },
};

onMounted(recheck);
</script>

<template>
    <AppLayout>
        <Head :title="t('adminSystem.pageTitle')" />

        <div class="space-y-5">
            <h1 class="md-title-large text-[var(--md-sys-color-on-surface)] flex items-center gap-2.5">
                <Cpu :size="26" :style="{ color: 'var(--md-sys-color-error)' }" />{{ t('adminSystem.heading') }}
            </h1>

            <div v-if="errorBanner" class="rounded-[var(--md-sys-shape-corner-medium)] border border-[var(--md-sys-color-error)] bg-[var(--md-sys-color-error)]/10 px-4 py-3 md-body-medium text-[var(--md-sys-color-error)]">
                {{ errorBanner }}
            </div>
            <div v-if="infoBanner" class="rounded-[var(--md-sys-shape-corner-medium)] border border-[var(--md-sys-color-outline-variant)] bg-[var(--md-sys-color-surface-container-high)] px-4 py-3 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                {{ infoBanner }}
            </div>

            <Card padding="p-5" class="space-y-4">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">{{ t('adminSystem.executionMode') }}</h2>

                <div class="space-y-3">
                    <label class="flex items-start gap-3 rounded-[var(--md-sys-shape-corner-medium)] border px-4 py-3 cursor-pointer transition-colors"
                        :class="selectedMode === 'local'
                            ? 'border-[var(--md-sys-color-primary)] bg-[var(--md-sys-color-primary)]/5'
                            : 'border-[var(--md-sys-color-outline-variant)] hover:bg-[var(--md-sys-color-surface-container-low)]'">
                        <input type="radio" value="local" v-model="selectedMode" class="mt-1" />
                        <div>
                            <div class="md-label-large text-[var(--md-sys-color-on-surface)] flex items-center gap-1.5">
                                <Monitor :size="15" />{{ t('adminSystem.localMode') }}
                            </div>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mt-0.5">{{ t('adminSystem.localHelp') }}</p>
                        </div>
                    </label>

                    <label class="flex items-start gap-3 rounded-[var(--md-sys-shape-corner-medium)] border px-4 py-3 cursor-pointer transition-colors"
                        :class="selectedMode === 'ephemeral'
                            ? 'border-[var(--md-sys-color-primary)] bg-[var(--md-sys-color-primary)]/5'
                            : 'border-[var(--md-sys-color-outline-variant)] hover:bg-[var(--md-sys-color-surface-container-low)]'">
                        <input type="radio" value="ephemeral" v-model="selectedMode" class="mt-1" />
                        <div>
                            <div class="md-label-large text-[var(--md-sys-color-on-surface)] flex items-center gap-1.5">
                                <PackageOpen :size="15" />{{ t('adminSystem.ephemeralMode') }}
                            </div>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mt-0.5">{{ t('adminSystem.ephemeralHelp') }}</p>
                        </div>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <Button variant="filled" :disabled="saving || selectedMode === mode" @click="saveMode">
                        {{ t('adminSystem.saveMode') }}
                    </Button>
                    <span v-if="selectedMode === 'ephemeral' && !isReady(checks)" class="md-body-small text-[var(--md-sys-color-tertiary)]">
                        {{ t('adminSystem.notReadyHint') }}
                    </span>
                </div>
            </Card>

            <Card padding="p-0" class="overflow-hidden">
                <div class="flex items-center justify-between px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)]">
                    <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">{{ t('adminSystem.readiness') }}</h2>
                    <div class="flex items-center gap-2">
                        <Button variant="text" :disabled="checking" @click="recheck">
                            <template #leading><RefreshCw :size="15" :class="checking && 'animate-spin'" /></template>
                            {{ t('adminSystem.recheck') }}
                        </Button>
                        <Button variant="tonal" :disabled="building" @click="buildImage">
                            <template #leading><Hammer :size="15" /></template>
                            {{ t('adminSystem.buildImage') }}
                        </Button>
                    </div>
                </div>

                <table class="w-full">
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <tr v-for="check in checks" :key="check.name" class="hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors">
                            <td class="px-5 py-3 md-body-medium font-medium text-[var(--md-sys-color-on-surface)] whitespace-nowrap">
                                {{ t(`adminSystem.checks.${check.name}`) }}
                            </td>
                            <td class="px-5 py-3 w-28">
                                <span class="inline-flex items-center gap-1.5 md-label-small font-medium" :class="statusMeta[check.status]?.cls">
                                    <component :is="statusMeta[check.status]?.icon" :size="15" />
                                    {{ t(statusMeta[check.status]?.label ?? 'adminSystem.statusInfo') }}
                                </span>
                            </td>
                            <td class="px-5 py-3 md-body-small text-[var(--md-sys-color-on-surface-variant)]">
                                {{ check.message }}
                            </td>
                        </tr>
                        <tr v-if="!checks.length">
                            <td colspan="3" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                                {{ checking ? t('adminSystem.checking') : t('adminSystem.noChecks') }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="lastBuild" class="px-5 py-3 border-t border-[var(--md-sys-color-outline-variant)] md-body-small text-[var(--md-sys-color-on-surface-variant)]">
                    {{ lastBuild.ok
                        ? t('adminSystem.lastBuildOk', { at: lastBuild.at })
                        : t('adminSystem.lastBuildFailed', { at: lastBuild.at, error: lastBuild.error }) }}
                </div>
            </Card>
        </div>
    </AppLayout>
</template>

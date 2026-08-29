<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button, MarkdownRenderer } from '@/Components/ui';
import {
    StickyNote, Pencil, Save, X, Check,
} from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    dashboard_note: {
        type: Object,
        default: () => ({ content: '', updated_by: null, updated_at: null }),
    },
    can: {
        type: Object,
        default: () => ({ edit_dashboard_note: false }),
    },
});

const editing = ref(false);
const form = useForm({ content: props.dashboard_note.content ?? '' });

function startEditing() {
    form.content = props.dashboard_note.content ?? '';
    editing.value = true;
}

function cancelEditing() {
    editing.value = false;
    form.reset();
    form.clearErrors();
}

function save() {
    form.put('/sorify/dashboard-note', {
        onSuccess: () => { editing.value = false; },
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('dashboard.title')" />

        <!-- Dashboard note -->
        <Card padding="p-0">
            <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)] flex items-center justify-between">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] flex items-center gap-2">
                    <StickyNote :size="18" :style="{ color: 'var(--md-ext-color-warning)' }" />
                    {{ t('dashboard.notes') }}
                </h2>
                <Button v-if="can.edit_dashboard_note && !editing" variant="text" @click="startEditing">
                    <template #leading><Pencil :size="16" /></template>
                    {{ t('dashboard.edit') }}
                </Button>
            </div>

            <div class="px-5 py-4">
                <template v-if="editing">
                    <textarea
                        v-model="form.content"
                        rows="8"
                        :placeholder="t('dashboard.notePlaceholder')"
                        class="w-full px-3.5 py-2.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] text-[var(--md-sys-color-on-surface)] md-body-medium !font-mono !text-sm placeholder:text-[var(--md-sys-color-on-surface-variant)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                    />
                    <p v-if="form.errors.content" class="md-body-small text-[var(--md-sys-color-error)] mt-1.5">{{ form.errors.content }}</p>
                    <p v-else class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mt-1.5">{{ t('dashboard.markdownSupported') }}</p>

                    <div class="flex justify-end gap-3 mt-3">
                        <Button variant="text" @click="cancelEditing">
                            <template #leading><X :size="16" /></template>
                            {{ t('dashboard.cancel') }}
                        </Button>
                        <Button variant="filled" :disabled="form.processing" @click="save">
                            <template #leading>
                                <Check v-if="!form.processing" :size="16" />
                                <Save v-else :size="16" class="animate-spin" />
                            </template>
                            {{ form.processing ? t('dashboard.saving') : t('dashboard.save') }}
                        </Button>
                    </div>
                </template>

                <template v-else>
                    <MarkdownRenderer
                        v-if="dashboard_note.content"
                        :content="dashboard_note.content"
                    />
                    <div v-else class="flex items-center gap-3">
                        <StickyNote :size="28" class="opacity-40 flex-shrink-0" />
                        <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                            {{ can.edit_dashboard_note ? t('dashboard.nothingHereEdit') : t('dashboard.nothingHere') }}
                        </p>
                    </div>

                    <p v-if="dashboard_note.updated_by" class="md-label-small text-[var(--md-sys-color-on-surface-variant)] mt-3">
                        {{ t('dashboard.lastUpdatedBy', { name: dashboard_note.updated_by }) }}
                    </p>
                </template>
            </div>
        </Card>

    </AppLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button } from '@/Components/ui';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({
            total_suites: 0,
            total_tests: 0,
            total_runs: 0,
            pass_rate_30d: null,
        }),
    },
    dashboard_note: {
        type: Object,
        default: () => ({ content: '', updated_by: null, updated_at: null }),
    },
    can: {
        type: Object,
        default: () => ({ edit_dashboard_note: false }),
    },
});

function formatPassRate(rate) {
    if (rate === null || rate === undefined) return '—';
    return `${Math.round(rate)}%`;
}

const renderedNote = computed(() => DOMPurify.sanitize(marked.parse(props.dashboard_note.content ?? '', { async: false })));

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
        <Head title="Dashboard" />

        <!-- Page header -->
        <div class="mb-6">
            <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)]">Dashboard</h1>
            <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">Overview of your QA test runs</p>
        </div>

        <!-- Stat cards -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <Card>
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Test Suites</p>
                <p class="md-display-small text-[var(--md-sys-color-on-surface)] mt-2">{{ stats.total_suites }}</p>
            </Card>
            <Card>
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Tests</p>
                <p class="md-display-small text-[var(--md-sys-color-on-surface)] mt-2">{{ stats.total_tests }}</p>
            </Card>
            <Card>
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Total Runs</p>
                <p class="md-display-small text-[var(--md-sys-color-on-surface)] mt-2">{{ stats.total_runs }}</p>
            </Card>
            <Card>
                <p class="md-label-medium text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Pass Rate (30d)</p>
                <p
                    class="md-display-small mt-2"
                    :class="{
                        'text-[var(--md-ext-color-success)]': stats.pass_rate_30d >= 90,
                        'text-[var(--md-ext-color-warning)]': stats.pass_rate_30d >= 70 && stats.pass_rate_30d < 90,
                        'text-[var(--md-sys-color-error)]': stats.pass_rate_30d < 70 && stats.pass_rate_30d !== null,
                        'text-[var(--md-sys-color-on-surface-variant)]': stats.pass_rate_30d === null,
                    }"
                >
                    {{ formatPassRate(stats.pass_rate_30d) }}
                </p>
            </Card>
        </div>

        <!-- Dashboard note -->
        <Card padding="p-0">
            <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)] flex items-center justify-between">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">Notes</h2>
                <Button v-if="can.edit_dashboard_note && !editing" variant="text" @click="startEditing">Edit</Button>
            </div>

            <div class="px-5 py-4">
                <template v-if="editing">
                    <textarea
                        v-model="form.content"
                        rows="8"
                        placeholder="Write something for the team to see on the dashboard... (Markdown supported)"
                        class="w-full px-3.5 py-2.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] text-[var(--md-sys-color-on-surface)] md-body-medium !font-mono !text-sm placeholder:text-[var(--md-sys-color-on-surface-variant)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                    />
                    <p v-if="form.errors.content" class="md-body-small text-[var(--md-sys-color-error)] mt-1.5">{{ form.errors.content }}</p>
                    <p v-else class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mt-1.5">Markdown supported.</p>

                    <div class="flex justify-end gap-3 mt-3">
                        <Button variant="text" @click="cancelEditing">Cancel</Button>
                        <Button variant="filled" :disabled="form.processing" @click="save">
                            {{ form.processing ? 'Saving...' : 'Save' }}
                        </Button>
                    </div>
                </template>

                <template v-else>
                    <div
                        v-if="dashboard_note.content"
                        v-html="renderedNote"
                        class="md-body-medium text-[var(--md-sys-color-on-surface)] [&_h1]:md-title-large [&_h2]:md-title-medium [&_h3]:md-title-small [&_h1]:mt-4 [&_h1]:mb-2 [&_h2]:mt-4 [&_h2]:mb-2 [&_h3]:mt-3 [&_h3]:mb-1.5 [&_p]:mb-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ul]:mb-3 [&_ol]:list-decimal [&_ol]:pl-5 [&_ol]:mb-3 [&_li]:mb-1 [&_a]:text-[var(--md-sys-color-primary)] [&_a:hover]:underline [&_code]:font-mono [&_code]:text-sm [&_code]:bg-[var(--md-sys-color-surface-container-high)] [&_code]:px-1 [&_code]:py-0.5 [&_code]:rounded [&_pre]:bg-[var(--md-sys-color-surface-container-high)] [&_pre]:p-3 [&_pre]:rounded-[var(--md-sys-shape-corner-small)] [&_pre]:overflow-x-auto [&_pre]:mb-3 [&_blockquote]:border-l-2 [&_blockquote]:border-[var(--md-sys-color-outline)] [&_blockquote]:pl-3 [&_blockquote]:italic [&_blockquote]:text-[var(--md-sys-color-on-surface-variant)] [&_strong]:font-semibold [&_hr]:border-[var(--md-sys-color-outline-variant)] [&_hr]:my-3"
                    />
                    <p v-else class="md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                        {{ can.edit_dashboard_note ? 'Nothing here yet. Click Edit to add a note.' : 'Nothing here yet.' }}
                    </p>

                    <p v-if="dashboard_note.updated_by" class="md-label-small text-[var(--md-sys-color-on-surface-variant)] mt-3">
                        Last updated by {{ dashboard_note.updated_by }}
                    </p>
                </template>
            </div>
        </Card>

    </AppLayout>
</template>

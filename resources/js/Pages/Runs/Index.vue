<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Chip, SuiteName, AvatarGroup, RanBy, ScreenshotThumbs, ScreenshotLightbox } from '@/Components/ui';
import { useScreenshotLightbox } from '@/composables/useScreenshotLightbox';

const props = defineProps({
    runs: {
        type: Object,
        default: () => ({ data: [], links: [], meta: {} }),
    },
    filters: {
        type: Object,
        default: () => ({ per_page: 30 }),
    },
});

const perPage = ref(props.filters.per_page ?? 30);
const lightbox = useScreenshotLightbox();

watch(perPage, () => {
    router.get('/sorify/runs', { per_page: perPage.value, page: 1 }, { preserveState: true, replace: true });
});

function formatDuration(ms) {
    if (!ms && ms !== 0) return '—';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}
</script>

<template>
    <AppLayout>
        <Head title="Runs" />

        <div class="mb-6">
            <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)]">Runs</h1>
            <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">All test runs across your suites</p>
        </div>

        <Card padding="p-0">
            <div v-if="!runs.data.length" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                No runs yet. Create a test suite to get started.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-[var(--md-sys-color-surface-container-low)]">
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Suite</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Users</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Status</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Passed</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Failed</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Duration</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Screenshots</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Created by</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Ran by</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <tr
                            v-for="run in runs.data"
                            :key="run.id"
                            class="hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors"
                        >
                            <td class="px-5 py-3 md-body-medium">
                                <Link
                                    v-if="run.suite_id"
                                    :href="`/sorify/suites/${run.suite_id}`"
                                    class="text-[var(--md-sys-color-on-surface)] hover:text-[var(--md-sys-color-primary)] transition-colors"
                                >
                                    <SuiteName v-if="run.suite_name ?? run.suite?.name" :name="run.suite_name ?? run.suite?.name" :bold="false" />
                                    <span v-else>—</span>
                                </Link>
                                <span v-else class="text-[var(--md-sys-color-on-surface)]">
                                    <SuiteName v-if="run.suite_name ?? run.suite?.name" :name="run.suite_name ?? run.suite?.name" :bold="false" />
                                    <span v-else>—</span>
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <AvatarGroup :users="run.members ?? []" :suite-id="run.suite_id" />
                            </td>
                            <td class="px-5 py-3">
                                <Chip :status="run.status" />
                            </td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-ext-color-success)]">{{ run.passed_count ?? '—' }}</td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-sys-color-error)]">{{ run.failed_count ?? '—' }}</td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">{{ formatDuration(run.duration_ms) }}</td>
                            <td class="px-5 py-3">
                                <ScreenshotThumbs :screenshots="run.screenshots ?? []" @open="lightbox.open" />
                            </td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">{{ run.created_by ?? '—' }}</td>
                            <td class="px-5 py-3"><RanBy :triggered-by="run.triggered_by" :triggered-by-user="run.triggered_by_user" /></td>
                            <td class="px-5 py-3 text-right">
                                <Link
                                    :href="`/sorify/runs/${run.id}`"
                                    class="md-label-small text-[var(--md-sys-color-primary)] hover:underline"
                                >
                                    View Run<span v-if="run.total_tests != null"> ({{ run.total_tests }} {{ run.total_tests === 1 ? 'test' : 'tests' }})</span>
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination footer -->
            <div
                v-if="runs.data.length"
                class="flex items-center justify-between px-5 py-3 border-t border-[var(--md-sys-color-outline-variant)]"
            >
                <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                    Showing {{ runs.from ?? 0 }}–{{ runs.to ?? 0 }} of {{ runs.total }} runs
                </p>

                <div class="flex items-center gap-3">
                    <div v-if="runs.last_page > 1" class="flex items-center gap-1">
                        <button
                            v-for="link in runs.links"
                            :key="link.label"
                            :disabled="!link.url || link.active"
                            @click="link.url && router.get(link.url, { per_page: perPage }, { preserveState: true, replace: true })"
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
                        v-model="perPage"
                        class="bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-2 py-1 md-label-small text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                    >
                        <option :value="10">10 / page</option>
                        <option :value="30">30 / page</option>
                        <option :value="50">50 / page</option>
                        <option :value="100">100 / page</option>
                    </select>
                </div>
            </div>
        </Card>

        <!-- Screenshot lightbox -->
        <ScreenshotLightbox
            :shots="lightbox.shots.value"
            :index="lightbox.index.value"
            @close="lightbox.close"
            @update:index="lightbox.setIndex"
        />
    </AppLayout>
</template>

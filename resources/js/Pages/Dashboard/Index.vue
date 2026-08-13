<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Chip, SuiteName } from '@/Components/ui';

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
    recent_runs: {
        type: Array,
        default: () => [],
    },
});

function formatDuration(ms) {
    if (!ms && ms !== 0) return '—';
    if (ms < 1000) return `${ms}ms`;
    if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
    return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
}

function formatPassRate(rate) {
    if (rate === null || rate === undefined) return '—';
    return `${Math.round(rate)}%`;
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

        <!-- Recent runs -->
        <Card padding="p-0">
            <div class="px-5 py-4 border-b border-[var(--md-sys-color-outline-variant)] flex items-center justify-between">
                <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)]">Recent Runs</h2>
                <Link href="/sorify/suites" class="md-label-medium text-[var(--md-sys-color-primary)] hover:underline">All suites &rarr;</Link>
            </div>

            <div v-if="!recent_runs.length" class="px-5 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                No runs yet. Create a test suite to get started.
            </div>

            <div v-else class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-[var(--md-sys-color-surface-container-low)]">
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Suite</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Status</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Passed</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Failed</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Duration</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Created by</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Ran by</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <tr
                            v-for="run in recent_runs"
                            :key="run.id"
                            class="hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors"
                        >
                            <td class="px-5 py-3 md-body-medium font-medium">
                                <Link
                                    v-if="run.suite_id"
                                    :href="`/sorify/suites/${run.suite_id}`"
                                    class="text-[var(--md-sys-color-on-surface)] hover:text-[var(--md-sys-color-primary)] transition-colors"
                                >
                                    <SuiteName v-if="run.suite_name ?? run.suite?.name" :name="run.suite_name ?? run.suite?.name" />
                                    <span v-else>—</span>
                                </Link>
                                <span v-else class="text-[var(--md-sys-color-on-surface)]">
                                    <SuiteName v-if="run.suite_name ?? run.suite?.name" :name="run.suite_name ?? run.suite?.name" />
                                    <span v-else>—</span>
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                <Chip :status="run.status" />
                            </td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-ext-color-success)]">{{ run.passed_count ?? '—' }}</td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-sys-color-error)]">{{ run.failed_count ?? '—' }}</td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">{{ formatDuration(run.duration_ms) }}</td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">{{ run.created_by ?? '—' }}</td>
                            <td class="px-5 py-3 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">{{ run.triggered_by ?? '—' }}</td>
                            <td class="px-5 py-3 text-right">
                                <Link
                                    :href="`/sorify/runs/${run.id}`"
                                    class="md-label-small text-[var(--md-sys-color-primary)] hover:underline"
                                >
                                    View Run &rarr;
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

    </AppLayout>
</template>

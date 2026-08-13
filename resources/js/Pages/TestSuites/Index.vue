<script setup>
import { ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button, TextField, Modal } from '@/Components/ui';
import { formatDate } from '@/utils/date';

function debounce(fn, delay) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
}

const props = defineProps({
    suites: {
        type: Object,
        default: () => ({ data: [], links: [], meta: {} }),
    },
    filters: {
        type: Object,
        default: () => ({ search: '', per_page: 10 }),
    },
});

const search  = ref(props.filters.search ?? '');
const perPage = ref(props.filters.per_page ?? 10);

function reload(overrides = {}) {
    router.get(
        '/sorify/suites',
        { search: search.value, per_page: perPage.value, ...overrides },
        { preserveState: true, replace: true },
    );
}

const debouncedSearch = debounce(() => reload({ page: 1 }), 350);

watch(search, () => debouncedSearch());
watch(perPage, () => reload({ page: 1 }));

// ── Modal ──────────────────────────────────────────────────────────────────
const showModal = ref(false);

const form = useForm({
    name: '',
    playwright_proxy: '',
    playwright_proxy_pac: '',
    history_retention: 5,
    description: '',
});

function openModal() {
    form.reset();
    showModal.value = true;
}

function closeModal() {
    showModal.value = false;
    form.reset();
}

function submit() {
    form.post('/sorify/suites', {
        onSuccess: () => closeModal(),
    });
}

// ── Helpers ────────────────────────────────────────────────────────────────
function formatPassRate(rate) {
    if (rate === null || rate === undefined) return '—';
    return `${Math.round(rate)}%`;
}
</script>

<template>
    <AppLayout>
        <Head title="Test Suites" />

        <div class="flex flex-col flex-1 min-h-0">

        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="md-headline-small text-[var(--md-sys-color-on-surface)]">Test Suites</h1>
                <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mt-1">Manage your automated test suites</p>
            </div>
            <Button variant="filled" @click="openModal">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                New Suite
            </Button>
        </div>

        <!-- Search -->
        <div class="mb-4">
            <div class="relative max-w-sm">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--md-sys-color-on-surface-variant)] pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/>
                </svg>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Search suites..."
                    class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] pl-9 pr-4 py-2.5 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                />
            </div>
        </div>

        <!-- Suites table -->
        <Card padding="p-0" class="flex flex-col flex-1 min-h-0">
            <div v-if="!suites.data.length" class="px-5 py-12 text-center">
                <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)]">
                    {{ search ? 'No suites match your search.' : 'No test suites yet.' }}
                </p>
                <button
                    v-if="!search"
                    @click="openModal"
                    class="mt-3 md-label-medium text-[var(--md-sys-color-primary)] hover:underline"
                >
                    Create your first suite &rarr;
                </button>
            </div>

            <div v-else class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
                <table class="w-full">
                    <thead>
                        <tr class="bg-[var(--md-sys-color-surface-container-low)]">
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Name</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Tests</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Runs</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Pass Rate</th>
                            <th class="text-left px-5 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Last Run</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <tr
                            v-for="suite in suites.data"
                            :key="suite.id"
                            class="hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors"
                        >
                            <td class="px-5 py-4">
                                <div>
                                    <p class="font-medium md-body-medium text-[var(--md-sys-color-on-surface)]">{{ suite.name }}</p>
                                    <p v-if="suite.description" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mt-0.5 truncate max-w-xs">{{ suite.description }}</p>
                                    <div class="flex flex-wrap gap-1.5 mt-1.5">
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded-[var(--md-sys-shape-corner-full)] md-label-small"
                                            :class="suite.has_teams_webhook ? 'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]' : 'bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)]'"
                                        >
                                            Teams: {{ suite.has_teams_webhook ? 'Enabled' : 'Disabled' }}
                                        </span>
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded-[var(--md-sys-shape-corner-full)] md-label-small"
                                            :class="suite.take_screenshot ? 'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]' : 'bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)]'"
                                        >
                                            Screenshots: {{ suite.take_screenshot ? 'Enabled' : 'Disabled' }}
                                        </span>
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded-[var(--md-sys-shape-corner-full)] md-label-small"
                                            :class="(suite.playwright_proxy_pac || suite.playwright_proxy) ? 'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]' : 'bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)]'"
                                        >
                                            Proxy: {{ suite.playwright_proxy_pac ? 'PAC' : (suite.playwright_proxy ? 'HTTP' : 'Not set') }}
                                        </span>
                                        <span
                                            class="inline-flex items-center px-2 py-0.5 rounded-[var(--md-sys-shape-corner-full)] md-label-small"
                                            :class="suite.schedule && suite.schedule.is_enabled ? 'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]' : 'bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)]'"
                                        >
                                            Schedule: {{ suite.schedule ? (suite.schedule.is_enabled ? 'On' : 'Paused') : 'Off' }}
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4 md-body-medium text-[var(--md-sys-color-on-surface)]">{{ suite.tests_count ?? suite.test_count ?? '—' }}</td>
                            <td class="px-5 py-4 md-body-medium text-[var(--md-sys-color-on-surface)]">{{ suite.test_runs_count ?? suite.runs_count ?? '—' }}</td>
                            <td class="px-5 py-4">
                                <span
                                    :class="{
                                        'text-[var(--md-ext-color-success)]': suite.pass_rate >= 90,
                                        'text-[var(--md-ext-color-warning)]': suite.pass_rate >= 70 && suite.pass_rate < 90,
                                        'text-[var(--md-sys-color-error)]': suite.pass_rate < 70 && suite.pass_rate !== null && suite.pass_rate !== undefined,
                                        'text-[var(--md-sys-color-on-surface-variant)]': suite.pass_rate === null || suite.pass_rate === undefined,
                                    }"
                                >
                                    {{ formatPassRate(suite.pass_rate) }}
                                </span>
                            </td>
                            <td class="px-5 py-4 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ formatDate(suite.last_run_at) }}</td>
                            <td class="px-5 py-4 text-right">
                                <Link
                                    :href="`/sorify/suites/${suite.id}`"
                                    class="md-label-small text-[var(--md-sys-color-primary)] hover:underline"
                                >
                                    View Suite &rarr;
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination footer -->
            <div
                v-if="suites.data.length"
                class="flex items-center justify-between px-5 py-3 border-t border-[var(--md-sys-color-outline-variant)]"
            >
                <p class="md-label-small text-[var(--md-sys-color-on-surface-variant)]">
                    Showing {{ suites.from ?? 0 }}–{{ suites.to ?? 0 }} of {{ suites.total }} suites
                </p>

                <div class="flex items-center gap-3">
                    <div v-if="suites.last_page > 1" class="flex items-center gap-1">
                        <button
                            v-for="link in suites.links"
                            :key="link.label"
                            :disabled="!link.url || link.active"
                            @click="link.url && router.get(link.url, { search, per_page: perPage }, { preserveState: true, replace: true })"
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
                        <option :value="50">50 / page</option>
                        <option :value="100">100 / page</option>
                    </select>
                </div>
            </div>
        </Card>

        </div><!-- end flex-1 column -->

        <!-- New Suite Modal -->
        <Modal :show="showModal" title="New Test Suite" max-width="max-w-lg" @close="closeModal">
                    <form @submit.prevent="submit" class="px-6 py-5 space-y-4">
                        <TextField v-model="form.name" label="Suite Name *" placeholder="My Test Suite" required :error="form.errors.name" />

                        <TextField
                            v-model="form.playwright_proxy"
                            label="HTTP Proxy"
                            placeholder="http://proxy.example.com:8080"
                            hint="Proxy used by Playwright when running tests. Leave empty for direct connection."
                            :error="form.errors.playwright_proxy"
                        />

                        <TextField
                            v-model="form.playwright_proxy_pac"
                            label="Proxy Auto-Config (PAC)"
                            type="textarea"
                            :rows="6"
                            placeholder="function FindProxyForURL(url, host) { return 'PROXY proxy.example.com:8080'; }"
                            hint="Paste a raw PAC script to route requests by domain. Supported for Chromium and Firefox; ignored for WebKit."
                            :error="form.errors.playwright_proxy_pac"
                        />
                        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] -mt-1">
                            Precedence: if both fields above are filled in, the Proxy Auto-Config (PAC) script is used and the HTTP Proxy is ignored. If only one is set, that one is used. If both are empty, tests connect directly.
                        </p>

                        <div>
                            <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">Keep History</label>
                            <select
                                v-model="form.history_retention"
                                class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                            >
                                <option :value="3">Last 3 runs</option>
                                <option :value="5">Last 5 runs</option>
                                <option :value="10">Last 10 runs</option>
                            </select>
                            <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mt-1.5">Older runs (and their screenshots) are deleted automatically per test.</p>
                            <p v-if="form.errors.history_retention" class="md-body-small text-[var(--md-sys-color-error)] mt-1.5">{{ form.errors.history_retention }}</p>
                        </div>

                        <TextField v-model="form.description" label="Description" type="textarea" :rows="3" placeholder="Optional description..." :error="form.errors.description" />

                        <div class="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="text" @click="closeModal">Cancel</Button>
                            <Button type="submit" variant="filled" :disabled="form.processing">
                                {{ form.processing ? 'Creating...' : 'Create Suite' }}
                            </Button>
                        </div>
                    </form>
        </Modal>
    </AppLayout>
</template>

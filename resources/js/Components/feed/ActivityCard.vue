<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Card, Avatar } from '@/Components/ui';
import { formatDate, formatRelativeTime } from '@/utils/date';
import {
    Play, CircleCheck, Ban, FolderPlus, SquarePen, Copy, FilePlus2,
    Pencil, Code, Trash2, CirclePause, UserPlus, LogIn, UserCog,
    CalendarClock, Braces, Cookie, Workflow,
} from '@lucide/vue';
import RunCardBody from './RunCardBody.vue';

const props = defineProps({
    activity: { type: Object, required: true },
    liveRun: { type: Object, default: null },
});

const emit = defineEmits(['open-lightbox']);

const { t } = useI18n();

const TYPE_META = {
    run_triggered:         { icon: Play,         accent: 'var(--md-sys-color-primary)' },
    run_completed:         { icon: CircleCheck,  accent: 'var(--md-ext-color-success)' },
    run_cancelled:         { icon: Ban,           accent: 'var(--md-sys-color-on-surface-variant)' },
    suite_created:         { icon: FolderPlus,   accent: 'var(--md-sys-color-tertiary)' },
    suite_updated:         { icon: SquarePen,    accent: 'var(--md-sys-color-tertiary)' },
    suite_duplicated:      { icon: Copy,         accent: 'var(--md-sys-color-tertiary)' },
    test_created:          { icon: FilePlus2,    accent: 'var(--md-sys-color-secondary)' },
    test_updated:          { icon: Pencil,       accent: 'var(--md-sys-color-secondary)' },
    test_code_updated:     { icon: Code,         accent: 'var(--md-sys-color-secondary)' },
    test_deleted:          { icon: Trash2,       accent: 'var(--md-sys-color-error)' },
    test_status_changed:   { icon: CirclePause,  accent: 'var(--md-ext-color-warning)' },
    suite_members_changed:  { icon: UserPlus,     accent: 'var(--md-ext-color-warning)' },
    user_registered:       { icon: LogIn,        accent: 'var(--md-ext-color-success)' },
    user_created:          { icon: UserCog,      accent: 'var(--md-sys-color-error)' },
    schedule_updated:      { icon: CalendarClock, accent: 'var(--md-sys-color-primary)' },
    variables_updated:     { icon: Braces,       accent: 'var(--md-ext-color-warning)' },
    cookies_updated:       { icon: Cookie,       accent: 'var(--md-ext-color-warning)' },
    integration_updated:   { icon: Workflow,     accent: 'var(--md-sys-color-tertiary)' },
};

const meta = computed(() => TYPE_META[props.activity.type] ?? null);
const payload = computed(() => props.activity.payload ?? {});

const isRunType = computed(() => ['run_triggered', 'run_completed', 'run_cancelled'].includes(props.activity.type));
const run = computed(() => props.liveRun ?? props.activity.subject ?? null);

// Who/what performed the action: the actor user when known, otherwise the
// run trigger source (CI webhook, scheduler, MCP) shown as the actor label.
const sourceLabel = computed(() => {
    if (props.activity.actor) return null;
    const source = payload.value.triggered_by;
    if (source === 'ci') return 'CI Webhook';
    if (source === 'schedule') return 'Schedule';
    if (source === 'mcp') return 'MCP';
    return null;
});

const actorLabel = computed(() => props.activity.actor?.name ?? sourceLabel.value ?? t('feed.system'));

// Uniform sentence: {actor} {verb} {object} {preposition} {suite link}
const parts = computed(() => {
    const p = payload.value;
    const type = props.activity.type;

    const byType = {
        run_triggered: () => ({ verb: t('feed.actions.run_triggered'), preposition: 'in' }),
        run_completed: () => ({ verb: t('feed.actions.run_completed'), preposition: 'in' }),
        run_cancelled: () => ({ verb: t('feed.actions.run_cancelled'), preposition: 'in' }),
        suite_created: () => ({ verb: t('feed.actions.suite_created') }),
        suite_updated: () => ({ verb: t('feed.actions.suite_updated') }),
        suite_duplicated: () => ({ verb: t('feed.actions.suite_duplicated'), object: p.source_suite_name, preposition: 'as' }),
        test_created: () => p.count != null
            ? { verb: t('feed.actions.test_created_count', { count: p.count }), preposition: 'in' }
            : { verb: t('feed.actions.test_created'), object: p.name, preposition: 'in' },
        test_updated: () => ({ verb: t('feed.actions.test_updated'), object: p.name, preposition: 'in' }),
        test_code_updated: () => ({ verb: t('feed.actions.test_code_updated'), object: p.name, preposition: 'in' }),
        test_deleted: () => p.count != null
            ? { verb: t('feed.actions.test_deleted_count', { count: p.count }), preposition: 'in' }
            : { verb: t('feed.actions.test_deleted'), object: p.name, preposition: 'in' },
        test_status_changed: () => {
            const state = p.status === 'active' ? 'active' : 'disabled';
            return p.count != null
                ? { verb: t(`feed.actions.test_status_changed_count_${state}`, { count: p.count }), preposition: 'in' }
                : { verb: t(`feed.actions.test_status_changed_${state}`), object: p.name, preposition: 'in' };
        },
        suite_members_changed: () => ({
            verb: t(`feed.actions.member_${p.action}`),
            object: p.member_name,
            preposition: p.action === 'removed' ? 'from' : 'to',
        }),
        user_registered: () => ({ verb: t('feed.actions.user_registered') }),
        user_created: () => ({ verb: t('feed.actions.user_created'), object: p.user_name }),
        schedule_updated: () => ({ verb: t(`feed.actions.schedule_${p.action}`), preposition: 'in' }),
        variables_updated: () => ({ verb: t('feed.actions.variables_updated', { count: p.count ?? 0 }), preposition: 'in' }),
        cookies_updated: () => ({ verb: t('feed.actions.cookies_updated', { count: p.count ?? 0 }), preposition: 'in' }),
        integration_updated: () => ({
            verb: t(`feed.actions.integration_${p.action}`, { type: integrationTypeLabel(p.type) }),
            preposition: 'in',
        }),
    };

    return byType[type] ? byType[type]() : { verb: '' };
});

const prepositionLabel = computed(() => {
    switch (parts.value.preposition) {
        case 'in': return t('feed.prepositions.in');
        case 'as': return t('feed.prepositions.as');
        case 'to': return t('feed.prepositions.to');
        case 'from': return t('feed.prepositions.from');
        default: return null;
    }
});

const scheduleMeta = computed(() => {
    if (props.activity.type !== 'schedule_updated' || payload.value.action !== 'set') return null;
    return {
        cron: payload.value.cron_expression,
        timezone: payload.value.timezone,
        enabled: payload.value.is_enabled,
    };
});

function integrationTypeLabel(type) {
    if (type === 'github_action') return 'GitHub Action';
    if (type === 'http_request') return 'HTTP';
    return type ?? '';
}
</script>

<template>
    <!-- No overflow-hidden on the card: it would clip the avatars' hover
         tooltips. The accent bar rounds its own left edge instead. -->
    <Card v-if="meta" variant="outlined" padding="p-0">
        <div class="flex items-stretch">
            <div class="w-1 flex-shrink-0 rounded-l-[var(--md-sys-shape-corner-medium)]" :style="{ background: meta.accent }" />

            <div class="px-4 py-3.5 flex-1 min-w-0">
                <!-- Header: actor + action + target + time -->
                <div class="flex items-start justify-between gap-3">
                    <div class="flex-1 min-w-0 flex items-center gap-2.5">
                        <Avatar
                            v-if="activity.actor"
                            :name="activity.actor.name"
                            :email="activity.actor.email"
                            :avatar-url="activity.actor.avatar_url"
                        />
                        <span
                            v-else
                            class="w-7 h-7 rounded-full bg-[var(--md-sys-color-surface-container-high)] flex items-center justify-center flex-shrink-0"
                            :style="{ color: meta.accent }"
                        >
                            <component :is="meta.icon" :size="14" />
                        </span>

                        <!-- Single-row sentence: glue text never shrinks, the
                             name spans (actor / object / suite) shrink and
                             ellipsize to keep everything on one line. Spacing
                             comes from the flex gap — leading spaces inside
                             flex-item spans would be collapsed away by CSS.
                             The actor name keeps priority: on desktop it does
                             not shrink at all (up to its cap), so the suite /
                             object names absorb the squeeze; on mobile it
                             participates so nothing gets hard-clipped. -->
                        <p class="md-body-medium text-[var(--md-sys-color-on-surface)] min-w-0 flex items-center gap-x-1.5 overflow-hidden">
                            <span class="font-semibold truncate min-w-0 shrink sm:shrink-0 max-w-[8rem] sm:max-w-[16rem]">{{ actorLabel }}</span>
                            <span class="flex-shrink-0 whitespace-nowrap">{{ parts.verb }}</span>
                            <span v-if="parts.object" class="font-medium truncate min-w-0">{{ parts.object }}</span>
                            <span v-if="prepositionLabel" class="opacity-70 flex-shrink-0 whitespace-nowrap">{{ prepositionLabel }}</span>
                            <Link
                                v-if="activity.suite"
                                :href="`/sorify/suites/${activity.suite.id}`"
                                class="text-[var(--md-sys-color-primary)] hover:underline truncate min-w-0"
                                :title="activity.suite.name"
                            >{{ activity.suite.name }}</Link>
                        </p>
                    </div>

                    <span class="group relative inline-flex items-center md-label-small text-[var(--md-sys-color-on-surface-variant)] whitespace-nowrap flex-shrink-0">
                        {{ formatRelativeTime(activity.created_at) }}
                        <div class="pointer-events-none absolute right-0 top-full mt-1.5 z-20 hidden group-hover:flex flex-col items-center whitespace-nowrap">
                            <div class="px-2.5 py-1.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-inverse-surface)] text-[var(--md-sys-color-inverse-on-surface)] md-label-small shadow-elevation-1">
                                {{ formatDate(activity.created_at) }}
                            </div>
                        </div>
                    </span>
                </div>

                <!-- Run bodies (the large cards) -->
                <RunCardBody
                    v-if="isRunType"
                    :activity="activity"
                    :run="run"
                    @open-lightbox="(shots, i) => emit('open-lightbox', shots, i)"
                />

                <!-- Schedule meta -->
                <div v-else-if="scheduleMeta" class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 md-label-medium text-[var(--md-sys-color-on-surface-variant)]">
                    <span class="inline-flex items-center gap-1"><CalendarClock :size="13" /><code class="text-[var(--md-sys-color-on-surface)]">{{ scheduleMeta.cron }}</code></span>
                    <span class="opacity-70">{{ scheduleMeta.timezone }}</span>
                    <span
                        class="px-2 py-0.5 rounded-[var(--md-sys-shape-corner-full)]"
                        :class="scheduleMeta.enabled
                            ? 'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]'
                            : 'bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)]'"
                    >{{ scheduleMeta.enabled ? t('feed.enabled') : t('feed.disabled') }}</span>
                </div>
            </div>
        </div>
    </Card>
</template>

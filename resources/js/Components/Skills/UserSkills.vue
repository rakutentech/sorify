<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { Button, Card } from '@/Components/ui';
import AiButton from '@/Components/Agent/AiButton.vue';
import SkillEditForm from '@/Components/Skills/SkillEditForm.vue';
import { openAgentDrawer } from '@/composables/useAgentDrawer.js';
import {
    BookMarked, Download, Eye, EyeOff, Link2, LoaderCircle, Pencil, Plus, Trash2, Unlink,
} from '@lucide/vue';

const { t } = useI18n();

const props = defineProps({
    skills: { type: Array, default: () => [] },
    limit: { type: Number, default: 25 },
});

const atLimit = computed(() => props.skills.length >= props.limit);

const blankForm = () => ({
    id: null,
    name: '',
    description: '',
    content: '',
    is_public: false,
    copied_from_id: null,
    copies_count: 0,
});

const editing = ref(null);
const saving = ref(false);
const deletingId = ref(null);
const error = ref(null);

const editingExisting = computed(() => editing.value !== null && editing.value.id !== null);

function csrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function isBlank(value) {
    return value === null || value === undefined || String(value).trim() === '';
}

function startCreate() {
    editing.value = blankForm();
    error.value = null;
}

function startEdit(skill) {
    editing.value = { ...blankForm(), ...skill };
    error.value = null;
}

function cancelEdit() {
    editing.value = null;
    error.value = null;
}

async function save() {
    if (isBlank(editing.value.name) || isBlank(editing.value.content)) return;

    saving.value = true;
    error.value = null;

    const payload = {
        name: editing.value.name,
        description: isBlank(editing.value.description) ? null : editing.value.description,
        content: editing.value.content,
        is_public: !! editing.value.is_public,
    };

    try {
        const response = await fetch(
            editingExisting.value ? `/sorify/skills/${editing.value.id}` : '/sorify/skills',
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
        router.reload({ preserveScroll: true, preserveState: true });
    } catch (e) {
        error.value = e.message;
    } finally {
        saving.value = false;
    }
}

async function remove(skill) {
    if (! confirm(t('skills.confirmDelete', { name: skill.name }))) return;

    deletingId.value = skill.id;

    try {
        await fetch(`/sorify/skills/${skill.id}`, {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        });

        if (editing.value?.id === skill.id) cancelEdit();

        router.reload({ preserveScroll: true, preserveState: true });
    } finally {
        deletingId.value = null;
    }
}

// ── AI agent integration ─────────────────────────────────────────────────────
// The drawer's context mirrors the page-aware agent context (page:
// profile_skills + own skills), plus the full content of the skill being
// edited, so the agent can apply changes through the update_skill /
// create_skill MCP tools.

function skillsContext(activeSkill = null) {
    const payload = {
        page: 'profile_skills',
        own_skills: props.skills.map((skill) => ({
            skill_id: skill.id,
            name: skill.name,
            is_public: !!skill.is_public,
        })),
    };

    if (activeSkill) {
        payload.active_skill = {
            skill_id: activeSkill.id,
            name: activeSkill.name,
            description: activeSkill.description ?? null,
            is_public: !!activeSkill.is_public,
            content: activeSkill.content,
        };
    }

    return JSON.stringify(payload, null, 2);
}

function aiCreateSkill() {
    openAgentDrawer({
        context: skillsContext(),
        message: t('agent.prompts.createSkill'),
    });
}

function aiEditSkill(skill) {
    openAgentDrawer({
        context: skillsContext(skill),
        message: t('agent.prompts.editSkill', { name: skill.name }),
    });
}
</script>

<template>
    <Card padding="p-6">
        <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
            <h2 class="md-title-medium text-[var(--md-sys-color-on-surface)] flex items-center gap-2">
                <BookMarked :size="20" :style="{ color: 'var(--md-sys-color-primary)' }" />
                {{ t('skills.title') }}
                <span
                    class="md-label-small px-2 py-0.5 rounded-full"
                    :class="atLimit
                        ? 'bg-[var(--md-ext-color-warning-container)] text-[var(--md-ext-color-on-warning-container)]'
                        : 'bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface-variant)]'"
                >
                    {{ t('skills.skillUsage', { count: skills.length, limit }) }}
                </span>
            </h2>
            <div class="flex items-center gap-2">
                <!-- The Button sets pointer-events: none on itself when
                     disabled, which swallows the native title tooltip —
                     the tooltip lives on the wrapping span instead. -->
                <span :title="atLimit ? t('skills.limitReachedTitle', { limit }) : null" class="inline-flex">
                    <Button variant="tonal" size="sm" :disabled="atLimit" @click="startCreate">
                        <template #leading><Plus :size="16" /></template>
                        {{ t('skills.add') }}
                    </Button>
                </span>
                <AiButton v-if="!editing" :label="t('agent.buttons.createSkill')" :title="atLimit ? t('skills.limitReachedTitle', { limit }) : null" @click="aiCreateSkill" />
            </div>
        </div>

        <p class="md-body-small text-[var(--md-sys-color-on-surface-variant)] mb-4">
            {{ t('skills.hint') }} {{ t('skills.limitCaption', { limit }) }}
        </p>

        <!-- New skill: its own collapsible block above the list -->
        <div
            v-if="editing && !editingExisting"
            class="rounded-[var(--md-sys-shape-corner-medium)] bg-[var(--md-sys-color-surface-container-lowest)] p-4 mb-4"
        >
            <SkillEditForm :form="editing" :saving="saving" :error="error" @save="save" @cancel="cancelEdit" />
        </div>

        <div v-if="skills.length === 0 && !editing" class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">
            {{ t('skills.empty') }}
        </div>

        <!-- Each skill expands its own collapsible edit block in place -->
        <ul v-if="skills.length" class="space-y-2">
            <li
                v-for="skill in skills"
                :key="skill.id"
                class="rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-highest)] px-4 py-3"
            >
                <div class="flex items-center gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="md-label-large text-[var(--md-sys-color-on-surface)] truncate">{{ skill.name }}</p>
                            <span
                                class="md-label-small flex-shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full"
                                :class="skill.is_public
                                    ? 'bg-[var(--md-ext-color-success-container)] text-[var(--md-ext-color-on-success-container)]'
                                    : 'bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface-variant)]'"
                            >
                                <component :is="skill.is_public ? Eye : EyeOff" :size="10" />
                                {{ skill.is_public ? t('skills.publicBadge') : t('skills.privateBadge') }}
                            </span>
                            <span
                                v-if="skill.copies_count > 0"
                                class="md-label-small flex-shrink-0 inline-flex items-center gap-1 text-[var(--md-sys-color-on-surface-variant)]"
                                :title="t('skills.installsCount', { count: skill.copies_count })"
                            >
                                <Download :size="10" />
                                {{ skill.copies_count }}
                            </span>
                            <!-- Installed copy: where it came from. Links to the
                                 original on the shared Skills page; when the
                                 original has been deleted the copy survives
                                 (installs are detached) but there is nothing
                                 left to link to. -->
                            <Link
                                v-if="skill.original"
                                :href="`/sorify/skills/browse?skill=${skill.original.id}`"
                                class="md-label-small min-w-0 inline-flex items-center gap-1 text-[var(--md-sys-color-primary)] hover:underline"
                                :title="skill.original.author_name
                                    ? t('skills.copiedFromTitle', { name: skill.original.name, author: skill.original.author_name })
                                    : t('skills.copiedFromTitleNoAuthor', { name: skill.original.name })"
                            >
                                <Link2 :size="10" class="flex-shrink-0" />
                                <span class="truncate">{{ t('skills.copiedFrom', { name: skill.original.name }) }}</span>
                            </Link>
                            <span
                                v-else-if="skill.copied_from_id"
                                class="md-label-small flex-shrink-0 inline-flex items-center gap-1 text-[var(--md-sys-color-on-surface-variant)] opacity-70"
                                :title="t('skills.originalDeletedTitle')"
                            >
                                <Unlink :size="10" />
                                {{ t('skills.originalDeleted') }}
                            </span>
                        </div>
                        <p v-if="skill.description" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] truncate">{{ skill.description }}</p>
                    </div>
                    <AiButton :label="t('agent.buttons.editSkill')" size="xs" @click="aiEditSkill(skill)" />
                    <Button variant="text" size="sm" @click="startEdit(skill)">
                        <template #leading><Pencil :size="14" /></template>
                        {{ t('skills.edit') }}
                    </Button>
                    <button
                        class="p-1.5 rounded-full text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-error)] transition-colors disabled:opacity-50"
                        :title="t('skills.delete')"
                        :disabled="deletingId === skill.id"
                        @click="remove(skill)"
                    >
                        <LoaderCircle v-if="deletingId === skill.id" :size="16" class="animate-spin" />
                        <Trash2 v-else :size="16" />
                    </button>
                </div>
                <!-- This skill's own collapsible edit block -->
                <div
                    v-if="editing && editing.id === skill.id"
                    class="mt-3 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container-lowest)] p-4"
                >
                    <SkillEditForm :form="editing" is-edit :saving="saving" :error="error" @save="save" @cancel="cancelEdit" />
                </div>
            </li>
        </ul>
    </Card>
</template>

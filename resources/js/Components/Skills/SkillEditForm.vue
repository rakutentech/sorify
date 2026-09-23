<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button, MarkdownRenderer } from '@/Components/ui';
import { Check, Eye, EyeOff, LoaderCircle, X } from '@lucide/vue';

// The shared skill editor — used both by the profile page's "new skill"
// block and by each skill's own collapsible edit block.
const props = defineProps({
    // The reactive form object owned by the parent (UserSkills' `editing`).
    form: { type: Object, required: true },
    isEdit: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    error: { type: String, default: null },
});

const emit = defineEmits(['save', 'cancel']);

const { t } = useI18n();

const previewing = ref(false);

function isBlank(value) {
    return value === null || value === undefined || String(value).trim() === '';
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="md-label-large text-[var(--md-sys-color-on-surface)]">
                {{ isEdit ? t('skills.editSkill') : t('skills.newSkill') }}
            </h3>
            <button class="text-[var(--md-sys-color-on-surface-variant)] hover:text-[var(--md-sys-color-on-surface)]" @click="emit('cancel')">
                <X :size="16" />
            </button>
        </div>

        <label class="block">
            <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('skills.name') }}</span>
            <input
                v-model="form.name"
                type="text"
                maxlength="100"
                class="mt-1 w-full md-body-large rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)]"
                :placeholder="t('skills.namePlaceholder')"
            >
        </label>

        <label class="block">
            <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('skills.description') }}</span>
            <input
                v-model="form.description"
                type="text"
                maxlength="500"
                class="mt-1 w-full md-body-large rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)]"
                :placeholder="t('skills.descriptionPlaceholder')"
            >
        </label>

        <!-- Content: editor / preview toggle -->
        <div>
            <div class="flex items-center justify-between mb-1">
                <span class="md-label-large text-[var(--md-sys-color-on-surface)]">{{ t('skills.content') }}</span>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 md-label-small px-2.5 py-1 rounded-full transition-colors"
                    :class="previewing
                        ? 'bg-[var(--md-sys-color-secondary-container)] text-[var(--md-sys-color-on-secondary-container)]'
                        : 'text-[var(--md-sys-color-on-surface-variant)] hover:bg-[var(--md-sys-color-surface-container)]'"
                    @click="previewing = !previewing"
                >
                    <Eye v-if="!previewing" :size="12" />
                    <EyeOff v-else :size="12" />
                    {{ previewing ? t('skills.editMarkdown') : t('skills.preview') }}
                </button>
            </div>
            <textarea
                v-if="!previewing"
                v-model="form.content"
                rows="12"
                class="w-full md-body-medium font-mono text-sm rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container)] text-[var(--md-sys-color-on-surface)] px-3.5 py-2.5 outline-none focus:ring-2 ring-[var(--md-sys-color-primary)] resize-y"
                :placeholder="t('skills.contentPlaceholder')"
            />
            <div
                v-else
                class="min-h-[12rem] max-h-[28rem] overflow-y-auto slim-scrollbar rounded-[var(--md-sys-shape-corner-extra-small)] bg-[var(--md-sys-color-surface-container)] px-3.5 py-2.5"
            >
                <p v-if="form.content.trim() === ''" class="md-body-small text-[var(--md-sys-color-on-surface-variant)] py-6 text-center">
                    {{ t('skills.previewEmpty') }}
                </p>
                <MarkdownRenderer v-else :content="form.content" density="compact" />
            </div>
        </div>

        <!-- Public switch -->
        <label class="flex items-start gap-3 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-surface-container)] px-3.5 py-3 cursor-pointer">
            <button
                type="button"
                role="switch"
                :aria-checked="form.is_public"
                class="relative flex-shrink-0 w-10 h-6 rounded-full transition-colors mt-0.5"
                :class="form.is_public
                    ? 'bg-[var(--md-sys-color-primary)]'
                    : 'bg-[var(--md-sys-color-surface-container-highest)] border border-[var(--md-sys-color-outline)]'"
                @click="form.is_public = !form.is_public"
            >
                <span
                    class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full transition-transform bg-[var(--md-sys-color-on-primary)] shadow-sm"
                    :class="form.is_public ? 'translate-x-4 bg-[var(--md-sys-color-on-primary)]' : 'bg-[var(--md-sys-color-outline)]'"
                />
            </button>
            <span>
                <span class="md-label-large text-[var(--md-sys-color-on-surface)] block">{{ t('skills.publicToggle') }}</span>
                <span class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">{{ t('skills.publicHint') }}</span>
            </span>
        </label>

        <p v-if="error" class="md-body-small text-[var(--md-sys-color-error)]">{{ error }}</p>

        <div class="flex items-center gap-2">
            <Button variant="filled" size="sm" :disabled="saving || isBlank(form.name) || isBlank(form.content)" @click="emit('save')">
                <LoaderCircle v-if="saving" :size="16" class="animate-spin" />
                <Check v-else :size="16" />
                {{ t('skills.save') }}
            </Button>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Avatar from './Avatar.vue';

const props = defineProps({
    users: { type: Array, default: () => [] },
    suiteId: { type: [Number, String], default: null },
    max: { type: Number, default: 3 },
});

const overflowCount = computed(() => Math.max(props.users.length - props.max, 0));
const visibleUsers = computed(() =>
    overflowCount.value > 0 ? props.users.slice(0, props.max - 1) : props.users.slice(0, props.max)
);
</script>

<template>
    <div class="flex flex-wrap items-center -space-x-2 gap-y-2">
        <Avatar v-for="user in visibleUsers" :key="user.id" :name="user.name" :email="user.email" :avatar-url="user.avatar_url" />

        <component
            :is="suiteId ? Link : 'div'"
            v-if="overflowCount > 0"
            :href="suiteId ? `/sorify/suites/${suiteId}` : undefined"
            class="group relative block"
        >
            <div class="w-7 h-7 rounded-full ring-2 ring-[var(--md-sys-color-surface-container-low)] bg-[var(--md-sys-color-surface-container-high)] text-[var(--md-sys-color-on-surface-variant)] flex items-center justify-center md-label-small font-medium select-none hover:bg-[var(--md-sys-color-secondary-container)] hover:text-[var(--md-sys-color-on-secondary-container)] transition-colors">
                +{{ overflowCount }}
            </div>
            <div class="pointer-events-none absolute left-1/2 -translate-x-1/2 bottom-full mb-1.5 z-20 hidden group-hover:flex flex-col items-center whitespace-nowrap">
                <div class="px-2.5 py-1.5 rounded-[var(--md-sys-shape-corner-small)] bg-[var(--md-sys-color-inverse-surface)] text-[var(--md-sys-color-inverse-on-surface)] md-label-small shadow-elevation-1">
                    View all {{ users.length }} members
                </div>
            </div>
        </component>

        <p v-if="!users.length" class="md-body-small text-[var(--md-sys-color-on-surface-variant)]">—</p>
    </div>
</template>

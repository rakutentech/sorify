<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Card, Button, TextField, Modal } from '@/Components/ui';
import CopyableSecret from '@/Components/CopyableSecret.vue';
import { formatDate } from '@/utils/date';

const props = defineProps({
    users: Array,
});

const page = usePage();
const currentUserId = computed(() => page.props.auth?.user?.id ?? null);

const showAddModal = ref(false);

const addForm = useForm({
    name: '',
    email: '',
    password: '',
    role: 'member',
});

function openAddModal() {
    addForm.reset();
    showAddModal.value = true;
}

function submitAdd() {
    addForm.post('/sorify/admin/users', {
        onSuccess: () => {
            showAddModal.value = false;
            addForm.reset();
        },
    });
}

function deleteUser(user) {
    if (!confirm(`Delete user "${user.name}" (${user.email})? This cannot be undone.`)) return;
    router.delete(`/sorify/admin/users/${user.id}`);
}

function changeRole(user, event) {
    router.put(`/sorify/admin/users/${user.id}`, { role: event.target.value });
}

function roleOf(user) {
    if (user.is_admin) return 'admin';
    if (user.is_view_only) return 'viewer';
    return 'member';
}

function resetPassword(user) {
    if (!confirm(`Generate a password reset link for "${user.name}" (${user.email})?`)) return;
    router.post(`/sorify/admin/users/${user.id}/reset-password`);
}

const resetLink = ref(null);

watch(
    () => page.props.flash?.reset_link,
    (value) => {
        if (value) resetLink.value = value;
    },
);
</script>

<template>
    <AppLayout>
        <Head title="Admin — Users" />

        <div class="space-y-5">
            <div class="flex items-center justify-between">
                <h1 class="md-title-large text-[var(--md-sys-color-on-surface)]">Users</h1>
                <Button variant="filled" @click="openAddModal">Add User</Button>
            </div>

            <!-- Users Table -->
            <Card padding="p-0" class="overflow-hidden">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-[var(--md-sys-color-outline-variant)] text-left bg-[var(--md-sys-color-surface-container-low)]">
                            <th class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Name</th>
                            <th class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Email</th>
                            <th class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Role</th>
                            <th class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Joined</th>
                            <th class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Last Active</th>
                            <th class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)] uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--md-sys-color-outline-variant)]">
                        <tr v-for="user in users" :key="user.id" class="hover:bg-[var(--md-sys-color-surface-container-low)] transition-colors">
                            <td class="px-4 py-3 md-body-medium font-medium text-[var(--md-sys-color-on-surface)]">{{ user.name }}</td>
                            <td class="px-4 py-3 md-body-medium text-[var(--md-sys-color-on-surface-variant)]">{{ user.email }}</td>
                            <td class="px-4 py-3">
                                <select
                                    :value="roleOf(user)"
                                    :disabled="user.id === currentUserId"
                                    :title="user.id === currentUserId ? 'You cannot change your own role.' : 'Change role'"
                                    @change="changeRole(user, $event)"
                                    class="md-label-small font-medium rounded-[var(--md-sys-shape-corner-extra-small)] border border-[var(--md-sys-color-outline-variant)] bg-[var(--md-sys-color-surface-container-high)] px-2 py-1 disabled:opacity-60 disabled:cursor-not-allowed"
                                >
                                    <option value="admin">Admin</option>
                                    <option value="member">Member</option>
                                    <option value="viewer">Viewer</option>
                                </select>
                            </td>
                            <td class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ formatDate(user.created_at) }}</td>
                            <td class="px-4 py-3 md-label-small text-[var(--md-sys-color-on-surface-variant)]">{{ user.last_login_at ? formatDate(user.last_login_at) : 'Never' }}</td>
                            <td class="px-4 py-3 space-x-3">
                                <button
                                    @click="resetPassword(user)"
                                    class="md-label-small text-[var(--md-sys-color-primary)] hover:underline transition-colors"
                                    title="Generate a password reset link"
                                >
                                    Reset Password
                                </button>
                                <button
                                    @click="deleteUser(user)"
                                    class="md-label-small text-[var(--md-sys-color-error)] hover:underline transition-colors"
                                    title="Delete user"
                                >
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!users.length">
                            <td colspan="6" class="px-4 py-8 text-center md-body-medium text-[var(--md-sys-color-on-surface-variant)]">No users found.</td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>

        <!-- Add User Modal -->
        <Modal :show="showAddModal" title="Add User" max-width="max-w-md" @close="showAddModal = false">
                    <form @submit.prevent="submitAdd" class="px-6 py-5 space-y-4">
                        <TextField v-model="addForm.name" label="Name" required :error="addForm.errors.name" />
                        <TextField v-model="addForm.email" label="Email" type="email" required :error="addForm.errors.email" />
                        <TextField
                            v-model="addForm.password"
                            label="Password"
                            type="password"
                            required
                            autocomplete="new-password"
                            :error="addForm.errors.password"
                        />

                        <div>
                            <label class="block md-label-large text-[var(--md-sys-color-on-surface)] mb-1.5">Role</label>
                            <select
                                v-model="addForm.role"
                                class="w-full bg-[var(--md-sys-color-surface-container-lowest)] border border-[var(--md-sys-color-outline)] rounded-[var(--md-sys-shape-corner-small)] px-3.5 py-2.5 md-body-medium text-[var(--md-sys-color-on-surface)] focus:outline-none focus:ring-2 focus:ring-[var(--md-sys-color-primary)] focus:border-transparent"
                            >
                                <option value="admin">Admin</option>
                                <option value="member">Member</option>
                                <option value="viewer">Viewer</option>
                            </select>
                            <p v-if="addForm.errors.role" class="md-body-small text-[var(--md-sys-color-error)] mt-1.5">{{ addForm.errors.role }}</p>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <Button type="button" variant="text" @click="showAddModal = false">Cancel</Button>
                            <Button type="submit" variant="filled" :disabled="addForm.processing">
                                <span v-if="addForm.processing">Creating…</span>
                                <span v-else>Create User</span>
                            </Button>
                        </div>
                    </form>
        </Modal>

        <!-- Reset Password Link Modal -->
        <Modal :show="!!resetLink" title="Password Reset Link" max-width="max-w-md" @close="resetLink = null">
                    <div class="px-6 py-5">
                        <p class="md-body-medium text-[var(--md-sys-color-on-surface-variant)] mb-4">
                            A reset email was sent to <strong>{{ resetLink?.email }}</strong>. If SMTP delivery fails on this
                            infra, copy the link below and send it to the user directly.
                        </p>

                        <CopyableSecret v-if="resetLink" :value="resetLink.link" />
                    </div>
                    <template #footer>
                        <Button type="button" variant="filled" @click="resetLink = null">Close</Button>
                    </template>
        </Modal>
    </AppLayout>
</template>

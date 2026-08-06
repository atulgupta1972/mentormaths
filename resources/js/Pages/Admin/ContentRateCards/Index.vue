<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    rateCards: { type: Array, default: () => [] },
    boards: { type: Array, default: () => [] },
    gradeLevels: { type: Array, default: () => [] },
});

const page = usePage();
const editingId = ref(null);

const form = useForm({
    board_id: '',
    grade_level_id: '',
    syllabus_chapter_id: '',
    content_type: 'textbook_chapter_mcq',
    default_amount_inr: 5000,
    admin_notes: '',
});

const editForm = useForm({
    default_amount_inr: 0,
    admin_notes: '',
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        board_id: data.board_id || null,
        grade_level_id: data.grade_level_id || null,
        syllabus_chapter_id: data.syllabus_chapter_id || null,
    })).post(route('admin.content-rate-cards.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('admin_notes'),
    });
};

const startEdit = (card) => {
    editingId.value = card.id;
    editForm.default_amount_inr = card.default_amount_inr;
    editForm.admin_notes = card.admin_notes ?? '';
    editForm.clearErrors();
};

const cancelEdit = () => {
    editingId.value = null;
    editForm.reset();
    editForm.clearErrors();
};

const saveEdit = (cardId) => {
    editForm.put(route('admin.content-rate-cards.update', cardId), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
            editForm.reset();
        },
    });
};

const formatInr = (amount) => `₹${Number(amount).toLocaleString('en-IN')}`;

const scopeLabel = (card) => {
    const parts = [
        card.board?.name || 'Any board',
        card.grade_level?.name || 'Any class',
    ];
    if (card.syllabus_chapter) {
        parts.push(`Ch ${card.syllabus_chapter.chapter_number}`);
    }
    return parts.join(' · ');
};
</script>

<template>
    <Head title="Content rate matrix" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Content rate matrix</h2>
                <p class="text-sm text-gray-500">Default pay per chapter. More specific rows override broader ones.</p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-4 px-4 sm:px-6">
                <div v-if="page.props.flash?.success" class="rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ page.props.flash.success }}
                </div>

                <form class="grid gap-3 rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-200 sm:grid-cols-2" @submit.prevent="submit">
                    <div>
                        <InputLabel value="Board (optional)" />
                        <select v-model="form.board_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            <option value="">Any board</option>
                            <option v-for="b in boards" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel value="Class (optional)" />
                        <select v-model="form.grade_level_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            <option value="">Any class</option>
                            <option v-for="g in gradeLevels" :key="g.id" :value="g.id">{{ g.name }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel value="Default amount (₹)" />
                        <input v-model="form.default_amount_inr" type="number" min="100" class="mt-1 block w-full rounded-md border-gray-300 text-sm" required>
                    </div>
                    <div class="sm:col-span-2">
                        <InputLabel value="Notes (e.g. diagram-heavy chapters)" />
                        <input v-model="form.admin_notes" type="text" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <PrimaryButton :disabled="form.processing">Add rate row</PrimaryButton>
                    </div>
                </form>

                <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Scope</th>
                                <th class="px-4 py-3">Amount</th>
                                <th class="px-4 py-3">Notes</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="card in rateCards" :key="card.id" :class="editingId === card.id ? 'bg-sky-50/60' : ''">
                                <td class="px-4 py-3">{{ scopeLabel(card) }}</td>
                                <td class="px-4 py-3">
                                    <input
                                        v-if="editingId === card.id"
                                        v-model="editForm.default_amount_inr"
                                        type="number"
                                        min="100"
                                        class="w-28 rounded-md border-gray-300 text-sm"
                                    >
                                    <span v-else class="font-medium">{{ formatInr(card.default_amount_inr) }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <input
                                        v-if="editingId === card.id"
                                        v-model="editForm.admin_notes"
                                        type="text"
                                        class="w-full min-w-[8rem] rounded-md border-gray-300 text-sm"
                                        placeholder="Optional notes"
                                    >
                                    <span v-else class="text-gray-600">{{ card.admin_notes || '—' }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div v-if="editingId === card.id" class="flex justify-end gap-2">
                                        <PrimaryButton type="button" class="!px-3 !py-1.5 !text-xs" :disabled="editForm.processing" @click="saveEdit(card.id)">
                                            Save
                                        </PrimaryButton>
                                        <SecondaryButton type="button" class="!px-3 !py-1.5 !text-xs" @click="cancelEdit">
                                            Cancel
                                        </SecondaryButton>
                                    </div>
                                    <button
                                        v-else
                                        type="button"
                                        class="text-sm font-medium text-indigo-600 hover:underline"
                                        @click="startEdit(card)"
                                    >
                                        Edit
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!rateCards.length">
                                <td colspan="4" class="px-4 py-8 text-center text-gray-500">No rates configured yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import McqOptionLine from '@/Components/McqOptionLine.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    item: Object,
    inQueue: { type: Boolean, default: false },
    queuePosition: { type: Number, default: null },
    queueTotal: { type: Number, default: null },
});

const page = usePage();
const form = useForm({ option_id: null, answer_text: '', queue: props.inQueue ? 'all' : null });

const isFillInBlank = computed(() => props.item?.question_type === 'fill_in_blank');

const answerPlaceholder = computed(() => {
    const format = props.item?.answer_format;

    if (format === 'integer') {
        return 'Enter a whole number, e.g. -4';
    }

    if (format === 'decimal') {
        return 'Enter a decimal, e.g. 3.5';
    }

    if (format === 'fraction') {
        return 'Enter a fraction, e.g. 3/4 or 1 1/2';
    }

    if (format === 'text') {
        return 'Enter your answer, e.g. < or > or =';
    }

    return 'Enter your answer';
});

const submitMcqAnswer = (optionId) => {
    form.option_id = optionId;
    form.answer_text = '';
    form.post(route('student.resolutions.answer', props.item.id), {
        preserveScroll: true,
    });
};

const submitBlankAnswer = () => {
    form.option_id = null;
    form.post(route('student.resolutions.answer', props.item.id), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Resolve sum" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">
                        {{ inQueue ? 'Clear all doubts' : 'Answer to clear' }}
                        <span v-if="inQueue && queuePosition && queueTotal" class="text-indigo-600">
                            · {{ queuePosition }} of {{ queueTotal }}
                        </span>
                    </p>
                    <h2 class="text-xl font-semibold text-gray-800">
                        <span v-if="item.set_code" class="font-mono text-indigo-600">{{ item.set_code }}</span>
                        <span v-else>Clear this sum</span>
                    </h2>
                </div>
                <Link :href="route('dashboard')" class="text-sm text-indigo-600">Dashboard</Link>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-3xl space-y-5 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    Your teacher should have explained this sum. Submit the correct answer to clear it from your help list.
                    <span v-if="inQueue"> The next doubt will appear automatically after a correct answer.</span>
                </div>

                <div v-if="page.props.flash?.success" class="rounded-md bg-emerald-50 p-3 text-sm text-emerald-900">
                    {{ page.props.flash.success }}
                </div>

                <div v-if="page.props.flash?.warning" class="rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                    {{ page.props.flash.warning }}
                </div>
                <div v-if="page.props.flash?.error" class="rounded-md bg-red-50 p-3 text-sm text-red-800">
                    {{ page.props.flash.error }}
                </div>

                <div class="rounded-lg bg-white p-5 shadow-sm">
                    <QuestionBody
                        :question-text="item.question_text"
                        :diagram-url="item.diagram_url"
                        use-html
                    />

                    <div v-if="isFillInBlank" class="mt-4 space-y-3">
                        <p v-if="item.answer_format_label" class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            {{ item.answer_format_label }}
                        </p>
                        <TextInput
                            v-model="form.answer_text"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            class="block w-full max-w-xs text-lg"
                            :placeholder="answerPlaceholder"
                            :disabled="form.processing"
                            @keyup.enter="submitBlankAnswer"
                        />
                        <PrimaryButton
                            type="button"
                            :disabled="form.processing || !form.answer_text.trim()"
                            @click="submitBlankAnswer"
                        >
                            {{ form.processing ? 'Checking…' : 'Submit answer' }}
                        </PrimaryButton>
                    </div>

                    <div v-else class="mt-4 space-y-2">
                        <button
                            v-for="(opt, optIndex) in item.options"
                            :key="opt.id"
                            type="button"
                            class="flex w-full items-start gap-3 rounded-lg border border-gray-200 px-4 py-3 text-left text-sm transition hover:border-indigo-300 hover:bg-indigo-50"
                            :disabled="form.processing"
                            @click="submitMcqAnswer(opt.id)"
                        >
                            <McqOptionLine :index="optIndex" :text="opt.option_text" />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

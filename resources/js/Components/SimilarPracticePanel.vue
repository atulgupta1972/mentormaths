<script setup>
import McqOptionLine from '@/Components/McqOptionLine.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import QuestionBody from '@/Components/QuestionBody.vue';
import TextInput from '@/Components/TextInput.vue';
import axios from 'axios';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    attemptId: { type: Number, required: true },
    wrongCount: { type: Number, default: 0 },
    initialVariants: { type: Array, default: () => [] },
    canGenerate: { type: Boolean, default: false },
    generateRoute: { type: String, required: true },
    checkRoute: { type: String, required: true },
});

const variants = ref([...props.initialVariants]);
const loading = ref(false);
const error = ref('');
const state = reactive({});

const hasVariants = computed(() => variants.value.length > 0);
const showSection = computed(() => props.wrongCount > 0);

const load = async () => {
    if (!props.canGenerate || props.wrongCount === 0) {
        return;
    }

    if (variants.value.length > 0) {
        return;
    }

    loading.value = true;
    error.value = '';

    try {
        const { data } = await axios.post(props.generateRoute);
        variants.value = data.variants ?? [];
    } catch (err) {
        error.value = err.response?.data?.message || 'Could not build similar practice right now.';
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    load();
});

const variantKey = (index) => String(index);

const getState = (index) => state[variantKey(index)] ?? { status: 'idle' };

const isFillBlank = (variant) => variant.type === 'fill_in_blank';

const blankInputs = reactive({});

const submitMcq = async (variant, optionIndex) => {
    const key = variantKey(variant.index);
    state[key] = { status: 'checking' };

    try {
        const { data } = await axios.post(props.checkRoute, {
            variant_index: variant.index,
            option_index: optionIndex,
        });

        state[key] = {
            status: data.correct ? 'correct' : 'wrong',
            message: data.message,
        };

        if (data.correct) {
            variant.student_correct = true;
        }
    } catch (err) {
        state[key] = {
            status: 'wrong',
            message: err.response?.data?.message || 'Could not check your answer.',
        };
    }
};

const submitBlank = async (variant) => {
    const key = variantKey(variant.index);
    const answerText = (blankInputs[key] ?? '').trim();

    if (!answerText) {
        return;
    }

    state[key] = { status: 'checking' };

    try {
        const { data } = await axios.post(props.checkRoute, {
            variant_index: variant.index,
            answer_text: answerText,
        });

        state[key] = {
            status: data.correct ? 'correct' : 'wrong',
            message: data.message,
        };

        if (data.correct) {
            variant.student_correct = true;
        }
    } catch (err) {
        state[key] = {
            status: 'wrong',
            message: err.response?.data?.message || 'Could not check your answer.',
        };
    }
};
</script>

<template>
    <div v-if="showSection" class="rounded-lg border border-sky-200 bg-sky-50/60 p-5 shadow-sm">
        <h3 class="text-sm font-semibold text-sky-950">Similar practice (new numbers)</h3>
        <p class="mt-1 text-xs text-sky-900">
            You got {{ wrongCount }} wrong — here are {{ wrongCount }} similar sum{{ wrongCount === 1 ? '' : 's' }} with different numbers. This does not change your score.
        </p>

        <p v-if="loading" class="mt-4 text-sm text-sky-800">Building similar sums…</p>
        <p v-else-if="error" class="mt-4 text-sm text-rose-800">{{ error }}</p>
        <p v-else-if="!canGenerate && !hasVariants" class="mt-4 text-sm text-sky-800">
            Similar practice is not available on this server yet.
        </p>

        <div v-if="hasVariants" class="mt-4 space-y-4">
            <article
                v-for="variant in variants"
                :key="variant.index"
                class="rounded-lg border bg-white p-4"
                :class="variant.student_correct ? 'border-emerald-200' : 'border-sky-100'"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-semibold text-indigo-600">
                        Similar to Q{{ variant.source_number }}
                    </p>
                    <span
                        v-if="variant.student_correct"
                        class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-800"
                    >
                        Solved
                    </span>
                </div>

                <div class="mt-3">
                    <QuestionBody :question-text="variant.question" />
                </div>

                <div
                    v-if="getState(variant.index).status === 'wrong'"
                    class="mt-3 rounded-md bg-rose-50 px-3 py-2 text-sm text-rose-900"
                >
                    {{ getState(variant.index).message }}
                </div>

                <div
                    v-if="getState(variant.index).status === 'correct' || variant.student_correct"
                    class="mt-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900"
                >
                    {{ getState(variant.index).message || 'Correct! Well done.' }}
                </div>

                <div
                    v-if="!variant.student_correct && getState(variant.index).status !== 'correct'"
                    class="mt-4"
                >
                    <div v-if="isFillBlank(variant)" class="space-y-3">
                        <p v-if="variant.answer_format_label" class="text-xs font-medium uppercase tracking-wide text-gray-500">
                            {{ variant.answer_format_label }}
                        </p>
                        <TextInput
                            v-model="blankInputs[variantKey(variant.index)]"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            class="block w-full max-w-xs text-lg"
                            :disabled="getState(variant.index).status === 'checking'"
                            @keyup.enter="submitBlank(variant)"
                        />
                        <PrimaryButton
                            type="button"
                            :disabled="getState(variant.index).status === 'checking' || !(blankInputs[variantKey(variant.index)] ?? '').trim()"
                            @click="submitBlank(variant)"
                        >
                            {{ getState(variant.index).status === 'checking' ? 'Checking…' : 'Check answer' }}
                        </PrimaryButton>
                    </div>

                    <div v-else class="space-y-2">
                        <button
                            v-for="(option, optIndex) in variant.options"
                            :key="optIndex"
                            type="button"
                            class="flex w-full items-start gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 text-left text-sm transition hover:border-indigo-300"
                            :disabled="getState(variant.index).status === 'checking'"
                            @click="submitMcq(variant, optIndex)"
                        >
                            <McqOptionLine :index="optIndex" :text="option" />
                        </button>
                    </div>
                </div>

                <div v-if="variant.method_hint" class="mt-3 rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-900">
                    <p class="font-semibold">Method</p>
                    <p class="mt-1 whitespace-pre-wrap">{{ variant.method_hint }}</p>
                </div>
            </article>
        </div>
    </div>
</template>

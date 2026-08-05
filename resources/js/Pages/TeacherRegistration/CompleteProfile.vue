<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    application: { type: Object, required: true },
    token: { type: String, required: true },
});

const page = usePage();

const form = useForm({
    city: props.application.city ?? '',
    state: props.application.state ?? '',
    country: props.application.country ?? 'India',
    teaches_english_medium: props.application.teaches_english_medium ?? true,
    teaches_hindi_medium: props.application.teaches_hindi_medium ?? false,
    regional_language: props.application.regional_language ?? '',
});

const submit = () => {
    form.post(route('teacher-registration.profile.update', props.token));
};
</script>

<template>
    <Head title="Complete mentor profile" />

    <div class="min-h-screen bg-gradient-to-b from-violet-50 via-white to-indigo-50/40">
        <header class="border-b border-violet-100/80 bg-white/80 backdrop-blur">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                <Link href="/" class="text-sm font-semibold uppercase tracking-wide text-indigo-600">← Mentor Maths</Link>
            </div>
        </header>

        <main class="mx-auto max-w-2xl px-6 py-10">
            <div class="mb-8 text-center">
                <span class="inline-block rounded-full bg-violet-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-violet-700">Mentor profile</span>
                <h1 class="mt-3 text-3xl font-bold text-gray-900">Complete your details</h1>
                <p class="mt-2 text-gray-600">Hi {{ application.name }} — please add your location and teaching languages.</p>
            </div>

            <div
                v-if="page.props.flash?.success"
                class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900"
            >
                {{ page.props.flash.success }}
            </div>

            <div
                v-if="application.missing_profile_field_labels?.length"
                class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950"
            >
                <p class="font-semibold">Still needed:</p>
                <ul class="mt-1 list-disc pl-5">
                    <li v-for="label in application.missing_profile_field_labels" :key="label">{{ label }}</li>
                </ul>
            </div>

            <form class="space-y-6 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-200" @submit.prevent="submit">
                <section>
                    <h2 class="text-lg font-semibold text-gray-900">Location</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="City *" />
                            <TextInput v-model="form.city" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.city" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="State *" />
                            <TextInput v-model="form.state" class="mt-1 block w-full" placeholder="e.g. Maharashtra" required />
                            <InputError :message="form.errors.state" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Country" />
                            <TextInput v-model="form.country" class="mt-1 block w-full" />
                            <p class="mt-1 text-xs text-gray-500">Default: India</p>
                            <InputError :message="form.errors.country" class="mt-1" />
                        </div>
                    </div>
                </section>

                <section>
                    <h2 class="text-lg font-semibold text-gray-900">Languages you teach in</h2>
                    <p class="mt-1 text-sm text-gray-600">Select all that apply. Add a regional language if you also teach in one.</p>
                    <div class="mt-3 flex flex-wrap gap-4 text-sm">
                        <label class="inline-flex items-center gap-2"><Checkbox v-model:checked="form.teaches_english_medium" /> English</label>
                        <label class="inline-flex items-center gap-2"><Checkbox v-model:checked="form.teaches_hindi_medium" /> Hindi</label>
                    </div>
                    <InputError :message="form.errors.teaches_english_medium" class="mt-2" />
                    <div class="mt-4">
                        <InputLabel value="Regional language (optional)" />
                        <TextInput v-model="form.regional_language" class="mt-1 block w-full" placeholder="e.g. Marathi, Tamil, Bengali" />
                        <InputError :message="form.errors.regional_language" class="mt-1" />
                    </div>
                </section>

                <PrimaryButton type="submit" :disabled="form.processing">
                    Save details
                </PrimaryButton>
            </form>
        </main>
    </div>
</template>

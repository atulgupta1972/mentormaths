<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    application: { type: Object, required: true },
    token: { type: String, required: true },
});

const page = usePage();

const acceptForm = useForm({ response: 'accepted' });
const declineForm = useForm({ response: 'declined' });

const respond = (form) => {
    form.post(route('teacher-registration.offer.respond', props.token));
};
</script>

<template>
    <Head title="Counter offer" />

    <div class="flex min-h-screen items-center justify-center bg-gradient-to-b from-indigo-50 to-white px-6 py-10">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
            <h1 class="text-xl font-bold text-gray-900">Rate counter offer</h1>
            <p class="mt-2 text-sm text-gray-600">Hi {{ application.name }}, please review Mentor Maths&apos;s offer for online doubt solving.</p>

            <div v-if="page.props.flash?.success" class="mt-4 rounded-md bg-green-50 px-3 py-2 text-sm text-green-800">
                {{ page.props.flash.success }}
            </div>

            <dl class="mt-6 space-y-3 text-sm">
                <div class="flex justify-between border-b border-gray-100 pb-2">
                    <dt class="text-gray-500">Your proposed rate</dt>
                    <dd class="font-semibold">₹{{ application.proposed_hourly_rate_inr }}/hour</dd>
                </div>
                <div class="flex justify-between border-b border-gray-100 pb-2">
                    <dt class="text-gray-500">Our counter offer</dt>
                    <dd class="font-semibold text-indigo-700">₹{{ application.counter_hourly_rate_inr }}/hour</dd>
                </div>
                <div class="flex justify-between border-b border-gray-100 pb-2">
                    <dt class="text-gray-500">Commitment</dt>
                    <dd>{{ application.doubt_sessions_per_week }}× / week · {{ application.doubt_hours_per_week }} hrs</dd>
                </div>
            </dl>

            <p v-if="application.counter_offer_message" class="mt-4 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700">
                {{ application.counter_offer_message }}
            </p>

            <div class="mt-6 flex flex-wrap gap-3">
                <PrimaryButton type="button" :disabled="acceptForm.processing || declineForm.processing" @click="respond(acceptForm)">
                    Accept offer
                </PrimaryButton>
                <SecondaryButton type="button" :disabled="acceptForm.processing || declineForm.processing" @click="respond(declineForm)">
                    Decline
                </SecondaryButton>
            </div>
        </div>
    </div>
</template>

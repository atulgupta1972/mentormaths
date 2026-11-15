<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    threads: {
        type: Array,
        default: () => [],
    },
    selected: {
        type: String,
        default: null,
    },
    messages: {
        type: Array,
        default: () => [],
    },
    session_open: {
        type: Boolean,
        default: false,
    },
    webhook_url: {
        type: String,
        required: true,
    },
    webhook_ready: {
        type: Boolean,
        default: false,
    },
});

const page = usePage();
const form = useForm({
    body: '',
});

const selectedThread = computed(() => props.threads.find((thread) => thread.mobile === props.selected) ?? null);
const flashSuccess = computed(() => page.props.flash?.success || '');

function sendReply() {
    if (!props.selected) {
        return;
    }

    form.post(route('admin.whatsapp-inbox.reply', { mobile: props.selected }), {
        preserveScroll: true,
        onSuccess: () => form.reset('body'),
    });
}
</script>

<template>
    <Head title="WhatsApp inbox" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">
                    Setup
                </p>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    WhatsApp inbox
                </h2>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-4 px-4 sm:px-6 lg:px-8">
                <p v-if="flashSuccess" class="rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-800">
                    {{ flashSuccess }}
                </p>

                <div v-if="!webhook_ready" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    Parent replies are not connected yet. On the server, set <span class="font-mono">WHATSAPP_WEBHOOK_VERIFY_TOKEN</span>
                    and <span class="font-mono">WHATSAPP_META_APP_SECRET</span>, then in the Meta app open WhatsApp → Configuration.
                    Callback URL: <span class="font-mono">{{ webhook_url }}</span>.
                    Use the same verify token, and subscribe to the <span class="font-mono">messages</span> field.
                </div>

                <div class="grid overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm md:grid-cols-[18rem_1fr]">
                    <div class="border-b border-gray-200 md:border-b-0 md:border-r">
                        <p v-if="!threads.length" class="px-4 py-6 text-sm text-gray-500">
                            No replies yet. A parent message to the Mentor Maths WhatsApp number will appear here.
                        </p>
                        <Link
                            v-for="thread in threads"
                            :key="thread.mobile"
                            :href="route('admin.whatsapp-inbox.index', { mobile: thread.mobile })"
                            class="block border-b border-gray-100 px-4 py-3 last:border-b-0"
                            :class="thread.mobile === selected ? 'bg-teal-50' : 'hover:bg-gray-50'"
                        >
                            <span class="flex items-center justify-between gap-2">
                                <span class="truncate text-sm font-semibold text-gray-900">{{ thread.title }}</span>
                                <span v-if="thread.unread" class="rounded-full bg-teal-700 px-2 py-0.5 text-[11px] font-semibold text-white">{{ thread.unread }}</span>
                            </span>
                            <span v-if="thread.student_name" class="mt-0.5 block truncate text-xs text-gray-500">{{ thread.student_name }}</span>
                            <span class="mt-1 block truncate text-xs text-gray-600">{{ thread.preview }}</span>
                            <span class="mt-1 block text-[11px] text-gray-400">{{ thread.at }}</span>
                        </Link>
                    </div>

                    <div class="flex min-h-[28rem] flex-col">
                        <div v-if="!selectedThread" class="px-4 py-6 text-sm text-gray-500">
                            Choose a conversation.
                        </div>
                        <template v-else>
                            <div class="border-b border-gray-200 px-4 py-3">
                                <p class="font-semibold text-gray-900">{{ selectedThread.title }}</p>
                                <p v-if="selectedThread.student_name" class="text-xs text-gray-500">{{ selectedThread.student_name }}</p>
                            </div>
                            <div class="flex-1 space-y-3 overflow-y-auto px-4 py-4">
                                <div
                                    v-for="message in messages"
                                    :key="message.id"
                                    class="max-w-[85%] rounded-lg px-3 py-2 text-sm"
                                    :class="message.direction === 'out' ? 'ml-auto bg-teal-700 text-white' : 'bg-gray-100 text-gray-800'"
                                >
                                    <p class="whitespace-pre-wrap">{{ message.body }}</p>
                                    <p class="mt-1 text-[11px]" :class="message.direction === 'out' ? 'text-teal-100' : 'text-gray-400'">
                                        {{ message.at }}
                                        <span v-if="message.status === 'failed'"> · not sent</span>
                                    </p>
                                </div>
                            </div>
                            <form class="border-t border-gray-200 px-4 py-3" @submit.prevent="sendReply">
                                <p v-if="!session_open" class="mb-2 text-sm text-amber-800">
                                    This parent last wrote more than 24 hours ago. Ask them to send a new message, then reply here.
                                </p>
                                <div class="flex gap-2">
                                    <textarea
                                        v-model="form.body"
                                        rows="2"
                                        maxlength="1000"
                                        placeholder="Write a reply"
                                        class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500"
                                        :disabled="!session_open || form.processing"
                                    />
                                    <button
                                        type="submit"
                                        class="shrink-0 self-end rounded-md bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-60"
                                        :disabled="!session_open || form.processing || !form.body.trim()"
                                    >
                                        Send
                                    </button>
                                </div>
                                <p v-if="form.errors.body" class="mt-2 text-sm text-red-600">{{ form.errors.body }}</p>
                            </form>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

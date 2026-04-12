<script setup lang="ts">
import { ref, nextTick, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useCasinoTableStore } from '@/stores/casinoTable';

const props = defineProps<{
    tableId: number;
}>();

const store = useCasinoTableStore();
const messages = computed(() => store.chatMessages);
const newMessage = ref('');
const chatContainer = ref<HTMLElement | null>(null);
const isOpen = ref(false);

function scrollToBottom() {
    nextTick(() => {
        if (chatContainer.value) {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }
    });
}

// Auto-scroll when messages arrive.
watch(() => store.chatMessages.length, () => scrollToBottom());

function sendMessage() {
    if (!newMessage.value.trim()) return;

    router.post(
        route('casino.chat.send', props.tableId),
        { message: newMessage.value.trim() },
        { preserveScroll: true, preserveState: true },
    );

    newMessage.value = '';
}

function formatTime(ts: string): string {
    return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <div class="fixed bottom-4 right-4 z-40">
        <!-- Toggle button -->
        <button
            v-if="!isOpen"
            @click="isOpen = true"
            class="rounded-full bg-amber-600 p-3 text-white shadow-lg hover:bg-amber-500"
        >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
        </button>

        <!-- Chat panel -->
        <div
            v-if="isOpen"
            class="flex h-80 w-72 flex-col rounded-lg border border-zinc-700 bg-zinc-900 shadow-2xl"
        >
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-zinc-700 px-3 py-2">
                <span class="text-xs font-semibold text-zinc-400">Table Chat</span>
                <button @click="isOpen = false" class="text-zinc-500 hover:text-zinc-300">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Messages -->
            <div ref="chatContainer" class="flex-1 overflow-y-auto px-3 py-2 space-y-1">
                <div v-for="(msg, i) in messages" :key="i" class="text-xs">
                    <span class="font-semibold text-amber-400">{{ msg.username }}</span>
                    <span class="ml-1 text-zinc-500">{{ formatTime(msg.timestamp) }}</span>
                    <p class="text-zinc-300">{{ msg.message }}</p>
                </div>
                <div v-if="messages.length === 0" class="mt-4 text-center text-xs text-zinc-600">
                    No messages yet
                </div>
            </div>

            <!-- Input -->
            <form @submit.prevent="sendMessage" class="border-t border-zinc-700 px-2 py-2">
                <div class="flex gap-1">
                    <input
                        v-model="newMessage"
                        type="text"
                        maxlength="200"
                        placeholder="Type a message..."
                        class="flex-1 rounded border border-zinc-600 bg-zinc-800 px-2 py-1 text-xs text-zinc-200 placeholder-zinc-600 focus:border-amber-500 focus:outline-none"
                    />
                    <button
                        type="submit"
                        class="rounded bg-amber-600 px-2 py-1 text-xs text-white hover:bg-amber-500"
                    >
                        Send
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

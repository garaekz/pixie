<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { Generation } from '@/types/generation';

const props = defineProps<{
    generation: Pick<
        Generation,
        'id' | 'prompt' | 'status' | 'image_url' | 'image_url_expires_at'
    >;
}>();
const failed = ref(false);
watch(
    () => props.generation.image_url,
    () => {
        failed.value = false;
    },
);
const expired = computed(
    () =>
        !!props.generation.image_url_expires_at &&
        new Date(props.generation.image_url_expires_at).getTime() <= Date.now(),
);
const unavailable = computed(
    () => failed.value || expired.value || !props.generation.image_url,
);
</script>

<template>
    <div
        class="flex aspect-[4/3] items-center justify-center overflow-hidden bg-muted/60"
    >
        <img
            v-if="!unavailable"
            :src="props.generation.image_url!"
            :alt="props.generation.prompt"
            class="h-full w-full object-contain"
            @error="failed = true"
        />
        <p
            v-else-if="props.generation.status === 'failed'"
            class="px-5 text-center text-sm text-muted-foreground"
        >
            Generation failed
        </p>
        <p
            v-else-if="props.generation.status === 'succeeded'"
            class="px-5 text-center text-sm text-muted-foreground"
        >
            Image unavailable or expired
        </p>
        <div
            v-else
            class="flex flex-col items-center gap-2 text-center text-sm text-muted-foreground"
            aria-live="polite"
        >
            <span
                class="size-5 animate-spin rounded-full border-2 border-muted-foreground/30 border-t-foreground motion-reduce:animate-none"
                aria-hidden="true"
            />
            {{
                props.generation.status === 'processing'
                    ? 'Rendering your image…'
                    : 'Queued for rendering…'
            }}
        </div>
    </div>
</template>

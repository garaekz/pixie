<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { computed } from 'vue';
import type { Generation } from '@/types/generation';
import { money } from '@/lib/generation';

const props = defineProps<{
    generation: Pick<Generation, 'status' | 'user_charge'>;
}>();
const pending = computed(
    () =>
        props.generation.status !== 'failed' &&
        props.generation.user_charge === null,
);
</script>

<template>
    <Badge
        v-if="pending"
        variant="outline"
        class="text-muted-foreground"
        aria-live="polite"
    >
        Pricing<span aria-hidden="true" class="inline-flex gap-0.5 pl-0.5">
            <span class="motion-safe:animate-bounce motion-reduce:animate-none"
                >·</span
            >
            <span
                class="[animation-delay:120ms] motion-safe:animate-bounce motion-reduce:animate-none"
                >·</span
            >
            <span
                class="[animation-delay:240ms] motion-safe:animate-bounce motion-reduce:animate-none"
                >·</span
            >
        </span>
        <span class="sr-only">Final charge is still being calculated.</span>
    </Badge>
    <Badge
        v-else
        variant="outline"
        class="border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
    >
        {{
            props.generation.status === 'failed'
                ? 'Free'
                : money(props.generation.user_charge)
        }}
    </Badge>
</template>

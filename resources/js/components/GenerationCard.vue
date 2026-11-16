<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useTimeAgo } from '@vueuse/core';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import GenerationCostBadge from '@/components/GenerationCostBadge.vue';
import GenerationImage from '@/components/GenerationImage.vue';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import { dateTime, statusLabel } from '@/lib/generation';
import type { GenerationSummary } from '@/types/generation';

const props = defineProps<{
    generation: GenerationSummary;
    href: string;
    canRetry?: boolean;
    retrying?: boolean;
}>();

const emit = defineEmits<{ retry: [id: string] }>();
const relativeCreatedAt = useTimeAgo(() => props.generation.created_at);
</script>

<template>
    <Card class="h-full min-w-0 gap-0 overflow-hidden py-0">
        <Link
            :href="props.href"
            class="flex flex-1 flex-col transition-opacity hover:opacity-90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
        >
            <GenerationImage :generation="props.generation" />
            <CardContent class="flex min-h-36 flex-col gap-3 p-4">
                <p
                    class="line-clamp-2 min-h-10 text-sm font-medium"
                    :title="props.generation.prompt"
                >
                    {{ props.generation.prompt }}
                </p>
                <div
                    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                >
                    <span>{{ props.generation.size.replace('x', ' × ') }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ statusLabel(props.generation.quality) }}</span>
                    <span aria-hidden="true">·</span>
                    <time
                        :datetime="props.generation.created_at"
                        :title="dateTime(props.generation.created_at)"
                        >{{ relativeCreatedAt }}</time
                    >
                </div>
                <div
                    class="mt-auto flex flex-wrap items-center justify-between gap-2"
                >
                    <GenerationStatusBadge :status="props.generation.status" />
                    <GenerationCostBadge :generation="props.generation" />
                </div>
            </CardContent>
        </Link>
        <div v-if="props.canRetry" class="border-t px-4 py-3">
            <Button
                class="w-full"
                size="sm"
                variant="outline"
                :disabled="props.retrying"
                @click="emit('retry', props.generation.id)"
            >
                {{ props.retrying ? 'Checking…' : 'Retry safely' }}
            </Button>
        </div>
    </Card>
</template>

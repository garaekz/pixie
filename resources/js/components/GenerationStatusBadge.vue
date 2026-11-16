<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { GenerationStatus } from '@/types/generation';
import { statusLabel } from '@/lib/generation';

const props = defineProps<{ status: GenerationStatus }>();

const statusClass: Record<GenerationStatus, string> = {
    succeeded:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    failed: 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300',
    processing:
        'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-300',
    queued: 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
};
</script>

<template>
    <Badge variant="outline" :class="statusClass[props.status]">
        <span
            aria-hidden="true"
            class="mr-1 size-1.5 rounded-full bg-current"
        />
        {{ statusLabel(props.status) }}
    </Badge>
</template>

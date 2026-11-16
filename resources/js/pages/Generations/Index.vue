<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, watch } from 'vue';
import GenerationHistory from '@/components/GenerationHistory.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import genRoutes from '@/routes/generations';
import type {
    GenerationPaginator,
    GenerationStatus,
    GenerationStatusOption,
} from '@/types/generation';

const props = defineProps<{
    generations: GenerationPaginator;
    filters: { status: GenerationStatus | null };
    statuses: GenerationStatusOption[];
}>();

defineOptions({ layout: AppLayout });

const active = computed(() =>
    props.generations.data.some(
        (generation) =>
            generation.status === 'queued' ||
            generation.status === 'processing',
    ),
);
const poll = usePoll(5000, { only: ['generations'] }, { autoStart: false });
watch(active, (value) => (value ? poll.start() : poll.stop()), {
    immediate: true,
});
</script>

<template>
    <div>
        <Head title="Generations" />
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-8 p-4 md:p-8">
            <header
                class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"
            >
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight">
                        Generations
                    </h1>
                    <p class="mt-2 text-muted-foreground">
                        Your images, settings, and charges, all in one place.
                    </p>
                </div>
                <Button as-child>
                    <Link :href="dashboard()"
                        ><Plus class="size-4" />New generation</Link
                    >
                </Button>
            </header>
            <GenerationHistory
                :generations="props.generations"
                :filters="filters"
                :statuses="statuses"
                :index-url="genRoutes.index.url()"
            />
        </div>
    </div>
</template>

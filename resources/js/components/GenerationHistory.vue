<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import GenerationCard from '@/components/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import genRoutes from '@/routes/generations';
import type {
    GenerationFeed,
    GenerationPaginator,
    GenerationStatus,
    GenerationStatusOption,
} from '@/types/generation';

const props = defineProps<{
    generations: GenerationFeed | GenerationPaginator;
    filters: { status: GenerationStatus | null };
    statuses: GenerationStatusOption[];
    indexUrl: string;
}>();

const filters = computed(() => [
    { value: null, label: 'All' },
    ...props.statuses,
]);
const retryForm = useForm({});
const pagination = computed(() =>
    'links' in props.generations ? props.generations : null,
);

function retry(id: string): void {
    retryForm.post(genRoutes.retry.url(id), { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <nav
            class="flex flex-wrap gap-2"
            aria-label="Filter generations by status"
        >
            <Link
                v-for="filter in filters"
                :key="filter.value ?? 'all'"
                :href="indexUrl"
                :data="filter.value ? { status: filter.value } : {}"
                preserve-state
                preserve-scroll
                :aria-current="
                    props.filters.status === filter.value ? 'true' : undefined
                "
                class="rounded-full border px-3 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="
                    props.filters.status === filter.value
                        ? 'border-foreground bg-foreground text-background'
                        : 'border-transparent text-muted-foreground hover:bg-muted hover:text-foreground'
                "
            >
                {{ filter.label }}
            </Link>
        </nav>

        <p
            v-for="error in retryForm.errors"
            :key="error"
            role="alert"
            class="text-sm text-destructive"
        >
            {{ error }}
        </p>

        <div
            v-if="!generations.data.length"
            class="flex min-h-48 flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-8 text-center"
        >
            <p class="font-medium">
                {{
                    props.filters.status
                        ? 'No matching generations'
                        : 'Your gallery starts here'
                }}
            </p>
            <p class="max-w-sm text-sm text-muted-foreground">
                {{
                    props.filters.status
                        ? 'Try another status to see more of your images.'
                        : 'Create an image and find its result, settings, and final charge here.'
                }}
            </p>
        </div>
        <div
            v-else
            class="grid grid-cols-1 items-stretch gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
                :href="genRoutes.show.url(generation.id)"
                :can-retry="
                    generation.submission_state === 'uncertain' &&
                    (generation.status === 'queued' ||
                        generation.status === 'processing')
                "
                :retrying="retryForm.processing"
                @retry="retry"
            />
        </div>

        <div
            v-if="pagination?.total"
            class="flex flex-wrap items-center justify-between gap-4"
        >
            <p class="text-sm text-muted-foreground">
                {{ pagination.from }}–{{ pagination.to }} of
                {{ pagination.total }}
                {{ pagination.total === 1 ? 'generation' : 'generations' }}
            </p>
            <nav
                v-if="pagination.links.length > 3"
                class="flex flex-wrap gap-2"
                aria-label="Generation history pages"
            >
                <template v-for="link in pagination.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-state
                        preserve-scroll
                        :aria-current="link.active ? 'page' : undefined"
                        class="rounded-md border px-3 py-1.5 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="
                            link.active
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        "
                        v-html="link.label"
                    />
                    <Button
                        v-else
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled
                    >
                        <span v-html="link.label" />
                    </Button>
                </template>
            </nav>
        </div>
    </div>
</template>

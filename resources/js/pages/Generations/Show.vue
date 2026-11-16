<script setup lang="ts">
import { Head, Link, useForm, usePoll } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import genRoutes from '@/routes/generations';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import GenerationCostBadge from '@/components/GenerationCostBadge.vue';
import GenerationImage from '@/components/GenerationImage.vue';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import type { Generation } from '@/types/generation';
import { dateTime, money, statusLabel } from '@/lib/generation';
import AppLayout from '@/layouts/AppLayout.vue';

const props = defineProps<{ generation: Generation }>();
defineOptions({ layout: AppLayout });

const retryForm = useForm({});
const active = computed(() =>
    ['queued', 'processing'].includes(props.generation.status),
);
const poll = usePoll(5000, { only: ['generation'] }, { autoStart: false });

watch(active, (value) => (value ? poll.start() : poll.stop()), {
    immediate: true,
});

function retry(): void {
    retryForm.post(genRoutes.retry.url(props.generation.id), {
        preserveScroll: true,
    });
}

function usageLabel(key: string): string {
    return key
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}
</script>

<template>
    <div>
        <Head :title="`Generation ${generation.id.slice(0, 8)}`" />
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4 md:p-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Link
                    :href="genRoutes.index.url()"
                    class="text-sm text-muted-foreground hover:text-foreground"
                >
                    ← All generations
                </Link>
                <span
                    class="font-mono text-xs text-muted-foreground"
                    :title="generation.id"
                >
                    {{ generation.id.slice(0, 8) }}…
                </span>
            </div>

            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-muted-foreground">
                        Generation details
                    </p>
                    <h1 class="text-3xl font-semibold tracking-tight">
                        Your image
                    </h1>
                </div>
                <div class="flex items-center gap-2">
                    <GenerationStatusBadge :status="generation.status" />
                    <GenerationCostBadge :generation="generation" />
                </div>
            </header>

            <p
                v-for="error in retryForm.errors"
                :key="error"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ error }}
            </p>

            <div
                class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(320px,0.9fr)]"
            >
                <Card class="h-fit gap-0 overflow-hidden py-0">
                    <GenerationImage :generation="generation" />
                    <CardContent
                        v-if="generation.image_url_expires_at"
                        class="border-t p-4 text-xs text-muted-foreground"
                    >
                        Image links expire
                        {{ dateTime(generation.image_url_expires_at) }}.
                    </CardContent>
                </Card>

                <div class="flex min-w-0 flex-col gap-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Prompt</CardTitle>
                            <CardDescription
                                >What Pixie sent for this
                                image.</CardDescription
                            >
                        </CardHeader>
                        <CardContent>
                            <p
                                class="text-sm leading-6 break-words whitespace-pre-wrap"
                            >
                                {{ generation.prompt }}
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Settings</CardTitle></CardHeader>
                        <CardContent
                            class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm"
                        >
                            <div>
                                <p class="text-muted-foreground">Canvas</p>
                                <p class="font-medium">
                                    {{ generation.size.replace('x', ' × ') }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Quality</p>
                                <p class="font-medium">
                                    {{ statusLabel(generation.quality) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Created</p>
                                <p class="font-medium">
                                    {{ dateTime(generation.created_at) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Started</p>
                                <p class="font-medium">
                                    {{
                                        generation.started_at
                                            ? dateTime(generation.started_at)
                                            : '—'
                                    }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Completed</p>
                                <p class="font-medium">
                                    {{
                                        generation.completed_at
                                            ? dateTime(generation.completed_at)
                                            : '—'
                                    }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Reservation</p>
                                <p class="font-medium">
                                    {{ money(generation.reserved) }}
                                </p>
                            </div>
                            <div class="col-span-2">
                                <p class="text-muted-foreground">
                                    Generation ID
                                </p>
                                <p class="font-mono text-xs break-all">
                                    {{ generation.id }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader
                            ><CardTitle>Cost</CardTitle
                            ><CardDescription
                                >Final billing uses actual provider
                                usage.</CardDescription
                            ></CardHeader
                        >
                        <CardContent
                            class="flex flex-wrap gap-x-8 gap-y-4 text-sm"
                        >
                            <div>
                                <p class="text-muted-foreground">Estimate</p>
                                <p class="font-semibold">
                                    {{ money(generation.estimate) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Charged</p>
                                <p class="font-semibold">
                                    {{
                                        generation.status === 'failed'
                                            ? 'Free'
                                            : generation.user_charge === null
                                              ? 'Pending'
                                              : money(generation.user_charge)
                                    }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card v-if="generation.reference_images.length">
                        <CardHeader
                            ><CardTitle>Reference images</CardTitle></CardHeader
                        >
                        <CardContent class="space-y-2 text-sm">
                            <a
                                v-for="reference in generation.reference_images"
                                :key="reference"
                                :href="reference"
                                target="_blank"
                                rel="noreferrer"
                                class="block truncate text-primary underline-offset-4 hover:underline"
                                >{{ reference }}</a
                            >
                        </CardContent>
                    </Card>

                    <Card v-if="generation.usage">
                        <CardHeader
                            ><CardTitle>Usage</CardTitle
                            ><CardDescription
                                >Token usage reported for this
                                generation.</CardDescription
                            ></CardHeader
                        >
                        <CardContent>
                            <details>
                                <summary
                                    class="cursor-pointer text-sm font-medium"
                                >
                                    Show usage details
                                </summary>
                                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                    <template
                                        v-for="(value, key) in generation.usage"
                                        :key="key"
                                    >
                                        <dt class="text-muted-foreground">
                                            {{ usageLabel(String(key)) }}
                                        </dt>
                                        <dd class="text-right font-medium">
                                            {{ value.toLocaleString() }}
                                        </dd>
                                    </template>
                                </dl>
                            </details>
                        </CardContent>
                    </Card>

                    <div
                        v-if="
                            active &&
                            generation.submission_state === 'uncertain'
                        "
                        class="flex items-center justify-between gap-4 rounded-xl border border-amber-300/50 bg-amber-50/60 p-4 text-sm dark:bg-amber-950/20"
                    >
                        <p>
                            We can safely check the original request again if
                            delivery was interrupted.
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="retryForm.processing"
                            @click="retry"
                            >{{
                                retryForm.processing
                                    ? 'Checking…'
                                    : 'Retry safely'
                            }}</Button
                        >
                    </div>
                    <p
                        v-if="generation.error"
                        role="alert"
                        class="rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
                    >
                        {{ generation.error }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

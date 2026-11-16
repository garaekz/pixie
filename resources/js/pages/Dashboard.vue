<script setup lang="ts">
import { Head, Link, useForm, useHttp, usePoll } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import genRoutes from '@/routes/generations';
import credits from '@/routes/credits';
import { dashboard } from '@/routes';
import GenerationHistory from '@/components/GenerationHistory.vue';
import { money } from '@/lib/generation';
import type {
    GenerationFeed,
    GenerationStatus,
    GenerationStatusOption,
} from '@/types/generation';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';

type Props = {
    generations: GenerationFeed;
    filters: { status: GenerationStatus | null };
    statuses: GenerationStatusOption[];
    wallet: { balance: string; reserved: string; available: string };
    providerConfigured: boolean;
    options: {
        sizes: { value: string; label: string }[];
        qualities: { value: string; label: string }[];
        defaults: { size: string; quality: string };
    };
};
const props = defineProps<Props>();
const quoteFailure = ref<string | null>(null);
defineOptions({ layout: AppLayout });
const prompt = ref('');
const size = ref(props.options.defaults.size);
const quality = ref(props.options.defaults.quality);
const references = ref('');
const requestId = ref(crypto.randomUUID());
const quote = useHttp({
    prompt: '',
    size: '',
    quality: '',
    reference_images: [] as string[],
});
const estimate = ref<{ estimate: string; maximum: string } | null>(null);
const quoteBusy = ref(false);
let timer: number | undefined;
let sequence = 0;
const form = useForm({
    request_id: requestId.value,
    prompt: '',
    size: size.value,
    quality: quality.value,
    reference_images: [] as string[],
});
const creditForm = useForm({ request_id: crypto.randomUUID() });
const active = computed(() =>
    props.generations.data.some((item) =>
        ['queued', 'processing'].includes(item.status),
    ),
);
const poll = usePoll(
    5000,
    { only: ['generations', 'wallet'] },
    { autoStart: false },
);
watch(active, (value) => (value ? poll.start() : poll.stop()), {
    immediate: true,
});
const urls = computed(() =>
    references.value
        .split(/\r?\n/)
        .map((item) => item.trim())
        .filter(Boolean),
);
watch(
    [prompt, size, quality, references],
    () => {
        estimate.value = null;
        quoteFailure.value = null;
        quote.clearErrors();
        quoteBusy.value = true;
        const current = ++sequence;
        window.clearTimeout(timer);
        if (!prompt.value.trim() || urls.value.length > 4) {
            quoteBusy.value = false;
            return;
        }
        timer = window.setTimeout(async () => {
            try {
                quote.prompt = prompt.value;
                quote.size = size.value;
                quote.quality = quality.value;
                quote.reference_images = urls.value;
                const result = (await quote.post(genRoutes.estimate.url())) as {
                    estimate: string;
                    maximum: string;
                };
                if (current === sequence) estimate.value = result;
            } catch {
                if (current === sequence) {
                    estimate.value = null;
                    quoteFailure.value =
                        'Unable to calculate a price. Check the fields below or try again shortly.';
                }
            } finally {
                if (current === sequence) quoteBusy.value = false;
            }
        }, 350);
    },
    { deep: true },
);
onBeforeUnmount(() => {
    window.clearTimeout(timer);
    sequence++;
});
const errors = computed(() =>
    Object.values({ ...quote.errors, ...form.errors }),
);
function submit(): void {
    if (quoteBusy.value || !estimate.value) return;
    form.request_id = requestId.value;
    form.prompt = prompt.value;
    form.size = size.value;
    form.quality = quality.value;
    form.reference_images = urls.value;
    form.post(genRoutes.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            prompt.value = '';
            references.value = '';
            estimate.value = null;
            requestId.value = crypto.randomUUID();
        },
    });
}
function buyCredits(): void {
    if (creditForm.processing) return;
    creditForm.post(credits.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            creditForm.request_id = crypto.randomUUID();
        },
    });
}
</script>

<template>
    <div>
        <Head title="Generate" />
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-8 p-4 md:p-8">
            <header
                class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <p class="text-sm font-medium text-muted-foreground">
                        Pixie studio
                    </p>
                    <h1 class="text-3xl font-semibold tracking-tight">
                        Make something worth keeping.
                    </h1>
                    <p class="mt-2 text-muted-foreground">
                        Describe an image and preview the reservation before you
                        submit.
                    </p>
                </div>
                <div
                    class="flex items-center gap-3 rounded-xl border bg-card px-4 py-3 text-sm shadow-sm"
                >
                    <div>
                        <p class="text-muted-foreground">Available credits</p>
                        <p class="font-semibold">
                            {{ money(wallet.available) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ money(wallet.reserved) }} held ·
                            {{ money(wallet.balance) }} balance
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="creditForm.processing"
                        @click="buyCredits"
                        >Buy $5 credits</Button
                    >
                </div>
            </header>
            <p class="text-sm text-muted-foreground">
                Test credits only. Buying credits never takes a real payment.
                Pixelforge produces abstract placeholder images.
            </p>
            <p
                v-for="message in creditForm.errors"
                :key="message"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ message }}
            </p>
            <div
                v-if="!providerConfigured"
                class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                Pixelforge is not configured yet.
            </div>
            <div
                v-if="urls.length > 4"
                class="rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
            >
                Add no more than four reference image URLs.
            </div>
            <section class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                <Card
                    ><CardHeader
                        ><CardTitle>New generation</CardTitle
                        ><CardDescription
                            >Your prompt is sent securely to
                            Pixelforge.</CardDescription
                        ></CardHeader
                    ><CardContent
                        ><form
                            class="flex flex-col gap-6"
                            @submit.prevent="submit"
                        >
                            <div class="grid gap-2">
                                <Label for="prompt">Prompt</Label
                                ><textarea
                                    id="prompt"
                                    v-model="prompt"
                                    maxlength="4000"
                                    rows="7"
                                    class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-[3px]"
                                    required
                                />
                                <p
                                    class="text-right text-xs text-muted-foreground"
                                >
                                    {{ prompt.length }}/4000
                                </p>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="size">Canvas</Label
                                    ><select
                                        id="size"
                                        v-model="size"
                                        class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        <option
                                            v-for="option in options.sizes"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="quality">Quality</Label
                                    ><select
                                        id="quality"
                                        v-model="quality"
                                        class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                    >
                                        <option
                                            v-for="option in options.qualities"
                                            :key="option.value"
                                            :value="option.value"
                                        >
                                            {{ option.label }}
                                        </option>
                                    </select>
                                </div>
                            </div>
                            <div class="grid gap-2">
                                <Label for="references"
                                    >Reference image URLs
                                    <span
                                        class="font-normal text-muted-foreground"
                                        >(optional, one per line)</span
                                    ></Label
                                ><textarea
                                    id="references"
                                    v-model="references"
                                    rows="3"
                                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:ring-[3px]"
                                    placeholder="https://example.com/style.png"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Up to 4 URLs.
                                </p>
                            </div>
                            <div
                                v-if="errors.length || quoteFailure"
                                class="space-y-1 text-sm text-destructive"
                            >
                                <p v-if="quoteFailure">{{ quoteFailure }}</p>
                                <p v-for="error in errors" :key="error">
                                    {{ error }}
                                </p>
                            </div>
                            <Button
                                type="submit"
                                size="lg"
                                :disabled="
                                    form.processing ||
                                    quoteBusy ||
                                    !estimate ||
                                    !providerConfigured
                                "
                                >{{
                                    form.processing
                                        ? 'Sending…'
                                        : quoteBusy
                                          ? 'Updating estimate…'
                                          : 'Generate image'
                                }}</Button
                            >
                        </form></CardContent
                    ></Card
                >
                <Card class="h-fit"
                    ><CardHeader
                        ><CardTitle>Cost preview</CardTitle
                        ><CardDescription
                            >Final billing uses actual provider
                            usage.</CardDescription
                        ></CardHeader
                    ><CardContent class="flex flex-col gap-5"
                        ><div class="rounded-lg bg-muted/60 p-4">
                            <p class="text-sm text-muted-foreground">
                                Estimated charge
                            </p>
                            <p
                                class="mt-1 text-3xl font-semibold"
                                :title="estimate?.estimate"
                            >
                                {{ estimate ? money(estimate.estimate) : '—' }}
                            </p>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground"
                                >Maximum reservation</span
                            ><span :title="estimate?.maximum">{{
                                estimate ? money(estimate.maximum) : '—'
                            }}</span>
                        </div>
                        <p class="text-xs leading-5 text-muted-foreground">
                            Failed generations are free. Unused reservations
                            return to your balance.
                        </p></CardContent
                    ></Card
                >
            </section>
            <section class="flex flex-col gap-5">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold">
                            Recent generations
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Open an image to see its settings and final charge.
                        </p>
                    </div>
                    <Link
                        :href="
                            genRoutes.index({
                                query: { status: filters.status },
                            })
                        "
                        class="text-sm font-medium underline-offset-4 hover:underline"
                    >
                        View all generations →
                    </Link>
                </div>
                <GenerationHistory
                    :generations="generations"
                    :filters="filters"
                    :statuses="statuses"
                    :index-url="dashboard.url()"
                />
            </section>
        </div>
    </div>
</template>

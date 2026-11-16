<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login, register } from '@/routes';
</script>

<template>
    <main
        class="flex min-h-screen items-center justify-center bg-background px-6 py-12 text-foreground"
    >
        <Head title="Pixie" />
        <div
            class="w-full max-w-4xl rounded-3xl border bg-card p-8 shadow-sm md:p-14"
        >
            <nav class="flex items-center justify-between">
                <span class="text-lg font-semibold tracking-tight"
                    >✦ Pixie</span
                >
                <div class="flex gap-3 text-sm">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                        class="rounded-md bg-primary px-4 py-2 text-primary-foreground"
                        >Open studio</Link
                    ><template v-else
                        ><Link
                            :href="login()"
                            class="rounded-md px-4 py-2 text-muted-foreground hover:text-foreground"
                            >Log in</Link
                        ><Link
                            :href="register()"
                            class="rounded-md bg-primary px-4 py-2 text-primary-foreground"
                            >Create account</Link
                        ></template
                    >
                </div>
            </nav>
            <section
                class="grid gap-10 py-20 md:grid-cols-[1.1fr_.9fr] md:items-center"
            >
                <div>
                    <p class="mb-4 text-sm font-medium text-muted-foreground">
                        A small image studio for curious people
                    </p>
                    <h1
                        class="text-5xl font-semibold tracking-tight md:text-6xl"
                    >
                        Turn a sentence into a picture.
                    </h1>
                    <p
                        class="mt-6 max-w-xl text-lg leading-8 text-muted-foreground"
                    >
                        Pixie sends your prompt to a simulated image provider,
                        shows the work as it happens, and keeps the exact cost
                        visible.
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        <Link
                            :href="
                                $page.props.auth.user ? dashboard() : register()
                            "
                            class="rounded-md bg-primary px-5 py-3 text-sm font-medium text-primary-foreground"
                            >Start generating</Link
                        ><span
                            class="rounded-md border px-5 py-3 text-sm text-muted-foreground"
                            >No real money or AI</span
                        >
                    </div>
                </div>
                <div class="rounded-2xl bg-muted/60 p-8">
                    <div
                        class="flex aspect-square items-center justify-center rounded-xl border border-dashed"
                    >
                        <span class="text-7xl text-muted-foreground/50">✦</span>
                    </div>
                    <p class="mt-4 text-sm text-muted-foreground">
                        Abstract placeholders are supplied by the fictional
                        Pixelforge provider.
                    </p>
                </div>
            </section>
            <p class="text-xs text-muted-foreground">
                Pixelforge is a simulated provider for this assessment. Images
                are abstract placeholders and no real payment is taken.
            </p>
        </div>
    </main>
</template>

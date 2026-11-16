<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import admin from '@/routes/admin';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';

type Row = {
    date: string;
    user: { id: number; name: string; email: string };
    provider_cost: string;
    user_spend: string;
    profit: string;
    generations: number;
};
type Props = {
    rows: Row[];
    totals: {
        provider_cost: string;
        user_spend: string;
        profit: string;
        generations: number;
    };
    reconciliation: {
        provider_cost: string | null;
        local_cost: string;
        difference: string | null;
        error: string | null;
    };
    filters: { start_date: string; end_date: string };
};
const props = defineProps<Props>();
const filters = reactive({
    start_date: props.filters.start_date,
    end_date: props.filters.end_date,
});
const page = usePage();
defineOptions({ layout: AppLayout });
function money(value: string): string {
    const normalized = value.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
    return normalized === '' || normalized === '0' ? '$0' : `$${normalized}`;
}
function isZero(value: string | null): boolean {
    return value !== null && /^-?0(?:\.0+)?$/.test(value);
}
function apply(): void {
    router.get(admin.report.url(), filters, {
        preserveState: true,
        replace: true,
    });
}
</script>

<template>
    <div>
        <Head title="Admin report" />
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-8 p-4 md:p-8">
            <header>
                <p class="text-sm font-medium text-muted-foreground">
                    Operations
                </p>
                <h1 class="text-3xl font-semibold tracking-tight">
                    Spend and provider reconciliation
                </h1>
                <p class="mt-2 text-muted-foreground">
                    UTC completion dates, exact provider costs, and the user
                    charges that include the 25% markup.
                </p>
            </header>
            <p
                v-for="error in page.props.errors"
                :key="error"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ error }}
            </p>
            <Card
                ><CardContent class="flex flex-wrap items-end gap-4 p-4"
                    ><div class="grid gap-2">
                        <Label for="start_date">From</Label
                        ><Input
                            id="start_date"
                            v-model="filters.start_date"
                            type="date"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="end_date">To</Label
                        ><Input
                            id="end_date"
                            v-model="filters.end_date"
                            type="date"
                        />
                    </div>
                    <Button @click="apply">Apply dates</Button></CardContent
                ></Card
            >
            <div class="grid gap-4 sm:grid-cols-4">
                <Card
                    ><CardHeader
                        ><CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >User spend</CardTitle
                        ></CardHeader
                    ><CardContent class="text-2xl font-semibold">{{
                        money(totals.user_spend)
                    }}</CardContent></Card
                ><Card
                    ><CardHeader
                        ><CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Provider cost</CardTitle
                        ></CardHeader
                    ><CardContent class="text-2xl font-semibold">{{
                        money(totals.provider_cost)
                    }}</CardContent></Card
                ><Card
                    ><CardHeader
                        ><CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Profit</CardTitle
                        ></CardHeader
                    ><CardContent class="text-2xl font-semibold">{{
                        money(totals.profit)
                    }}</CardContent></Card
                ><Card
                    ><CardHeader
                        ><CardTitle
                            class="text-sm font-medium text-muted-foreground"
                            >Generations</CardTitle
                        ></CardHeader
                    ><CardContent class="text-2xl font-semibold">{{
                        totals.generations
                    }}</CardContent></Card
                >
            </div>
            <Card
                ><CardHeader
                    ><CardTitle>Provider reconciliation</CardTitle></CardHeader
                ><CardContent
                    class="flex flex-wrap items-center gap-x-8 gap-y-3 text-sm"
                    ><div>
                        <p class="text-muted-foreground">Pixelforge usage</p>
                        <p class="font-semibold">
                            {{
                                reconciliation.provider_cost === null
                                    ? 'Unavailable'
                                    : money(reconciliation.provider_cost)
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Local ledger</p>
                        <p class="font-semibold">
                            {{ money(reconciliation.local_cost) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Difference</p>
                        <p
                            class="font-semibold"
                            :class="
                                reconciliation.difference &&
                                !isZero(reconciliation.difference)
                                    ? 'text-destructive'
                                    : 'text-emerald-600'
                            "
                        >
                            {{
                                reconciliation.difference === null
                                    ? '—'
                                    : money(reconciliation.difference)
                            }}
                        </p>
                    </div>
                    <p v-if="reconciliation.error" class="text-destructive">
                        {{ reconciliation.error }}
                    </p></CardContent
                ></Card
            >
            <Card
                ><CardHeader
                    ><CardTitle>Daily and user detail</CardTitle></CardHeader
                ><CardContent class="overflow-x-auto"
                    ><table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b text-muted-foreground">
                                <th class="p-3">Date</th>
                                <th class="p-3">User</th>
                                <th class="p-3 text-right">Generations</th>
                                <th class="p-3 text-right">Provider</th>
                                <th class="p-3 text-right">Spend</th>
                                <th class="p-3 text-right">Profit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in rows"
                                :key="`${row.date}-${row.user.id}`"
                                class="border-b last:border-0"
                            >
                                <td class="p-3">{{ row.date }}</td>
                                <td class="p-3">
                                    <p class="font-medium">
                                        {{ row.user.name }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ row.user.email }}
                                    </p>
                                </td>
                                <td class="p-3 text-right">
                                    {{ row.generations }}
                                </td>
                                <td class="p-3 text-right">
                                    {{ money(row.provider_cost) }}
                                </td>
                                <td class="p-3 text-right">
                                    {{ money(row.user_spend) }}
                                </td>
                                <td class="p-3 text-right">
                                    {{ money(row.profit) }}
                                </td>
                            </tr>
                            <tr v-if="!rows.length">
                                <td
                                    colspan="6"
                                    class="p-8 text-center text-muted-foreground"
                                >
                                    No settled generations in this range.
                                </td>
                            </tr>
                        </tbody>
                    </table></CardContent
                ></Card
            >
        </div>
    </div>
</template>

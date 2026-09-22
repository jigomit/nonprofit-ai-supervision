<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download, ShieldAlert, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import {
    acknowledge as reportAcknowledge,
    exportMethod as reportExport,
    index as reportIndex,
} from '@/routes/report';
import type { Team } from '@/types';

type Props = {
    period: { days: number; since: string; options: number[] };
    totals: {
        runs: number;
        released: number;
        awaiting: number;
        rejected: number;
        failed: number;
        releasedWithoutExpert: number;
    };
    byLevel: { value: string; label: string; runs: number }[];
    overrides: {
        id: number;
        task: string;
        releasedAt: string | null;
        by: string;
        justification: string | null;
        supervisionNote: string;
    }[];
    expertSignOffs: {
        id: number;
        task: string;
        releasedAt: string | null;
        by: string;
        credential: string | null;
        licence: string | null;
    }[];
    supervisionChanges: {
        id: number;
        task: string;
        from: string;
        to: string;
        isEscalation: boolean;
        changedAt: string | null;
    }[];
    currentTeam?: Team | null;
};

const props = defineProps<Props>();

const teamSlug = computed(() => props.currentTeam?.slug ?? '');
const base = computed(() => reportIndex(teamSlug.value).url);
const exportUrl = computed(
    () => reportExport(teamSlug.value).url + `?days=${props.period.days}`,
);

const periodLabel = (days: number) =>
    days === 365 ? 'Last 12 months' : `Last ${days} days`;

const setPeriod = (days: number) => {
    router.get(base.value, { days }, { preserveState: true, replace: true });
};

// Of the work that reached a gate, how much a person actually cleared. The
// honest headline: overrides are excluded from the numerator.
const supervisedShare = computed(() => {
    const decided = props.totals.released + props.totals.rejected;
    if (decided === 0) return null;
    return Math.round(
        ((decided - props.totals.releasedWithoutExpert) / decided) * 100,
    );
});

const maxLevelRuns = computed(() =>
    Math.max(1, ...props.byLevel.map((level) => level.runs)),
);

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
            { title: 'Board report', href: '#' },
        ],
    }),
});
</script>

<template>
    <Head title="Board report" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Board report"
                :description="`How much work AI touched since ${period.since}, and who cleared it.`"
            />
            <a
                :href="exportUrl"
                class="inline-flex h-9 items-center gap-2 rounded-md border border-input px-3 text-sm shadow-xs transition-colors hover:bg-accent"
            >
                <Download class="size-4" />
                Export CSV
            </a>
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="days in period.options"
                :key="days"
                type="button"
                class="h-8 rounded-md border px-3 text-xs transition-colors"
                :class="
                    period.days === days
                        ? 'border-ring bg-accent'
                        : 'border-input hover:bg-accent/50'
                "
                @click="setPeriod(days)"
            >
                {{ periodLabel(days) }}
            </button>
        </div>

        <!-- Alerts first: a level that moved changes the gate on work the
             organization already runs. -->
        <section
            v-if="supervisionChanges.length > 0"
            class="flex flex-col gap-3 rounded-xl border border-amber-600/30 bg-amber-500/5 p-4 dark:border-amber-400/25"
        >
            <h2 class="text-sm font-semibold">
                The library changed how much review some of your work needs
            </h2>
            <ul class="flex flex-col gap-2">
                <li
                    v-for="change in supervisionChanges"
                    :key="change.id"
                    class="flex flex-wrap items-center justify-between gap-3 text-sm"
                >
                    <span>
                        <span class="font-medium">{{ change.task }}</span>
                        — {{ change.from }} →
                        <span class="font-medium">{{ change.to }}</span>
                        <span
                            v-if="change.isEscalation"
                            class="ml-1 text-xs text-amber-700 dark:text-amber-300"
                            >(stricter)</span
                        >
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="
                            router.post(
                                reportAcknowledge({
                                    current_team: teamSlug,
                                    change: change.id,
                                }).url,
                                {},
                                { preserveScroll: true },
                            )
                        "
                    >
                        Acknowledge
                    </Button>
                </li>
            </ul>
        </section>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div
                class="flex flex-col items-start gap-1 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            >
                <span class="text-2xl font-semibold tabular-nums">{{
                    totals.runs
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >tasks run with AI</span
                >
            </div>
            <div
                class="flex flex-col items-start gap-1 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            >
                <span class="text-2xl font-semibold tabular-nums">{{
                    totals.released
                }}</span>
                <span class="text-xs text-muted-foreground">released</span>
            </div>
            <div
                class="flex flex-col items-start gap-1 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            >
                <span class="text-2xl font-semibold tabular-nums">{{
                    totals.awaiting
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >still waiting on a person</span
                >
            </div>
            <div
                class="flex flex-col items-start gap-1 rounded-xl border p-4"
                :class="
                    totals.releasedWithoutExpert > 0
                        ? 'border-rose-600/30 bg-rose-500/5 dark:border-rose-400/25'
                        : 'border-sidebar-border/70 dark:border-sidebar-border'
                "
            >
                <span class="text-2xl font-semibold tabular-nums">{{
                    totals.releasedWithoutExpert
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >released without expert review</span
                >
            </div>
        </div>

        <section
            v-if="supervisedShare !== null"
            class="flex items-center gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <ShieldCheck class="size-5 shrink-0 text-muted-foreground" />
            <p class="text-sm">
                <span class="text-lg font-semibold tabular-nums"
                    >{{ supervisedShare }}%</span
                >
                of decided work was cleared at the level its task calls for.
            </p>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold">What level the work needed</h2>
            <div class="flex flex-col gap-2">
                <div
                    v-for="level in byLevel"
                    :key="level.value"
                    class="flex items-center gap-3 text-sm"
                >
                    <span class="w-32 shrink-0 text-muted-foreground">{{
                        level.label
                    }}</span>
                    <div
                        class="h-2 flex-1 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            class="h-full rounded-full bg-foreground/60"
                            :style="{
                                width: `${(level.runs / maxLevelRuns) * 100}%`,
                            }"
                        />
                    </div>
                    <span class="w-10 shrink-0 text-right tabular-nums">{{
                        level.runs
                    }}</span>
                </div>
            </div>
        </section>

        <section v-if="overrides.length > 0" class="flex flex-col gap-3">
            <div class="flex items-center gap-2">
                <ShieldAlert class="size-4 text-rose-600 dark:text-rose-400" />
                <h2 class="text-sm font-semibold">
                    Released without expert review
                </h2>
            </div>
            <ul class="flex flex-col gap-2">
                <li
                    v-for="override in overrides"
                    :key="override.id"
                    class="rounded-xl border border-rose-600/25 bg-rose-500/5 p-4 text-sm dark:border-rose-400/25"
                >
                    <p class="font-medium">{{ override.task }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ override.by }} · {{ override.releasedAt }}
                    </p>
                    <p v-if="override.justification" class="mt-2">
                        “{{ override.justification }}”
                    </p>
                    <p class="mt-2 text-xs text-muted-foreground">
                        Why the level applied: {{ override.supervisionNote }}
                    </p>
                </li>
            </ul>
        </section>

        <section v-if="expertSignOffs.length > 0" class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold">Cleared by a professional</h2>
            <ul class="flex flex-col gap-2">
                <li
                    v-for="signOff in expertSignOffs"
                    :key="signOff.id"
                    class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border"
                >
                    <span class="font-medium">{{ signOff.task }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{ signOff.by }}
                        <template v-if="signOff.credential"
                            >· {{ signOff.credential }}</template
                        >
                        <template v-if="signOff.licence"
                            >· {{ signOff.licence }}</template
                        >
                        · {{ signOff.releasedAt }}
                    </span>
                </li>
            </ul>
        </section>

        <p
            v-if="totals.runs === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            No work has been run in this period yet.
        </p>
    </div>
</template>

<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import SupervisionBadge from '@/components/SupervisionBadge.vue';
import TaskStatusPill from '@/components/TaskStatusPill.vue';
import { dashboard } from '@/routes';
import { show as taskShow } from '@/routes/tasks';
import type { Team } from '@/types';

type RunSummary = {
    id: number;
    skill: { slug: string; name: string };
    status: string;
    statusLabel: string;
    supervision: string;
    supervisionLabel: string;
    supervisionGate: string;
    requestedBy: string;
    releasedWithoutExpert: boolean;
    createdAt: string | null;
    releasedAt: string | null;
};

type Props = {
    runs: RunSummary[];
    filters: { status: string };
    counts: {
        awaiting: number;
        released: number;
        releasedWithoutExpert: number;
    };
    currentTeam?: Team | null;
};

const props = defineProps<Props>();

const filterTo = (status: string) => {
    router.get(
        window.location.pathname,
        { status: status || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const activeFilter = computed(() => props.filters.status);
const teamSlug = computed(() => props.currentTeam?.slug ?? '');

const formatDate = (value: string | null) =>
    value
        ? new Date(value).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
          })
        : '—';

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
            { title: 'Work', href: '#' },
        ],
    }),
});
</script>

<template>
    <Head title="Work" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Work"
            description="Everything this organization has run, and what is still waiting on a decision."
        />

        <div class="grid gap-3 sm:grid-cols-3">
            <button
                type="button"
                class="flex flex-col items-start gap-1 rounded-xl border p-4 text-left transition-colors hover:bg-accent/50"
                :class="
                    activeFilter === 'awaiting'
                        ? 'border-ring ring-[3px] ring-ring/20'
                        : 'border-sidebar-border/70 dark:border-sidebar-border'
                "
                @click="filterTo(activeFilter === 'awaiting' ? '' : 'awaiting')"
            >
                <span class="text-2xl font-semibold tabular-nums">{{
                    counts.awaiting
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >waiting on a decision</span
                >
            </button>

            <button
                type="button"
                class="flex flex-col items-start gap-1 rounded-xl border p-4 text-left transition-colors hover:bg-accent/50"
                :class="
                    activeFilter === 'released'
                        ? 'border-ring ring-[3px] ring-ring/20'
                        : 'border-sidebar-border/70 dark:border-sidebar-border'
                "
                @click="filterTo(activeFilter === 'released' ? '' : 'released')"
            >
                <span class="text-2xl font-semibold tabular-nums">{{
                    counts.released
                }}</span>
                <span class="text-xs text-muted-foreground">released</span>
            </button>

            <!-- Surfaced as a headline number, not buried: this is the figure a
                 board or a funder will ask about. -->
            <div
                class="flex flex-col items-start gap-1 rounded-xl border p-4"
                :class="
                    counts.releasedWithoutExpert > 0
                        ? 'border-rose-600/30 bg-rose-500/5 dark:border-rose-400/25'
                        : 'border-sidebar-border/70 dark:border-sidebar-border'
                "
            >
                <span class="text-2xl font-semibold tabular-nums">{{
                    counts.releasedWithoutExpert
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >released without expert review</span
                >
            </div>
        </div>

        <div
            v-if="runs.length > 0"
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full min-w-[42rem] text-sm">
                <thead
                    class="border-b bg-muted/40 text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-2.5 text-left font-medium">Task</th>
                        <th class="px-4 py-2.5 text-left font-medium">
                            Who has to clear it
                        </th>
                        <th class="px-4 py-2.5 text-left font-medium">
                            Status
                        </th>
                        <th class="px-4 py-2.5 text-left font-medium">
                            Requested by
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Started
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="run in runs"
                        :key="run.id"
                        class="border-b last:border-0 hover:bg-accent/40"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="
                                    taskShow({
                                        current_team: teamSlug,
                                        taskRun: run.id,
                                    }).url
                                "
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ run.skill.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <SupervisionBadge
                                :level="run.supervision"
                                :label="run.supervisionLabel"
                            />
                            <!-- The reviewer lives on this screen; making them
                                 open a run to learn what is being asked of
                                 them is the wrong way round. -->
                            <p
                                class="mt-1 max-w-xs text-xs text-muted-foreground"
                            >
                                {{ run.supervisionGate }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <TaskStatusPill
                                :status="run.status"
                                :label="run.statusLabel"
                                :flagged="run.releasedWithoutExpert"
                            />
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ run.requestedBy }}
                        </td>
                        <td
                            class="px-4 py-3 text-right text-muted-foreground tabular-nums"
                        >
                            {{ formatDate(run.createdAt) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="rounded-xl border border-dashed p-10 text-center">
            <p class="text-sm font-medium">Nothing here yet</p>
            <p class="mt-1 text-xs text-muted-foreground">
                Open a task in the catalogue and run it to see it appear here.
            </p>
        </div>
    </div>
</template>

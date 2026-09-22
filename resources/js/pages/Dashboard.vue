<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CalendarClock, Inbox, ShieldAlert } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import SupervisionBadge from '@/components/SupervisionBadge.vue';
import TaskStatusPill from '@/components/TaskStatusPill.vue';
import { dashboard } from '@/routes';
import { edit as organizationEdit } from '@/routes/organization';
import { index as reportIndex } from '@/routes/report';
import { index as schedulesIndex } from '@/routes/schedules';
import { index as skillsIndex } from '@/routes/skills';
import { index as tasksIndex, show as taskShow } from '@/routes/tasks';
import type { DashboardInvitation, Team } from '@/types';

type Props = {
    pendingInvitations?: DashboardInvitation[];
    awaitingDecision: {
        id: number;
        task: string;
        supervision: string;
        supervisionLabel: string;
        requestedBy: string;
        waitingSince: string | null;
    }[];
    dueWork: {
        id: number;
        task: string;
        dueAt: string;
        isOverdue: boolean;
        daysOverdue: number;
    }[];
    recentRuns: {
        id: number;
        task: string;
        status: string;
        statusLabel: string;
        releasedWithoutExpert: boolean;
        at: string | null;
    }[];
    summary: {
        runs: number;
        released: number;
        releasedWithoutExpert: number;
        supervisionChanges: number;
    };
    setup: {
        isOnboarded: boolean;
        catalogueSize: number;
        hasSchedules: boolean;
        hasRun: boolean;
    };
    currentTeam?: Team | null;
};

const props = defineProps<Props>();

const slug = computed(() => props.currentTeam?.slug ?? '');

// One next step, never a checklist. Five half-done things is how an empty
// product starts feeling like a chore.
const nextStep = computed(() => {
    if (!props.setup.isOnboarded) {
        return {
            title: 'Tell us about your organization',
            body: 'Your fiscal year, your budget, and which collections apply. It decides what work appears here and how the expert gate behaves.',
            action: 'Set up the organization',
            href: organizationEdit(slug.value).url,
        };
    }

    if (!props.setup.hasRun) {
        return {
            title: 'Run your first task',
            body: `${props.setup.catalogueSize} tasks are in your catalogue, each carrying the level of human review it needs before its output can be used.`,
            action: 'Open the catalogue',
            href: skillsIndex(slug.value).url,
        };
    }

    if (!props.setup.hasSchedules) {
        return {
            title: 'Put the recurring work on a calendar',
            body: 'The annual return, the audit, the board cycle. Anchor them to your fiscal year end and they stay right every year.',
            action: 'Open the calendar',
            href: schedulesIndex(slug.value).url,
        };
    }

    return null;
});

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});
</script>

<template>
    <Head title="Dashboard" />

    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            :title="currentTeam?.name ?? 'Dashboard'"
            description="What needs a person right now."
        />

        <section
            v-if="nextStep"
            class="flex flex-col items-start gap-3 rounded-xl border border-sidebar-border/70 p-5 dark:border-sidebar-border"
        >
            <h2 class="text-sm font-semibold">{{ nextStep.title }}</h2>
            <p class="max-w-prose text-sm text-muted-foreground">
                {{ nextStep.body }}
            </p>
            <Link
                :href="nextStep.href"
                class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/90"
            >
                {{ nextStep.action }}
                <ArrowRight class="size-4" />
            </Link>
        </section>

        <!-- The queue leads. Everything below it is reporting, and reporting
             can wait until after the work that is actually blocked. -->
        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-3">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <Inbox class="size-4 text-muted-foreground" />
                    Waiting for you
                </h2>
                <Link
                    :href="tasksIndex(slug).url"
                    class="text-xs text-muted-foreground underline-offset-4 hover:underline"
                >
                    All work
                </Link>
            </div>

            <div v-if="awaitingDecision.length > 0" class="flex flex-col gap-2">
                <Link
                    v-for="run in awaitingDecision"
                    :key="run.id"
                    :href="
                        taskShow({ current_team: slug, taskRun: run.id }).url
                    "
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sidebar-border/70 p-4 transition-colors hover:bg-accent/40 dark:border-sidebar-border"
                >
                    <span class="flex flex-col gap-0.5">
                        <span class="text-sm font-medium">{{ run.task }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ run.requestedBy }}
                            <template v-if="run.waitingSince"
                                >· waiting {{ run.waitingSince }}</template
                            >
                        </span>
                    </span>
                    <SupervisionBadge
                        :level="run.supervision"
                        :label="run.supervisionLabel"
                    />
                </Link>
            </div>

            <p
                v-else
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Nothing is waiting on you.
            </p>
        </section>

        <section v-if="dueWork.length > 0" class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-3">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <CalendarClock class="size-4 text-muted-foreground" />
                    Due now
                </h2>
                <Link
                    :href="schedulesIndex(slug).url"
                    class="text-xs text-muted-foreground underline-offset-4 hover:underline"
                >
                    Calendar
                </Link>
            </div>

            <div class="flex flex-col gap-2">
                <div
                    v-for="item in dueWork"
                    :key="item.id"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
                    :class="
                        item.isOverdue
                            ? 'border-rose-600/30 bg-rose-500/5 dark:border-rose-400/25'
                            : 'border-sidebar-border/70 dark:border-sidebar-border'
                    "
                >
                    <span class="text-sm font-medium">{{ item.task }}</span>
                    <span
                        class="text-xs"
                        :class="
                            item.isOverdue
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-muted-foreground'
                        "
                    >
                        <template v-if="item.isOverdue"
                            >{{ item.daysOverdue }} day{{
                                item.daysOverdue === 1 ? '' : 's'
                            }}
                            overdue</template
                        >
                        <template v-else>due today</template>
                    </span>
                </div>
            </div>
        </section>

        <section
            v-if="summary.supervisionChanges > 0"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-600/30 bg-amber-500/5 p-4 dark:border-amber-400/25"
        >
            <p class="flex items-center gap-2 text-sm">
                <ShieldAlert class="size-4 shrink-0" />
                The library changed how much review
                {{ summary.supervisionChanges }}
                {{ summary.supervisionChanges === 1 ? 'task' : 'tasks' }} you
                run {{ summary.supervisionChanges === 1 ? 'needs' : 'need' }}.
            </p>
            <Link
                :href="reportIndex(slug).url"
                class="text-xs underline underline-offset-4"
            >
                Review the change
            </Link>
        </section>

        <section v-if="setup.hasRun" class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold">Last 30 days</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <div
                    class="flex flex-col items-start gap-1 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                >
                    <span class="text-2xl font-semibold tabular-nums">{{
                        summary.runs
                    }}</span>
                    <span class="text-xs text-muted-foreground"
                        >tasks run with AI</span
                    >
                </div>
                <div
                    class="flex flex-col items-start gap-1 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                >
                    <span class="text-2xl font-semibold tabular-nums">{{
                        summary.released
                    }}</span>
                    <span class="text-xs text-muted-foreground">released</span>
                </div>
                <div
                    class="flex flex-col items-start gap-1 rounded-xl border p-4"
                    :class="
                        summary.releasedWithoutExpert > 0
                            ? 'border-rose-600/30 bg-rose-500/5 dark:border-rose-400/25'
                            : 'border-sidebar-border/70 dark:border-sidebar-border'
                    "
                >
                    <span class="text-2xl font-semibold tabular-nums">{{
                        summary.releasedWithoutExpert
                    }}</span>
                    <span class="text-xs text-muted-foreground"
                        >released without expert review</span
                    >
                </div>
            </div>
        </section>

        <section v-if="recentRuns.length > 0" class="flex flex-col gap-3">
            <h2 class="text-sm font-semibold">Recently</h2>
            <div class="flex flex-col gap-2">
                <Link
                    v-for="run in recentRuns"
                    :key="run.id"
                    :href="
                        taskShow({ current_team: slug, taskRun: run.id }).url
                    "
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-sidebar-border/70 px-4 py-3 text-sm transition-colors hover:bg-accent/40 dark:border-sidebar-border"
                >
                    <span>{{ run.task }}</span>
                    <span class="flex items-center gap-3">
                        <span class="text-xs text-muted-foreground">{{
                            run.at
                        }}</span>
                        <TaskStatusPill
                            :status="run.status"
                            :label="run.statusLabel"
                            :flagged="run.releasedWithoutExpert"
                        />
                    </span>
                </Link>
            </div>
        </section>
    </div>
</template>

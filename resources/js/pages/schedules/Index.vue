<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CalendarPlus, Pause, Play, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SupervisionBadge from '@/components/SupervisionBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import type { Team } from '@/types';

type Schedule = {
    id: number;
    skill: { slug: string; name: string };
    supervision: string;
    supervisionLabel: string;
    cadence: string;
    cadenceLabel: string;
    anchoredToFiscalYearEnd: boolean;
    nextDueAt: string;
    daysUntilDue: number;
    isOverdue: boolean;
    isActive: boolean;
    lastStartedAt: string | null;
};

type Props = {
    schedules: Schedule[];
    cadences: { value: string; label: string }[];
    fiscalYearEndMonth: number | null;
    schedulableSkills: { slug: string; name: string; supervision: string }[];
    counts: { due: number; overdue: number };
    currentTeam?: Team | null;
};

const props = defineProps<Props>();

const base = computed(() => `/${props.currentTeam?.slug}/calendar`);

const form = useForm({
    skill: '',
    cadence: 'annually',
    anchored_to_fiscal_year_end: false,
    anchor_month: 1,
    anchor_day: 1,
    months_after_anchor: 0,
});

const months = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

const fiscalYearEndLabel = computed(() =>
    props.fiscalYearEndMonth ? months[props.fiscalYearEndMonth - 1] : null,
);

const dueWording = (schedule: Schedule) => {
    if (!schedule.isActive) return 'Paused';
    if (schedule.isOverdue) {
        const days = Math.abs(schedule.daysUntilDue);
        return `${days} day${days === 1 ? '' : 's'} overdue`;
    }
    if (schedule.daysUntilDue === 0) return 'Due today';
    return `in ${schedule.daysUntilDue} days`;
};

const selectClasses =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
            { title: 'Calendar', href: '#' },
        ],
    }),
});
</script>

<template>
    <Head title="Calendar" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Calendar"
            description="Recurring work, and when it next comes round. Nothing runs itself — the calendar tells you what is due and you decide to start it."
        />

        <div class="grid gap-3 sm:grid-cols-2">
            <div
                class="flex flex-col items-start gap-1 rounded-xl border p-4"
                :class="
                    counts.overdue > 0
                        ? 'border-rose-600/30 bg-rose-500/5 dark:border-rose-400/25'
                        : 'border-sidebar-border/70 dark:border-sidebar-border'
                "
            >
                <span class="text-2xl font-semibold tabular-nums">{{
                    counts.overdue
                }}</span>
                <span class="text-xs text-muted-foreground">overdue</span>
            </div>
            <div
                class="flex flex-col items-start gap-1 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            >
                <span class="text-2xl font-semibold tabular-nums">{{
                    counts.due
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >due now or earlier</span
                >
            </div>
        </div>

        <div
            v-if="schedules.length > 0"
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full min-w-[46rem] text-sm">
                <thead
                    class="border-b bg-muted/40 text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-2.5 text-left font-medium">Task</th>
                        <th class="px-4 py-2.5 text-left font-medium">Gate</th>
                        <th class="px-4 py-2.5 text-left font-medium">
                            Repeats
                        </th>
                        <th class="px-4 py-2.5 text-left font-medium">
                            Next due
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="schedule in schedules"
                        :key="schedule.id"
                        class="border-b last:border-0"
                        :class="{ 'opacity-60': !schedule.isActive }"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="`skills/${schedule.skill.slug}`"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ schedule.skill.name }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">
                            <SupervisionBadge
                                :level="schedule.supervision"
                                :label="schedule.supervisionLabel"
                            />
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{ schedule.cadenceLabel }}
                            <span
                                v-if="schedule.anchoredToFiscalYearEnd"
                                class="text-xs"
                                >· from year end</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            <span class="tabular-nums">{{
                                schedule.nextDueAt
                            }}</span>
                            <span
                                class="ml-2 text-xs"
                                :class="
                                    schedule.isOverdue
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : 'text-muted-foreground'
                                "
                                >{{ dueWording(schedule) }}</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Button
                                    v-if="schedule.isActive"
                                    size="sm"
                                    variant="outline"
                                    @click="
                                        router.post(
                                            `${base}/${schedule.id}/run`,
                                        )
                                    "
                                >
                                    Run now
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    :aria-label="
                                        schedule.isActive ? 'Pause' : 'Resume'
                                    "
                                    @click="
                                        router.patch(
                                            `${base}/${schedule.id}`,
                                            { is_active: !schedule.isActive },
                                            { preserveScroll: true },
                                        )
                                    "
                                >
                                    <Pause
                                        v-if="schedule.isActive"
                                        class="size-4"
                                    />
                                    <Play v-else class="size-4" />
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Remove from calendar"
                                    @click="
                                        router.delete(
                                            `${base}/${schedule.id}`,
                                            { preserveScroll: true },
                                        )
                                    "
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="rounded-xl border border-dashed p-10 text-center">
            <p class="text-sm font-medium">Nothing on the calendar</p>
            <p class="mt-1 text-xs text-muted-foreground">
                Add the work that comes round every year — the annual return,
                the audit, the board cycle.
            </p>
        </div>

        <section
            v-if="schedulableSkills.length > 0"
            class="flex max-w-3xl flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <h2 class="text-sm font-semibold">Add recurring work</h2>

            <form
                class="flex flex-col gap-4"
                @submit.prevent="
                    form.post(base, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    })
                "
            >
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="skill">Task</Label>
                        <select
                            id="skill"
                            v-model="form.skill"
                            :class="selectClasses"
                        >
                            <option value="">Choose a task…</option>
                            <option
                                v-for="skill in schedulableSkills"
                                :key="skill.slug"
                                :value="skill.slug"
                            >
                                {{ skill.name }}
                            </option>
                        </select>
                        <InputError :message="form.errors.skill" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="cadence">Repeats</Label>
                        <select
                            id="cadence"
                            v-model="form.cadence"
                            :class="selectClasses"
                        >
                            <option
                                v-for="cadence in cadences"
                                :key="cadence.value"
                                :value="cadence.value"
                            >
                                {{ cadence.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Anchoring to the fiscal year end is what makes a shared
                     calendar useful: "four months after year end" stays right
                     whenever that year happens to end. -->
                <label
                    class="flex cursor-pointer items-start gap-3 rounded-lg border border-sidebar-border/70 p-3 dark:border-sidebar-border"
                >
                    <input
                        v-model="form.anchored_to_fiscal_year_end"
                        type="checkbox"
                        class="mt-0.5 size-4 shrink-0"
                    />
                    <span class="flex flex-col gap-0.5">
                        <span class="text-sm font-medium"
                            >Count from our fiscal year end</span
                        >
                        <span class="text-xs text-muted-foreground">
                            <template v-if="fiscalYearEndLabel"
                                >Your year ends in
                                {{ fiscalYearEndLabel }}.</template
                            >
                            <template v-else
                                >Set your fiscal year end on the Organization
                                page to use this.</template
                            >
                        </span>
                    </span>
                </label>
                <InputError
                    :message="form.errors.anchored_to_fiscal_year_end"
                />

                <div class="grid gap-3 sm:grid-cols-3">
                    <div
                        v-if="
                            !form.anchored_to_fiscal_year_end &&
                            form.cadence === 'annually'
                        "
                        class="grid gap-2"
                    >
                        <Label for="anchor_month">Month</Label>
                        <select
                            id="anchor_month"
                            v-model="form.anchor_month"
                            :class="selectClasses"
                        >
                            <option
                                v-for="(month, index) in months"
                                :key="month"
                                :value="index + 1"
                            >
                                {{ month }}
                            </option>
                        </select>
                    </div>

                    <div class="grid gap-2">
                        <Label for="anchor_day">Day of month</Label>
                        <Input
                            id="anchor_day"
                            v-model.number="form.anchor_day"
                            type="number"
                            min="1"
                            max="28"
                        />
                        <InputError :message="form.errors.anchor_day" />
                    </div>

                    <div
                        v-if="form.anchored_to_fiscal_year_end"
                        class="grid gap-2"
                    >
                        <Label for="months_after_anchor"
                            >Months after year end</Label
                        >
                        <Input
                            id="months_after_anchor"
                            v-model.number="form.months_after_anchor"
                            type="number"
                            min="0"
                            max="11"
                        />
                        <InputError
                            :message="form.errors.months_after_anchor"
                        />
                    </div>
                </div>

                <div>
                    <Button type="submit" :disabled="form.processing">
                        <CalendarPlus class="size-4" />
                        Add to calendar
                    </Button>
                </div>
            </form>
        </section>
    </div>
</template>

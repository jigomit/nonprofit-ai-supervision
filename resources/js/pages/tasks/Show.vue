<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    Copy,
    Download,
    FileText,
    RotateCcw,
    ShieldAlert,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SupervisionBadge from '@/components/SupervisionBadge.vue';
import TaskStatusPill from '@/components/TaskStatusPill.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import {
    attachment as taskAttachment,
    decision as taskDecision,
    download as taskDownload,
    index as tasksIndex,
    inviteExpert as taskInviteExpert,
    revise as taskRevise,
    show as taskShow,
} from '@/routes/tasks';
import type { Team } from '@/types';

type Props = {
    run: {
        id: number;
        skill: { slug: string; name: string };
        status: string;
        statusLabel: string;
        supervision: string;
        supervisionLabel: string;
        supervisionGate: string;
        supervisionNote: string;
        requestedBy: string;
        releasedWithoutExpert: boolean;
        allowsOverride: boolean;
        output: string | null;
        rawOutput: string | null;
        isExportable: boolean;
        canRevise: boolean;
        revisedFrom: number | null;
        failureReason: string | null;
        inputs: Record<string, string> | null;
        attachments: {
            id: number;
            name: string;
            size: string;
            status: string;
            statusLabel: string;
            explanation: string | null;
            reachedTheModel: boolean;
            truncated: boolean;
            characters: number;
        }[];
        usage: Record<string, number> | null;
        sourceCommit: string | null;
        createdAt: string | null;
        releasedAt: string | null;
        approvals: {
            id: number;
            decision: string;
            summary: string;
            justification: string | null;
            credentialReference: string | null;
            decidedAt: string;
        }[];
        pendingInvitations: {
            email: string;
            name: string | null;
            url: string;
            expiresAt: string;
        }[];
    };
    credentialTypes: { value: string; label: string }[];
    currentTeam?: Team | null;
};

const props = defineProps<Props>();

// Inertia reuses this component when moving between runs, so anything derived
// from props has to be computed. Plain consts here silently kept pointing at
// the run you came from.
const awaitingReview = computed(() => props.run.status === 'awaiting_review');
const awaitingExpert = computed(() => props.run.status === 'awaiting_expert');

const decision = useForm({
    decision: 'approved',
    credential_type: '',
    credential_reference: '',
    justification: '',
});

const invite = useForm({ email: '', name: '', credential_type: '' });

const showOverride = ref(false);
const copied = ref<string | null>(null);

const workUrl = tasksIndex(props.currentTeam?.slug ?? '').url;
const routeArgs = computed(() => ({
    current_team: props.currentTeam?.slug ?? '',
    taskRun: props.run.id,
}));

const decisionUrl = computed(() => taskDecision(routeArgs.value).url);
const inviteUrl = computed(() => taskInviteExpert(routeArgs.value).url);
const downloadUrl = computed(() => taskDownload(routeArgs.value).url);
const reviseUrl = computed(() => taskRevise(routeArgs.value).url);

const attachmentUrl = (attachment: number) =>
    taskAttachment({ ...routeArgs.value, attachment }).url;

const originalUrl = computed(() =>
    props.run.revisedFrom
        ? taskShow({
              current_team: props.currentTeam?.slug ?? '',
              taskRun: props.run.revisedFrom,
          }).url
        : null,
);

const revision = useForm({ notes: (props.run.inputs?.notes as string) ?? '' });

watch(
    () => props.run.id,
    () => {
        revision.notes = (props.run.inputs?.notes as string) ?? '';
    },
);
const copiedDraft = ref(false);

const copyDraft = async () => {
    if (!props.run.rawOutput) return;

    try {
        await navigator.clipboard.writeText(props.run.rawOutput);
        copiedDraft.value = true;
        setTimeout(() => (copiedDraft.value = false), 2000);
    } catch {
        copiedDraft.value = false;
    }
};

const submitDecision = (value: string) => {
    decision.decision = value;
    decision.post(decisionUrl.value, { preserveScroll: true });
};

const copyLink = async (url: string) => {
    try {
        await navigator.clipboard.writeText(url);
        copied.value = url;
        setTimeout(() => (copied.value = null), 2000);
    } catch {
        copied.value = null;
    }
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
            {
                title: 'Work',
                href: props.currentTeam
                    ? tasksIndex(props.currentTeam.slug).url
                    : '/',
            },
        ],
    }),
});
</script>

<template>
    <Head :title="run.skill.name" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Link
            :href="workUrl"
            class="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 hover:underline"
        >
            <ArrowLeft class="size-4" />
            Back to work
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="run.skill.name"
                :description="`Requested by ${run.requestedBy}`"
            />
            <div class="flex items-center gap-2">
                <SupervisionBadge
                    :level="run.supervision"
                    :label="run.supervisionLabel"
                    size="md"
                />
                <TaskStatusPill
                    :status="run.status"
                    :label="run.statusLabel"
                    :flagged="run.releasedWithoutExpert"
                />
            </div>
        </div>

        <section
            class="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <p class="text-sm">{{ run.supervisionGate }}</p>
            <p class="text-sm text-muted-foreground">
                <span class="font-medium">Why this level:</span>
                {{ run.supervisionNote }}
            </p>
        </section>

        <section
            v-if="run.attachments.length"
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <h2 class="text-sm font-semibold">Documents given to this task</h2>

            <ul class="flex flex-col gap-3">
                <li
                    v-for="file in run.attachments"
                    :key="file.id"
                    class="flex flex-wrap items-start gap-x-3 gap-y-1 text-sm"
                >
                    <FileText
                        class="mt-0.5 size-4 shrink-0"
                        :class="
                            file.reachedTheModel
                                ? 'text-muted-foreground'
                                : 'text-amber-600'
                        "
                    />
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <a
                                :href="attachmentUrl(file.id)"
                                class="truncate font-medium underline underline-offset-4"
                                >{{ file.name }}</a
                            >
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >{{ file.size }}</span
                            >
                            <span
                                class="text-xs"
                                :class="
                                    file.reachedTheModel
                                        ? 'text-muted-foreground'
                                        : 'font-medium text-amber-700 dark:text-amber-300'
                                "
                                >{{ file.statusLabel }}</span
                            >
                        </div>
                        <!-- A file the model never read must say so here. A
                             reviewer who assumes the 990 was used will check
                             the draft against a document it never saw. -->
                        <p
                            v-if="file.explanation"
                            class="text-xs text-amber-700 dark:text-amber-300"
                        >
                            {{ file.explanation }}
                        </p>
                        <p
                            v-else-if="file.truncated"
                            class="text-xs text-muted-foreground"
                        >
                            Longer than fits in one task — the first
                            {{ file.characters.toLocaleString() }} characters
                            were used.
                        </p>
                    </div>
                </li>
            </ul>
        </section>

        <div
            v-if="run.failureReason"
            class="rounded-xl border border-rose-600/30 bg-rose-500/5 p-4 text-sm dark:border-rose-400/25"
        >
            <p class="font-medium">This run did not finish</p>
            <p class="mt-1 text-muted-foreground">{{ run.failureReason }}</p>
        </div>

        <section v-if="run.output" class="flex flex-col gap-2">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold">
                    {{ run.isExportable ? 'The document' : 'Draft' }}
                </h2>
                <!-- Taking the work away is only offered once it has cleared
                     its gate. Before that it is readable so it can be
                     reviewed, and nothing more. -->
                <div v-if="run.isExportable" class="flex items-center gap-3">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 text-xs underline-offset-4 hover:underline"
                        @click="copyDraft"
                    >
                        <Copy class="size-3.5" />
                        {{ copiedDraft ? 'Copied' : 'Copy' }}
                    </button>
                    <a
                        :href="downloadUrl"
                        class="inline-flex items-center gap-1.5 text-xs underline-offset-4 hover:underline"
                    >
                        <Download class="size-3.5" />
                        Download
                    </a>
                </div>
                <span v-else class="text-xs text-muted-foreground">
                    Available to copy once it has been signed off.
                </span>
            </div>
            <!-- eslint-disable-next-line vue/no-v-html -->
            <article
                class="prose prose-sm max-w-none rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border dark:prose-invert"
                v-html="run.output"
            />
        </section>

        <!-- Decisions -->
        <section
            v-if="awaitingReview || awaitingExpert"
            class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <h2 class="text-sm font-semibold">Your decision</h2>

            <div v-if="awaitingExpert" class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="credential_type"
                        >Your professional standing</Label
                    >
                    <select
                        id="credential_type"
                        v-model="decision.credential_type"
                        :class="selectClasses"
                    >
                        <option value="">
                            Not a credentialed professional
                        </option>
                        <option
                            v-for="type in credentialTypes"
                            :key="type.value"
                            :value="type.value"
                        >
                            {{ type.label }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label for="credential_reference"
                        >Licence number (optional)</Label
                    >
                    <Input
                        id="credential_reference"
                        v-model="decision.credential_reference"
                        placeholder="OH-12345"
                    />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="decision_notes">
                    Notes for whoever asked
                    <span class="text-muted-foreground"
                        >— required if you send it back</span
                    >
                </Label>
                <textarea
                    id="decision_notes"
                    v-model="decision.justification"
                    rows="2"
                    placeholder="What needs to change, or why this is fine as it stands."
                    class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                />
            </div>

            <InputError :message="decision.errors.decision" />

            <div class="flex flex-wrap gap-3">
                <Button
                    :disabled="decision.processing"
                    @click="submitDecision('approved')"
                >
                    <Check class="size-4" />
                    Approve and release
                </Button>
                <Button
                    variant="outline"
                    :disabled="decision.processing"
                    @click="submitDecision('rejected')"
                >
                    <X class="size-4" />
                    Reject
                </Button>
            </div>

            <!-- The override is deliberately not a peer of the buttons above:
                 it is a different act, and it leaves a permanent mark. -->
            <div v-if="run.allowsOverride" class="border-t pt-4">
                <button
                    v-if="!showOverride"
                    type="button"
                    class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    @click="showOverride = true"
                >
                    No expert available?
                </button>

                <div v-else class="flex flex-col gap-3">
                    <div
                        class="flex gap-3 rounded-lg border border-rose-600/25 bg-rose-500/5 p-3 text-sm dark:border-rose-400/25"
                    >
                        <ShieldAlert class="mt-0.5 size-4 shrink-0" />
                        <p>
                            This releases work that should have had a
                            credentialed sign-off. It stays flagged on this run,
                            on the work list, and on the board report. Say who
                            decided and why.
                        </p>
                    </div>
                    <textarea
                        id="justification"
                        v-model="decision.justification"
                        rows="3"
                        placeholder="Who decided, and on what basis."
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    />
                    <InputError :message="decision.errors.justification" />
                    <div>
                        <Button
                            variant="destructive"
                            :disabled="decision.processing"
                            @click="submitDecision('released_without_expert')"
                        >
                            Release without expert review
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Invite an outside professional -->
        <section
            v-if="awaitingExpert"
            class="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <div>
                <h2 class="text-sm font-semibold">
                    Send this to your accountant or attorney
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    They get a single-use link to this one piece of work. No
                    account needed. The link expires in 14 days.
                </p>
            </div>

            <form
                class="grid gap-3 sm:grid-cols-3"
                @submit.prevent="
                    invite.post(inviteUrl, {
                        preserveScroll: true,
                        onSuccess: () => invite.reset(),
                    })
                "
            >
                <div class="grid gap-2">
                    <Label for="invite_email">Email</Label>
                    <Input
                        id="invite_email"
                        v-model="invite.email"
                        type="email"
                        placeholder="cpa@example.com"
                    />
                    <InputError :message="invite.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label for="invite_name">Name (optional)</Label>
                    <Input id="invite_name" v-model="invite.name" />
                </div>
                <div class="flex items-end">
                    <Button type="submit" :disabled="invite.processing">
                        Create link
                    </Button>
                </div>
            </form>

            <div
                v-for="invitation in run.pendingInvitations"
                :key="invitation.url"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border"
            >
                <span class="text-muted-foreground">
                    {{ invitation.name ?? invitation.email }}
                </span>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-xs underline-offset-4 hover:underline"
                    @click="copyLink(invitation.url)"
                >
                    <Copy class="size-3.5" />
                    {{ copied === invitation.url ? 'Copied' : 'Copy link' }}
                </button>
            </div>
        </section>

        <!-- Sending work back is not a dead end. The rejected run stays as it
             was decided; a second attempt is a new run pointing at it. -->
        <section
            v-if="run.canRevise"
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <h2 class="text-sm font-semibold">Try again</h2>
            <p class="text-sm text-muted-foreground">
                This run stays on the record as it was decided. A second attempt
                starts fresh and points back at it.
            </p>
            <form
                class="flex flex-col gap-3"
                @submit.prevent="
                    revision.post(reviseUrl, { preserveScroll: true })
                "
            >
                <div class="grid gap-2">
                    <Label for="revision_notes">What should change?</Label>
                    <textarea
                        id="revision_notes"
                        v-model="revision.notes"
                        rows="3"
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    />
                    <InputError :message="revision.errors.notes" />
                </div>
                <div>
                    <Button type="submit" :disabled="revision.processing">
                        <RotateCcw class="size-4" />
                        Start a revised run
                    </Button>
                </div>
            </form>
        </section>

        <p v-if="originalUrl" class="text-sm text-muted-foreground">
            This is a second attempt.
            <Link :href="originalUrl" class="underline underline-offset-4"
                >See the run it replaces</Link
            >.
        </p>

        <!-- The record -->
        <section v-if="run.approvals.length > 0" class="flex flex-col gap-2">
            <h2 class="text-sm font-semibold">Decision record</h2>
            <ul class="flex flex-col gap-2">
                <li
                    v-for="approval in run.approvals"
                    :key="approval.id"
                    class="rounded-lg border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border"
                >
                    <p class="font-medium">{{ approval.summary }}</p>
                    <p
                        v-if="approval.credentialReference"
                        class="text-xs text-muted-foreground"
                    >
                        Licence {{ approval.credentialReference }}
                    </p>
                    <p
                        v-if="approval.justification"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        “{{ approval.justification }}”
                    </p>
                </li>
            </ul>
        </section>

        <footer class="mt-auto border-t pt-4 text-xs text-muted-foreground">
            <span v-if="run.usage?.cache_read_input_tokens">
                Instructions served from cache ({{
                    run.usage.cache_read_input_tokens.toLocaleString()
                }}
                tokens).
            </span>
            <span v-if="run.sourceCommit">
                Instructions from
                <code class="font-mono">{{ run.sourceCommit.slice(0, 7) }}</code
                >.
            </span>
        </footer>
    </div>
</template>

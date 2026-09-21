<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, Copy, ShieldAlert, X } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SupervisionBadge from '@/components/SupervisionBadge.vue';
import TaskStatusPill from '@/components/TaskStatusPill.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
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
        failureReason: string | null;
        inputs: Record<string, string> | null;
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

const awaitingReview = props.run.status === 'awaiting_review';
const awaitingExpert = props.run.status === 'awaiting_expert';

const decision = useForm({
    decision: 'approved',
    credential_type: '',
    credential_reference: '',
    justification: '',
});

const invite = useForm({ email: '', name: '', credential_type: '' });

const showOverride = ref(false);
const copied = ref<string | null>(null);

const decisionUrl = `/${props.currentTeam?.slug}/tasks/${props.run.id}/decision`;
const inviteUrl = `/${props.currentTeam?.slug}/tasks/${props.run.id}/expert-invitation`;

const submitDecision = (value: string) => {
    decision.decision = value;
    decision.post(decisionUrl, { preserveScroll: true });
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
            { title: 'Work', href: '../tasks' },
        ],
    }),
});
</script>

<template>
    <Head :title="run.skill.name" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Link
            href="../tasks"
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

        <div
            v-if="run.failureReason"
            class="rounded-xl border border-rose-600/30 bg-rose-500/5 p-4 text-sm dark:border-rose-400/25"
        >
            <p class="font-medium">This run did not finish</p>
            <p class="mt-1 text-muted-foreground">{{ run.failureReason }}</p>
        </div>

        <section v-if="run.output" class="flex flex-col gap-2">
            <h2 class="text-sm font-semibold">Draft</h2>
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

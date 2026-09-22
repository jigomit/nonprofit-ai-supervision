<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, FileText, Play, TriangleAlert } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SupervisionBadge from '@/components/SupervisionBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { edit as aiEdit } from '@/routes/ai';
import { edit as organizationEdit } from '@/routes/organization';
import { index as skillsIndex, show as skillShow } from '@/routes/skills';
import { store as tasksStore } from '@/routes/tasks';
import type { Team } from '@/types';

type Props = {
    skill: {
        slug: string;
        name: string;
        description: string;
        category: string;
        categoryLabel: string;
        isCore: boolean;
        supervision: string;
        supervisionLabel: string;
        supervisionGate: string;
        supervisionNote: string;
        body: string;
        failureModes: string[];
        deliverables: string[];
        tokenEstimate: number;
        dateAdded: string | null;
        lastReviewed: string | null;
        license: string | null;
        sourceCommit: string | null;
        related: {
            slug: string;
            name: string;
            supervision: string;
            supervisionLabel: string;
        }[];
    };
    enabled: boolean;
    missingContext: string[];
    hasAiProvider: boolean;
    currentTeam?: Team | null;
};

const props = defineProps<Props>();

const teamSlug = computed(() => props.currentTeam?.slug ?? '');
const catalogueUrl = computed(() => skillsIndex(teamSlug.value).url);

const run = useForm<{ skill: string; notes: string; files: File[] }>({
    skill: props.skill.slug,
    notes: '',
    files: [],
});

const pickFiles = (event: Event) => {
    run.files = Array.from((event.target as HTMLInputElement).files ?? []);
};

const dropFile = (index: number) => {
    run.files = run.files.filter((_, at) => at !== index);
};

// "a, b and c" — a list a person would say out loud.
const listed = (items: string[]) =>
    items.length <= 1
        ? (items[0] ?? '')
        : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;

const readableSize = (bytes: number) =>
    bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;

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
                title: 'Skill catalogue',
                href: props.currentTeam
                    ? skillsIndex(props.currentTeam.slug).url
                    : '/',
            },
        ],
    }),
});
</script>

<template>
    <Head :title="skill.name" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Link
            :href="catalogueUrl"
            class="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 hover:underline"
        >
            <ArrowLeft class="size-4" />
            Back to catalogue
        </Link>

        <Heading :title="skill.name" :description="skill.description" />

        <!-- The gate comes before the instructions deliberately: what has to
             happen to this output matters more than how it gets produced. -->
        <section
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <div class="flex flex-wrap items-center gap-3">
                <SupervisionBadge
                    :level="skill.supervision"
                    :label="skill.supervisionLabel"
                    size="md"
                />
                <span class="text-sm text-muted-foreground">{{
                    skill.supervisionGate
                }}</span>
            </div>
            <p class="text-sm">
                <span class="font-medium">Why this level:</span>
                {{ skill.supervisionNote }}
            </p>
        </section>

        <!-- What the library says goes wrong with this task, before anyone
             starts it. The whole point of the corpus is that these mistakes
             are already known; leaving them inside the instructions means only
             the model ever reads them. -->
        <section
            v-if="skill.failureModes.length"
            class="flex flex-col gap-3 rounded-xl border border-amber-600/25 bg-amber-500/5 p-4"
        >
            <div class="flex items-center gap-2">
                <TriangleAlert class="size-4 shrink-0 text-amber-600" />
                <h2 class="text-sm font-semibold">
                    Where this usually goes wrong
                </h2>
            </div>
            <ul class="flex flex-col gap-2">
                <li
                    v-for="(mode, index) in skill.failureModes"
                    :key="index"
                    class="flex gap-2.5 text-sm text-muted-foreground"
                >
                    <span
                        class="mt-2 size-1.5 shrink-0 rounded-full bg-amber-600/60"
                    />
                    <span class="preflight [&_p]:inline" v-html="mode" />
                </li>
            </ul>
        </section>

        <section
            v-if="skill.deliverables.length"
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <h2 class="text-sm font-semibold">What you should end up with</h2>
            <ul class="flex flex-col gap-2">
                <li
                    v-for="(item, index) in skill.deliverables"
                    :key="index"
                    class="flex gap-2.5 text-sm text-muted-foreground"
                >
                    <span
                        class="mt-0.5 shrink-0 text-xs tabular-nums opacity-60"
                        >{{ index + 1 }}.</span
                    >
                    <span class="preflight [&_p]:inline" v-html="item" />
                </li>
            </ul>
        </section>

        <!-- The run control sits under the gate and the warnings, so it is
             never possible to start work without having seen what will be
             required to release it. -->
        <section
            v-if="enabled"
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
        >
            <h2 class="text-sm font-semibold">Run this task</h2>
            <form
                class="flex flex-col gap-3"
                @submit.prevent="
                    run.post(tasksStore(teamSlug).url, {
                        preserveScroll: true,
                    })
                "
            >
                <div class="grid gap-2">
                    <Label for="notes">What do you need?</Label>
                    <textarea
                        id="notes"
                        v-model="run.notes"
                        rows="3"
                        placeholder="Anything specific this should take into account."
                        class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    />
                    <InputError :message="run.errors.notes" />
                    <InputError :message="run.errors.skill" />
                </div>

                <div class="grid gap-2">
                    <Label for="files">Documents (optional)</Label>
                    <input
                        id="files"
                        type="file"
                        multiple
                        accept=".pdf,.docx,.xlsx,.pptx,.txt,.md,.csv,.tsv,.json,.log"
                        class="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-accent"
                        @change="pickFiles"
                    />
                    <p class="text-xs text-muted-foreground">
                        Last year's 990, the budget, an export from your CRM.
                        The words inside are sent with the task; a scan with no
                        text in it cannot be read.
                    </p>

                    <ul v-if="run.files.length" class="flex flex-col gap-1">
                        <li
                            v-for="(file, index) in run.files"
                            :key="file.name + index"
                            class="flex items-center gap-2 text-sm"
                        >
                            <FileText
                                class="size-3.5 shrink-0 text-muted-foreground"
                            />
                            <span class="truncate">{{ file.name }}</span>
                            <span
                                class="shrink-0 text-xs text-muted-foreground tabular-nums"
                                >{{ readableSize(file.size) }}</span
                            >
                            <button
                                type="button"
                                class="shrink-0 text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                                @click="dropFile(index)"
                            >
                                Remove
                            </button>
                        </li>
                    </ul>

                    <InputError :message="run.errors.files" />
                    <InputError
                        :message="
                            (run.errors as Record<string, string>)['files.0']
                        "
                    />
                </div>
                <!-- Both of these change what comes back, so they belong at
                     the button rather than on a settings page nothing links
                     to. Neither blocks: a wall here is what teaches someone to
                     paste the prompt into ChatGPT instead. -->
                <div
                    v-if="!hasAiProvider"
                    class="flex gap-3 rounded-lg border border-amber-600/25 bg-amber-500/5 p-3 text-sm"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0 text-amber-600"
                    />
                    <p class="text-muted-foreground">
                        No AI service is set up yet, so this will come back as
                        placeholder text rather than a real draft.
                        <Link
                            v-if="currentTeam"
                            :href="aiEdit(currentTeam.slug)"
                            class="font-medium text-foreground underline underline-offset-4"
                            >Set one up first</Link
                        >.
                    </p>
                </div>

                <div
                    v-else-if="missingContext.length"
                    class="flex gap-3 rounded-lg border border-amber-600/25 bg-amber-500/5 p-3 text-sm"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0 text-amber-600"
                    />
                    <p class="text-muted-foreground">
                        The model has not been told
                        {{ listed(missingContext) }}, so expect a generic draft
                        with blanks to fill in.
                        <Link
                            v-if="currentTeam"
                            :href="organizationEdit(currentTeam.slug)"
                            class="font-medium text-foreground underline underline-offset-4"
                            >Fill in your profile</Link
                        >
                        and every task afterwards gets it.
                    </p>
                </div>

                <div class="flex flex-col gap-2">
                    <Button type="submit" :disabled="run.processing">
                        <Play class="size-4" />
                        {{ run.processing ? 'Starting…' : 'Run task' }}
                    </Button>
                    <!-- Said at the point of action. The gate panel above
                         explains the level; this says the app will enforce it. -->
                    <p class="text-xs text-muted-foreground">
                        {{
                            skill.supervision === 'unsupervised'
                                ? 'This one is released as soon as it is written — no approval needed.'
                                : 'The draft will be held here until it has been signed off. You will not be able to download or copy it before then.'
                        }}
                    </p>
                </div>
            </form>
        </section>

        <p v-else class="text-sm text-muted-foreground">
            This task is not in your organization's catalogue. Add its
            collection on the Organization page to run it.
        </p>

        <dl class="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-xs text-muted-foreground">Category</dt>
                <dd>
                    {{ skill.categoryLabel }}
                    <span v-if="!skill.isCore" class="text-muted-foreground"
                        >· special collection</span
                    >
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Added</dt>
                <dd class="tabular-nums">{{ skill.dateAdded ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Last reviewed</dt>
                <dd class="tabular-nums">
                    {{ skill.lastReviewed ?? 'Not recorded' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">Instruction size</dt>
                <dd class="tabular-nums">
                    ~{{ skill.tokenEstimate.toLocaleString() }} tokens
                </dd>
            </div>
        </dl>

        <section v-if="skill.related.length > 0" class="flex flex-col gap-2">
            <h2 class="text-sm font-semibold">Related tasks</h2>
            <div class="flex flex-wrap gap-2">
                <Link
                    v-for="related in skill.related"
                    :key="related.slug"
                    :href="
                        skillShow({
                            current_team: teamSlug,
                            skill: related.slug,
                        }).url
                    "
                    class="inline-flex items-center gap-2 rounded-lg border border-sidebar-border/70 px-3 py-1.5 text-xs transition-colors hover:bg-accent/50 dark:border-sidebar-border"
                >
                    {{ related.name }}
                    <SupervisionBadge
                        :level="related.supervision"
                        :label="related.supervisionLabel"
                    />
                </Link>
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <h2 class="text-sm font-semibold">Instructions</h2>
            <!-- eslint-disable-next-line vue/no-v-html -->
            <article
                class="prose prose-sm max-w-none dark:prose-invert prose-headings:font-semibold prose-pre:overflow-x-auto"
                v-html="skill.body"
            />
        </section>

        <footer class="mt-auto border-t pt-4 text-xs text-muted-foreground">
            From the open-source nonprofit skills library<span
                v-if="skill.license"
            >
                ({{ skill.license }})</span
            ><span v-if="skill.sourceCommit">
                at
                <code class="font-mono">{{
                    skill.sourceCommit.slice(0, 7)
                }}</code></span
            >.
        </footer>
    </div>
</template>

<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, Play } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import SupervisionBadge from '@/components/SupervisionBadge.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
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
    currentTeam?: Team | null;
};

const props = defineProps<Props>();

const teamSlug = computed(() => props.currentTeam?.slug ?? '');
const catalogueUrl = computed(() => skillsIndex(teamSlug.value).url);

const run = useForm({ skill: props.skill.slug, notes: '' });

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

        <!-- The run control sits under the gate, so it is never possible to
             start work without having seen what will be required to release it. -->
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
                <div>
                    <Button type="submit" :disabled="run.processing">
                        <Play class="size-4" />
                        {{ run.processing ? 'Starting…' : 'Run task' }}
                    </Button>
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

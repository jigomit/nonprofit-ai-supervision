<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    usable: boolean;
    invitation: {
        token: string;
        name: string | null;
        credentialType: string | null;
        expiresAt: string;
    };
    run: {
        organization: string;
        task: string;
        supervisionNote: string;
        status: string;
        output: string | null;
        requestedBy: string;
        createdAt: string | null;
    };
    credentialTypes: { value: string; label: string }[];
};

const props = defineProps<Props>();

const page = usePage();
const status = computed(() => page.props.flash?.status);

const form = useForm({
    decision: 'approved',
    approver_name: props.invitation.name ?? '',
    credential_type: props.invitation.credentialType ?? '',
    credential_reference: '',
    justification: '',
});

const submit = (decision: string) => {
    form.decision = decision;
    form.post(`/expert-review/${props.invitation.token}`, {
        preserveScroll: true,
    });
};

const selectClasses =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';
</script>

<template>
    <Head :title="`Review: ${run.task}`" />

    <div class="min-h-screen bg-background px-4 py-10 text-foreground">
        <div class="mx-auto flex max-w-3xl flex-col gap-6">
            <header class="flex flex-col gap-1">
                <p
                    class="text-xs tracking-wide text-muted-foreground uppercase"
                >
                    {{ run.organization }}
                </p>
                <h1 class="text-2xl font-semibold text-balance">
                    {{ run.task }}
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ run.requestedBy }} has asked you to review this before it
                    is used.
                </p>
            </header>

            <div
                v-if="status"
                class="rounded-xl border border-emerald-600/25 bg-emerald-500/10 p-4 text-sm dark:border-emerald-400/25"
            >
                {{ status }}
            </div>

            <div
                v-else-if="!usable"
                class="rounded-xl border border-dashed p-8 text-center"
            >
                <p class="text-sm font-medium">This link is no longer active</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    It has already been used, has expired, or the work has since
                    been decided. Ask {{ run.requestedBy }} for a new link.
                </p>
            </div>

            <template v-else>
                <section
                    class="rounded-xl border border-amber-600/25 bg-amber-500/10 p-4 text-sm dark:border-amber-400/25"
                >
                    <p class="font-medium">
                        Why your sign-off is being asked for
                    </p>
                    <p class="mt-1">{{ run.supervisionNote }}</p>
                </section>

                <section v-if="run.output" class="flex flex-col gap-2">
                    <h2 class="text-sm font-semibold">
                        The draft, as produced
                    </h2>
                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <article
                        class="prose prose-sm max-w-none rounded-xl border p-4 dark:prose-invert"
                        v-html="run.output"
                    />
                </section>

                <section class="flex flex-col gap-4 rounded-xl border p-4">
                    <h2 class="text-sm font-semibold">Your decision</h2>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="approver_name">Your name</Label>
                            <Input
                                id="approver_name"
                                v-model="form.approver_name"
                            />
                            <InputError :message="form.errors.approver_name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="credential_type">Your standing</Label>
                            <select
                                id="credential_type"
                                v-model="form.credential_type"
                                :class="selectClasses"
                            >
                                <option value="">Select…</option>
                                <option
                                    v-for="type in credentialTypes"
                                    :key="type.value"
                                    :value="type.value"
                                >
                                    {{ type.label }}
                                </option>
                            </select>
                            <InputError
                                :message="form.errors.credential_type"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="credential_reference"
                                >Licence number</Label
                            >
                            <Input
                                id="credential_reference"
                                v-model="form.credential_reference"
                                placeholder="Optional"
                            />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="justification">Notes (optional)</Label>
                        <textarea
                            id="justification"
                            v-model="form.justification"
                            rows="3"
                            class="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        />
                    </div>

                    <InputError :message="form.errors.decision" />

                    <div class="flex flex-wrap gap-3">
                        <Button
                            :disabled="form.processing"
                            @click="submit('approved')"
                        >
                            Approve and release
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="form.processing"
                            @click="submit('rejected')"
                        >
                            Send back
                        </Button>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Your name and the standing you select are recorded
                        permanently against this piece of work.
                    </p>
                </section>
            </template>
        </div>
    </div>
</template>

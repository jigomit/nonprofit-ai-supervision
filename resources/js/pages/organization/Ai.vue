<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CircleCheck, KeyRound, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AiSettingsController from '@/actions/App/Http/Controllers/AiSettingsController';
import { dashboard } from '@/routes';
import { edit as organizationEdit } from '@/routes/organization';
import type { Team } from '@/types';

type Provider = {
    value: string;
    label: string;
    defaultModel: string;
    modelHint: string;
    needsApiKey: boolean;
    needsBaseUrl: boolean;
    defaultBaseUrl: string | null;
};

type Props = {
    settings: {
        provider: string | null;
        model: string | null;
        baseUrl: string | null;
        hasApiKey: boolean;
        isConfigured: boolean;
    };
    providers: Provider[];
    canEdit: boolean;
    currentTeam?: Team | null;
};

const props = defineProps<Props>();

const form = useForm({
    ai_provider: props.settings.provider ?? '',
    ai_model: props.settings.model ?? '',
    ai_api_key: '',
    ai_base_url: props.settings.baseUrl ?? '',
});

const selected = computed(() =>
    props.providers.find((provider) => provider.value === form.ai_provider),
);

// Switching service makes the stored key useless, so say that before the save
// rather than after it.
const switchingProvider = computed(
    () =>
        props.settings.hasApiKey &&
        form.ai_provider !== '' &&
        form.ai_provider !== props.settings.provider,
);

const needsNewKey = computed(
    () =>
        (selected.value?.needsApiKey ?? false) &&
        (!props.settings.hasApiKey || switchingProvider.value),
);

const teamSlug = computed(() => props.currentTeam?.slug ?? '');

const submit = () => {
    form.put(AiSettingsController.update.url(teamSlug.value), {
        preserveScroll: true,
        onSuccess: () => form.reset('ai_api_key'),
    });
};

const remove = () => {
    router.delete(AiSettingsController.destroy.url(teamSlug.value), {
        preserveScroll: true,
    });
};

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
                title: 'Organization',
                href: props.currentTeam
                    ? organizationEdit(props.currentTeam.slug)
                    : '#',
            },
            { title: 'AI provider', href: '#' },
        ],
    }),
});
</script>

<template>
    <Head title="AI provider" />

    <div class="flex h-full flex-1 flex-col gap-8 p-4">
        <Heading
            title="AI provider"
            description="Your drafts are produced on your own account, with your own key. Nothing you send leaves your provider, and no other organization's work is ever billed to you."
        />

        <div class="flex max-w-3xl flex-col gap-8">
            <div
                v-if="settings.isConfigured"
                class="flex gap-3 rounded-lg border border-emerald-600/25 bg-emerald-500/10 p-3 text-sm text-emerald-800 dark:text-emerald-200"
            >
                <CircleCheck class="mt-0.5 size-4 shrink-0" />
                <p>
                    Drafts are being produced by
                    <span class="font-medium">{{
                        providers.find((p) => p.value === settings.provider)
                            ?.label
                    }}</span
                    >.
                </p>
            </div>
            <div
                v-else
                class="flex gap-3 rounded-lg border border-amber-600/25 bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-200"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                <p>
                    No provider is set, so tasks return placeholder output. The
                    gates, the approvals and the audit record all still work —
                    the draft itself just isn't real.
                </p>
            </div>

            <form class="flex flex-col gap-8" @submit.prevent="submit">
                <fieldset :disabled="!canEdit" class="contents">
                    <section class="flex flex-col gap-3">
                        <h2 class="text-sm font-semibold">Service</h2>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label
                                v-for="provider in providers"
                                :key="provider.value"
                                class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition-colors hover:bg-accent/40"
                                :class="
                                    form.ai_provider === provider.value
                                        ? 'border-ring ring-[3px] ring-ring/20'
                                        : 'border-sidebar-border/70 dark:border-sidebar-border'
                                "
                            >
                                <input
                                    v-model="form.ai_provider"
                                    type="radio"
                                    name="ai_provider"
                                    :value="provider.value"
                                    class="mt-0.5 size-4 shrink-0"
                                />
                                <span class="flex flex-col gap-0.5">
                                    <span class="text-sm font-medium">{{
                                        provider.label
                                    }}</span>
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            provider.needsApiKey
                                                ? provider.defaultModel
                                                : 'Runs on your own machine or server'
                                        }}</span
                                    >
                                </span>
                            </label>
                        </div>
                        <InputError :message="form.errors.ai_provider" />
                    </section>

                    <section v-if="selected" class="flex flex-col gap-4">
                        <h2 class="text-sm font-semibold">Connection</h2>

                        <div class="grid gap-2">
                            <Label for="ai_model">Model</Label>
                            <Input
                                id="ai_model"
                                v-model="form.ai_model"
                                :placeholder="selected.defaultModel"
                            />
                            <p class="text-xs text-muted-foreground">
                                Leave blank to use
                                {{ selected.defaultModel }} —
                                {{ selected.modelHint }}.
                            </p>
                            <InputError :message="form.errors.ai_model" />
                        </div>

                        <div v-if="selected.needsApiKey" class="grid gap-2">
                            <Label for="ai_api_key">API key</Label>
                            <Input
                                id="ai_api_key"
                                v-model="form.ai_api_key"
                                type="password"
                                autocomplete="off"
                                :placeholder="
                                    needsNewKey
                                        ? 'Paste your key'
                                        : 'Stored — leave blank to keep it'
                                "
                            />
                            <p
                                v-if="switchingProvider"
                                class="text-xs text-amber-700 dark:text-amber-300"
                            >
                                You're changing service, so the key you already
                                stored won't work. Enter the new one.
                            </p>
                            <p v-else class="text-xs text-muted-foreground">
                                Encrypted before it is stored and never shown
                                again — not to us, and not back to this page.
                            </p>
                            <InputError :message="form.errors.ai_api_key" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="ai_base_url">
                                Address
                                <span
                                    v-if="!selected.needsBaseUrl"
                                    class="font-normal text-muted-foreground"
                                    >(optional)</span
                                >
                            </Label>
                            <Input
                                id="ai_base_url"
                                v-model="form.ai_base_url"
                                :placeholder="selected.defaultBaseUrl ?? ''"
                                inputmode="url"
                            />
                            <p class="text-xs text-muted-foreground">
                                {{
                                    selected.needsBaseUrl
                                        ? 'Where your server is reachable from this application.'
                                        : 'Only needed if you route through a gateway or proxy.'
                                }}
                            </p>
                            <InputError :message="form.errors.ai_base_url" />
                        </div>
                    </section>
                </fieldset>

                <div v-if="canEdit" class="flex flex-wrap items-center gap-4">
                    <Button type="submit" :disabled="form.processing">
                        <KeyRound />
                        {{ form.processing ? 'Saving…' : 'Save provider' }}
                    </Button>
                    <Button
                        v-if="settings.provider"
                        type="button"
                        variant="ghost"
                        @click="remove"
                    >
                        Remove
                    </Button>
                    <p
                        v-if="form.recentlySuccessful"
                        class="text-sm text-muted-foreground"
                    >
                        Saved.
                    </p>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    Only an owner or admin can change these settings.
                </p>
            </form>

            <p class="max-w-prose text-sm text-muted-foreground">
                Whichever service you choose, the supervision rules are
                unchanged: the tier a task carries is a property of the task,
                not of the model that drafted it.
                <Link
                    v-if="currentTeam"
                    :href="organizationEdit(currentTeam.slug)"
                    class="underline underline-offset-4"
                    >Review who has to sign off</Link
                >.
            </p>
        </div>
    </div>
</template>

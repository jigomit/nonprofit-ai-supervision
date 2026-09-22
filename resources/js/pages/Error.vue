<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard } from '@/routes';

const props = defineProps<{ status: number }>();

const page = usePage();

const homeUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

const homeLabel = computed(() =>
    page.props.currentTeam ? 'Back to your dashboard' : 'Back to the start',
);

/**
 * Say what happened and what to do about it. No apologies, no status-code
 * poetry, and never "something went wrong" — a person who hit one of these
 * wants to know whether it was them, us, or just time passing.
 */
const messages: Record<number, { title: string; body: string }> = {
    401: {
        title: 'You need to sign in first',
        body: 'This page is behind a login. Sign in and we will bring you straight back here.',
    },
    403: {
        title: 'This is not yours to open',
        body: 'You are signed in, but this belongs to a different organization — or it needs a role you do not have. If you think that is wrong, ask an owner of that organization to add you.',
    },
    404: {
        title: 'There is nothing at this address',
        body: 'The link may be mistyped, or whatever was here has since been removed. Nothing has broken.',
    },
    410: {
        title: 'This link has expired',
        body: 'Review links work once and then stop. Ask whoever sent it for a fresh one — it takes them a moment.',
    },
    419: {
        title: 'The page sat still too long',
        body: 'For safety, forms stop accepting after a while. Go back, reload, and your details should still be there.',
    },
    429: {
        title: 'That was a lot of requests at once',
        body: 'Give it a minute and try again. Nothing was lost.',
    },
    500: {
        title: 'Something on our side failed',
        body: 'This is ours, not yours. The failure has been recorded. Try again in a moment, and tell us if it keeps happening.',
    },
    503: {
        title: 'Down for a short while',
        body: 'We are updating the application. It should be back within a few minutes.',
    },
};

const message = computed(
    () =>
        messages[props.status] ?? {
            title: 'That did not work',
            body: 'We are not sure what happened. Try again, and tell us if it keeps happening.',
        },
);
</script>

<template>
    <Head :title="message.title" />

    <div class="shell">
        <main class="panel">
            <p class="code">Error {{ status }}</p>
            <h1>{{ message.title }}</h1>
            <p class="body">{{ message.body }}</p>

            <div class="actions">
                <Link :href="homeUrl" class="btn">{{ homeLabel }}</Link>
                <button
                    type="button"
                    class="btn btn-quiet"
                    @click="$inertia.visit($page.url, { replace: true })"
                >
                    Try again
                </button>
            </div>
        </main>
    </div>
</template>

<style scoped>
.shell {
    --ink: #12161c;
    --ink-raised: #171c24;
    --paper: #eceef1;
    --paper-dim: #9aa4b2;
    --rule: #262d38;

    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 1.5rem;
    background: var(--ink);
    color: var(--paper);
    font-family:
        'IBM Plex Sans',
        ui-sans-serif,
        system-ui,
        -apple-system,
        sans-serif;
    line-height: 1.6;
}

.panel {
    width: 100%;
    max-width: 34rem;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
    padding-bottom: 6vh;
}

.code {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 0.7rem;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--paper-dim);
    margin: 0;
}

h1 {
    font-family: 'Spectral', Georgia, 'Times New Roman', serif;
    font-weight: 400;
    font-size: clamp(1.75rem, 5vw, 2.5rem);
    line-height: 1.15;
    letter-spacing: -0.01em;
    margin: 0;
    text-wrap: balance;
}

.body {
    margin: 0;
    color: var(--paper-dim);
    max-width: 46ch;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 0.75rem;
}

.btn {
    display: inline-flex;
    align-items: center;
    height: 2.75rem;
    padding-inline: 1.5rem;
    border: 0;
    border-radius: 2px;
    background: var(--paper);
    color: var(--ink);
    font: inherit;
    font-size: 0.9rem;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: background 160ms ease;
}

.btn:hover,
.btn:focus-visible {
    background: #fff;
}

.btn-quiet {
    background: transparent;
    color: var(--paper);
    border: 1px solid var(--rule);
}

.btn-quiet:hover,
.btn-quiet:focus-visible {
    background: var(--ink-raised);
}

:focus-visible {
    outline: 2px solid var(--paper);
    outline-offset: 3px;
}
</style>

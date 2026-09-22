<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard, login } from '@/routes';

const page = usePage();

const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : null,
);

/**
 * The library's own three words, its own counts, and its own account of what
 * each level demands. Nothing here is marketing copy dressed up as data.
 */
const levels = [
    {
        token: 'unsupervised',
        label: 'Released as produced',
        count: 7,
        gate: 'A mistake costs time, not much else.',
        example: 'Social media · email newsletters · grant research',
        tone: 'pass',
    },
    {
        token: 'review',
        label: 'Held for a colleague',
        count: 70,
        gate: 'A knowledgeable staff member reads it before it is used.',
        example: 'Grant proposals · board minutes · donor communications',
        tone: 'hold',
    },
    {
        token: 'expert-required',
        label: 'Held for a professional',
        count: 25,
        gate: 'A credentialed CPA or attorney signs off before it is filed.',
        example: 'Form 990 · bylaws · indirect cost rates · gift instruments',
        tone: 'stop',
    },
];

const steps = [
    {
        title: 'Your catalogue',
        body: 'Every organization gets the 57 core tasks. The five special collections — housing, arts, faith, resale, community finance — are opt-in, so a food bank is never asked to reason about performance licensing.',
    },
    {
        title: 'The run',
        body: 'A person starts a task. The instructions come from the library verbatim; your organization’s own details are passed separately and never mixed into them.',
    },
    {
        title: 'The gate',
        body: 'The output lands where its level says it lands. Approving expert-required work without a credential is refused — not discouraged, refused.',
    },
    {
        title: 'The record',
        body: 'Who cleared it, what standing they claimed, and when. Exportable — and the count of anything released without the sign-off it needed sits at the top.',
    },
];
</script>

<template>
    <Head title="Signoff — supervised AI for nonprofits">
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link
            rel="preconnect"
            href="https://fonts.gstatic.com"
            crossorigin="anonymous"
        />
        <link
            href="https://fonts.googleapis.com/css2?family=Spectral:ital,wght@0,300;0,400;0,600;1,300&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap"
            rel="stylesheet"
        />
    </Head>

    <div class="shell">
        <header class="bar">
            <span class="wordmark">Signoff</span>
            <nav class="bar-nav">
                <a
                    href="https://github.com/sector-skills/nonprofit-skills"
                    class="quiet-link"
                    >The library</a
                >
                <Link
                    v-if="dashboardUrl"
                    :href="dashboardUrl"
                    class="quiet-link"
                    >Dashboard</Link
                >
                <Link v-else :href="login()" class="quiet-link">Sign in</Link>
            </nav>
        </header>

        <main>
            <!-- The gate is the product, so the gate is the hero. -->
            <section class="hero">
                <p class="eyebrow">Supervised AI for nonprofits</p>
                <h1>
                    Most nonprofits already use AI.<br />
                    <em>Very few can say who checked it.</em>
                </h1>
                <p class="lede">
                    Signoff gives every task a level of human review and then
                    enforces it. Work that needs a professional does not move
                    until one signs it — and what does move leaves a record you
                    can hand to a board.
                </p>

                <div class="actions">
                    <Link v-if="dashboardUrl" :href="dashboardUrl" class="btn"
                        >Open the app</Link
                    >
                    <Link v-else :href="login()" class="btn">Sign in</Link>
                    <a href="#how" class="btn btn-quiet">How it works</a>
                </div>

                <!-- Signature: three lanes, and only one lets the work
                     through. Runs once on load, then holds still. -->
                <figure class="gate" aria-hidden="true">
                    <div
                        v-for="(level, index) in levels"
                        :key="level.token"
                        class="lane"
                        :class="`lane-${level.tone}`"
                        :style="{ '--delay': `${index * 260}ms` }"
                    >
                        <span class="lane-token">{{ level.token }}</span>
                        <span class="lane-track">
                            <span class="lane-item" />
                        </span>
                        <span class="lane-rule" />
                        <span class="lane-verdict">{{
                            level.tone === 'pass' ? 'released' : 'held'
                        }}</span>
                    </div>
                </figure>
                <p class="gate-caption">
                    102 nonprofit tasks. Seven of them can be released without
                    anyone reading the output.
                </p>
            </section>

            <section class="levels">
                <h2 class="section-head">The three levels</h2>
                <div class="level-grid">
                    <article
                        v-for="level in levels"
                        :key="level.token"
                        class="level"
                        :class="`level-${level.tone}`"
                    >
                        <header>
                            <code>{{ level.token }}</code>
                            <span class="count">{{ level.count }}</span>
                        </header>
                        <h3>{{ level.label }}</h3>
                        <p class="gate-text">{{ level.gate }}</p>
                        <p class="example">{{ level.example }}</p>
                    </article>
                </div>
                <p class="note">
                    The level belongs to the task, not to you. An organization
                    can demand more review than the library asks for. It cannot
                    ask for less.
                </p>
            </section>

            <section id="how" class="how">
                <h2 class="section-head">How it works</h2>
                <!-- Numbered because it genuinely is a sequence: each step only
                     makes sense after the one before it. -->
                <ol class="steps">
                    <li v-for="(step, index) in steps" :key="step.title">
                        <span class="step-index">{{ index + 1 }}</span>
                        <div>
                            <h3>{{ step.title }}</h3>
                            <p>{{ step.body }}</p>
                        </div>
                    </li>
                </ol>
            </section>

            <section class="contrast">
                <blockquote>
                    <p>
                        A written AI policy tells someone what they are not
                        allowed to do. It has no way of stopping them.
                    </p>
                </blockquote>
                <p>
                    Roughly half of nonprofit staff report using AI in ways no
                    policy covered, and about one organization in twenty-five
                    has a documented, repeatable workflow for it. The gap is not
                    a missing document. It is that nothing was ever attached to
                    the work itself.
                </p>
            </section>

            <section class="credit">
                <h2 class="section-head">Built on open work</h2>
                <p>
                    The levels are not invented here. They come from the
                    <a href="https://github.com/sector-skills/nonprofit-skills"
                        >Nonprofit AI Skills Library</a
                    >, maintained by Brendon Connelly and contributors, where
                    each task records how much review it needs and why. Signoff
                    reads that library rather than copying it, and every
                    imported task names the revision it came from.
                </p>
                <p>
                    If the levels are useful to you, the place to improve them
                    is upstream.
                </p>
            </section>
        </main>

        <footer class="foot">
            <span>Signoff · MIT licensed · an early pilot, not a product</span>
            <a href="https://jigomit.com" class="quiet-link">JIGOMIT</a>
        </footer>
    </div>
</template>

<style scoped>
.shell {
    /* Ink ground, paper type. This is an application about records, and the
       only colour on the page is the colour that carries meaning. */
    --ink: #12161c;
    --ink-raised: #171c24;
    --paper: #eceef1;
    --paper-dim: #9aa4b2;
    --rule: #262d38;
    --pass: #4c9e72;
    --hold: #cd9a3c;
    --stop: #c4593d;

    min-height: 100vh;
    background: var(--ink);
    color: var(--paper);
    font-family: 'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif;
    font-size: 16px;
    line-height: 1.6;
    display: flex;
    flex-direction: column;
}

main {
    flex: 1;
    width: 100%;
    max-width: 68rem;
    margin: 0 auto;
    padding-inline: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 7rem;
    padding-bottom: 7rem;
}

.bar {
    width: 100%;
    max-width: 68rem;
    margin: 0 auto;
    padding: 1.75rem 1.5rem 3.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1.5rem;
}

.wordmark {
    font-family: 'Spectral', Georgia, serif;
    font-size: 1.15rem;
    font-weight: 600;
}

.bar-nav {
    display: flex;
    gap: 1.75rem;
    font-size: 0.875rem;
}

.quiet-link {
    color: var(--paper-dim);
    text-decoration: none;
    transition: color 160ms ease;
}

.quiet-link:hover,
.quiet-link:focus-visible {
    color: var(--paper);
}

.hero {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 1.5rem;
}

.eyebrow {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 0.7rem;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--paper-dim);
    margin: 0;
}

h1 {
    font-family: 'Spectral', Georgia, serif;
    font-weight: 400;
    font-size: clamp(2.1rem, 6vw, 3.9rem);
    line-height: 1.08;
    letter-spacing: -0.015em;
    margin: 0;
    max-width: 18ch;
    text-wrap: balance;
}

h1 em {
    font-style: italic;
    font-weight: 300;
    color: var(--paper-dim);
}

.lede {
    max-width: 54ch;
    color: var(--paper-dim);
    margin: 0;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 0.5rem;
}

.btn {
    display: inline-flex;
    align-items: center;
    height: 2.75rem;
    padding-inline: 1.5rem;
    border-radius: 2px;
    background: var(--paper);
    color: var(--ink);
    font-size: 0.9rem;
    font-weight: 500;
    text-decoration: none;
    transition:
        background 160ms ease,
        color 160ms ease;
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
    color: var(--paper);
}

.gate {
    width: 100%;
    margin: 2.5rem 0 0;
    padding: 1.75rem 0;
    border-top: 1px solid var(--rule);
    border-bottom: 1px solid var(--rule);
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
}

.lane {
    display: grid;
    grid-template-columns: 9.5rem 1fr 1px 5rem;
    align-items: center;
    gap: 1rem;
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 0.72rem;
}

.lane-token {
    color: var(--paper-dim);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.lane-track {
    position: relative;
    height: 1px;
    background: var(--rule);
}

.lane-item {
    position: absolute;
    top: 50%;
    left: 0;
    width: 0.5rem;
    height: 0.5rem;
    margin-top: -0.25rem;
    border-radius: 1px;
    background: currentColor;
    animation: travel 1.5s cubic-bezier(0.4, 0, 0.2, 1) var(--delay) both;
}

/* The pass lane carries on past the rule; the held lanes stop dead at it. */
.lane-pass .lane-item {
    animation-name: travel-through;
}

.lane-rule {
    height: 2.25rem;
    background: var(--rule);
}

.lane-verdict {
    text-align: right;
    letter-spacing: 0.04em;
    opacity: 0;
    animation: settle 400ms ease calc(var(--delay) + 1.2s) both;
}

.lane-pass {
    color: var(--pass);
}

.lane-hold {
    color: var(--hold);
}

.lane-stop {
    color: var(--stop);
}

@keyframes travel {
    from {
        left: 0;
    }
    to {
        left: calc(100% - 0.5rem);
    }
}

@keyframes travel-through {
    from {
        left: 0;
    }
    to {
        left: calc(100% + 1.4rem);
    }
}

@keyframes settle {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .lane-item {
        animation: none;
        left: calc(100% - 0.5rem);
    }

    .lane-pass .lane-item {
        left: calc(100% + 1.4rem);
    }

    .lane-verdict {
        animation: none;
        opacity: 1;
    }
}

.gate-caption {
    font-size: 0.85rem;
    color: var(--paper-dim);
    margin: 0;
    max-width: 48ch;
}

.section-head {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 0.7rem;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--paper-dim);
    font-weight: 400;
    margin: 0 0 2rem;
    padding-bottom: 0.9rem;
    border-bottom: 1px solid var(--rule);
}

.level-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
    gap: 1px;
    background: var(--rule);
    border: 1px solid var(--rule);
}

.level {
    background: var(--ink);
    padding: 1.75rem 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.level header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 1rem;
}

.level code {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 0.72rem;
}

.level .count {
    font-family: 'Spectral', Georgia, serif;
    font-size: 2rem;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}

.level-pass code,
.level-pass .count {
    color: var(--pass);
}

.level-hold code,
.level-hold .count {
    color: var(--hold);
}

.level-stop code,
.level-stop .count {
    color: var(--stop);
}

.level h3 {
    font-family: 'Spectral', Georgia, serif;
    font-weight: 400;
    font-size: 1.25rem;
    margin: 0;
}

.gate-text {
    margin: 0;
    font-size: 0.9rem;
}

.example {
    margin: auto 0 0;
    padding-top: 0.75rem;
    font-size: 0.78rem;
    color: var(--paper-dim);
    border-top: 1px solid var(--rule);
}

.note {
    margin: 1.5rem 0 0;
    max-width: 56ch;
    color: var(--paper-dim);
    font-size: 0.9rem;
}

.steps {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 2.25rem;
}

.steps li {
    display: grid;
    grid-template-columns: 2.5rem 1fr;
    gap: 1.25rem;
    max-width: 62ch;
}

.step-index {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 0.72rem;
    color: var(--paper-dim);
    padding-top: 0.45rem;
}

.steps h3 {
    font-family: 'Spectral', Georgia, serif;
    font-weight: 400;
    font-size: 1.3rem;
    margin: 0 0 0.4rem;
}

.steps p {
    margin: 0;
    color: var(--paper-dim);
}

.contrast {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    max-width: 60ch;
}

.contrast blockquote {
    margin: 0;
    padding-left: 1.5rem;
    border-left: 2px solid var(--stop);
}

.contrast blockquote p {
    font-family: 'Spectral', Georgia, serif;
    font-size: clamp(1.3rem, 3vw, 1.75rem);
    font-style: italic;
    font-weight: 300;
    line-height: 1.35;
    margin: 0;
}

.contrast > p {
    margin: 0;
    color: var(--paper-dim);
}

.credit {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    max-width: 62ch;
}

.credit p {
    margin: 0;
    color: var(--paper-dim);
}

.credit a {
    color: var(--paper);
    text-underline-offset: 3px;
}

.foot {
    width: 100%;
    max-width: 68rem;
    margin: 0 auto;
    padding: 2rem 1.5rem 3rem;
    border-top: 1px solid var(--rule);
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    justify-content: space-between;
    font-size: 0.8rem;
    color: var(--paper-dim);
}

:focus-visible {
    outline: 2px solid var(--paper);
    outline-offset: 3px;
}

@media (max-width: 40rem) {
    main {
        gap: 4.5rem;
        padding-bottom: 4.5rem;
    }

    .lane {
        grid-template-columns: 7.5rem 1fr 1px 3.5rem;
        gap: 0.6rem;
        font-size: 0.65rem;
    }
}
</style>

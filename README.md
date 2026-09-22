# Signoff

**Supervised AI for nonprofits. The level of review a task needs is a property of the task, and the application enforces it.**

Most nonprofits already use AI. Very few can say what it produced, who checked it, or on what basis — 53% report informal, unofficial use, and only about 4% have documented, repeatable AI workflows.[^1] The usual answer is a written policy. A policy does not stop anything.

This does. Every task carries a supervision level, and that level is a gate the work cannot get past:

| Level             | What happens to the output                                                               |
| ----------------- | ---------------------------------------------------------------------------------------- |
| `unsupervised`    | Released as it is produced. A mistake costs time, not much else.                         |
| `review`          | Blocked until a named staff member signs it off.                                         |
| `expert-required` | Blocked until a credentialed CPA or attorney signs it off, with the credential recorded. |

The result is a record an organization can hand to its board or a funder: what AI touched, who cleared it, and what standing they claimed.

## Where the supervision levels come from

They are not invented here. They come from the [Nonprofit AI Skills Library](https://github.com/sector-skills/nonprofit-skills) — an open, MIT-licensed collection of nonprofit tasks maintained by **Brendon Connelly** and contributors, browsable at [nonprofit-skills.ai](https://nonprofit-skills.ai). Each task in it records how much human review it genuinely needs, and why.

At the time of writing that is 102 tasks: **7 unsupervised, 70 review, 25 expert-required.**

This application does not copy that library. It reads a checkout of it at install time, and each imported row records the commit it came from, so an audit record can name the revision it was built against. If you find the levels useful, the place to contribute is upstream.

## What makes this different from a policy generator

- **The level belongs to the task, not the tenant.** An organization can decide a task needs _more_ review than the library says. It cannot decide it needs less — the model refuses.
- **The rules are snapshotted when work starts.** Loosening your policy later does not retroactively unlock work that was blocked when it ran.
- **Outside professionals need no account.** The organization invites the accountant or attorney it already works with, by single-use expiring link. There is no marketplace and no credential verification — the professional relationship exists outside this software.
- **Overrides are recorded, not hidden.** Where policy allows it, an owner can release expert-required work without a sign-off — but only with a written reason, and the release stays flagged on the run, the work list, and the board report.
- **Upstream changes surface.** When the library revises a level, every organization running that task is told rather than having its gate silently moved.
- **Each organization brings its own model.** Anthropic, OpenAI, xAI, Mistral, Meta's Llama, or Ollama on your own machine — configured per organization, with your own key. The supervision tier is a property of the task, not of the model that drafted it, so it does not change with the provider.

## Status

**Early. Built as a pilot, not a product.** It has no billing, no self-serve onboarding, and has not yet been run by a real organization. Registration is open by default — turn it off before deploying anywhere reachable. Each organization supplies its own API key, so an open registration does not spend yours; the development fallback in `.env` does, and should be left empty in production.

Published for the discussion around it more than for installation.

## Running it

Requires PHP 8.3+, MySQL, Node, and Composer.

```bash
git clone <this repo> signoff && cd signoff
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate

# create the database named in .env, then:
php artisan migrate

# fetch the skills library and import it
git clone https://github.com/sector-skills/nonprofit-skills.git storage/app/skills-library
php artisan skills:import
```

No key is needed to try it. An organization with no provider set gets clearly-marked placeholder output, which is enough to exercise every gate, the approval queue and the audit record without spending anything.

To produce real drafts, each organization sets its own provider and key under **Organization → AI provider**. Keys are encrypted at rest and never sent back to the browser.

**Running locally.** Ollama needs an address rather than a key, so an organization that does not want its work leaving its own network can run entirely on its own hardware.

One thing to know before relying on it: over its context window Ollama does not fail. It drops the middle of the prompt and answers from what is left, reporting success — and because the trimming takes the middle, both ends of the instructions survive, so the output reads as though nothing happened. Skill bodies here average about 3,900 tokens and reach 9,400, against Ollama's 4,096 token default. This application sizes the window to each prompt, refuses a task by name when the model cannot hold it, and discards any draft the server did not read in full.

A model with an 8K window (llama3) covers 82 of the 102 tasks. One with a large window (llama3.1 and later) covers all of them.

The `AI_FALLBACK_*` values in `.env` are a development convenience for organizations that have not configured a provider. Leave them empty in production.

Runs and emails are queued, so a worker has to be running:

```bash
php artisan queue:work
```

## Tests

```bash
php artisan test
```

295 tests. The ones worth reading are `tests/Feature/Tasks/TaskGateTest.php`, which assert the gate cannot be bypassed, and `tests/Browser/GateTest.php`, which assert the interface does not quietly offer a way around it either.

## Built with

Laravel 13, Inertia 3, Vue 3, Tailwind 4, Pest 4. Anthropic goes through the official PHP SDK, so skill instructions can be sent as a cached system block and repeated runs of the same task reuse them; every other provider speaks OpenAI's chat-completions shape and shares one driver, which makes adding another an entry in `config/ai.php`.

## Licence

MIT — see [LICENSE](LICENSE). The skills library it reads is separately MIT-licensed and is not redistributed here.

[^1]: NTEN and The Bridgespan Group, _State of Nonprofit AI_ (2026, n=917); Virtuous and Fundraising.AI, _Nonprofit AI Adoption Report_ (2026, n=346).

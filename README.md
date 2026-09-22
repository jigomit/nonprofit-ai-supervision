# Signoff

**Nonprofit AI supervision: the level of review a task needs is a property of the task, and the application enforces it.**

_The repository is `nonprofit-ai-supervision`; the application calls itself Signoff._

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
- **The known mistakes are shown before the work starts.** 95 of the 102 tasks document where the work usually goes wrong, and 64 say what you should end up with. Both are lifted out of the instructions and put in front of the person, above the button, rather than being read only by the model.
- **Upstream changes surface.** When the library revises a level, every organization running that task is told rather than having its gate silently moved.
- **Each organization brings its own model.** Anthropic, OpenAI, xAI, Mistral, Meta's Llama, or Ollama on your own machine — configured per organization, with your own key. The supervision tier is a property of the task, not of the model that drafted it, so it does not change with the provider.

## Status

**Early. Built as a pilot, not a product.** It has no billing, no self-serve onboarding, and has not yet been run by a real organization. Sign-up is closed by default and each organization supplies its own API key, so a reachable host does not hand out accounts or spend anyone's money — see [deploy/](deploy/) for the three settings that matter and the two processes it does not work without.

Published for the discussion around it more than for installation.

## Running it

Requires PHP 8.3+, MySQL, Node 22, and Composer. `poppler` (for `pdftotext`) if you
want PDFs to be readable when attached — without it a PDF uploads and is marked
unreadable rather than silently contributing nothing.

```bash
git clone git@github.com:jigomit/nonprofit-ai-supervision.git signoff && cd signoff

# .env and the app key must exist before the front end is built: the Vite
# plugin generates typed routes by calling artisan.
composer install
cp .env.example .env && php artisan key:generate

# create the MySQL database named in .env (nothing creates it for you), then:
php artisan migrate

npm install && npm run build

# fetch the skills library and import it — with no import the catalogue is
# empty and there is nothing to run
git clone https://github.com/sector-skills/nonprofit-skills.git storage/app/skills-library
php artisan skills:import

# create the first account; sign-up is closed, so this is the way in
php artisan signoff:install

php artisan serve
```

`composer setup` does everything up to the migration in one step; the library
import and the first account are still yours to run.

New to it? **[docs/using-signoff.md](docs/using-signoff.md)** walks through it from
the other side of the screen — no terminal.

No key is needed to try it. An organization with no provider set gets clearly-marked placeholder output, which is enough to exercise every gate, the approval queue and the audit record without spending anything.

To produce real drafts, each organization sets its own provider and key under **Organization → AI provider**. Keys are encrypted at rest and never sent back to the browser.

**Running locally.** Ollama needs an address rather than a key, so an organization that does not want its work leaving its own network can run entirely on its own hardware.

One thing to know before relying on it: over its context window Ollama does not fail. It drops the middle of the prompt and answers from what is left, reporting success — and because the trimming takes the middle, both ends of the instructions survive, so the output reads as though nothing happened. Skill bodies here average about 3,900 tokens and reach 9,400, against Ollama's 4,096 token default. This application sizes the window to each prompt, refuses a task by name when the model cannot hold it, and discards any draft the server did not read in full.

A model with an 8K window (llama3) covers 82 of the 102 tasks. One with a large window (llama3.1 and later) covers all of them.

The `AI_FALLBACK_*` values in `.env` are a development convenience for organizations that have not configured a provider. Leave them empty in production.

Runs and emails are queued, so a worker has to be running — and the calendar
only speaks if the scheduler is:

```bash
php artisan queue:work
php artisan schedule:work
```

Neither failure is visible from the interface: with no worker every run sits
at "queued" and the app merely looks slow. `deploy/supervisor.conf` keeps both
up on a server.

## Tests

```bash
php artisan test
```

337 tests. The ones worth reading are `tests/Feature/Tasks/TaskGateTest.php`, which assert the gate cannot be bypassed, and `tests/Browser/GateTest.php`, which assert the interface does not quietly offer a way around it either.

## Built with

Laravel 13, Inertia 3, Vue 3, Tailwind 4, Pest 5. Anthropic goes through the official PHP SDK, so skill instructions can be sent as a cached system block and repeated runs of the same task reuse them; every other provider speaks OpenAI's chat-completions shape and shares one driver, which makes adding another an entry in `config/ai.php`.

## Licence

MIT — see [LICENSE](LICENSE) and [NOTICE](NOTICE). The skills library it reads is separately MIT-licensed and is not redistributed here.

[^1]: NTEN and The Bridgespan Group, _State of Nonprofit AI_ (2026, n=917); Virtuous and Fundraising.AI, _Nonprofit AI Adoption Report_ (2026, n=346).

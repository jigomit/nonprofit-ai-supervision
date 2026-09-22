# Deploying

Beyond the usual Laravel steps, three things decide whether this application
is safe and whether it works at all.

## 1. Close the front door

```
ALLOW_REGISTRATION=false
```

Off is the default, and `.env.example` ships it off. An account here can spend
the organization's API key and read its work, so pilot organizations are
invited to a team rather than signing themselves up.

Which leaves the obvious question of how the first account exists at all:

```bash
php artisan signoff:install
```

It asks for a name, an email and a password, creates the owner and their
organization, and refuses to run a second time. After that, invitations go out
from Settings → Teams.

## 2. Leave the fallback empty

```
AI_FALLBACK_PROVIDER=
AI_FALLBACK_API_KEY=
```

These exist so a developer can run the app without configuring an
organization. Filled in on a live host, they become the host's key paying for
every organization that has not set one of its own.

## 3. Run the two processes

`deploy/supervisor.conf` in this directory. Neither failure is visible from
the interface:

| missing         | what you see                                               |
| --------------- | ---------------------------------------------------------- |
| `queue:work`    | every run sits at "queued"; the app looks slow, not broken |
| `schedule:work` | the calendar never emails anyone; due work goes quiet      |

Check both after any deploy:

```bash
php artisan queue:monitor default --max=25   # a growing queue means no worker
php artisan schedule:list                    # what should be running, and when
```

## Two host packages

`poppler-utils` — PDFs attached to a task are read with `pdftotext`. Without it
a PDF uploads, is stored, and is marked unreadable; the model never sees it and
the run says so, but nobody gets the 990 they thought they had attached.

`shell_exec` must not be in `disable_functions`. The importer reads the skill
library's commit with it, and without it every imported task records a null
`source_commit` — losing the provenance the audit record exists for. The import
still reports success.

## Also worth setting

`AI_DAILY_RUN_LIMIT` caps how many runs one organization may start in a day
(default 50, `0` removes it). An organization that needs more gets its own
number on its profile rather than the cap being lifted for everyone.

`APP_URL` must be the real public address. Notification emails link back to
the work waiting for a decision, and a wrong value here sends every reviewer
to a dead link.

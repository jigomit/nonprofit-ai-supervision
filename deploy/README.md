# Deploying

Beyond the usual Laravel steps, three things decide whether this application
is safe and whether it works at all.

## 1. Close the front door

```
ALLOW_REGISTRATION=false
```

Off is the default. An account here can spend the organization's API key and
read its work, so pilot organizations are invited to a team rather than
signing themselves up. Invitations go out from Settings → Teams.

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

## Also worth setting

`AI_DAILY_RUN_LIMIT` caps how many runs one organization may start in a day
(default 50, `0` removes it). An organization that needs more gets its own
number on its profile rather than the cap being lifted for everyone.

`APP_URL` must be the real public address. Notification emails link back to
the work waiting for a decision, and a wrong value here sends every reviewer
to a dead link.

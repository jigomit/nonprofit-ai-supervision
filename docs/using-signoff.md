# Using Signoff

For the people doing the work. No terminal, no setup — this assumes somebody
has already installed it and sent you a link.

---

## What it is for

Nearly every nonprofit uses AI now. Very few can say who checked the output
before it went out.

Signoff does not try to stop you using AI. It gives each piece of work a level
of human review and then enforces it, so that when a funder, an auditor or your
board asks who checked something, there is an answer.

## The three levels

The level belongs to the **task**, not to you. It comes from the open library
of nonprofit tasks this runs on, and your organization can demand more review
than the library asks for — never less.

| Level                           | What happens                                                             | Examples                                   |
| ------------------------------- | ------------------------------------------------------------------------ | ------------------------------------------ |
| **No review needed**            | The draft is yours immediately                                           | Social posts, a newsletter, grant research |
| **Someone must read it**        | Held until a colleague signs off                                         | Most things — appeals, reports, plans      |
| **A professional must sign it** | Held until a CPA or attorney signs off, and their credential is recorded | Form 990, bylaws, gift instruments         |

Roughly 7 of the 102 tasks need no review, 70 need a colleague, and 25 need a
credentialed professional.

---

## Setting up, once

**1. Describe your organization.** Organization in the sidebar. This does two
things: it decides which tasks appear in your catalogue, and it is what every
task is told about you.

> Do not skip the mission. Every draft is written from this. Leave it blank and
> the AI has nothing specific to work from, and you get generic copy full of
> blanks to fill in. That reads like a bad tool; it is an empty form.

Also here: **what happens when work needs a professional and none is
available**. The default lets an owner release it anyway with a written reason,
which stays flagged on the record for ever. The alternative is a hard stop.
Choose deliberately — a hard stop is honest, but work that cannot move is work
people do somewhere else instead.

**2. Choose who writes the drafts.** Organization → AI provider. You bring your
own account — ChatGPT, Claude, Grok, Mistral, Llama, or Ollama running on your
own machine. Your key is encrypted and never shown again, and nothing you send
is billed to anyone else.

> Until you do this, tasks come back as placeholder text rather than real
> drafts. Everything else works, so it is easy to miss. The run will say so.

---

## Doing a piece of work

**Find the task.** Skill catalogue in the sidebar. Filter by level, or search.
Tasks not in your catalogue are shown but cannot be run — add their collection
on the Organization page.

**Read the two things above the button.** _Where this usually goes wrong_ is the
list of mistakes people actually make on this task — it is the most valuable
thing on the page and it costs you fifteen seconds. _What you should end up
with_ is what a finished job looks like.

**Say what you need, and attach what you have.** Last year's 990, the budget, an
export from your CRM. The words inside are sent with the task, so the draft can
use your real figures instead of inventing them.

> A scan or a photograph of a document is a picture — there are no words in it
> to read. The run will tell you if a file could not be read; believe it, and do
> not assume the AI saw it.

**Run it, and wait.** A minute or two, sometimes longer on your own hardware.
The page updates itself.

**It stops at its gate.** Work that needs review sits in the Work list until
someone signs off. You cannot copy or download it before then — that is the
point, not a bug.

---

## If you are the one reviewing

You will get an email, and it will be in **Work** and on your dashboard.

Your job is not to check the AI's grammar. It is to be the person whose name is
on this. Read it as though you wrote it, because as far as the record is
concerned, you approved it.

Three things you can do:

- **Approve** — the work is released, and the record says you released it.
- **Send it back** — say why. The original stays on the record exactly as it
  was; a second attempt starts fresh and points back at it. Nothing is quietly
  rewritten.
- **Ask a professional** — for work that needs a CPA or attorney, send a
  single-use link. They need no account and the link expires in 14 days.

If a draft reads thin and generic, check the organization profile before
blaming the tool. An empty profile is the usual cause.

---

## Work that needs a professional

Signoff does not supply accountants or lawyers. It assumes you already have
one — your auditor, the attorney who did your bylaws — and gives you a way to
put one specific document in front of them.

They open the link, read the work, and sign with their credential type and
licence number. That is recorded against the run permanently.

If none is available and your policy allows it, an owner can release the work
anyway, with a written reason. It stays flagged — on the run, on the Work list,
and on the board report. That is deliberate. The flag is the product.

---

## The board report

Report in the sidebar. One page for the board packet: what ran, what needed
review, who cleared it, and anything released without the professional sign-off
it called for. Exportable.

If your board has never asked about AI, this is the document that lets you
answer before they do.

---

## The calendar

Nothing runs itself. The calendar tells you what is due — the annual return,
the audit, the board cycle — anchored to your own fiscal year, and emails you on
weekday mornings. You still decide to start it.

---

## When something looks wrong

| What you see                        | What it means                                               |
| ----------------------------------- | ----------------------------------------------------------- |
| A draft full of `[square brackets]` | The organization profile is thin. Fill in the mission.      |
| "This is not a real draft"          | No AI provider is set up yet.                               |
| A run stuck on "Queued"             | Whoever runs the server needs to check the queue worker.    |
| "Not in your catalogue"             | Add that collection on the Organization page.               |
| A task refused as too long          | Only on local models. A larger model handles all 102 tasks. |
| A file marked "No text found"       | It is a scan. The AI did not see it.                        |

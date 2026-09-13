# ADR-0039 — Archive unreferenced Superpowers plans out of the working tree

**Status:** Accepted — 13 Sep 2026
**Supersedes:** nothing. This implements `AGENTS.md` §Development methodology
rather than overriding it — see "The rule already says this".

## Context

`docs/superpowers/` is 135 files and ~1,060,000 tokens: **81% of the entire
documentation corpus**, and roughly twelve times the size of everything else in
`docs/` combined.

It is not a per-session cost — nothing instructs an agent to read it. The cost
is paid on every repository-wide search. `grep`, `Explore` and an agent's own
file-walking sweep it, and an agent following a plausible-looking filename can
spend tens of thousands of tokens inside a plan describing work that shipped a
month ago.

The measurement that started this: the product owner asked which parts of the
repository are a token burden. This is the largest single answer.

## The rule already says this

`AGENTS.md` §Development methodology, last line:

> `docs/superpowers/plans/` is an append-only historical record of how one pass
> of work was executed — **never consult it to answer "what is the current
> state"**.

That rule has two halves, and the repository was honouring only the first.
Keeping every plan checked out in the working tree is precisely what invites
the consultation the second half forbids: a file that is present, greppable and
confidently written reads like current truth whether or not it is.

So this ADR does not need to supersede the append-only rule. Append-only
concerns *editing* history. Nothing here edits a single archived line.

## Decision

Remove from the working tree the **60** files under `docs/superpowers/` that
satisfy both mechanical filters below. Leave `docs/superpowers/ARCHIVED-INDEX.md`
naming every one of them and the command that retrieves it.

**Filter 1 — nothing living cites it.** 66 of the 135 files are referenced from
`app/`, `database/`, `tests/`, `docs/` or `.kiro/`. Code doc blocks cite the
plan that explains a decision — 47 files in `app/`, 19 in `tests/`, 16 in
`database/`. Those 66 are load-bearing and stay. This was the finding that most
changed the shape of this decision: half the archive is actively wired into the
code as its "why".

**Filter 2 — the removed set is closed under linking.** Of the 69 files nothing
living cites, 9 are still linked from a file that stays. Removing those would
break `ci/verify-docs.sh` GATE 4, which checks that every markdown link
resolves. They stay. The remaining 60 link only to each other.

69 minus 9 is 60, and ~467,000 tokens.

## Why removal from the working tree is not deletion

Git history is the archive. It already was, before this ADR.

```bash
git show <removal-commit>^:docs/superpowers/plans/<name>.md   # read it
git grep -n "<pattern>" <removal-commit>^ -- docs/superpowers/ # search all of it
```

Neither touches the working tree. That asymmetry is the entire point:
retrievable by a human who asks for it, invisible to the tools that would
otherwise sweep it into an agent's context.

This was verified, not assumed — the retrieval command was run against a file
after its removal, and the full original content came back. The evidence is in
the PR that carries this ADR.

## Consequences

**Good.** Repository-wide search stops walking ~467,000 tokens of superseded
plans. The 66 files that code actually cites remain exactly where their doc
blocks say they are — no link, in prose or in code, changes.

**Costly.** Reading an archived plan now takes a `git show` rather than opening
a file. That is a deliberate trade: the friction falls on the rare, deliberate
act of consulting history, not on the routine act of searching the repo.

**Risk accepted, stated plainly.** "Nothing cites it" is measured today, not
forever. A future reader may want the 12 Aug payment-auth review rounds — six
files for one batch, all archived here — and will have to know to look in git
history. `ARCHIVED-INDEX.md` exists so that the knowledge of *what* was archived
stays in the working tree even when the content does not.

**Not done here.** The other 75 files are untouched. If the 66 cited ones should
eventually move too, that needs the code doc blocks to stop pointing at them
first, which is a larger change than this one.

## Alternatives rejected

**Delete outright.** Loses the content for anyone without git access to history,
and gains nothing over this — git keeps it either way.

**Move to a separate archive repository.** Another repository to create, host,
permission and keep in sync, to solve a problem `git show` already solves.

**Summarise each plan and keep the summary.** Forbidden by `AGENTS.md`
§Documentation — a hand-maintained summary of a canonical record is a rival
source, and it would also add tokens rather than remove them.

**Leave it alone.** The status quo, and the reason the corpus reached 81%
history in the first place.

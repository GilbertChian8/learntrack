---
description: Build one LearnTrack ticket, through to an open pull request.
argument-hint: NN [base-branch]
---
Build ticket $1 of LearnTrack. Base branch: $2 (origin/main when empty). You run unattended: end with an open pull request, or stop with a report that says why.

Read CLAUDE.md, docs/tickets/README.md and the ticket file docs/tickets/$1-*.md. Then read every TDD and contract section the ticket lists under "Read first". Do not build from memory of the design.

Rules: write the acceptance tests first and run them on MySQL; stay inside the ticket's "Allowed paths" plus tests; use Boost search-docs or Context7 before using any package API. If the code must differ from a document, change the document in the same branch and say why. If the design itself must change, do not push: stop and report the conflict.

When the build is done:

1. Run every check in CLAUDE.md.
2. Run the pr-reviewer agent on the local diff against the base branch. Fix its blockers and run the checks again. Fix a nit only when it is real drift or a real bug.
3. Push with `git push -u origin <branch>` and open the pull request against the base branch with `gh pr create`. The title is `NN: <ticket title>`.
4. Stop. Never merge.

The PR body: the ticket's PR checklist filled in; a table of every acceptance test with its result; every document changed, with its reason; "Decisions to confirm", one line each for every decision you made without asking, with the reason; the pr-reviewer blockers you fixed; anything left open; and whether a one-line rule in docs/review-lessons.md would have prevented a mistake you made, with the proposed line.

Build ticket $ARGUMENTS of LearnTrack.

Read CLAUDE.md, docs/tickets/README.md and the ticket file docs/tickets/$ARGUMENTS-*.md. Then read every TDD and contract section the ticket lists under "Read first". Do not build from memory of the design.

Rules: write the acceptance tests first and run them on MySQL; stay inside the ticket's "Allowed paths" plus tests; use Boost search-docs or Context7 before using any package API; stop and ask me if the design must change. If the code must differ from a document, change the document in the same branch and say why.

When done, report: a table of every acceptance test with its result, decisions you made without asking, documents changed with reasons, and anything left open. Say whether a one-line rule in docs/review-lessons.md would have prevented a mistake you made in this ticket, and propose the line. Do not push or open the PR until I say so.

# Contributing

Confluence is the canonical product specification, Jira project UCCM tracks
implementation and acceptance, and this repository contains source and release
automation.

## Delivery workflow

1. Select a refined Jira issue.
2. Create a focused branch named for the issue.
3. Add tests appropriate to the risk.
4. Run syntax, coding-standard and static-analysis checks.
5. Open a draft pull request linked to the Jira issue.
6. Keep implementation, review approval, merge, release and production
   acceptance as separate gates.

Never commit credentials, private update tokens, visitor consent records,
complete IP addresses, database exports, or production configuration.

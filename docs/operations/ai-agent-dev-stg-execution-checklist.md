# AI-Agent Dev+Staging Execution Checklist

Use this checklist when authorizing an AI agent to execute `ai-agent-dev-stg-setup-prompt.md`.

## Before execution

- [ ] Repository URL and branch are confirmed.
- [ ] Server identity, IP, Ubuntu version, CPU, RAM, and disk are confirmed.
- [ ] A named deployment user with SSH key access exists.
- [ ] Current Git state and server configuration are backed up or reproducible.
- [ ] DNS control owner is identified.
- [ ] Development access-control method is selected: VPN, allowlist, or basic auth.
- [ ] Non-production object storage and provider sandboxes are available or explicitly marked blocked.
- [ ] Secrets will be provided outside Git and outside the prompt.
- [ ] The agent cannot access production credentials or unsanitized production data.
- [ ] Human approval is required for destructive host, database, firewall, DNS, or credential changes.

## Agent permissions

Recommended:

```text
Repository read/write       allowed
Non-production SSH          allowed when supervised
Production access           prohibited
Secret values in chat/log   prohibited
DNS modification            optional and separately approved
Provider account mutation   separately approved
Database destructive action human approval required
```

## Required pause conditions

The agent must pause for:

- missing or unverified SSH fallback access before disabling password login;
- destructive database migration or volume deletion;
- firewall change that could remove current access;
- DNS/certificate ownership ambiguity;
- missing secret/provider account;
- request to use production data or credentials;
- incompatibility requiring a baseline architecture change.

## Evidence required at completion

- [ ] Git diff or commit list.
- [ ] Redacted environment inventory.
- [ ] `docker compose config` validation.
- [ ] Container health and resource report.
- [ ] Listening-port evidence.
- [ ] Database cross-access negative tests.
- [ ] Redis/queue namespace evidence.
- [ ] HTTPS/noindex/access-control evidence.
- [ ] Staging migration and smoke-test result.
- [ ] Queue/scheduler evidence.
- [ ] Backup upload and restore-test evidence.
- [ ] CI pipeline result or explicit blocker.
- [ ] Rollback steps.
- [ ] Final readiness state: READY, READY WITH BLOCKERS, or NOT READY.

## Post-execution human review

A human must review:

- firewall and SSH hardening;
- Docker privilege model;
- secrets and file permissions;
- CI protected environments;
- database initialization scripts;
- backup restore evidence;
- financial/provider sandbox configuration;
- all security, authorization, and migration changes.

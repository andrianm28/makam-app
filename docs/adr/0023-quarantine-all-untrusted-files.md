# ADR-0023: Quarantine All Untrusted Files Before Use

## Status
Accepted — 23 July 2026

## Decision
All user/vendor/admin uploads enter private quarantine and pass type/content/size and malware checks before becoming accessible domain attachments. Restricted-document scanning fails closed.

## Consequences
Adds asynchronous scan latency and scanner operations, but prevents direct exposure of malicious files.

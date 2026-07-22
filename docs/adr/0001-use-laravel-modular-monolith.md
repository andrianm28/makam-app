# ADR-0001: Use Laravel Modular Monolith

- **Status:** Proposed
- **Decision drivers:** fastest delivery, minimum operational effort, consistent transactions, AI-agent readability.

## Decision

Implement Makam.co.id as a Laravel modular monolith. Use Livewire for public journeys and Filament for admin, vendor, and operator panels. Separate domain modules in code while retaining one deployable application initially.

## Consequences

Positive: simple deployment, strong transactional consistency, reuse of policies/queues/validation, lower team overhead.  
Negative: module boundaries require discipline; scaling is primarily application-tier scaling; public and back-office releases are coupled.

## Review triggers

Sustained independent scaling need, regulatory isolation requirement, or team ownership boundaries that cannot be handled inside the monolith.

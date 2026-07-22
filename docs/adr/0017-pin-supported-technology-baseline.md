# ADR-0017: Pin Supported Technology Baseline

## Status
Accepted — 23 July 2026

## Decision
Use PHP 8.5, Laravel 13, Livewire 4, Filament 5, Tailwind 4.1+, Node 24 LTS, PostgreSQL 18, Redis 8.2, and Ubuntu 24.04 LTS as the supported baseline. Lock exact packages and runtime artifacts.

## Consequences
Predictable builds and support runway improve; upgrades require deliberate compatibility testing. Ubuntu 26.04 and newer Redis lines are not adopted solely because they are newer.

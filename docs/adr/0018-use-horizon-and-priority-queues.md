# ADR-0018: Use Horizon and Priority-Separated Redis Queues

## Status
Accepted — 23 July 2026

## Decision
Operate Laravel Redis queues with Horizon and separate critical, urgent, notifications, default, imports/media, and reports workloads. Do not use Redis Cluster with Horizon.

## Consequences
Critical payment/Urgent work is isolated from batch jobs. Additional worker configuration and monitoring are required.

# Design — Funeral Case Management

## Aggregate

`funeral_cases`, `case_assignments`, `case_tasks`, `case_task_events`, `case_communications`, `case_appointments`, `case_incidents`, `case_evidence`.

## Statuses

`NEW`, `TRIAGED`, `COORDINATING`, `READY_FOR_SERVICE`, `IN_SERVICE`, `COMPLETED`, `DECLINED`, `CANCELLED`, `TRANSFERRED`.

## Task engine

Template instantiation is idempotent. Critical task completion may require evidence or structured outcome. Escalation jobs use unique case/task/window keys.

## Security

Case scope by assignment and role. Customer projection allowlists only public timeline events.

## Metrics

First response, availability confirmation, overdue task count, fallback rate, handover rate, completion SLA.

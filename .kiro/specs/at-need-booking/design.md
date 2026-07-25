# Design — At-Need Booking

## Entry point (normative)

`AGENTS.md`: *"Booking exposes Steps 1–9 exactly as documented."* The public entry point for At-Need/Urgent therefore **remains the nine-step wizard** (`public-booking-wizard`, Step 3 → `URGENT_TODAY`). The lightweight intake described below is the **internal** sequence behind that entry, not a parallel public form.

`booking-wizard-fields.md` §Branching is the governing resolution: *"The UI retains the stakeholder's nine-step framing. Internal workflow may shorten operational data collection for Urgent."* So the negative criterion *"No long Pre-Need wizard imposed on urgent family"* is satisfied by shortening what is **asked**, never by bypassing the nine-step entry. Resolves the tension recorded in `docs/planning/kiro-specs-analysis.md` §5.5.

AC4 (documents may be completed after service) means the wizard's Step 7 must support a **conditional-requirement mode** on the Urgent branch — outstanding documents render as `pending` follow-up, not as a blocking validation error.

## Components

A lightweight intake component calls `CreateFuneralCaseAction`. Progressive data collection occurs through case tasks. Quote/payment remains shared with OrderWorkflow. Document completeness is represented explicitly and can be post-service when policy permits.

## Main sequence

Intake -> triage -> case owner -> availability/reservation -> quote -> payment policy -> service coordination -> completion -> documents/certificate.

## Failure behavior

Capacity closed returns truthful status and alternative contact/next step; active case failures escalate to humans.

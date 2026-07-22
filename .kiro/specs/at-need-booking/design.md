# Design — At-Need Booking

A lightweight intake component calls `CreateFuneralCaseAction`. Progressive data collection occurs through case tasks. Quote/payment remains shared with OrderWorkflow. Document completeness is represented explicitly and can be post-service when policy permits.

## Main sequence

Intake -> triage -> case owner -> availability/reservation -> quote -> payment policy -> service coordination -> completion -> documents/certificate.

## Failure behavior

Capacity closed returns truthful status and alternative contact/next step; active case failures escalate to humans.

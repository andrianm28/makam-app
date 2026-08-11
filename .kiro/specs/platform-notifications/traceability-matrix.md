# Traceability Matrix - Platform Notifications

`Closed (local evidence)` means the implementation and named tests exist and
pass locally; CI PostgreSQL evidence is still pending. `NOT TESTED` is reserved
for provider, role, or external-channel behavior that cannot be exercised on
the combined development host. Created 11 Aug 2026 as part of the lane's Task 6
doc reconciliation; every row was mapped from the implementation committed by
lane Tasks 1-5 on `lane/l2-notifications` and re-verified against the test
files named. Matrix (AC1) authority: `docs/contracts/notification-matrix.md`,
reconciled field by field on the same date (header note).

| AC | Requirement | Test evidence | Status |
|---|---|---|---|
| AC1 | `notification-matrix.md` is the single source of truth for event, recipient scope, channel, and template; never restated | `tests/Unit/Platform/Notification/TemplateRendererTest.php` (`test_the_matrix_reader_returns_every_event_and_preserves_cell_facts`, `test_the_reconciled_matrix_parses_end_to_end`); `tests/Feature/Notification/NotificationTemplatePersistenceTest.php` (`test_the_matrix_seed_covers_every_matrix_event_with_one_active_version`) | Closed (local evidence) |
| AC2 | Server-resolve `WhatsAppMode` (`ACTIVE` or `EMAIL_IN_APP_FALLBACK`) | `tests/Feature/Notification/NotificationDispatchPipelineTest.php` (`test_ac2_ac12_whatsapp_gate_closed_records_unavailable_not_a_silent_drop`) | Closed (local evidence) |
| AC3 | Per-channel delivery state: queued, sent, delivered, failed, unavailable | `tests/Feature/Notification/NotificationDispatchPipelineTest.php` (`test_sent_delivery_persists_provider_reference_and_provider_idempotency_key`, `test_missing_active_template_version_records_unavailable_delivery_without_blank_in_app_content`); `tests/Unit/Platform/Notification/DeliveryStatePresentationTest.php` | Closed (local evidence) |
| AC4 | UI never claims a delivery without a recorded state; absent -> `pending`/`unavailable`, never sent | `tests/Feature/Notification/InAppNotificationListPageTest.php` (`test_delivery_chips_render_pending_and_unavailable_never_a_false_sent`, `test_sent_deliveries_render_terkirim`); `tests/Feature/Notification/NotificationDispatchPipelineTest.php` (`test_ac4_no_delivery_row_means_no_delivery_claim`); `tests/Unit/Platform/Notification/DeliveryStatePresentationTest.php` | Closed (local evidence) |
| AC5 | Channel failure never changes business state; a failed email never fails an order | `tests/Feature/Notification/NotificationDispatchPipelineTest.php` (`test_ac5_a_throwing_channel_never_changes_business_state_or_propagates`) | Closed (local evidence) |
| AC6 | Recipient scope from record scope: customer, admin, cemetery operator, vendor, case manager, finance | `tests/Unit/Platform/Notification/RecipientResolverTest.php` (scope, cross-scope leakage, revocation); `tests/Unit/Platform/Notification/ProvisionalAggregateNotificationSubjectSourceTest.php`; `tests/Unit/Platform/Notification/ProvisionalScopeEntityRecipientRoleSourceTest.php`; `tests/Feature/Notification/NotificationDispatchPipelineTest.php` (`test_cross_scope_leakage_an_actor_scoped_to_a_different_cemetery_receives_nothing`) | Closed (local evidence) |
| AC7 | Always create in-app records for admin/operator/vendor using record scope, independent of external channel success | `tests/Feature/Notification/NotificationDispatchPipelineTest.php` (`test_ac7_the_in_app_record_survives_a_throwing_channel`, `test_ac7_vendor_recipient_gets_an_in_app_record_without_an_external_channel`, `test_ac7_platform_admin_recipient_gets_an_in_app_record`) | Closed (local evidence) |
| AC8 | Idempotent dispatch per (event, recipient, channel, window); no double-send under retry | `tests/Feature/Notification/NotificationDispatchPipelineTest.php` (`test_ac8_a_duplicate_outbox_delivery_produces_exactly_one_of_everything`, `test_delivery_key_collision_is_rejected_by_the_database_unique_constraint`, `test_concurrent_channel_workers_claim_a_delivery_before_only_one_provider_send`, `test_reclaimed_delivery_reuses_provider_key_after_provider_success_before_state_write`) | Closed (local evidence) |
| AC9 | Dispatch from the transactional outbox, never inline | `tests/Feature/Notification/NotificationDispatchPipelineTest.php` (consumer path via `ConsumeOutboxNotificationJob`); `tests/Unit/Platform/Notification/NotificationDeliveryWriteApiTest.php` (`test_only_dispatch_notification_writes_notification_deliveries`, `test_channel_send_boundary_requires_the_channel_job`); `tests/Unit/Platform/Notification/NotificationDeliveryWriteGuardTest.php` | Closed (local evidence) |
| AC10 | No private attachment on email/WhatsApp; authenticated link only | `app/Platform/Notification/Contracts/Channel.php` (`send()` carries no attachment — structural); `tests/Unit/Platform/Notification/TemplateRendererTest.php` (`test_a_restricted_variable_is_rejected_even_when_allowlisted`) | Closed (local evidence) — structural guard proven; real external-channel behavior NOT TESTED (no provider) |
| AC11 | No restricted data (KTP, KK, death-certificate content, bank details, full addresses) in a body, subject, or template variable | `tests/Unit/Platform/Notification/TemplateRendererTest.php` (`test_a_restricted_variable_is_rejected_even_when_allowlisted`, `test_a_non_allowlisted_template_variable_is_a_hard_error`) | Closed (local evidence) |
| AC12 | WhatsApp only with approved BSP + approved templates; while `G-WA-01` is closed the UI states WhatsApp unavailable | `tests/Feature/Notification/NotificationDispatchPipelineTest.php` (`test_ac2_ac12_whatsapp_gate_closed_records_unavailable_not_a_silent_drop`); `tests/Unit/Platform/Notification/DeliveryStatePresentationTest.php`; `tests/Feature/Notification/InAppNotificationListPageTest.php` (`test_delivery_chips_render_pending_and_unavailable_never_a_false_sent`) | Closed-gate half: Closed (local evidence); approved-BSP/template-approval flow: NOT TESTED (G-WA-01 closed, out of lane scope) |
| AC13 | Versioned, previewable templates; a template change must not retroactively alter sent records | `tests/Feature/Notification/NotificationTemplatePersistenceTest.php` (`test_a_new_version_does_not_change_the_existing_snapshot`, `test_a_persisted_template_version_cannot_be_updated_or_deleted`, `test_a_persisted_template_version_cannot_be_saved_or_deleted`, `test_a_query_builder_update_cannot_change_a_template_version`, `test_a_query_builder_delete_cannot_remove_a_template_version`, `test_an_active_version_must_belong_to_its_template`) | Versioning/snapshot-immutability half: Closed (local evidence); "previewable" half: NOT TESTED (no template preview UI built on this branch) |
| AC14 | Failure observable, retried with bounded backoff; permanent failure escalates to an operational queue | `tests/Feature/Notification/NotificationDispatchPipelineTest.php` (`test_failed_delivery_is_retried_then_escalated_to_default_queue_after_max_attempts`, `test_permanent_channel_failure_is_recorded_without_retry`) | Closed (local evidence) |

## Notes (honest scope of each Closed row)

- **AC6 — case manager and finance resolve to nothing by design, not by
  omission.** Every Case-manager matrix cell is `TBD` (ruling 4 refinement),
  and Finance is underivable from any scope grant (`business_entity` cannot
  distinguish admin from finance — ruling 2). `RecipientResolver` treats both
  `none` and `TBD` as no-recipient values (its `EMPTY_VALUES`), so no recipient
  policy is silently invented. Customer, admin, cemetery operator, and vendor
  resolution are proven against real scope-assignment state.
- **AC9 — the consumer is proven against outbox rows recorded directly.** None
  of the six outbox-mapped events has a producer in this codebase yet (booking
  wizard Step 9, availability, quote, and payment domains are unbuilt — D3 in
  the lane ledger), so the consumer is correct-but-dormant in production. The
  "never inline" half is by construction: `DispatchNotification` is the only
  writer of `notification_deliveries` (`NotificationDeliveryWriteGuard`) and no
  inline send API exists.
- **AC14 — the escalation queue is `default`.** `docs/architecture/queue-and-outbox.md`
  defines no `operations` queue name; the plan's fallback rule lands the
  permanent-failure escalation as an ops-tagged `RetryFailedDeliveryJob` on the
  `default` queue (proven by `Queue::assertPushed` in the named test).
- **AC8 window key is degenerate today** (window = outbox event id for all six
  transactional events, D8) — the idempotency anchor and the DB unique
  constraint are real and collision-proven; a real time-window feature does not
  exist yet.
- **AC10/AC12/AC13 split statuses** are intentional: each row's "Closed (local
  evidence)" half is proven on this host, and the provider/UI/BSP halves are
  honestly NOT TESTED exactly as the plan's own NOT TESTED section frames them.
- **K7** remains external and unseen; `Channel` keeps the module provider-neutral.

## Delivery-rule readings (recorded in the matrix header note, 11 Aug 2026)

- **Rule 3** ("WhatsApp failure falls back to email/in-app when configured")
  reads, under the current module: while `WhatsAppMode` is `EMAIL_IN_APP_FALLBACK`
  (`G-WA-01` closed), WhatsApp is never dispatched and is recorded as a real
  `UNAVAILABLE` delivery row; no WA→EMAIL re-route is configured.
- **Rule 6** ("reference, current status, next action, and support contact") is
  a content requirement the seeded templates do not yet carry — the seeded body
  is the matrix row's recipient/channel facts, marked as a snapshot. Recorded
  as a gap, not closed here (template content is a seed/migration change).

## Corrections (append-only)

- **11 Aug 2026** — `tasks.md`'s NOT TESTED note originally read "Nothing here
  is implemented ... has **not** been reconciled against these criteria field
  by field." True when written; stale from Task 6 onward. Corrected in
  `tasks.md` to the committed state; this entry preserves the original claim
  rather than rewriting it. No AC row above was found to overclaim during this
  reconciliation.

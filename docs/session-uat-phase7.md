# Phase 7 browser UAT — 2026-09-08

Environment: local app at http://localhost:3000, admin session, actual browser UI.

## Passed through the browser

- Login and Schedule load successfully.
- Existing test registration REG-20260908-0016 (ID 43) shows 12 / 0 / 12 and validity 2026-09-08 through 2027-09-08.
- Scheduled -> Completed changes summary to 12 / 1 / 11, persisted after reload.
- Completed -> Alpha returns summary to 12 / 0 / 12. Restored to Scheduled.
- Add Session before validity and after expiry is rejected with the allowed date range.
- Edit beyond expiry is rejected; edit exactly on expiry succeeds. Original session date restored to 2026-09-09.
- Bulk validation rejects after-expiry dates and accepts expiry day.
- Generate rejects dates beyond expiry.
- Generate rejects when existing Scheduled sessions already cover remaining entitlement.
- Created a separate registration for existing child Testing and guardian Mom Testing: REG-20260908-0017 (ID 44), 12/month for 6 months. Summary is 72 / 0 / 72.
- Add Multiple saves two schedules (2027-08-02 and 2027-08-03, 08:00–09:30); summary remains 72 / 0 / 72.
- Generate with Wednesday enabled, 2027-08-04 through 2027-08-11, appends exactly two schedules, preserves previous schedules and stops at End Date. Summary remains 72 / 0 / 72.
- Repeating the same generation range is rejected without duplicates.
- Add Session on expiry day (2027-09-08, 08:00–09:30) succeeds.
- With one test session Completed and ID 44 temporarily expired, summary is 72 / 1 / 0, labelled Expired. Generate/Add/Add Multiple are disabled; edit date and session time are disabled. All five historical sessions remain visible.
- Updating notes while expired succeeds and preserves the date, time and Completed status.
- With ID 44 temporarily set to Total 1 and Used 1, Remaining is 0 with No sessions remaining. Generate is rejected.

## Fixture handling

Only testing registrations were used for changes. ID 43's original schedule date and Scheduled status were restored.
ID 44's original Total 72 and validity 2026-09-08 through 2027-09-08 were saved before simulation and restored afterward. Original values are in storage/app/private/uat-phase7-registration-44.json. The temporary fixture script was removed.
ID 44 and its five schedules are retained for review, with notes beginning UAT Phase 7. No billing was generated.

## Scope limits

This run verifies the listed browser flows. Simulated expiry and exhausted balance used direct, guarded changes to the newly created testing registration, then UI interactions; system time and actual student registrations were not changed.
The final-slot concurrency scenario was subsequently exercised on 2026-09-09 using two independent PHP processes and MySQL connections invoking the actual application controllers as distinct admin users. This is backend concurrency verification, not a second browser UI run. See results below.

Observation for later product review: existing test ID 43 started with 13 Scheduled rows versus Total 12. Generate correctly refused additions. Manual Add/Multiple now apply the same entitlement check and reject any submission that would make Scheduled sessions exceed Total Session.

## Final-slot concurrency results — 2026-09-09

Reproducer: `php scripts/uat-session-concurrency.php`. Two transactions synchronize after locking different registrations; the winner holds the session-time lock for 700 ms so the competing requests overlap. Each case starts with an empty slot of capacity 1. Expected outcome: one success, one HTTP 422, one saved session.

| Concurrent operations | Responses | Saved sessions | Result |
| --- | --- | --- | --- |
| Add Session / Add Session | 422 / 200 | 1 | PASS |
| Generate / Generate | 422 / 200 | 1 | PASS |
| Generate / Add Session | 422 / 200 | 1 | PASS |
| Add Multiple / Add Multiple | 422 / 200 | 1 | PASS |
| Add Session / Add Multiple | 422 / 200 | 1 | PASS |

Evidence: `storage/app/private/uat_p7_eeb55a89c0_/report.json`.

The check used randomly prefixed, isolated tables in local MySQL. These tables were removed in the script's finally block after both workers finished. No production/application table rows or slot capacities were modified.

### Blocking finding

`TherapySessionController::store()` eager-loads programs before acquiring the slot lock. The later `getSessionCreateConflicts()` occupancy and duplicate checks therefore use save-time locking current reads, so a waiting request sees the winning transaction's committed session before it can insert.

The save paths for Add Session and Add Multiple now pass a locking mode to `getSessionCreateConflicts()`. Bulk preview keeps its read-only behavior. The reproducer and targeted backend tests pass after the change.

Status: planned browser scenarios executed, targeted capacity race fixed and retested, and manual browser UAT accepted by the user. Phase 7 is complete.

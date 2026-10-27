-- Session validity now belongs to the child master record.
-- Applied directly to the live aha_montessori database on 2026-10-27.
-- Existing children remain NULL; registration-level date columns are removed.

ALTER TABLE children
    ADD COLUMN session_started_at DATE NULL,
    ADD COLUMN session_expired_at DATE NULL;

ALTER TABLE registrations
    DROP COLUMN session_started_at,
    DROP COLUMN session_expired_at;

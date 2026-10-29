-- School Readiness annual registration fee flag (local-first, additive only).
-- Existing programs default to a normal tuition item.

ALTER TABLE programs
    ADD COLUMN is_registration_fee TINYINT(1) NOT NULL DEFAULT 0 AFTER program_track_id;

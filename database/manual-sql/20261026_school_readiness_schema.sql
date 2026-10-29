-- School Readiness schema additions (local-first, no data backfill).
-- Existing categories, programs, registrations, bills, and therapy sessions are preserved.

ALTER TABLE program_categories
    ADD COLUMN supports_tracks TINYINT(1) NOT NULL DEFAULT 0 AFTER name;

CREATE TABLE program_tracks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_category_id BIGINT UNSIGNED NOT NULL,
    subject_name VARCHAR(100) NOT NULL,
    delivery_mode VARCHAR(20) NOT NULL,
    price_per_session DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_program_tracks_category_subject_mode (program_category_id, subject_name, delivery_mode),
    CONSTRAINT fk_program_tracks_category FOREIGN KEY (program_category_id)
        REFERENCES program_categories (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE program_track_classes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    program_track_id BIGINT UNSIGNED NOT NULL,
    class_code VARCHAR(30) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_program_track_classes_track_code (program_track_id, class_code),
    CONSTRAINT fk_program_track_classes_track FOREIGN KEY (program_track_id)
        REFERENCES program_tracks (id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE programs
    ADD COLUMN program_track_id BIGINT UNSIGNED NULL AFTER program_category_id,
    ADD KEY idx_programs_program_track_id (program_track_id),
    ADD CONSTRAINT fk_programs_program_track FOREIGN KEY (program_track_id)
        REFERENCES program_tracks (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE program_category_session_times
    ADD COLUMN program_track_class_id BIGINT UNSIGNED NULL AFTER program_category_id,
    ADD KEY idx_session_times_track_class_id (program_track_class_id),
    ADD CONSTRAINT fk_session_times_track_class FOREIGN KEY (program_track_class_id)
        REFERENCES program_track_classes (id) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE therapy_sessions
    ADD COLUMN registration_program_id BIGINT UNSIGNED NULL AFTER registration_id,
    ADD COLUMN program_category_session_time_id BIGINT UNSIGNED NULL AFTER registration_program_id,
    ADD KEY idx_therapy_sessions_registration_program_id (registration_program_id),
    ADD KEY idx_therapy_sessions_category_session_time_id (program_category_session_time_id),
    ADD KEY idx_therapy_sessions_date_time (therapy_date, start_time, end_time),
    ADD CONSTRAINT fk_therapy_sessions_registration_program FOREIGN KEY (registration_program_id)
        REFERENCES registration_programs (id) ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT fk_therapy_sessions_category_session_time FOREIGN KEY (program_category_session_time_id)
        REFERENCES program_category_session_times (id) ON DELETE SET NULL ON UPDATE CASCADE;

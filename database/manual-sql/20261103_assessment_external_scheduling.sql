-- Run once against the local aha_montessori database.
-- Do not run this file with php artisan migrate.

ALTER TABLE program_categories
    ADD COLUMN schedule_mode VARCHAR(24) NOT NULL DEFAULT 'fixed_slots'
        AFTER supports_tracks;

CREATE TABLE assessment_details (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    registration_id BIGINT UNSIGNED NOT NULL,
    appointment_date DATE NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    report_disk VARCHAR(50) NULL,
    report_path VARCHAR(1024) NULL,
    report_original_name VARCHAR(255) NULL,
    report_uploaded_by BIGINT UNSIGNED NULL,
    report_uploaded_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_assessment_details_registration_id (registration_id),
    KEY idx_assessment_details_report_uploaded_by (report_uploaded_by),
    CONSTRAINT fk_assessment_details_registration
        FOREIGN KEY (registration_id) REFERENCES registrations (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_assessment_details_report_uploaded_by
        FOREIGN KEY (report_uploaded_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

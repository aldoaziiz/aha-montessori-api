-- Milestone Progress reports: run manually against the LOCAL aha_montessori database.
-- Do not run with php artisan migrate. Review the paired *_kinder_seed.sql file too.

CREATE TABLE milestone_curricula (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_milestone_curricula_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE milestone_areas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    curriculum_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_milestone_areas_curriculum_name (curriculum_id, name),
    KEY idx_milestone_areas_curriculum_sort (curriculum_id, sort_order),
    CONSTRAINT fk_milestone_areas_curriculum FOREIGN KEY (curriculum_id)
        REFERENCES milestone_curricula (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE milestone_subareas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    area_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_milestone_subareas_area_name (area_id, name),
    KEY idx_milestone_subareas_area_sort (area_id, sort_order),
    CONSTRAINT fk_milestone_subareas_area FOREIGN KEY (area_id)
        REFERENCES milestone_areas (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_milestones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    area_id BIGINT UNSIGNED NOT NULL,
    subarea_id BIGINT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_curriculum_milestones_area_name (area_id, name),
    KEY idx_curriculum_milestones_area_subarea_sort (area_id, subarea_id, sort_order),
    CONSTRAINT fk_curriculum_milestones_area FOREIGN KEY (area_id)
        REFERENCES milestone_areas (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_curriculum_milestones_subarea FOREIGN KEY (subarea_id)
        REFERENCES milestone_subareas (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_milestone_ages (
    milestone_id BIGINT UNSIGNED NOT NULL,
    age SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (milestone_id, age),
    CONSTRAINT fk_curriculum_milestone_ages_milestone FOREIGN KEY (milestone_id)
        REFERENCES curriculum_milestones (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE milestone_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    child_id BIGINT UNSIGNED NULL,
    curriculum_id BIGINT UNSIGNED NULL,
    report_name VARCHAR(255) NOT NULL,
    report_date DATE NOT NULL,
    academic_year_start_month TINYINT UNSIGNED NOT NULL,
    academic_year_start_year SMALLINT UNSIGNED NOT NULL,
    academic_year_end_month TINYINT UNSIGNED NOT NULL,
    academic_year_end_year SMALLINT UNSIGNED NOT NULL,
    teacher_comments TEXT NULL,
    child_name_snapshot VARCHAR(255) NOT NULL,
    child_birth_date_snapshot DATE NULL,
    child_age_years_snapshot SMALLINT UNSIGNED NULL,
    child_age_months_snapshot TINYINT UNSIGNED NULL,
    curriculum_name_snapshot VARCHAR(160) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_milestone_reports_report_date (report_date, id),
    KEY idx_milestone_reports_child (child_id),
    KEY idx_milestone_reports_curriculum (curriculum_id),
    CONSTRAINT fk_milestone_reports_child FOREIGN KEY (child_id)
        REFERENCES children (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_milestone_reports_curriculum FOREIGN KEY (curriculum_id)
        REFERENCES milestone_curricula (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_milestone_reports_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE milestone_report_areas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    master_area_id BIGINT UNSIGNED NULL,
    area_name_snapshot VARCHAR(160) NOT NULL,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    area_note TEXT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_milestone_report_areas_report_sort (report_id, display_order),
    KEY idx_milestone_report_areas_master_area (master_area_id),
    CONSTRAINT fk_milestone_report_areas_report FOREIGN KEY (report_id)
        REFERENCES milestone_reports (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_milestone_report_areas_master_area FOREIGN KEY (master_area_id)
        REFERENCES milestone_areas (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE milestone_report_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_area_id BIGINT UNSIGNED NOT NULL,
    master_milestone_id BIGINT UNSIGNED NULL,
    master_subarea_id BIGINT UNSIGNED NULL,
    subarea_name_snapshot VARCHAR(160) NULL,
    subarea_order_snapshot INT UNSIGNED NULL,
    milestone_name_snapshot VARCHAR(255) NOT NULL,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    progress_code VARCHAR(2) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_milestone_report_items_area_sort (report_area_id, subarea_order_snapshot, display_order),
    KEY idx_milestone_report_items_master_milestone (master_milestone_id),
    KEY idx_milestone_report_items_master_subarea (master_subarea_id),
    CONSTRAINT fk_milestone_report_items_area FOREIGN KEY (report_area_id)
        REFERENCES milestone_report_areas (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_milestone_report_items_master_milestone FOREIGN KEY (master_milestone_id)
        REFERENCES curriculum_milestones (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_milestone_report_items_master_subarea FOREIGN KEY (master_subarea_id)
        REFERENCES milestone_subareas (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE milestone_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    target_type VARCHAR(40) NOT NULL,
    target_id BIGINT UNSIGNED NULL,
    target_label VARCHAR(255) NOT NULL,
    event_context JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_milestone_audit_logs_actor_created (actor_user_id, created_at),
    KEY idx_milestone_audit_logs_target (target_type, target_id, created_at),
    CONSTRAINT fk_milestone_audit_logs_actor FOREIGN KEY (actor_user_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

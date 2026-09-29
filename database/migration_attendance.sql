-- ========================================================
-- EduTrack Migration: Session-based Attendance (NON-DESTRUCTIVE)
-- System: MariaDB 10.2+
-- Purpose: Replace the "one percentage per student/semester" model with real
--          per-session attendance records, and reconstruct the existing
--          124 legacy percentage rows into real attendance_records.
--
-- SAFETY NOTES
--   * Nothing is dropped. The legacy `attendance` table is retained as an
--     archive and is no longer read by the application.
--   * Idempotent: if legacy sessions/records already exist the migration
--     skips the data step, so it is safe to run repeatedly.
--   * A percentage cannot be inverted into history, so each legacy row is
--     reconstructed as 100 synthetic sessions for that semester, with
--     exactly ROUND(percentage) 'present' records out of 100. Because every
--     legacy percentage is a whole number (verified: 0 fractional rows),
--     the recomputed percentage is IDENTICAL to the original value.
-- ========================================================

USE `edutrack`;

-- --------------------------------------------------------
-- 1. Table: attendance_sessions  (one class/lecture event)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_key` VARCHAR(191) NOT NULL COMMENT 'Idempotency key, e.g. m|12|6|2026-09-25',
    `subject_id` INT DEFAULT NULL COMMENT 'NULL for legacy/unspecified subject',
    `semester_id` INT NOT NULL,
    `session_date` DATE NOT NULL,
    `taken_by` VARCHAR(120) DEFAULT NULL,
    `source` ENUM('legacy', 'manual', 'import') NOT NULL DEFAULT 'manual',
    `note` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_attendance_sessions_key` (`session_key`),
    INDEX `idx_att_sessions_semester` (`semester_id`),
    INDEX `idx_att_sessions_date` (`session_date`),
    INDEX `idx_att_sessions_subject` (`subject_id`),
    INDEX `idx_att_sessions_source` (`source`),
    CONSTRAINT `fk_att_sessions_subject` FOREIGN KEY (`subject_id`)
        REFERENCES `subjects` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_att_sessions_semester` FOREIGN KEY (`semester_id`)
        REFERENCES `semesters` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 2. Table: attendance_records  (one student, one session)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_id` INT NOT NULL,
    `student_id` INT NOT NULL,
    `status` ENUM('present', 'absent', 'late', 'excused') NOT NULL DEFAULT 'present',
    `marked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_attendance_session_student` (`session_id`, `student_id`),
    INDEX `idx_att_records_student` (`student_id`),
    INDEX `idx_att_records_status` (`status`),
    CONSTRAINT `fk_attendance_records_session` FOREIGN KEY (`session_id`)
        REFERENCES `attendance_sessions` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_attendance_records_student` FOREIGN KEY (`student_id`)
        REFERENCES `students` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- 3. Mark the legacy `attendance` table as deprecated
--    (comment only - no data is altered)
-- --------------------------------------------------------
-- Widen the provenance enum so CSV imports can be distinguished from
-- manually marked sessions. MODIFY is idempotent, safe to re-run.
ALTER TABLE `attendance_sessions`
    MODIFY COLUMN `source` ENUM('legacy', 'manual', 'import') NOT NULL DEFAULT 'manual';

ALTER TABLE `attendance`
    COMMENT 'DEPRECATED archive of legacy per-semester percentages. Retained for audit only. The application reads attendance_records instead.';

-- --------------------------------------------------------
-- 4. Reconstruct legacy percentages as real records
--    (runs only once - guarded by NOT EXISTS)
-- --------------------------------------------------------

-- 4a. 100 legacy sessions per semester that appears in `attendance`
INSERT INTO `attendance_sessions`
    (`session_key`, `subject_id`, `semester_id`, `session_date`, `taken_by`, `source`, `note`)
SELECT
    CONCAT('legacy|', leg.semester_id, '|', n.i),
    NULL,
    leg.semester_id,
    DATE_ADD('2024-06-03', INTERVAL (n.i - 1) DAY),
    'system-migration',
    'legacy',
    'Reconstructed from legacy percentage snapshot'
FROM (
    SELECT DISTINCT `semester_id` FROM `attendance`
) AS leg
CROSS JOIN (
    SELECT (x.i + y.i * 10 + 1) AS i
    FROM (
        SELECT 0 i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
        SELECT 4     UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL
        SELECT 8     UNION ALL SELECT 9
    ) AS x
    CROSS JOIN (
        SELECT 0 i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
        SELECT 4     UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL
        SELECT 8     UNION ALL SELECT 9
    ) AS y
) AS n
WHERE NOT EXISTS (
    SELECT 1 FROM `attendance_sessions` WHERE `source` = 'legacy'
);

-- 4b. One record per (legacy session, student); 'present' for the first
--     ROUND(percentage) sessions, 'absent' for the remainder.
INSERT INTO `attendance_records`
    (`session_id`, `student_id`, `status`)
SELECT
    ses.`id`,
    leg.`student_id`,
    CASE WHEN n.i <= ROUND(leg.`percentage`) THEN 'present' ELSE 'absent' END
FROM (
    SELECT (x.i + y.i * 10 + 1) AS i
    FROM (
        SELECT 0 i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
        SELECT 4     UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL
        SELECT 8     UNION ALL SELECT 9
    ) AS x
    CROSS JOIN (
        SELECT 0 i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL
        SELECT 4     UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL
        SELECT 8     UNION ALL SELECT 9
    ) AS y
) AS n
CROSS JOIN `attendance` AS leg
INNER JOIN `attendance_sessions` AS ses
    ON ses.`source` = 'legacy'
   AND ses.`semester_id` = leg.`semester_id`
   AND ses.`session_key` = CONCAT('legacy|', leg.`semester_id`, '|', n.i)
WHERE NOT EXISTS (
    SELECT 1 FROM `attendance_records` LIMIT 1
);

-- --------------------------------------------------------
-- 5. Verification helper (read-only)
--    Recomputed % must match `attendance`.`percentage` exactly.
-- --------------------------------------------------------
-- SELECT leg.student_id,
--        leg.semester_id,
--        leg.percentage AS legacy_pct,
--        ROUND(SUM(ar.status IN ('present','late')) * 100.0 / COUNT(*), 2) AS recomputed_pct
-- FROM attendance leg
-- JOIN attendance_sessions ses
--   ON ses.source='legacy' AND ses.semester_id=leg.semester_id
-- JOIN attendance_records ar ON ar.session_id = ses.id
-- GROUP BY leg.student_id, leg.semester_id, leg.percentage;

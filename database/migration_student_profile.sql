-- ========================================================
-- EduTrack Migration: Student Profile (non-destructive)
-- System: MariaDB 10.0.2+  (supports ADD COLUMN IF NOT EXISTS)
-- Purpose: Extends the existing `students` table with profile
--          columns and creates the `student_documents` table.
-- Safe to run multiple times - nothing is dropped.
-- ========================================================

USE `edutrack`;

-- --------------------------------------------------------
-- 1. Extend `students` with profile columns (add only if missing)
-- --------------------------------------------------------
ALTER TABLE `students`
    ADD COLUMN IF NOT EXISTS `register_no` VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `section` VARCHAR(5) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `dob` DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `gender` VARCHAR(10) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `course` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `admission_year` SMALLINT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `blood_group` VARCHAR(10) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `category` VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `address` VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `languages_known` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `guardian_name` VARCHAR(120) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `guardian_phone` VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `emergency_contact` VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `relationship` VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `credits` INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `backlogs` INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `photo` VARCHAR(255) DEFAULT NULL;

-- --------------------------------------------------------
-- 2. Create `student_documents` (if not present)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_documents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `doc_name` VARCHAR(120) NOT NULL,
    `doc_type` VARCHAR(50) NOT NULL DEFAULT 'academic' COMMENT 'identity/academic/certificate/fee/other',
    `status` ENUM('Pending', 'Verified') NOT NULL DEFAULT 'Pending',
    `uploaded_on` DATE DEFAULT NULL,
    `file_path` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_student_documents_student` FOREIGN KEY (`student_id`)
        REFERENCES `students` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX `idx_student_documents_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
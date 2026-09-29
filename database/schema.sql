-- ========================================================
-- EduTrack Database Schema
-- System: MySQL 5.7+ / MariaDB 10.3+
-- Tables: departments, semesters, students, student_documents, subjects, marks,
--         attendance (legacy summary), attendance_sessions, attendance_records
-- ========================================================

CREATE DATABASE IF NOT EXISTS `edutrack` 
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

USE `edutrack`;

-- Drop existing tables in reverse dependency order
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `attendance_records`;
DROP TABLE IF EXISTS `attendance_sessions`;
DROP TABLE IF EXISTS `attendance`;
DROP TABLE IF EXISTS `marks`;
DROP TABLE IF EXISTS `student_documents`;
DROP TABLE IF EXISTS `subjects`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `semesters`;
DROP TABLE IF EXISTS `departments`;
SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------
-- Table: departments
-- --------------------------------------------------------
CREATE TABLE `departments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `dept_key` VARCHAR(30) NOT NULL UNIQUE COMMENT 'Unique identifier, e.g. dept-cs',
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `code` VARCHAR(10) NOT NULL UNIQUE,
    `icon` VARCHAR(50) NOT NULL DEFAULT 'laptop',
    `color` VARCHAR(25) NOT NULL DEFAULT '#38bdf8',
    `bg_color` VARCHAR(60) NOT NULL DEFAULT 'rgba(56, 189, 248, 0.15)',
    `border_color` VARCHAR(60) NOT NULL DEFAULT 'rgba(56, 189, 248, 0.35)',
    `hod` VARCHAR(120) NOT NULL,
    `faculty_count` INT NOT NULL DEFAULT 15,
    `sparkline` TEXT DEFAULT NULL COMMENT 'JSON array of historical metrics',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: semesters
-- --------------------------------------------------------
CREATE TABLE `semesters` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `semester_number` INT NOT NULL UNIQUE,
    `academic_year` VARCHAR(20) NOT NULL DEFAULT '2023-2024',
    `term` ENUM('Odd', 'Even') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: students
-- --------------------------------------------------------
CREATE TABLE `students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `roll_no` VARCHAR(25) NOT NULL UNIQUE,
    `name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(150) DEFAULT NULL UNIQUE,
    `phone` VARCHAR(30) DEFAULT NULL,
    `department_id` INT NOT NULL,
    `year` INT NOT NULL DEFAULT 1 CHECK (`year` BETWEEN 1 AND 4),
    `semester_id` INT NOT NULL,
    `avatar` VARCHAR(255) DEFAULT NULL,
    `register_no` VARCHAR(30) DEFAULT NULL,
    `section` VARCHAR(5) DEFAULT NULL,
    `dob` DATE DEFAULT NULL,
    `gender` VARCHAR(10) DEFAULT NULL,
    `course` VARCHAR(100) DEFAULT NULL,
    `admission_year` SMALLINT DEFAULT NULL,
    `blood_group` VARCHAR(10) DEFAULT NULL,
    `category` VARCHAR(20) DEFAULT NULL,
    `address` VARCHAR(255) DEFAULT NULL,
    `languages_known` VARCHAR(100) DEFAULT NULL,
    `guardian_name` VARCHAR(120) DEFAULT NULL,
    `guardian_phone` VARCHAR(30) DEFAULT NULL,
    `emergency_contact` VARCHAR(30) DEFAULT NULL,
    `relationship` VARCHAR(50) DEFAULT NULL,
    `credits` INT DEFAULT NULL,
    `backlogs` INT NOT NULL DEFAULT 0,
    `photo` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_students_department` FOREIGN KEY (`department_id`) 
        REFERENCES `departments` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_students_semester` FOREIGN KEY (`semester_id`) 
        REFERENCES `semesters` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX `idx_students_roll_no` (`roll_no`),
    INDEX `idx_students_department` (`department_id`),
    INDEX `idx_students_year` (`year`),
    INDEX `idx_students_semester` (`semester_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: student_documents
-- --------------------------------------------------------
CREATE TABLE `student_documents` (
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

-- --------------------------------------------------------
-- Table: subjects
-- --------------------------------------------------------
CREATE TABLE `subjects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `subject_code` VARCHAR(25) NOT NULL UNIQUE,
    `name` VARCHAR(150) NOT NULL,
    `department_id` INT NOT NULL,
    `semester_id` INT NOT NULL,
    `credits` INT NOT NULL DEFAULT 3,
    CONSTRAINT `fk_subjects_department` FOREIGN KEY (`department_id`) 
        REFERENCES `departments` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_subjects_semester` FOREIGN KEY (`semester_id`) 
        REFERENCES `semesters` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX `idx_subjects_department` (`department_id`),
    INDEX `idx_subjects_semester` (`semester_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: marks
-- --------------------------------------------------------
CREATE TABLE `marks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `subject_id` INT NOT NULL,
    `semester_id` INT NOT NULL,
    `marks` DECIMAL(5,2) NOT NULL CHECK (`marks` >= 0 AND `marks` <= 100),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_marks_student` FOREIGN KEY (`student_id`) 
        REFERENCES `students` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_marks_subject` FOREIGN KEY (`subject_id`) 
        REFERENCES `subjects` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_marks_semester` FOREIGN KEY (`semester_id`) 
        REFERENCES `semesters` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY `unique_student_subject_sem` (`student_id`, `subject_id`, `semester_id`),
    INDEX `idx_marks_student` (`student_id`),
    INDEX `idx_marks_subject` (`subject_id`),
    INDEX `idx_marks_semester` (`semester_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: attendance (DEPRECATED legacy summary)
-- --------------------------------------------------------
-- Kept only as an audit trail of the original per-semester percentages.
-- The application reads attendance exclusively from attendance_records.
-- --------------------------------------------------------
CREATE TABLE `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `semester_id` INT NOT NULL,
    `percentage` DECIMAL(5,2) NOT NULL CHECK (`percentage` >= 0 AND `percentage` <= 100),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`) 
        REFERENCES `students` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_attendance_semester` FOREIGN KEY (`semester_id`) 
        REFERENCES `semesters` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY `unique_student_sem_att` (`student_id`, `semester_id`),
    INDEX `idx_attendance_student` (`student_id`),
    INDEX `idx_attendance_semester` (`semester_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: attendance_sessions
-- --------------------------------------------------------
-- One row per real class/subject/date event that attendance was recorded for.
-- There is deliberately no student_id here: a session is a class event, and the
-- per-student outcome lives in attendance_records.
-- --------------------------------------------------------
CREATE TABLE `attendance_sessions` (
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
-- Table: attendance_records
-- --------------------------------------------------------
-- One row per student per session. This is the table every attendance
-- percentage in the application is computed from:
--   (present + late) / (present + late + absent) * 100   [excused excluded]
-- --------------------------------------------------------
CREATE TABLE `attendance_records` (
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

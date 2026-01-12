-- Fix for Visual Analysis Error: Missing database columns
-- This SQL adds the required columns for the visual analysis feature
-- Run this on production database to fix the error

-- ============================================================================
-- IMPORTANT: Always backup your database before running this script!
-- ============================================================================

USE crockenhill;

-- Step 1: Check if columns already exist (optional verification)
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM
    INFORMATION_SCHEMA.COLUMNS
WHERE
    TABLE_SCHEMA = 'crockenhill'
    AND TABLE_NAME = 'media_processing_logs'
    AND COLUMN_NAME IN ('visual_samples', 'song_clusters', 'visual_sample_count', 'visual_processing_time');

-- Step 2: Add visual analysis columns to media_processing_logs table
ALTER TABLE `media_processing_logs`
ADD COLUMN IF NOT EXISTS `visual_samples` JSON NULL
    COMMENT 'Visual frame analysis samples with timestamps and classifications'
    AFTER `rms_stats`,
ADD COLUMN IF NOT EXISTS `song_clusters` JSON NULL
    COMMENT 'Clustered song periods identified from visual analysis'
    AFTER `visual_samples`,
ADD COLUMN IF NOT EXISTS `visual_sample_count` INT NULL
    COMMENT 'Total number of visual samples analyzed'
    AFTER `song_clusters`,
ADD COLUMN IF NOT EXISTS `visual_processing_time` FLOAT NULL
    COMMENT 'Time taken for visual analysis in seconds'
    AFTER `visual_sample_count`;

-- Step 3: Add visual metadata columns to livestream_segments table
ALTER TABLE `livestream_segments`
ADD COLUMN IF NOT EXISTS `visual_confidence` FLOAT NULL
    COMMENT 'Confidence score from visual classification (0-1)'
    AFTER `peak_rms`,
ADD COLUMN IF NOT EXISTS `visual_sample_count` INT NULL
    COMMENT 'Number of visual samples in this segment'
    AFTER `visual_confidence`,
ADD COLUMN IF NOT EXISTS `calibration_method` VARCHAR(255) NULL
    COMMENT 'Method used for threshold calibration: per_song_visual, adaptive, fixed, fallback'
    AFTER `visual_sample_count`;

-- Step 4: Verify columns were created successfully
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM
    INFORMATION_SCHEMA.COLUMNS
WHERE
    TABLE_SCHEMA = 'crockenhill'
    AND (
        (TABLE_NAME = 'media_processing_logs' AND COLUMN_NAME IN ('visual_samples', 'song_clusters', 'visual_sample_count', 'visual_processing_time'))
        OR
        (TABLE_NAME = 'livestream_segments' AND COLUMN_NAME IN ('visual_confidence', 'visual_sample_count', 'calibration_method'))
    )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- ============================================================================
-- SUCCESS MESSAGE
-- ============================================================================
-- If no errors occurred, the visual analysis columns have been added successfully.
-- You can now process livestream videos with visual analysis enabled.
-- ============================================================================

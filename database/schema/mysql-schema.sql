/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `calendar_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `calendar_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `google_event_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `meeting_slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `speaker` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `status` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmed',
  `is_categorized_automatically` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `calendar_events_google_event_id_unique` (`google_event_id`),
  KEY `calendar_events_meeting_slug_start_datetime_index` (`meeting_slug`,`start_datetime`),
  KEY `calendar_events_start_datetime_status_index` (`start_datetime`,`status`),
  KEY `calendar_events_meeting_slug_index` (`meeting_slug`),
  KEY `calendar_events_start_datetime_index` (`start_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `livestream_processing_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `livestream_processing_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `processing_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint unsigned NOT NULL,
  `file_format` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration` double DEFAULT NULL,
  `status` enum('pending','rms_generation','processing','segmentation','segmenting','segmentation_complete','extraction','extraction_complete','transcription','sermon_submitted','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `threshold_method` enum('fixed','adaptive','fallback') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed',
  `adaptive_threshold` decimal(5,2) DEFAULT NULL,
  `rms_stats` json DEFAULT NULL,
  `rms_log_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sermon_audio_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sermon_video_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sermon_start_time` double DEFAULT NULL,
  `sermon_end_time` double DEFAULT NULL,
  `sermon_processing_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sermon_id` int unsigned DEFAULT NULL,
  `processing_metadata` json DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `livestream_processing_logs_processing_id_unique` (`processing_id`),
  KEY `livestream_processing_logs_sermon_id_foreign` (`sermon_id`),
  KEY `livestream_processing_logs_status_index` (`status`),
  KEY `livestream_processing_logs_processing_id_index` (`processing_id`),
  CONSTRAINT `livestream_processing_logs_sermon_id_foreign` FOREIGN KEY (`sermon_id`) REFERENCES `sermons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `livestream_segments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `livestream_segments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `processing_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `media_processing_log_id` bigint unsigned NOT NULL,
  `segment_index` tinyint unsigned NOT NULL,
  `start_time` decimal(10,3) NOT NULL,
  `end_time` decimal(10,3) NOT NULL,
  `duration` decimal(10,3) NOT NULL,
  `classification` enum('song','speech','silence') COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_sermon_segment` tinyint(1) NOT NULL DEFAULT '0',
  `is_sermon_candidate` tinyint(1) NOT NULL DEFAULT '0',
  `avg_rms` double DEFAULT NULL,
  `peak_rms` double DEFAULT NULL,
  `visual_confidence` double DEFAULT NULL COMMENT 'Confidence score from visual classification (0-1)',
  `visual_sample_count` int DEFAULT NULL COMMENT 'Number of visual samples in this segment',
  `calibration_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Method used for threshold calibration: per_song_visual, adaptive, fixed, fallback',
  `segment_order` int NOT NULL DEFAULT '0',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `livestream_segments_processing_id_index` (`processing_id`),
  KEY `livestream_segments_processing_log_id_index` (`media_processing_log_id`),
  KEY `livestream_segments_is_sermon_segment_index` (`is_sermon_segment`),
  KEY `livestream_segments_is_sermon_candidate_index` (`is_sermon_candidate`),
  CONSTRAINT `livestream_segments_media_processing_log_id_foreign` FOREIGN KEY (`media_processing_log_id`) REFERENCES `media_processing_logs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `livestream_segments_processing_id_foreign` FOREIGN KEY (`processing_id`) REFERENCES `media_processing_logs` (`processing_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `media` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `collection_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `conversions_disk` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` bigint unsigned NOT NULL,
  `manipulations` json NOT NULL,
  `custom_properties` json NOT NULL,
  `generated_conversions` json NOT NULL,
  `responsive_images` json NOT NULL,
  `order_column` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_uuid_unique` (`uuid`),
  KEY `media_model_type_model_id_index` (`model_type`,`model_id`),
  KEY `media_order_column_index` (`order_column`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `media_processing_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `media_processing_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `processing_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `processing_type` enum('audio','video','livestream') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_step` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint DEFAULT NULL,
  `duration` double DEFAULT NULL,
  `source_file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audio_file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transcript_file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rms_log_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sermon_start_time` double DEFAULT NULL,
  `sermon_end_time` double DEFAULT NULL,
  `ai_analysis` json DEFAULT NULL,
  `processing_metadata` json DEFAULT NULL,
  `threshold_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adaptive_threshold` double DEFAULT NULL,
  `rms_stats` json DEFAULT NULL,
  `visual_samples` json DEFAULT NULL COMMENT 'Visual frame analysis samples with timestamps and classifications',
  `song_clusters` json DEFAULT NULL COMMENT 'Clustered song periods identified from visual analysis',
  `visual_sample_count` int DEFAULT NULL COMMENT 'Total number of visual samples analyzed',
  `visual_processing_time` double DEFAULT NULL COMMENT 'Time taken for visual analysis in seconds',
  `sermon_id` int unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `owner_user_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_processing_logs_processing_id_unique` (`processing_id`),
  KEY `media_processing_logs_sermon_id_foreign` (`sermon_id`),
  KEY `media_processing_logs_processing_type_index` (`processing_type`),
  KEY `media_processing_logs_status_index` (`status`),
  KEY `media_processing_logs_processing_type_status_index` (`processing_type`,`status`),
  KEY `media_processing_logs_owner_user_id_foreign` (`owner_user_id`),
  CONSTRAINT `media_processing_logs_owner_user_id_foreign` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `media_processing_logs_sermon_id_foreign` FOREIGN KEY (`sermon_id`) REFERENCES `sermons` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `meetings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meetings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `page_id` int unsigned DEFAULT NULL,
  `slug` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('SundayAndBibleStudies','ChildrenAndYoungPeople','Adults','Occasional') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `meeting_date` datetime DEFAULT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT '0',
  `frequency` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `day` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `location` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `who` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pictures` tinyint(1) NOT NULL,
  `leaders_phone` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `leaders_email` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meetings_slug_unique` (`slug`),
  KEY `meetings_type_day_index` (`type`,`day`),
  KEY `meetings_page_id_foreign` (`page_id`),
  CONSTRAINT `meetings_page_id_foreign` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `heading` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `area` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `admin` enum('yes','no') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'no',
  `markdown` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `navigation` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pages_slug_unique` (`slug`),
  KEY `pages_area_index` (`area`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  KEY `password_reset_tokens_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `preacher_aliases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `preacher_aliases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `preacher_id` bigint unsigned NOT NULL,
  `alias` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `preacher_aliases_alias_unique` (`alias`),
  KEY `preacher_aliases_preacher_id_index` (`preacher_id`),
  CONSTRAINT `preacher_aliases_preacher_id_foreign` FOREIGN KEY (`preacher_id`) REFERENCES `preachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `preachers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `preachers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `preachers_slug_unique` (`slug`),
  KEY `preachers_name_index` (`name`),
  KEY `preachers_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sermon_processing_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sermon_processing_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `processing_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` enum('audio','video','livestream') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'audio',
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audio_file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transcript_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_analysis` json DEFAULT NULL,
  `status` enum('pending','processing','completed','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_step` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `source_metadata` json DEFAULT NULL,
  `sermon_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sermon_processing_logs_processing_id_unique` (`processing_id`),
  KEY `sermon_processing_logs_sermon_id_foreign` (`sermon_id`),
  KEY `sermon_processing_logs_status_created_at_index` (`status`,`created_at`),
  KEY `sermon_processing_logs_processing_id_index` (`processing_id`),
  KEY `sermon_processing_logs_source_type_status_index` (`source_type`,`status`),
  CONSTRAINT `sermon_processing_logs_sermon_id_foreign` FOREIGN KEY (`sermon_id`) REFERENCES `sermons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sermon_processing_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sermon_processing_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `processing_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `step` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('started','completed','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sermon_processing_steps_processing_id_step_unique` (`processing_id`,`step`),
  KEY `sermon_processing_steps_processing_id_step_index` (`processing_id`,`step`),
  KEY `sermon_processing_steps_processing_id_status_index` (`processing_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sermons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sermons` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `livestream_processing_id` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date NOT NULL,
  `service` enum('morning','evening','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audio_file_path` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `video_file_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_type` enum('manual','audio_upload','livestream','video_upload') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `segment_start_time` decimal(10,3) DEFAULT NULL,
  `segment_end_time` decimal(10,3) DEFAULT NULL,
  `duration` double DEFAULT NULL,
  `filetype` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mp3',
  `title` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preacher` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Mark Drury',
  `preacher_id` bigint unsigned DEFAULT NULL,
  `preacher_source` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preacher_confidence` double DEFAULT NULL,
  `needs_preacher_review` tinyint(1) NOT NULL DEFAULT '0',
  `series` varchar(75) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `points` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `show_points` tinyint(1) NOT NULL DEFAULT '0',
  `summary` text COLLATE utf8mb4_unicode_ci,
  `meta_description` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `show_summary` tinyint(1) NOT NULL DEFAULT '0',
  `transcript_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_generated_at` timestamp NULL DEFAULT NULL,
  `thumbnail_metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sermons_slug_unique` (`slug`),
  KEY `sermons_date_service_index` (`date`,`service`),
  KEY `sermons_preacher_index` (`preacher`),
  KEY `sermons_series_index` (`series`),
  KEY `sermons_livestream_processing_id_index` (`livestream_processing_id`),
  KEY `sermons_source_type_index` (`source_type`),
  KEY `sermons_preacher_id_foreign` (`preacher_id`),
  CONSTRAINT `sermons_preacher_id_foreign` FOREIGN KEY (`preacher_id`) REFERENCES `preachers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `ip_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `sessions_id_unique` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `speaker_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `speaker_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `preacher_id` bigint unsigned NOT NULL,
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_version` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `centroid_embedding` json NOT NULL,
  `sample_count` int unsigned NOT NULL DEFAULT '0',
  `quality_score` double DEFAULT NULL,
  `accept_threshold` double DEFAULT NULL,
  `margin_threshold` double DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `speaker_profiles_preacher_id_index` (`preacher_id`),
  KEY `speaker_profiles_is_active_index` (`is_active`),
  CONSTRAINT `speaker_profiles_preacher_id_foreign` FOREIGN KEY (`preacher_id`) REFERENCES `preachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `speaker_samples`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `speaker_samples` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `speaker_profile_id` bigint unsigned NOT NULL,
  `sermon_id` int unsigned DEFAULT NULL,
  `media_processing_log_id` bigint unsigned DEFAULT NULL,
  `embedding` json NOT NULL,
  `duration_seconds` double NOT NULL,
  `quality_score` double DEFAULT NULL,
  `source` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `speaker_samples_media_processing_log_id_foreign` (`media_processing_log_id`),
  KEY `speaker_samples_speaker_profile_id_index` (`speaker_profile_id`),
  KEY `speaker_samples_sermon_id_index` (`sermon_id`),
  CONSTRAINT `speaker_samples_media_processing_log_id_foreign` FOREIGN KEY (`media_processing_log_id`) REFERENCES `media_processing_logs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `speaker_samples_sermon_id_foreign` FOREIGN KEY (`sermon_id`) REFERENCES `sermons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `speaker_samples_speaker_profile_id_foreign` FOREIGN KEY (`speaker_profile_id`) REFERENCES `speaker_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2014_02_04_224912_create_sermons_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2014_10_12_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2014_10_12_100000_create_password_reset_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2015_01_10_180038_create_session_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2015_02_08_213811_create_pages_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2015_06_28_114208_create_meetings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2019_08_19_000000_create_failed_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2019_12_14_000001_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2023_11_23_194306_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2024_07_09_000001_update_sermon_service_enum',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2025_06_29_151500_fix_users_table_timestamps',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2025_06_29_151647_add_email_verified_at_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2025_06_29_151648_add_event_fields_to_meetings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2025_07_09_220128_add_missing_indexes_to_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2025_07_14_201510_create_calendar_events_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2025_07_15_212543_create_sermon_processing_logs_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2025_07_15_212712_add_transcript_path_to_sermons_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2025_01_30_100000_add_summary_to_sermons_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2025_08_01_190139_create_livestream_processing_logs_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2025_08_01_190146_create_livestream_segments_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2025_08_01_190157_add_livestream_columns_to_sermons_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2025_08_07_150535_update_sermon_processing_logs_current_step_length',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2025_09_01_192100_add_adaptive_threshold_fields_to_livestream_processing_logs',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2025_09_01_204201_add_video_upload_source_type_to_sermons',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2025_09_02_201709_create_sermon_processing_steps_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2025_09_03_204510_update_livestream_processing_logs_status_enum',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2025_09_05_011042_extend_sermon_processing_logs_for_media_types',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2025_09_13_195343_add_thumbnail_fields_to_sermons_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2025_09_14_081431_add_processing_fields_to_sermon_processing_logs',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2025_10_02_082440_add_audio_file_path_to_sermon_processing_logs',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2025_10_02_140532_create_media_processing_logs_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2025_10_02_140717_update_livestream_segments_to_use_media_processing_log',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2025_10_02_143000_fix_livestream_segments_foreign_keys',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2025_10_03_071419_standardize_sermon_file_path_fields',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2025_10_12_143942_add_visibility_toggles_to_sermons_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2025_11_16_223228_add_visual_analysis_columns',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_01_14_221559_add_duration_to_sermons_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_01_14_add_meta_description_to_sermons_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_01_21_083314_create_media_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_01_21_170720_add_page_id_to_meetings_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_01_21_170857_create_pages_for_meetings',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_02_06_132545_rename_meetings_pascalcase_columns',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_02_14_202556_create_preachers_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_02_14_202603_create_preacher_aliases_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_02_14_202611_add_preacher_columns_to_sermons_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_02_15_205529_create_speaker_profiles_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_02_15_205611_create_speaker_samples_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_02_18_193615_create_job_batches_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_02_19_120000_add_owner_user_id_to_media_processing_logs_table',4);

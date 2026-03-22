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
  KEY `calendar_events_start_datetime_index` (`start_datetime`),
  CONSTRAINT `calendar_events_meeting_slug_foreign` FOREIGN KEY (`meeting_slug`) REFERENCES `meetings` (`slug`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `church_service_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `church_service_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `church_service_id` bigint unsigned NOT NULL,
  `position` int unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `section_type` enum('welcome','prayer','notices','song','childrens_talk','bible_reading','sermon','other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` enum('email','openlp','manual') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `openlp_search_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `song_id` bigint unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `active_position` int GENERATED ALWAYS AS ((case when (`deleted_at` is null) then `position` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `church_service_items_active_position_unique` (`church_service_id`,`active_position`),
  KEY `church_service_items_church_service_id_position_index` (`church_service_id`,`position`),
  KEY `church_service_items_church_service_id_type_index` (`church_service_id`,`type`),
  KEY `church_service_items_song_id_index` (`song_id`),
  KEY `church_service_items_type_song_id_index` (`type`,`song_id`),
  KEY `church_service_items_section_type_index` (`section_type`),
  CONSTRAINT `church_service_items_church_service_id_foreign` FOREIGN KEY (`church_service_id`) REFERENCES `church_services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `church_service_items_song_id_foreign` FOREIGN KEY (`song_id`) REFERENCES `songs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `church_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `church_services` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `service` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `needs_review` tinyint(1) NOT NULL DEFAULT '0',
  `review_state` enum('not_reviewed','reviewed','reopened') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_reviewed',
  `manual_reviewed_at` timestamp NULL DEFAULT NULL,
  `manual_reviewed_by_user_id` int unsigned DEFAULT NULL,
  `manual_review_reopened_at` timestamp NULL DEFAULT NULL,
  `manual_review_reopened_by_source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canonical_conflict_state` enum('none','detected','reopened') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `canonical_conflict_detected_at` timestamp NULL DEFAULT NULL,
  `canonical_conflict_incoming_source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canonical_conflict_reviewed_previously` tinyint(1) DEFAULT NULL,
  `canonical_conflict_canonical_changed` tinyint(1) DEFAULT NULL,
  `canonical_conflict_reason` enum('unspecified','conflicts_only','canonical_changed','canonical_changed_with_conflicts') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `church_services_date_service_unique` (`date`,`service`),
  KEY `church_services_needs_review_index` (`needs_review`),
  KEY `church_services_manual_reviewed_by_user_id_index` (`manual_reviewed_by_user_id`),
  KEY `church_services_review_state_index` (`review_state`),
  KEY `church_services_canonical_conflict_state_index` (`canonical_conflict_state`),
  CONSTRAINT `church_services_canonical_conflict_state_check` CHECK ((((`canonical_conflict_state` = _utf8mb4'none') and (`canonical_conflict_detected_at` is null) and (`canonical_conflict_incoming_source` is null) and (`canonical_conflict_reviewed_previously` is null) and (`canonical_conflict_canonical_changed` is null) and (`canonical_conflict_reason` is null)) or ((`canonical_conflict_state` = _utf8mb4'detected') and (`canonical_conflict_detected_at` is not null) and (`canonical_conflict_incoming_source` is not null) and (`canonical_conflict_reason` is not null)) or ((`canonical_conflict_state` = _utf8mb4'reopened') and (`canonical_conflict_detected_at` is not null) and (`canonical_conflict_incoming_source` is not null) and (`canonical_conflict_reason` is not null)))),
  CONSTRAINT `church_services_review_state_check` CHECK ((((`review_state` = _utf8mb4'not_reviewed') and (`manual_reviewed_at` is null) and (`manual_review_reopened_at` is null) and (`manual_review_reopened_by_source` is null)) or ((`review_state` = _utf8mb4'reviewed') and (`manual_reviewed_at` is not null) and (`manual_review_reopened_at` is null) and (`manual_review_reopened_by_source` is null)) or ((`review_state` = _utf8mb4'reopened') and (`manual_reviewed_at` is not null) and (`manual_review_reopened_at` is not null) and (`manual_review_reopened_by_source` is not null))))
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
DROP TABLE IF EXISTS `inbound_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inbound_emails` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message_id` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_plain` longtext COLLATE utf8mb4_unicode_ci,
  `body_html` longtext COLLATE utf8mb4_unicode_ci,
  `received_at` timestamp NOT NULL,
  `status` enum('pending','processed','failed','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `processing_metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inbound_emails_message_id_unique` (`message_id`),
  KEY `inbound_emails_status_index` (`status`),
  KEY `inbound_emails_received_at_index` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
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
DROP TABLE IF EXISTS `livestream_segments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `livestream_segments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `media_processing_log_id` bigint unsigned NOT NULL,
  `segment_index` smallint unsigned NOT NULL,
  `start_time` decimal(10,3) NOT NULL,
  `end_time` decimal(10,3) NOT NULL,
  `duration` decimal(10,3) NOT NULL,
  `classification` enum('song','speech','silence') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_sermon_segment` tinyint(1) NOT NULL DEFAULT '0',
  `is_sermon_candidate` tinyint(1) NOT NULL DEFAULT '0',
  `avg_rms` double DEFAULT NULL,
  `peak_rms` double DEFAULT NULL,
  `visual_confidence` double DEFAULT NULL COMMENT 'Confidence score from visual classification (0-1)',
  `visual_sample_count` int DEFAULT NULL COMMENT 'Number of visual samples in this segment',
  `calibration_method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Method used for threshold calibration: per_song_visual, adaptive, fixed, fallback',
  `segment_order` int NOT NULL DEFAULT '0',
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `livestream_segments_log_index_unique` (`media_processing_log_id`,`segment_index`),
  KEY `livestream_segments_processing_log_id_index` (`media_processing_log_id`),
  KEY `livestream_segments_is_sermon_segment_index` (`is_sermon_segment`),
  KEY `livestream_segments_is_sermon_candidate_index` (`is_sermon_candidate`),
  KEY `livestream_segments_log_order_index` (`media_processing_log_id`,`segment_order`,`start_time`),
  KEY `livestream_segments_log_classification_time_index` (`media_processing_log_id`,`classification`,`start_time`),
  CONSTRAINT `livestream_segments_media_processing_log_id_foreign` FOREIGN KEY (`media_processing_log_id`) REFERENCES `media_processing_logs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `media` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `model_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint unsigned NOT NULL,
  `uuid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `collection_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `conversions_disk` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  `processing_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `processing_type` enum('audio','video','livestream') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','started','processing','completed','skipped','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `current_step` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint DEFAULT NULL,
  `duration` double DEFAULT NULL,
  `extracted_date` date DEFAULT NULL,
  `extracted_service` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audio_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transcript_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rms_log_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sermon_start_time` double DEFAULT NULL,
  `sermon_end_time` double DEFAULT NULL,
  `ai_analysis` json DEFAULT NULL,
  `processing_metadata` json DEFAULT NULL,
  `threshold_method` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  `church_service_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_processing_logs_processing_id_unique` (`processing_id`),
  KEY `media_processing_logs_sermon_id_foreign` (`sermon_id`),
  KEY `media_processing_logs_processing_type_index` (`processing_type`),
  KEY `media_processing_logs_status_index` (`status`),
  KEY `media_processing_logs_processing_type_status_index` (`processing_type`,`status`),
  KEY `media_processing_logs_owner_user_id_foreign` (`owner_user_id`),
  KEY `media_processing_logs_extracted_identity_index` (`extracted_date`,`extracted_service`),
  KEY `media_processing_logs_church_service_id_foreign` (`church_service_id`),
  KEY `media_processing_logs_file_hash_index` (`file_hash`),
  KEY `media_processing_logs_review_queue_index` (`processing_type`,`status`,`current_step`,`updated_at`),
  CONSTRAINT `media_processing_logs_church_service_id_foreign` FOREIGN KEY (`church_service_id`) REFERENCES `church_services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `media_processing_logs_owner_user_id_foreign` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `media_processing_logs_sermon_id_foreign` FOREIGN KEY (`sermon_id`) REFERENCES `sermons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `meetings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meetings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `page_id` int unsigned DEFAULT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('SundayAndBibleStudies','ChildrenAndYoungPeople','Adults','Occasional') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `meeting_date` datetime DEFAULT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT '0',
  `frequency` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `day` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `who` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pictures` tinyint(1) NOT NULL,
  `leaders_phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `leaders_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meetings_slug_unique` (`slug`),
  UNIQUE KEY `meetings_page_id_unique` (`page_id`),
  KEY `meetings_type_day_index` (`type`,`day`),
  KEY `meetings_is_recurring_index` (`is_recurring`),
  KEY `meetings_meeting_date_index` (`meeting_date`),
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
  UNIQUE KEY `pages_area_slug_unique` (`area`,`slug`),
  KEY `pages_area_index` (`area`),
  KEY `pages_navigation_index` (`navigation`)
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
  `alias` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
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
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `preachers_slug_unique` (`slug`),
  UNIQUE KEY `preachers_name_unique` (`name`),
  KEY `preachers_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scripture_passages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scripture_passages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bible_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_reference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_passage_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `display_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `html_content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `copyright` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `fums_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fetched_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scripture_passages_bible_id_normalized_reference_unique` (`bible_id`,`normalized_reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sermon_processing_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sermon_processing_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `processing_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `step` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('started','completed','skipped','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sermon_processing_steps_processing_id_step_unique` (`processing_id`,`step`),
  KEY `sermon_processing_steps_processing_id_status_index` (`processing_id`,`status`),
  CONSTRAINT `sermon_processing_steps_processing_id_foreign` FOREIGN KEY (`processing_id`) REFERENCES `media_processing_logs` (`processing_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sermons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sermons` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `livestream_processing_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date NOT NULL,
  `service` enum('morning','evening','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sermon',
  `audio_file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `video_file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_type` enum('manual','audio_upload','livestream','video_upload') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `segment_start_time` decimal(10,3) DEFAULT NULL,
  `segment_end_time` decimal(10,3) DEFAULT NULL,
  `duration` double DEFAULT NULL,
  `filetype` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mp3',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scripture_passage_id` bigint unsigned DEFAULT NULL,
  `preacher` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Mark Drury',
  `preacher_id` bigint unsigned DEFAULT NULL,
  `preacher_source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preacher_confidence` double DEFAULT NULL,
  `needs_preacher_review` tinyint(1) NOT NULL DEFAULT '0',
  `series` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `points` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `show_points` tinyint(1) NOT NULL DEFAULT '0',
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `meta_description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `show_summary` tinyint(1) NOT NULL DEFAULT '0',
  `transcript_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  KEY `sermons_content_type_index` (`content_type`),
  KEY `sermons_scripture_passage_id_foreign` (`scripture_passage_id`),
  KEY `sermons_needs_preacher_review_index` (`needs_preacher_review`),
  CONSTRAINT `sermons_livestream_processing_id_foreign` FOREIGN KEY (`livestream_processing_id`) REFERENCES `media_processing_logs` (`processing_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `sermons_preacher_id_foreign` FOREIGN KEY (`preacher_id`) REFERENCES `preachers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sermons_scripture_passage_id_foreign` FOREIGN KEY (`scripture_passage_id`) REFERENCES `scripture_passages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_sections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `media_processing_log_id` bigint unsigned NOT NULL,
  `church_service_item_id` bigint unsigned DEFAULT NULL,
  `section_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `section_order` int unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_time` double NOT NULL,
  `end_time` double NOT NULL,
  `duration` double NOT NULL,
  `status` enum('identified','skipped') COLLATE utf8mb4_unicode_ci NOT NULL,
  `needs_manual_review` tinyint(1) NOT NULL DEFAULT '0',
  `source_segment_ids` json NOT NULL,
  `metadata` json DEFAULT NULL,
  `song_match_type` enum('confirmed','inferred','unmatched') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `matched_item_id` bigint unsigned DEFAULT NULL,
  `expected_item_id` bigint unsigned DEFAULT NULL,
  `confidence` decimal(4,3) DEFAULT NULL,
  `publication_status` enum('not_applicable','pending_approval','approved','rejected','published') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_applicable',
  `published_sermon_id` int unsigned DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `extracted_video_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extracted_audio_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extracted_at` timestamp NULL DEFAULT NULL,
  `unpublished_expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_sections_log_order_unique` (`media_processing_log_id`,`section_order`),
  UNIQUE KEY `service_sections_published_sermon_id_unique` (`published_sermon_id`),
  KEY `service_sections_log_type_index` (`media_processing_log_id`,`section_type`),
  KEY `service_sections_needs_review_index` (`needs_manual_review`),
  KEY `service_sections_church_service_item_index` (`church_service_item_id`),
  KEY `service_sections_publication_status_index` (`publication_status`),
  KEY `service_sections_unpublished_expires_at_index` (`unpublished_expires_at`),
  KEY `service_sections_published_sermon_id_index` (`published_sermon_id`),
  KEY `service_sections_log_type_order_index` (`media_processing_log_id`,`section_type`,`section_order`,`start_time`),
  KEY `service_sections_publication_status_updated_at_index` (`publication_status`,`updated_at`),
  KEY `service_sections_song_match_type_index` (`song_match_type`),
  KEY `service_sections_reporting_song_match_index` (`media_processing_log_id`,`church_service_item_id`,`section_type`,`song_match_type`),
  KEY `service_sections_matched_item_id_index` (`matched_item_id`),
  KEY `service_sections_expected_item_id_index` (`expected_item_id`),
  CONSTRAINT `service_sections_church_service_item_id_foreign` FOREIGN KEY (`church_service_item_id`) REFERENCES `church_service_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_sections_media_processing_log_id_foreign` FOREIGN KEY (`media_processing_log_id`) REFERENCES `media_processing_logs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_sections_published_sermon_id_foreign` FOREIGN KEY (`published_sermon_id`) REFERENCES `sermons` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_sections_confidence_range_check` CHECK (((`confidence` is null) or ((`confidence` >= 0.000) and (`confidence` <= 1.000)))),
  CONSTRAINT `service_sections_publication_link_check` CHECK ((((`publication_status` = _utf8mb4'published') and (`published_sermon_id` is not null) and (`published_at` is not null)) or ((`publication_status` <> _utf8mb4'published') and (`published_sermon_id` is null) and (`published_at` is null)))),
  CONSTRAINT `service_sections_publication_media_check` CHECK ((((`publication_status` in (_utf8mb4'approved',_utf8mb4'published')) and (`extracted_video_path` is not null) and (`extracted_audio_path` is not null) and (`extracted_at` is not null)) or (`publication_status` in (_utf8mb4'not_applicable',_utf8mb4'pending_approval',_utf8mb4'rejected')))),
  CONSTRAINT `service_sections_status_publication_check` CHECK (((`status` <> _utf8mb4'skipped') or (`publication_status` = _utf8mb4'not_applicable'))),
  CONSTRAINT `service_sections_timing_invariants_check` CHECK (((`start_time` >= 0) and (`end_time` > `start_time`) and (`duration` >= 0) and (abs(((`end_time` - `start_time`) - `duration`)) <= 0.050)))
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
DROP TABLE IF EXISTS `song_author_song`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `song_author_song` (
  `song_id` bigint unsigned NOT NULL,
  `song_author_id` bigint unsigned NOT NULL,
  `author_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  UNIQUE KEY `song_author_song_unique` (`song_id`,`song_author_id`,`author_type`),
  KEY `song_author_song_song_author_id_foreign` (`song_author_id`),
  CONSTRAINT `song_author_song_song_author_id_foreign` FOREIGN KEY (`song_author_id`) REFERENCES `song_authors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `song_author_song_song_id_foreign` FOREIGN KEY (`song_id`) REFERENCES `songs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `song_authors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `song_authors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `song_authors_display_name_unique` (`display_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `song_book_song`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `song_book_song` (
  `song_id` bigint unsigned NOT NULL,
  `song_book_id` bigint unsigned NOT NULL,
  `entry` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `song_book_song_unique` (`song_id`,`song_book_id`,`entry`),
  KEY `song_book_song_song_book_id_foreign` (`song_book_id`),
  CONSTRAINT `song_book_song_song_book_id_foreign` FOREIGN KEY (`song_book_id`) REFERENCES `song_books` (`id`) ON DELETE CASCADE,
  CONSTRAINT `song_book_song_song_id_foreign` FOREIGN KEY (`song_id`) REFERENCES `songs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `song_books`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `song_books` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source_book_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `publisher` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `song_books_source_book_id_unique` (`source_book_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `songs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `songs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `canonical_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alternate_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lyrics_xml` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `lyrics_plain` longtext COLLATE utf8mb4_unicode_ci,
  `verse_order` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `copyright` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comments` longtext COLLATE utf8mb4_unicode_ci,
  `ccli_number` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `songs_canonical_key_unique` (`canonical_key`),
  UNIQUE KEY `songs_slug_unique` (`slug`),
  KEY `songs_ccli_number_index` (`ccli_number`),
  KEY `songs_deleted_at_index` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `speaker_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `speaker_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `preacher_id` bigint unsigned NOT NULL,
  `provider` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `centroid_embedding` json NOT NULL,
  `sample_count` int unsigned NOT NULL DEFAULT '0',
  `quality_score` double DEFAULT NULL,
  `accept_threshold` double DEFAULT NULL,
  `margin_threshold` double DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `speaker_profiles_preacher_provider_version_unique` (`preacher_id`,`provider`,`model_version`),
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
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `approved` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `speaker_samples_profile_sermon_source_unique` (`speaker_profile_id`,`sermon_id`,`source`),
  KEY `speaker_samples_media_processing_log_id_foreign` (`media_processing_log_id`),
  KEY `speaker_samples_speaker_profile_id_index` (`speaker_profile_id`),
  KEY `speaker_samples_sermon_id_index` (`sermon_id`),
  KEY `speaker_samples_profile_approved_index` (`speaker_profile_id`,`approved`),
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
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_created_at_index` (`created_at`),
  KEY `users_is_admin_index` (`is_admin`)
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
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_02_24_092832_cleanup_schema_h3_issues',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_02_26_214602_add_is_admin_to_users_table_if_missing',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_02_26_221409_make_meetings_location_nullable_if_required',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_02_26_221409_normalize_password_reset_tokens_email_key',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_02_28_160000_create_church_services_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_02_28_160100_create_church_service_items_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_02_28_170000_create_service_sections_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_02_28_170100_add_extracted_identity_to_media_processing_logs_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_02_28_180000_add_publication_columns_to_service_sections_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_02_28_190000_create_songs_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_02_28_190100_create_song_authors_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_02_28_190200_create_song_author_song_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_02_28_190300_create_song_books_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_02_28_190400_create_song_book_song_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_02_28_190500_add_song_id_to_church_service_items_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_02_28_233313_reconcile_song_catalog_schema',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_03_08_120000_add_church_service_id_to_media_processing_logs_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_03_08_120000_add_confidence_to_service_sections_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_03_08_190000_add_source_to_church_service_items_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_03_08_210000_create_inbound_emails_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_03_09_060939_increase_column_lengths_for_sermons_and_meetings',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_03_09_120000_add_confidence_range_constraint_to_service_sections_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_03_09_120000_add_content_type_to_sermons_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_03_09_220737_add_slug_to_songs_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_03_10_075905_add_foreign_key_to_calendar_events_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_03_11_064615_increase_audio_file_path_length_on_sermons_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_03_12_145040_add_foreign_key_to_sermons_livestream_processing_id',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_03_12_220000_add_skipped_to_sermon_processing_steps_status_enum',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_03_13_095602_fortify_processing_log_integrity',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_03_13_194852_create_scripture_passages_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_03_13_194853_add_scripture_passage_id_to_sermons_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_03_14_082820_add_unique_constraint_to_pages_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_03_15_064347_add_unique_constraint_to_preachers_name',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_03_16_064234_add_index_to_users_created_at',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_03_17_083035_add_unique_constraint_to_livestream_segments_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_03_18_000001_add_file_hash_to_media_processing_logs',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_03_19_032845_add_filtering_indexes_to_sermons_and_pages',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_03_19_050000_add_additive_schema_guardrails_for_processing_tables',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_03_19_063550_add_unique_constraint_to_speaker_profiles_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_03_19_070000_add_speaker_sample_uniqueness_and_processing_log_retention',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_03_19_080000_remove_legacy_schema_artifacts',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2026_03_20_064153_add_unique_constraint_to_meetings_page_id',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2026_03_22_031306_add_admin_listing_filtering_indexes',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2026_03_22_080851_add_index_to_meetings_meeting_date',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2026_03_22_120000_formalize_review_publication_state_and_ordering_invariants',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2026_03_22_120000_promote_service_reporting_state_to_columns',9);

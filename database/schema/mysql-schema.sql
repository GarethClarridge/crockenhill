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
  `google_event_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meeting_slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `speaker` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `status` enum('confirmed','pending','tentative','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmed',
  `is_categorized_automatically` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `calendar_events_google_event_id_unique` (`google_event_id`),
  KEY `calendar_events_meeting_slug_start_datetime_index` (`meeting_slug`,`start_datetime`),
  KEY `calendar_events_start_datetime_status_index` (`start_datetime`,`status`),
  KEY `calendar_events_meeting_slug_index` (`meeting_slug`),
  KEY `calendar_events_start_datetime_index` (`start_datetime`),
  CONSTRAINT `calendar_events_meeting_slug_foreign` FOREIGN KEY (`meeting_slug`) REFERENCES `meetings` (`slug`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `calendar_events_google_event_id_format_check` CHECK (((cast(`google_event_id` as char charset binary) = trim(`google_event_id`)) and (`google_event_id` <> _utf8mb4''))),
  CONSTRAINT `calendar_events_location_format_check` CHECK (((`location` is null) or (cast(`location` as char charset binary) = trim(`location`)))),
  CONSTRAINT `calendar_events_speaker_format_check` CHECK (((`speaker` is null) or (cast(`speaker` as char charset binary) = trim(`speaker`)))),
  CONSTRAINT `calendar_events_timing_check` CHECK ((`end_datetime` >= `start_datetime`)),
  CONSTRAINT `calendar_events_title_format_check` CHECK (((cast(`title` as char charset binary) = trim(`title`)) and (`title` <> _utf8mb4'')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `church_service_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `church_service_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `church_service_id` bigint unsigned NOT NULL,
  `position` int unsigned NOT NULL,
  `type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `section_type` enum('welcome','prayer','notices','song','childrens_talk','bible_reading','sermon','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` enum('email','openlp','manual','livestream') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `openlp_search_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `song_id` int unsigned DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `livestream_processing_id` varchar(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `livestream_service_section_id` bigint unsigned DEFAULT NULL,
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
  KEY `church_service_items_livestream_service_section_id_foreign` (`livestream_service_section_id`),
  KEY `church_service_items_livestream_processing_id_index` (`livestream_processing_id`),
  CONSTRAINT `church_service_items_church_service_id_foreign` FOREIGN KEY (`church_service_id`) REFERENCES `church_services` (`id`) ON DELETE CASCADE,
  CONSTRAINT `church_service_items_livestream_service_section_id_foreign` FOREIGN KEY (`livestream_service_section_id`) REFERENCES `service_sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `church_service_items_song_id_foreign` FOREIGN KEY (`song_id`) REFERENCES `songs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `church_service_items_openlp_search_title_format_check` CHECK (((`openlp_search_title` is null) or ((cast(`openlp_search_title` as char charset binary) = trim(`openlp_search_title`)) and (`openlp_search_title` <> _utf8mb4'')))),
  CONSTRAINT `church_service_items_source_title_format_check` CHECK (((`source_title` is null) or ((cast(`source_title` as char charset binary) = trim(`source_title`)) and (`source_title` <> _utf8mb4'')))),
  CONSTRAINT `church_service_items_title_format_check` CHECK (((cast(`title` as char charset binary) = trim(`title`)) and (`title` <> _utf8mb4''))),
  CONSTRAINT `church_service_items_type_format_check` CHECK (((cast(`type` as char charset binary) = trim(`type`)) and (`type` <> _utf8mb4'')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `church_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `church_services` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `service` enum('morning','evening','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `needs_review` tinyint(1) NOT NULL DEFAULT '0',
  `review_state` enum('not_reviewed','reviewed','reopened') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_reviewed',
  `manual_reviewed_at` timestamp NULL DEFAULT NULL,
  `manual_reviewed_by_user_id` int unsigned DEFAULT NULL,
  `manual_review_reopened_at` timestamp NULL DEFAULT NULL,
  `manual_review_reopened_by_source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canonical_conflict_state` enum('none','detected','reopened') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `canonical_conflict_detected_at` timestamp NULL DEFAULT NULL,
  `canonical_conflict_incoming_source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `canonical_conflict_reviewed_previously` tinyint(1) DEFAULT NULL,
  `canonical_conflict_canonical_changed` tinyint(1) DEFAULT NULL,
  `canonical_conflict_reason` enum('unspecified','conflicts_only','canonical_changed','canonical_changed_with_conflicts') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_metadata` json DEFAULT NULL,
  `pending_structure_merge_source` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `church_services_date_service_unique` (`date`,`service`),
  KEY `church_services_needs_review_index` (`needs_review`),
  KEY `church_services_manual_reviewed_by_user_id_index` (`manual_reviewed_by_user_id`),
  KEY `church_services_review_state_index` (`review_state`),
  KEY `church_services_canonical_conflict_state_index` (`canonical_conflict_state`),
  KEY `church_services_pending_merge_source_index` (`pending_structure_merge_source`),
  CONSTRAINT `church_services_manual_reviewed_by_user_id_foreign` FOREIGN KEY (`manual_reviewed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `church_services_canonical_conflict_state_check` CHECK ((((`canonical_conflict_state` = _utf8mb4'none') and (`canonical_conflict_detected_at` is null) and (`canonical_conflict_incoming_source` is null) and (`canonical_conflict_reviewed_previously` is null) and (`canonical_conflict_canonical_changed` is null) and (`canonical_conflict_reason` is null)) or ((`canonical_conflict_state` = _utf8mb4'detected') and (`canonical_conflict_detected_at` is not null) and (`canonical_conflict_incoming_source` is not null) and (`canonical_conflict_reason` is not null)) or ((`canonical_conflict_state` = _utf8mb4'reopened') and (`canonical_conflict_detected_at` is not null) and (`canonical_conflict_incoming_source` is not null) and (`canonical_conflict_reason` is not null)))),
  CONSTRAINT `church_services_review_state_check` CHECK ((((`review_state` = _utf8mb4'not_reviewed') and (`manual_reviewed_at` is null) and (`manual_review_reopened_at` is null) and (`manual_review_reopened_by_source` is null)) or ((`review_state` = _utf8mb4'reviewed') and (`manual_reviewed_at` is not null) and (`manual_review_reopened_at` is null) and (`manual_review_reopened_by_source` is null)) or ((`review_state` = _utf8mb4'reopened') and (`manual_reviewed_at` is not null) and (`manual_review_reopened_at` is not null) and (`manual_review_reopened_by_source` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(75) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `type` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `filename` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `filetype` varchar(8) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `owner` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
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
DROP TABLE IF EXISTS `health_check_result_history_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `health_check_result_history_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `check_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `check_label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notification_message` text COLLATE utf8mb4_unicode_ci,
  `short_summary` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` json NOT NULL,
  `ended_at` timestamp NOT NULL,
  `batch` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `health_check_result_history_items_created_at_index` (`created_at`),
  KEY `health_check_result_history_items_batch_index` (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inbound_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inbound_emails` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `message_id` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_plain` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `body_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `received_at` timestamp NOT NULL,
  `status` enum('pending','processed','failed','rejected','archive_eval') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `processing_metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inbound_emails_message_id_unique` (`message_id`),
  KEY `inbound_emails_status_index` (`status`),
  KEY `inbound_emails_received_at_index` (`received_at`),
  CONSTRAINT `inbound_emails_from_format_check` CHECK (((cast(`from` as char charset binary) = trim(`from`)) and (`from` <> _utf8mb4''))),
  CONSTRAINT `inbound_emails_subject_format_check` CHECK (((cast(`subject` as char charset binary) = trim(`subject`)) and (`subject` <> _utf8mb4'')))
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
  KEY `livestream_segments_log_start_time_index` (`media_processing_log_id`,`start_time`),
  CONSTRAINT `livestream_segments_media_processing_log_id_foreign` FOREIGN KEY (`media_processing_log_id`) REFERENCES `media_processing_logs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `livestream_segments_segment_order_check` CHECK (((`segment_order` >= 0) or (`segment_order` is null))),
  CONSTRAINT `livestream_segments_timing_check` CHECK (((`start_time` >= 0) and (`end_time` >= `start_time`) and (`duration` >= 0))),
  CONSTRAINT `livestream_segments_visual_confidence_check` CHECK ((((`visual_confidence` >= 0) and (`visual_confidence` <= 1)) or (`visual_confidence` is null))),
  CONSTRAINT `livestream_segments_visual_sample_count_check` CHECK (((`visual_sample_count` >= 0) or (`visual_sample_count` is null)))
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
  `status` enum('pending','started','processing','completed','skipped','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `current_step` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dedup_key` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint DEFAULT NULL,
  `duration` double DEFAULT NULL,
  `extracted_date` date DEFAULT NULL,
  `extracted_service` enum('morning','evening','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audio_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `enhanced_audio_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transcript_file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rms_log_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sermon_start_time` double DEFAULT NULL,
  `sermon_end_time` double DEFAULT NULL,
  `ai_analysis` json DEFAULT NULL,
  `processing_metadata` json DEFAULT NULL,
  `queue_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `job_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempt_count` smallint unsigned DEFAULT NULL,
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
  `is_degraded_completion` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `owner_user_id` int unsigned DEFAULT NULL,
  `church_service_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_processing_logs_processing_id_unique` (`processing_id`),
  UNIQUE KEY `media_processing_logs_dedup_key_unique` (`dedup_key`),
  KEY `media_processing_logs_sermon_id_foreign` (`sermon_id`),
  KEY `media_processing_logs_processing_type_index` (`processing_type`),
  KEY `media_processing_logs_status_index` (`status`),
  KEY `media_processing_logs_processing_type_status_index` (`processing_type`,`status`),
  KEY `media_processing_logs_owner_user_id_foreign` (`owner_user_id`),
  KEY `media_processing_logs_extracted_identity_index` (`extracted_date`,`extracted_service`),
  KEY `media_processing_logs_church_service_id_foreign` (`church_service_id`),
  KEY `media_processing_logs_file_hash_index` (`file_hash`),
  KEY `media_processing_logs_review_queue_index` (`processing_type`,`status`,`current_step`,`updated_at`),
  KEY `media_processing_logs_original_filename_index` (`original_filename`),
  KEY `media_processing_logs_job_id_index` (`job_id`),
  CONSTRAINT `media_processing_logs_church_service_id_foreign` FOREIGN KEY (`church_service_id`) REFERENCES `church_services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `media_processing_logs_owner_user_id_foreign` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `media_processing_logs_sermon_id_foreign` FOREIGN KEY (`sermon_id`) REFERENCES `sermons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `media_processing_logs_duration_check` CHECK (((`duration` >= 0) or (`duration` is null))),
  CONSTRAINT `media_processing_logs_file_size_check` CHECK (((`file_size` >= 0) or (`file_size` is null))),
  CONSTRAINT `media_processing_logs_original_filename_format_check` CHECK (((cast(`original_filename` as char charset binary) = trim(`original_filename`)) and (`original_filename` <> _utf8mb4''))),
  CONSTRAINT `media_processing_logs_sermon_time_range_check` CHECK (((`sermon_start_time` is null) or (`sermon_end_time` is null) or ((`sermon_start_time` >= 0) and (`sermon_end_time` >= 0) and (`sermon_end_time` >= `sermon_start_time`)))),
  CONSTRAINT `media_processing_logs_visual_processing_time_check` CHECK (((`visual_processing_time` >= 0) or (`visual_processing_time` is null))),
  CONSTRAINT `media_processing_logs_visual_sample_count_check` CHECK (((`visual_sample_count` >= 0) or (`visual_sample_count` is null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `meetings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meetings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `page_id` int unsigned DEFAULT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('SundayAndBibleStudies','ChildrenAndYoungPeople','Adults','Occasional') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `meeting_date` datetime DEFAULT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT '0',
  `frequency` enum('daily','weekly','monthly','annually') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `day` varchar(75) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `location` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `who` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `pictures` tinyint(1) NOT NULL,
  `leaders_phone` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `leaders_email` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meetings_slug_unique` (`slug`),
  UNIQUE KEY `meetings_page_id_unique` (`page_id`),
  KEY `meetings_type_day_index` (`type`,`day`),
  KEY `meetings_meeting_date_index` (`meeting_date`),
  KEY `meetings_is_recurring_index` (`is_recurring`),
  CONSTRAINT `meetings_page_id_foreign` FOREIGN KEY (`page_id`) REFERENCES `pages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `meetings_day_format_check` CHECK (((`day` is null) or ((cast(`day` as char charset binary) = trim(`day`)) and (`day` <> _utf8mb3'')))),
  CONSTRAINT `meetings_leaders_email_format_check` CHECK (((`leaders_email` is null) or ((cast(`leaders_email` as char charset binary) = lower(trim(`leaders_email`))) and (`leaders_email` <> _utf8mb4'')))),
  CONSTRAINT `meetings_leaders_phone_format_check` CHECK (((`leaders_phone` is null) or ((cast(`leaders_phone` as char charset binary) = trim(`leaders_phone`)) and (`leaders_phone` <> _utf8mb3'')))),
  CONSTRAINT `meetings_location_format_check` CHECK (((`location` is null) or ((cast(`location` as char charset binary) = trim(`location`)) and (`location` <> _utf8mb3'')))),
  CONSTRAINT `meetings_recurring_frequency_check` CHECK (((`is_recurring` = 0) or (`frequency` is not null))),
  CONSTRAINT `meetings_slug_format_check` CHECK (regexp_like(`slug`,_utf8mb4'^[a-z0-9]+(?:-[a-z0-9]+)*$',_utf8mb4'c')),
  CONSTRAINT `meetings_time_check` CHECK (((`end_time` >= `start_time`) or (`end_time` is null) or (`start_time` is null))),
  CONSTRAINT `meetings_who_format_check` CHECK (((cast(`who` as char charset binary) = trim(`who`)) and (`who` <> _utf8mb3'')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `migration` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `batch` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `monitored_scheduled_task_log_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitored_scheduled_task_log_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `monitored_scheduled_task_id` bigint unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_scheduled_task_id` (`monitored_scheduled_task_id`),
  CONSTRAINT `fk_scheduled_task_id` FOREIGN KEY (`monitored_scheduled_task_id`) REFERENCES `monitored_scheduled_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `monitored_scheduled_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitored_scheduled_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cron_expression` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timezone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ping_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_started_at` datetime DEFAULT NULL,
  `last_finished_at` datetime DEFAULT NULL,
  `last_failed_at` datetime DEFAULT NULL,
  `last_skipped_at` datetime DEFAULT NULL,
  `registered_on_oh_dear_at` datetime DEFAULT NULL,
  `last_pinged_at` datetime DEFAULT NULL,
  `grace_time_in_minutes` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `heading` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `area` enum('christ','church','community','members','sermons') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `admin` enum('yes','no') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'no',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `markdown` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `navigation` tinyint(1) NOT NULL,
  `sort_order` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pages_area_slug_unique` (`area`,`slug`),
  KEY `pages_area_index` (`area`),
  KEY `pages_navigation_index` (`navigation`),
  CONSTRAINT `pages_description_format_check` CHECK (((cast(`description` as char charset binary) = trim(`description`)) and (`description` <> _utf8mb4''))),
  CONSTRAINT `pages_heading_format_check` CHECK (((cast(`heading` as char charset binary) = trim(`heading`)) and (`heading` <> _utf8mb3''))),
  CONSTRAINT `pages_slug_format_check` CHECK (regexp_like(`slug`,_utf8mb3'^[a-z0-9]+(?:-[a-z0-9]+)*$',_utf8mb4'c')),
  CONSTRAINT `pages_sort_order_check` CHECK (((`sort_order` >= 0) or (`sort_order` is null)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
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
DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `email` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  KEY `password_resets_email_index` (`email`),
  KEY `password_resets_token_index` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
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
DROP TABLE IF EXISTS `play_date`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `play_date` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `song_id` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `time` enum('a','p') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
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
  CONSTRAINT `preacher_aliases_preacher_id_foreign` FOREIGN KEY (`preacher_id`) REFERENCES `preachers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `preacher_aliases_alias_format_check` CHECK (((cast(`alias` as char charset binary) = lower(trim(`alias`))) and (`alias` <> _utf8mb4'')))
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
  KEY `preachers_is_active_index` (`is_active`),
  CONSTRAINT `preachers_name_format_check` CHECK (((cast(`name` as char charset binary) = trim(`name`)) and (`name` <> _utf8mb4''))),
  CONSTRAINT `preachers_slug_format_check` CHECK (regexp_like(`slug`,_utf8mb4'^[a-z0-9]+(?:-[a-z0-9]+)*$',_utf8mb4'c'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scripture_passages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scripture_passages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bible_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_passage_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `display_reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `html_content` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `copyright` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fums_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fetched_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scripture_passages_bible_id_normalized_reference_unique` (`bible_id`,`normalized_reference`),
  KEY `scripture_passages_fetched_at_index` (`fetched_at`),
  CONSTRAINT `scripture_passages_bible_id_check` CHECK (((cast(`bible_id` as char charset binary) = trim(`bible_id`)) and (`bible_id` <> _utf8mb4''))),
  CONSTRAINT `scripture_passages_copyright_check` CHECK (((cast(`copyright` as char charset binary) = trim(`copyright`)) and (`copyright` <> _utf8mb4''))),
  CONSTRAINT `scripture_passages_html_content_check` CHECK (((cast(`html_content` as char charset binary) = trim(`html_content`)) and (`html_content` <> _utf8mb4''))),
  CONSTRAINT `scripture_passages_normalized_reference_check` CHECK (((cast(`normalized_reference` as char charset binary) = trim(`normalized_reference`)) and (`normalized_reference` <> _utf8mb4'')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scripture_references`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scripture_references` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(11) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `song_id` smallint NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sermon_processing_steps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sermon_processing_steps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `processing_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `step` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('started','completed','skipped','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
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
DROP TABLE IF EXISTS `sermon_scripture_filters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sermon_scripture_filters` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sermon_id` int unsigned NOT NULL,
  `bible_book` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bible_chapter` smallint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sermon_scripture_filters_unique` (`sermon_id`,`bible_book`,`bible_chapter`),
  KEY `sermon_scripture_filters_book_chapter_sermon_index` (`bible_book`,`bible_chapter`,`sermon_id`),
  KEY `sermon_scripture_filters_book_sermon_index` (`bible_book`,`sermon_id`),
  CONSTRAINT `sermon_scripture_filters_sermon_id_foreign` FOREIGN KEY (`sermon_id`) REFERENCES `sermons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sermon_scripture_filters_bible_book_format_check` CHECK (((cast(`bible_book` as char charset binary) = trim(`bible_book`)) and (`bible_book` <> _utf8mb4''))),
  CONSTRAINT `sermon_scripture_filters_bible_chapter_check` CHECK ((`bible_chapter` > 0))
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
  `content_type` enum('sermon','childrens_talk') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'sermon',
  `audio_file_path` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `video_file_path` varchar(500) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `video_quality_status` enum('unassessed','approved','rejected','needs_review') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'unassessed',
  `video_quality_reason` varchar(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `video_visibility_override` enum('default','force_show','force_hide') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'default',
  `video_quality_assessed_at` timestamp NULL DEFAULT NULL,
  `source_type` enum('manual','audio_upload','livestream','video_upload') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'manual',
  `segment_start_time` decimal(10,3) DEFAULT NULL,
  `segment_end_time` decimal(10,3) DEFAULT NULL,
  `duration` double DEFAULT NULL,
  `filetype` varchar(8) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'mp3',
  `title` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `reference` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL COMMENT 'Denormalized scripture reference cache for display/search compatibility. scripture_passage_id is the canonical normalized identity when present.',
  `scripture_passage_id` bigint unsigned DEFAULT NULL COMMENT 'Canonical normalized scripture identity for the published sermon. The reference text column is a synchronized cache.',
  `download_count` int unsigned NOT NULL DEFAULT '0',
  `preacher` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'Mark Drury' COMMENT 'Denormalized preacher display cache. preacher_id is the canonical preacher identity for the published sermon.',
  `preacher_id` bigint unsigned DEFAULT NULL COMMENT 'Canonical preacher identity for the published sermon. The preacher text column is a synchronized cache.',
  `preacher_source` enum('id3','speaker_model','manual','default') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `preacher_confidence` double DEFAULT NULL,
  `needs_preacher_review` tinyint(1) NOT NULL DEFAULT '0',
  `series` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `points` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `show_points` tinyint(1) NOT NULL DEFAULT '0',
  `transcript_file_path` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `thumbnail_file_path` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `thumbnail_generated_at` timestamp NULL DEFAULT NULL,
  `thumbnail_metadata` json DEFAULT NULL,
  `summary` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `meta_description` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `show_summary` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `sermons_slug_unique` (`slug`),
  KEY `sermons_date_service_index` (`date`,`service`),
  KEY `sermons_preacher_index` (`preacher`),
  KEY `sermons_series_index` (`series`),
  KEY `sermons_livestream_processing_id_index` (`livestream_processing_id`),
  KEY `sermons_source_type_index` (`source_type`),
  KEY `sermons_content_type_index` (`content_type`),
  KEY `sermons_scripture_passage_id_foreign` (`scripture_passage_id`),
  KEY `sermons_needs_preacher_review_index` (`needs_preacher_review`),
  KEY `sermons_date_index` (`date`),
  KEY `sermons_video_quality_status_index` (`video_quality_status`),
  KEY `sermons_download_count_index` (`download_count`),
  KEY `sermons_preacher_id_foreign` (`preacher_id`),
  KEY `sermons_preacher_id_date_index` (`preacher_id`,`date`),
  CONSTRAINT `sermons_livestream_processing_id_foreign` FOREIGN KEY (`livestream_processing_id`) REFERENCES `media_processing_logs` (`processing_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `sermons_preacher_id_foreign` FOREIGN KEY (`preacher_id`) REFERENCES `preachers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sermons_scripture_passage_id_foreign` FOREIGN KEY (`scripture_passage_id`) REFERENCES `scripture_passages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `sermons_audio_file_path_format_check` CHECK (((`audio_file_path` <> _utf8mb3'') and (cast(`audio_file_path` as char charset binary) = trim(`audio_file_path`)))),
  CONSTRAINT `sermons_download_count_check` CHECK ((`download_count` >= 0)),
  CONSTRAINT `sermons_duration_check` CHECK (((`duration` >= 0) or (`duration` is null))),
  CONSTRAINT `sermons_preacher_confidence_check` CHECK (((`preacher_confidence` >= 0) and (`preacher_confidence` <= 1))),
  CONSTRAINT `sermons_preacher_format_check` CHECK (((cast(`preacher` as char charset binary) = trim(`preacher`)) and (`preacher` <> _utf8mb3''))),
  CONSTRAINT `sermons_reference_format_check` CHECK (((`reference` is null) or ((cast(`reference` as char charset binary) = trim(`reference`)) and (`reference` <> _utf8mb3'')))),
  CONSTRAINT `sermons_series_format_check` CHECK (((`series` is null) or ((cast(`series` as char charset binary) = trim(`series`)) and (`series` <> _utf8mb3'')))),
  CONSTRAINT `sermons_slug_format_check` CHECK (regexp_like(`slug`,_utf8mb3'^[a-z0-9]+(?:-[a-z0-9]+)*$',_utf8mb4'c')),
  CONSTRAINT `sermons_timing_invariants_check` CHECK (((`segment_start_time` >= 0) and ((`segment_end_time` >= `segment_start_time`) or (`segment_end_time` is null) or (`segment_start_time` is null)))),
  CONSTRAINT `sermons_title_format_check` CHECK (((cast(`title` as char charset binary) = trim(`title`)) and (`title` <> _utf8mb3'')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sermons_prod_import`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sermons_prod_import` (
  `livestream_processing_id` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date` date NOT NULL,
  `service` enum('morning','evening','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content_type` enum('sermon','childrens_talk') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'sermon',
  `audio_file_path` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `video_file_path` varchar(500) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `video_quality_status` enum('unassessed','approved','rejected','needs_review') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'unassessed',
  `video_quality_reason` varchar(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `video_visibility_override` enum('default','force_show','force_hide') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'default',
  `video_quality_assessed_at` timestamp NULL DEFAULT NULL,
  `source_type` enum('manual','audio_upload','livestream','video_upload') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'manual',
  `segment_start_time` decimal(10,3) DEFAULT NULL,
  `segment_end_time` decimal(10,3) DEFAULT NULL,
  `duration` double DEFAULT NULL,
  `filetype` varchar(8) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'mp3',
  `title` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `reference` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL COMMENT 'Denormalized scripture reference cache for display/search compatibility. scripture_passage_id is the canonical normalized identity when present.',
  `scripture_passage_id` bigint unsigned DEFAULT NULL COMMENT 'Canonical normalized scripture identity for the published sermon. The reference text column is a synchronized cache.',
  `download_count` int unsigned NOT NULL DEFAULT '0',
  `preacher` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'Mark Drury' COMMENT 'Denormalized preacher display cache. preacher_id is the canonical preacher identity for the published sermon.',
  `preacher_id` bigint unsigned DEFAULT NULL COMMENT 'Canonical preacher identity for the published sermon. The preacher text column is a synchronized cache.',
  `preacher_source` enum('id3','speaker_model','manual','default') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `preacher_confidence` double DEFAULT NULL,
  `needs_preacher_review` tinyint(1) NOT NULL DEFAULT '0',
  `series` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `points` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `show_points` tinyint(1) NOT NULL DEFAULT '0',
  `transcript_file_path` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `thumbnail_file_path` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `thumbnail_generated_at` timestamp NULL DEFAULT NULL,
  `thumbnail_metadata` json DEFAULT NULL,
  `summary` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `meta_description` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `show_summary` tinyint(1) NOT NULL DEFAULT '0',
  UNIQUE KEY `sermons_slug_unique` (`slug`),
  KEY `sermons_date_service_index` (`date`,`service`),
  KEY `sermons_preacher_index` (`preacher`),
  KEY `sermons_series_index` (`series`),
  KEY `sermons_livestream_processing_id_index` (`livestream_processing_id`),
  KEY `sermons_source_type_index` (`source_type`),
  KEY `sermons_content_type_index` (`content_type`),
  KEY `sermons_scripture_passage_id_foreign` (`scripture_passage_id`),
  KEY `sermons_needs_preacher_review_index` (`needs_preacher_review`),
  KEY `sermons_date_index` (`date`),
  KEY `sermons_video_quality_status_index` (`video_quality_status`),
  KEY `sermons_download_count_index` (`download_count`),
  KEY `sermons_preacher_id_foreign` (`preacher_id`),
  CONSTRAINT `sermons_prod_import_chk_1` CHECK (((`audio_file_path` <> _utf8mb3'') and (cast(`audio_file_path` as char charset binary) = trim(`audio_file_path`)))),
  CONSTRAINT `sermons_prod_import_chk_2` CHECK ((`download_count` >= 0)),
  CONSTRAINT `sermons_prod_import_chk_3` CHECK (((`duration` >= 0) or (`duration` is null))),
  CONSTRAINT `sermons_prod_import_chk_4` CHECK (((`preacher_confidence` >= 0) and (`preacher_confidence` <= 1))),
  CONSTRAINT `sermons_prod_import_chk_5` CHECK (((`series` is null) or ((cast(`series` as char charset binary) = trim(`series`)) and (`series` <> _utf8mb3'')))),
  CONSTRAINT `sermons_prod_import_chk_6` CHECK (regexp_like(`slug`,_utf8mb3'^[a-z0-9]+(?:-[a-z0-9]+)*$',_utf8mb4'c')),
  CONSTRAINT `sermons_prod_import_chk_7` CHECK (((`segment_start_time` >= 0) and ((`segment_end_time` >= `segment_start_time`) or (`segment_end_time` is null) or (`segment_start_time` is null)))),
  CONSTRAINT `sermons_prod_import_chk_8` CHECK (((cast(`title` as char charset binary) = trim(`title`)) and (`title` <> _utf8mb3'')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_sections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `media_processing_log_id` bigint unsigned NOT NULL,
  `church_service_item_id` bigint unsigned DEFAULT NULL,
  `section_type` enum('welcome','prayer','notices','song','childrens_talk','bible_reading','sermon','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `section_order` int unsigned NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `start_time` double NOT NULL,
  `end_time` double NOT NULL,
  `duration` double NOT NULL,
  `status` enum('identified') COLLATE utf8mb4_unicode_ci NOT NULL,
  `needs_manual_review` tinyint(1) NOT NULL DEFAULT '0',
  `source_segment_ids` json NOT NULL,
  `metadata` json DEFAULT NULL,
  `song_match_type` enum('confirmed','inferred','unmatched') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `matched_item_id` bigint unsigned DEFAULT NULL,
  `expected_item_id` bigint unsigned DEFAULT NULL,
  `confidence` decimal(4,3) DEFAULT NULL,
  `publication_status` enum('not_applicable','pending_approval','approved','rejected','published') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_applicable',
  `published_sermon_id` int unsigned DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `extracted_video_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extracted_audio_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  CONSTRAINT `service_sections_expected_item_id_foreign` FOREIGN KEY (`expected_item_id`) REFERENCES `church_service_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_sections_matched_item_id_foreign` FOREIGN KEY (`matched_item_id`) REFERENCES `church_service_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `service_sections_media_processing_log_id_foreign` FOREIGN KEY (`media_processing_log_id`) REFERENCES `media_processing_logs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_sections_published_sermon_id_foreign` FOREIGN KEY (`published_sermon_id`) REFERENCES `sermons` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `service_sections_confidence_range_check` CHECK (((`confidence` is null) or ((`confidence` >= 0.000) and (`confidence` <= 1.000)))),
  CONSTRAINT `service_sections_publication_link_check` CHECK ((((`publication_status` = _utf8mb4'published') and (`published_at` is not null)) or ((`publication_status` <> _utf8mb4'published') and (`published_sermon_id` is null) and (`published_at` is null)))),
  CONSTRAINT `service_sections_publication_media_check` CHECK ((((`publication_status` in (_utf8mb4'approved',_utf8mb4'published')) and (`extracted_video_path` is not null) and (`extracted_at` is not null) and ((`section_type` = _utf8mb4'song') or (`extracted_audio_path` is not null))) or (`publication_status` in (_utf8mb4'not_applicable',_utf8mb4'pending_approval',_utf8mb4'rejected')))),
  CONSTRAINT `service_sections_timing_invariants_check` CHECK (((`start_time` >= 0) and (`end_time` > `start_time`) and (`duration` >= 0) and (abs(((`end_time` - `start_time`) - `duration`)) <= 0.050)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `payload` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `ip_address` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  UNIQUE KEY `sessions_id_unique` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `song_author_song`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `song_author_song` (
  `song_id` bigint unsigned NOT NULL,
  `song_author_id` bigint unsigned NOT NULL,
  `author_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  KEY `song_author_song_song_id_index` (`song_id`),
  KEY `song_author_song_song_author_id_index` (`song_author_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `song_authors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `song_authors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `display_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `song_authors_display_name_unique` (`display_name`),
  CONSTRAINT `song_authors_display_name_check` CHECK ((`display_name` <> _utf8mb4'')),
  CONSTRAINT `song_authors_display_name_format_check` CHECK (((cast(`display_name` as char charset binary) = trim(`display_name`)) and (`display_name` <> _utf8mb4''))),
  CONSTRAINT `song_authors_first_name_format_check` CHECK (((`first_name` is null) or ((cast(`first_name` as char charset binary) = trim(`first_name`)) and (`first_name` <> _utf8mb4'')))),
  CONSTRAINT `song_authors_last_name_format_check` CHECK (((`last_name` is null) or ((cast(`last_name` as char charset binary) = trim(`last_name`)) and (`last_name` <> _utf8mb4''))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `song_book_song`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `song_book_song` (
  `song_id` int unsigned NOT NULL,
  `song_book_id` bigint unsigned NOT NULL,
  `entry` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
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
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `publisher` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `song_books_source_book_id_unique` (`source_book_id`),
  CONSTRAINT `song_books_name_format_check` CHECK (((cast(`name` as char charset binary) = trim(`name`)) and (`name` <> _utf8mb4''))),
  CONSTRAINT `song_books_publisher_format_check` CHECK (((`publisher` is null) or ((cast(`publisher` as char charset binary) = trim(`publisher`)) and (`publisher` <> _utf8mb4''))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `song_videos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `song_videos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `song_id` int unsigned NOT NULL,
  `service_section_id` bigint unsigned DEFAULT NULL,
  `church_service_id` bigint unsigned DEFAULT NULL,
  `video_file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration` double DEFAULT NULL,
  `recorded_date` date DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `song_videos_service_section_id_unique` (`service_section_id`),
  KEY `song_videos_church_service_id_foreign` (`church_service_id`),
  KEY `song_videos_song_id_is_featured_index` (`song_id`,`is_featured`),
  KEY `song_videos_song_id_recorded_date_index` (`song_id`,`recorded_date`),
  CONSTRAINT `song_videos_church_service_id_foreign` FOREIGN KEY (`church_service_id`) REFERENCES `church_services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `song_videos_service_section_id_foreign` FOREIGN KEY (`service_section_id`) REFERENCES `service_sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `song_videos_song_id_foreign` FOREIGN KEY (`song_id`) REFERENCES `songs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `song_videos_duration_check` CHECK (((`duration` >= 0) or (`duration` is null))),
  CONSTRAINT `song_videos_video_file_path_format_check` CHECK (((`video_file_path` <> _utf8mb4'') and (cast(`video_file_path` as char charset binary) = trim(`video_file_path`))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `songs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `songs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `praise_number` varchar(5) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `title` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `author` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `lyrics` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `copyright` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `alternative_title` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `current` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `major_category` enum('Psalms','Approaching God','Children’s','Christ’s Lordship over all of life','The Bible','The Christian life','The church','The Father','The future','The gospel','The Holy Spirit','The Son') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `minor_category` enum('The eternal Trinity','Adoration and thanksgiving','Creator and sustainer','Morning and evening','The Lord’s Day','Beginning and ending of the year','His character','His providence','His love','His covenant','His name and praise','His birth and childhood','His life and ministry','His suffering and death','His resurrection','His ascension and reign','His priesthood and intercession','His return in glory','His person and power','His presence in the church','His work in revival','Authority and sufficiency','Enjoyment and obedience','Character and privileges','Fellowship','Gifts and ministries','The life of prayer','Evangelism and mission','Baptism','The Lord’s Supper','Invitation and warning','Crying out for God','New birth and new life','Repentance and faith','Union with Christ','Love for Christ','Freedom in Christ','Submission and trust','Assurance and hope','Peace and joy','Holiness','Humbling and restoration','Commitment and obedience','Zeal in service','Guidance','Suffering and trial','Spiritual warfare','Perseverance','Facing death','The earth and harvest','Christian citizenship','Christian marriage','Families and children','Health and healing','Work and leisure','Those in need','Government and nations','The resurrection of the body','Judgement and hell','Heaven and glory') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `canonical_key` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `first_line_key` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `slug` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `alternate_title` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `lyrics_xml` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `lyrics_plain` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `verse_order` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `comments` longtext CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `ccli_number` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `import_metadata` json DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `songs_slug_unique` (`slug`),
  UNIQUE KEY `songs_canonical_key_unique` (`canonical_key`),
  KEY `songs_ccli_number_index` (`ccli_number`),
  KEY `songs_deleted_at_index` (`deleted_at`),
  KEY `songs_title_index` (`title`),
  KEY `songs_first_line_key_index` (`first_line_key`),
  FULLTEXT KEY `songs_lyrics_plain_fulltext` (`lyrics_plain`),
  CONSTRAINT `songs_alternate_title_check` CHECK (((`alternate_title` is null) or ((cast(`alternate_title` as char charset binary) = trim(`alternate_title`)) and (`alternate_title` <> _utf8mb3'')))),
  CONSTRAINT `songs_canonical_key_check` CHECK (((cast(`canonical_key` as char charset binary) = lower(trim(regexp_replace(`canonical_key`,_utf8mb3'[[:space:]]+',_utf8mb4' ')))) and (`canonical_key` <> _utf8mb3'') and (locate(_utf8mb3'@',`canonical_key`) = 0))),
  CONSTRAINT `songs_lyrics_xml_check` CHECK ((`lyrics_xml` <> _utf8mb3'')),
  CONSTRAINT `songs_slug_format_check` CHECK (regexp_like(`slug`,_utf8mb3'^[a-z0-9]+(?:-[a-z0-9]+)*$',_utf8mb4'c')),
  CONSTRAINT `songs_title_check` CHECK (((cast(`title` as char charset binary) = trim(`title`)) and (`title` <> _utf8mb3'')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
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
  CONSTRAINT `speaker_profiles_preacher_id_foreign` FOREIGN KEY (`preacher_id`) REFERENCES `preachers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `speaker_profiles_accept_threshold_check` CHECK (((`accept_threshold` >= 0) and (`accept_threshold` <= 1))),
  CONSTRAINT `speaker_profiles_margin_threshold_check` CHECK (((`margin_threshold` >= 0) and (`margin_threshold` <= 1))),
  CONSTRAINT `speaker_profiles_quality_score_check` CHECK (((`quality_score` >= 0) and (`quality_score` <= 1)))
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
  KEY `speaker_samples_approved_index` (`approved`),
  CONSTRAINT `speaker_samples_media_processing_log_id_foreign` FOREIGN KEY (`media_processing_log_id`) REFERENCES `media_processing_logs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `speaker_samples_sermon_id_foreign` FOREIGN KEY (`sermon_id`) REFERENCES `sermons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `speaker_samples_speaker_profile_id_foreign` FOREIGN KEY (`speaker_profile_id`) REFERENCES `speaker_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `speaker_samples_duration_check` CHECK ((`duration_seconds` >= 0)),
  CONSTRAINT `speaker_samples_quality_score_check` CHECK (((`quality_score` >= 0) and (`quality_score` <= 1)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(60) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `remember_token` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_created_at_index` (`created_at`),
  KEY `users_is_admin_index` (`is_admin`),
  CONSTRAINT `users_email_format_check` CHECK (((cast(`email` as char charset binary) = lower(trim(`email`))) and (`email` <> _utf8mb4''))),
  CONSTRAINT `users_name_format_check` CHECK (((cast(`name` as char charset binary) = trim(`name`)) and (`name` <> _utf8mb3'')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2014_02_04_224912_create_sermons_table',1);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2014_10_12_000000_create_users_table',1);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2014_10_12_100000_create_password_resets_table',1);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2015_01_10_180038_create_session_table',1);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2015_02_08_213811_create_pages_table',1);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2015_02_18_210750_create_songs_table',1);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2015_02_18_211526_other_songs_tables',1);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2015_03_03_221128_create_documents_table',1);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2015_06_28_114208_create_meetings_table',1);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2016_02_17_213441_add_fields_to_sessions_table',2);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2016_02_20_163912_add_alternative_title_to_songs_table',2);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2016_03_05_115035_adjust_play_date_table',3);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2016_10_12_074709_update_songs_table',3);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2016_10_15_212903_update_songs',3);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2014_10_12_100000_create_password_reset_tokens_table',4);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2016_12_06_215617_change_recommended_to_current_songs_table',5);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2016_12_06_220437_add_notes_to_songs_table',5);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2016_12_07_212223_change_categories_to_enum_songs_table',5);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2016_12_07_215507_drop_old_categories_songs_table',5);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2017_01_14_230141_add_points_to_songs_table',5);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2017_01_19_232848_add_markdown_to_pages_table',5);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2017_01_28_194118_drop_columns_from_meeting_table',5);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2019_08_19_000000_create_failed_jobs_table',5);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2019_12_14_000001_create_personal_access_tokens_table',5);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2023_11_23_194306_add_navigation_to_pages_table',5);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2023_11_23_194306_create_jobs_table',6);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2024_07_09_000001_update_sermon_service_enum',6);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_01_30_100000_add_summary_to_sermons_table',6);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_06_29_151500_fix_users_table_timestamps',7);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_06_29_151647_add_email_verified_at_to_users_table',7);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_06_29_151648_add_event_fields_to_meetings_table',7);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_07_09_220128_add_missing_indexes_to_tables',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_07_14_201510_create_calendar_events_table',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_07_15_212543_create_sermon_processing_logs_table',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_07_15_212712_add_transcript_path_to_sermons_table',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_08_01_190139_create_livestream_processing_logs_table',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_08_01_190146_create_livestream_segments_table',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_08_01_190157_add_livestream_columns_to_sermons_table',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_08_07_150535_update_sermon_processing_logs_current_step_length',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_09_01_192100_add_adaptive_threshold_fields_to_livestream_processing_logs',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_09_01_204201_add_video_upload_source_type_to_sermons',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_09_02_201709_create_sermon_processing_steps_table',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_09_03_204510_update_livestream_processing_logs_status_enum',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_09_05_011042_extend_sermon_processing_logs_for_media_types',8);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_09_13_195343_add_thumbnail_fields_to_sermons_table',9);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_09_14_081431_add_processing_fields_to_sermon_processing_logs',9);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_10_02_082440_add_audio_file_path_to_sermon_processing_logs',10);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_10_02_140532_create_media_processing_logs_table',10);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_10_02_140717_update_livestream_segments_to_use_media_processing_log',10);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_10_02_143000_fix_livestream_segments_foreign_keys',11);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_10_03_071419_standardize_sermon_file_path_fields',11);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_10_12_143942_add_visibility_toggles_to_sermons_table',12);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2025_11_16_223228_add_visual_analysis_columns',13);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_01_14_221559_add_duration_to_sermons_table',14);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_01_14_add_meta_description_to_sermons_table',14);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_01_21_083314_create_media_table',14);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_01_21_170720_add_page_id_to_meetings_table',14);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_01_21_170857_create_pages_for_meetings',14);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_06_132545_rename_meetings_pascalcase_columns',15);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_14_202556_create_preachers_table',16);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_14_202603_create_preacher_aliases_table',16);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_14_202611_add_preacher_columns_to_sermons_table',17);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_15_205529_create_speaker_profiles_table',17);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_15_205611_create_speaker_samples_table',17);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_18_193615_create_job_batches_table',18);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_19_120000_add_owner_user_id_to_media_processing_logs_table',19);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_24_092832_cleanup_schema_h3_issues',20);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_26_214602_add_is_admin_to_users_table_if_missing',21);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_26_221409_make_meetings_location_nullable_if_required',22);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_26_221409_normalize_password_reset_tokens_email_key',22);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_28_160000_create_church_services_table',23);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_28_160100_create_church_service_items_table',23);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_28_170000_create_service_sections_table',23);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_28_170100_add_extracted_identity_to_media_processing_logs_table',23);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_28_180000_add_publication_columns_to_service_sections_table',23);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_28_190000_create_songs_table',24);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_28_190100_create_song_authors_table',24);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_28_190200_create_song_author_song_table',25);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_28_190300_create_song_books_table',25);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_28_190400_create_song_book_song_table',25);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_28_190500_add_song_id_to_church_service_items_table',25);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_02_28_233313_reconcile_song_catalog_schema',25);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_08_120000_add_church_service_id_to_media_processing_logs_table',26);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_08_190000_add_source_to_church_service_items_table',26);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_08_210000_create_inbound_emails_table',27);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_08_120000_add_confidence_to_service_sections_table',28);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_09_060939_increase_column_lengths_for_sermons_and_meetings',29);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_09_120000_add_confidence_range_constraint_to_service_sections_table',30);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_09_120000_add_content_type_to_sermons_table',31);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_09_220737_add_slug_to_songs_table',31);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_10_075905_add_foreign_key_to_calendar_events_table',32);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_11_064615_increase_audio_file_path_length_on_sermons_table',32);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_12_145040_add_foreign_key_to_sermons_livestream_processing_id',33);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_12_220000_add_skipped_to_sermon_processing_steps_status_enum',34);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_13_095602_fortify_processing_log_integrity',35);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_13_194852_create_scripture_passages_table',35);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_13_194853_add_scripture_passage_id_to_sermons_table',35);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_14_082820_add_unique_constraint_to_pages_table',36);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_15_064347_add_unique_constraint_to_preachers_name',37);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_16_064234_add_index_to_users_created_at',38);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_17_083035_add_unique_constraint_to_livestream_segments_table',39);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_18_000001_add_file_hash_to_media_processing_logs',39);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_19_032845_add_filtering_indexes_to_sermons_and_pages',40);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_19_063550_add_unique_constraint_to_speaker_profiles_table',41);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_20_064153_add_unique_constraint_to_meetings_page_id',42);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_19_050000_add_additive_schema_guardrails_for_processing_tables',43);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_19_070000_add_speaker_sample_uniqueness_and_processing_log_retention',43);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_19_080000_remove_legacy_schema_artifacts',44);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_22_080851_add_index_to_meetings_meeting_date',45);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_22_031306_add_admin_listing_filtering_indexes',46);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_22_120000_formalize_review_publication_state_and_ordering_invariants',47);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_22_120000_promote_service_reporting_state_to_columns',47);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_22_213105_formalize_sermon_identity_authority',48);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_23_064329_convert_meetings_frequency_to_enum',49);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_24_073723_formalize_enum_columns',50);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_25_073747_formalize_calendar_event_constraints',51);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_26_113203_create_song_videos_table',52);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_26_184927_relax_service_section_publication_constraints_for_song_videos',52);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_26_190837_add_livestream_to_church_service_items_source_enum',52);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_27_133500_widen_song_copyright_column',53);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_27_190333_add_enhanced_audio_file_path_to_media_processing_logs',54);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_27_064143_add_time_check_to_meetings_table',55);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_29_184610_add_integrity_checks_to_sermons_table',55);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_30_050839_fortify_sermons_and_service_sections_integrity',55);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_03_31_051644_add_integrity_checks_to_speaker_tables',55);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_01_051759_add_duration_check_constraints_to_media_tables',55);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_03_062616_add_recurring_frequency_check_to_meetings_table',55);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_04_150531_add_integrity_checks_to_song_catalog_tables',55);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_05_000000_fortify_media_analysis_integrity',55);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_07_000000_add_video_quality_fields_to_sermons_table',55);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_07_053541_fortify_service_sections_integrity',55);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_08_072242_add_download_count_to_sermons_table',55);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_10_191118_add_fulltext_index_to_songs_lyrics_plain',56);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_09_052654_add_integrity_checks_to_scripture_passages',57);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_12_051922_fortify_sermon_download_count',58);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_13_082111_formalize_pages_area_column',59);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_14_090000_create_sermon_scripture_filters_table',59);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_15_102525_add_slug_check_constraints_to_tables',60);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_16_052656_add_integrity_check_to_preacher_aliases_table',60);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_20_053821_add_audio_file_path_check_to_sermons_table',60);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_21_053509_add_integrity_checks_to_users_table',61);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_21_181615_drop_overly_strict_check_constraints',62);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_21_183829_make_sermon_audio_file_path_nullable',62);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_21_222948_add_dedup_key_to_media_processing_logs',62);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_22_054106_add_integrity_checks_to_sermon_scripture_filters_table',62);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_22_200126_add_is_degraded_completion_to_media_processing_logs',62);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_22_201250_add_queue_correlation_to_media_processing_logs',62);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_23_123518_add_pending_structure_merge_source_to_church_services_table',62);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_23_124718_add_livestream_columns_to_church_service_items_table',62);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_23_224514_drop_batch_id_from_media_processing_logs',63);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_23_192858_add_integrity_check_to_preacher_aliases_table',64);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_27_052406_add_text_integrity_checks',64);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_28_150435_fortify_song_slug_integrity',64);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_29_202817_add_foreign_key_to_sermons_preacher_id',65);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_29_073634_fortify_media_processing_logs_integrity',66);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_04_30_053545_add_date_index_to_sermons_table',66);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_01_100000_restore_sermon_scripture_passage_id_foreign_key',66);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_01_100100_add_integrity_check_to_church_service_items_table',66);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_04_080644_add_integrity_check_to_pages_description',66);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_02_054439_add_integrity_checks_to_calendar_events_table',67);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_03_215005_fortify_church_service_items_integrity',67);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_05_054017_add_integrity_checks_to_inbound_emails_table',67);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_08_115011_add_integrity_check_to_sermons_preacher',67);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_12_100000_fortify_song_authors_integrity',67);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_15_053846_fortify_song_books_integrity',68);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_13_051713_fortify_meetings_integrity',69);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_17_053346_add_foreign_key_to_church_services_manual_reviewed_by_user_id',69);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_20_054000_add_integrity_check_to_song_videos_table',69);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_24_052601_add_integrity_check_to_sermons_reference_table',70);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_23_053404_add_check_constraints_to_scripture_passages_table',71);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_05_26_165514_fortify_song_identity_columns',72);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_06_01_065205_add_timing_index_to_livestream_segments_table',73);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_06_02_203631_add_indexes_to_song_author_song_table',74);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_06_10_072047_add_job_id_index_to_media_processing_logs_table',75);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_06_10_165337_create_schedule_monitor_tables',75);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_06_10_165517_create_health_tables',75);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_06_14_200000_add_missing_fk_indexes_to_media_processing_logs_and_speaker_samples',76);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_06_16_054346_add_index_to_church_service_items_livestream_service_section_id',76);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_06_18_120000_drop_redundant_church_service_items_livestream_index',76);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_06_18_130000_drop_redundant_fk_indexes_from_media_processing_logs_and_speaker_samples',76);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_06_19_054923_add_preacher_id_date_index_to_sermons_table',77);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_06_27_065159_add_index_to_songs_title',78);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_06_28_192314_add_index_to_speaker_samples_approved',78);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_07_07_190047_add_first_line_key_to_songs_table',78);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_07_10_155218_add_archive_eval_to_inbound_emails_status_enum',79);
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_07_12_221655_remove_skipped_from_service_sections_status',80);

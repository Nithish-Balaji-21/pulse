/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cron_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cron_runs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `job_name` varchar(255) NOT NULL,
  `run_date` date NOT NULL,
  `ran_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_job_date` (`job_name`,`run_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `Customer_ID` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` bigint unsigned NOT NULL,
  `Customer_Name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `City` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Phone` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `GST_Number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`Customer_ID`),
  KEY `customers_team_id_index` (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` text NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` text NOT NULL,
  `exception` text NOT NULL,
  `failed_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` text NOT NULL,
  `options` text,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `queue` longtext NOT NULL,
  `payload` text NOT NULL,
  `attempts` int NOT NULL,
  `reserved_at` int DEFAULT NULL,
  `available_at` int NOT NULL,
  `created_at` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
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
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `type` varchar(20) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `is_read_individual` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_team_user_date` (`team_id`,`user_id`,`date`),
  KEY `fk_notifications_user` (`user_id`),
  CONSTRAINT `fk_notifications_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(100) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `abilities` text,
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_UNIQUE` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_alerts_device_health_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_alerts_device_health_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `client_id` varchar(10) NOT NULL,
  `timestamp` datetime NOT NULL,
  `mfm_status` varchar(10) NOT NULL DEFAULT 'OK',
  `mfm_fail_streak` int NOT NULL DEFAULT '0',
  `queue_count` int NOT NULL DEFAULT '0',
  `queue_status` varchar(10) NOT NULL DEFAULT 'NORMAL',
  `uart_status` varchar(10) NOT NULL DEFAULT 'OK',
  `machine_running` tinyint NOT NULL DEFAULT '0',
  `alert_triggered` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_alerts_heartbeat_monitor`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_alerts_heartbeat_monitor` (
  `client_id` varchar(10) NOT NULL,
  `last_seen` datetime NOT NULL,
  `current_status` varchar(10) NOT NULL DEFAULT 'ALIVE',
  `alert_sent` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`client_id`),
  KEY `idx_last_seen` (`last_seen`),
  KEY `idx_alert_sent` (`alert_sent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_asset_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_asset_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned DEFAULT NULL,
  `name` varchar(50) NOT NULL,
  `is_active` tinyint NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `team_id` (`team_id`),
  CONSTRAINT `pulse_asset_categories_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_asset_outwards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_asset_outwards` (
  `outward_id` int unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int unsigned DEFAULT NULL,
  `outward_type` varchar(50) DEFAULT NULL,
  `outward_date` date NOT NULL,
  `to_company` varchar(50) NOT NULL,
  `outward_quantity` int NOT NULL,
  `approved_by` varchar(50) DEFAULT NULL,
  `remarks` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`outward_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `pulse_asset_outwards_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `pulse_assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_asset_purchases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_asset_purchases` (
  `asset_purchase_id` int unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int unsigned DEFAULT NULL,
  `purchase_type` varchar(50) NOT NULL,
  `purchase_date` date NOT NULL,
  `from_company` varchar(50) NOT NULL,
  `purchased_quantity` int NOT NULL,
  `price` double DEFAULT NULL,
  `remarks` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`asset_purchase_id`),
  KEY `asset_id` (`asset_id`),
  CONSTRAINT `pulse_asset_purchases_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `pulse_assets` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_asset_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_asset_returns` (
  `return_id` int unsigned NOT NULL AUTO_INCREMENT,
  `outward_id` int unsigned DEFAULT NULL,
  `returned_date` date NOT NULL,
  `returned_quantity` int NOT NULL,
  `remarks` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`return_id`),
  KEY `outward_id` (`outward_id`),
  CONSTRAINT `pulse_asset_returns_ibfk_1` FOREIGN KEY (`outward_id`) REFERENCES `pulse_asset_outwards` (`outward_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_assets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned DEFAULT NULL,
  `asset_category_id` int unsigned DEFAULT NULL,
  `asset_name` varchar(50) NOT NULL,
  `state` tinyint NOT NULL,
  `remarks` text,
  `attachments` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UQ_team_asset_name` (`team_id`,`asset_name`),
  KEY `asset_category_id` (`asset_category_id`),
  CONSTRAINT `pulse_assets_ibfk_1` FOREIGN KEY (`asset_category_id`) REFERENCES `pulse_asset_categories` (`id`),
  CONSTRAINT `pulse_assets_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_attendance` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int NOT NULL,
  `staff_id` int NOT NULL,
  `date` date NOT NULL,
  `duration` float unsigned DEFAULT NULL,
  `salary` int DEFAULT NULL,
  `task_description` varchar(100) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_team_staff_date` (`team_id`,`staff_id`,`date`),
  KEY `idx_team_id_date` (`team_id`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_device_data`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_device_data` (
  `id` int NOT NULL AUTO_INCREMENT,
  `datetime` datetime NOT NULL,
  `device_id` int unsigned NOT NULL,
  `d4` mediumint unsigned DEFAULT NULL,
  `d5` mediumint unsigned DEFAULT NULL,
  `d6` mediumint unsigned DEFAULT NULL,
  `d7` mediumint unsigned DEFAULT NULL,
  `d8` mediumint unsigned DEFAULT NULL,
  `d9` mediumint unsigned DEFAULT NULL,
  `d10` mediumint unsigned DEFAULT NULL,
  `d11` mediumint DEFAULT NULL,
  `d12` mediumint DEFAULT NULL,
  `d13` mediumint DEFAULT NULL,
  `d14` mediumint DEFAULT NULL,
  `d15` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_device_datetime` (`device_id`,`datetime`),
  KEY `idx_device_data_at` (`device_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_device_data_cft_calc`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_device_data_cft_calc` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `device_id` int unsigned NOT NULL,
  `cft_date` date NOT NULL,
  `cft_datetime` datetime NOT NULL,
  `cft_counter` int NOT NULL,
  `is_duplicate` tinyint(1) NOT NULL DEFAULT '0',
  `shift_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_device_date_counter` (`device_id`,`cft_date`,`cft_counter`),
  KEY `idx_device_cft_datetime` (`device_id`,`cft_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_device_data_daily_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_device_data_daily_summary` (
  `id` int NOT NULL AUTO_INCREMENT,
  `device_id` int NOT NULL,
  `date` date NOT NULL,
  `last_d15` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_device_date` (`device_id`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_device_data_fibre_bales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_device_data_fibre_bales` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `device_id` int unsigned NOT NULL,
  `field_id` varchar(50) DEFAULT NULL COMMENT 'Maps to master_fibrebale_machines.field_id — e.g. d10, d11',
  `bale_date` date NOT NULL,
  `bale_datetime` datetime NOT NULL,
  `bales_counter` int NOT NULL,
  `is_duplicate` tinyint(1) NOT NULL DEFAULT '0',
  `eb_units_counter` double DEFAULT NULL,
  `shift_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_device_date_counter` (`device_id`,`bale_date`,`bales_counter`),
  KEY `idx_device_bale_datetime` (`device_id`,`bale_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_device_data_fibre_mega_bales_weight`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_device_data_fibre_mega_bales_weight` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `device_id` int unsigned NOT NULL,
  `bale_weight_date` date NOT NULL,
  `bale_weight_datetime` datetime NOT NULL,
  `bale_weight` double DEFAULT NULL,
  `shift_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_device_time_weight` (`device_id`,`bale_weight_datetime`,`bale_weight`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_device_data_growbags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_device_data_growbags` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `device_id` int unsigned NOT NULL,
  `field_id` varchar(20) NOT NULL DEFAULT '',
  `growbag_date` date NOT NULL,
  `growbag_datetime` datetime NOT NULL,
  `growbags_counter` int NOT NULL,
  `is_duplicate` tinyint(1) NOT NULL DEFAULT '0',
  `eb_units_counter` double DEFAULT NULL,
  `shift_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_device_date_counter` (`device_id`,`growbag_date`,`growbags_counter`),
  KEY `idx_device_growbag_datetime` (`device_id`,`growbag_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_device_data_pith_blocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_device_data_pith_blocks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `device_id` int unsigned NOT NULL,
  `block_date` date NOT NULL,
  `block_datetime` datetime NOT NULL,
  `blocks_counter` int NOT NULL,
  `is_duplicate` tinyint(1) NOT NULL DEFAULT '0',
  `eb_units_counter` double DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_device_date_counter` (`device_id`,`block_date`,`blocks_counter`),
  KEY `idx_device_block_datetime` (`device_id`,`block_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_device_data_vehicles_telemetry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_device_data_vehicles_telemetry` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) NOT NULL,
  `movement_time` datetime NOT NULL,
  `latitude` decimal(10,6) NOT NULL,
  `longitude` decimal(10,6) NOT NULL,
  `ignition` tinyint NOT NULL DEFAULT '0',
  `speed` decimal(5,2) DEFAULT '0.00',
  `movement_status` tinyint NOT NULL DEFAULT '0',
  `total_odometer` float DEFAULT '0',
  `trip_odometer` float DEFAULT '0',
  `analog_input_1` float DEFAULT '0',
  `battery_level` float DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_imei_time` (`imei`,`movement_time`),
  KEY `idx_imei_time` (`imei`,`movement_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_device_data_water_meters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_device_data_water_meters` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `device_id` bigint DEFAULT NULL,
  `field_id` varchar(50) NOT NULL,
  `meter_date` date NOT NULL,
  `meter_datetime` datetime NOT NULL,
  `litres_counter` int NOT NULL,
  `is_duplicate` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_device_date_counter` (`device_id`,`meter_date`,`litres_counter`) /*!80000 INVISIBLE */,
  KEY `idx_device_water_datetime` (`device_id`,`field_id`,`meter_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_device_data_water_tank_levels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_device_data_water_tank_levels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `device_id` bigint DEFAULT NULL,
  `field_id` varchar(50) NOT NULL,
  `level_date` date NOT NULL,
  `level_datetime` datetime NOT NULL,
  `available_height` decimal(5,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_level` (`device_id`,`field_id`,`level_date`),
  KEY `idx_water_levels_lookup` (`device_id`,`field_id`,`level_datetime` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_devices` (
  `team_id` int NOT NULL,
  `device_id` int unsigned NOT NULL,
  `module` varchar(20) DEFAULT 'pith',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `off_th` int NOT NULL DEFAULT '10',
  `eb_units` varchar(20) DEFAULT 'kWh',
  `idle_th` int NOT NULL DEFAULT '95',
  `efficient_th` int NOT NULL DEFAULT '230',
  `duration_device_msg` int NOT NULL DEFAULT '20',
  `duration_device_missing` int NOT NULL DEFAULT '65',
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`device_id`),
  UNIQUE KEY `device_id_UNIQUE` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_husks_daily_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_husks_daily_stock` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `team_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `prod_date` date DEFAULT NULL,
  `opening_stock` decimal(12,2) DEFAULT NULL,
  `husks_received` double DEFAULT NULL,
  `cft` double DEFAULT '0',
  `closing_stock` decimal(12,2) DEFAULT NULL,
  `husks_correction` double DEFAULT NULL,
  `correction_comment` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_team_date` (`team_id`,`date`),
  KEY `fk_husks_user` (`user_id`),
  CONSTRAINT `fk_husks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `trg_reconcile_before_update_stock` BEFORE UPDATE ON `pulse_husks_daily_stock` FOR EACH ROW BEGIN
    DECLARE husks_per_cft DECIMAL(12,6) DEFAULT 0;

    SELECT COALESCE(CAST(value AS DECIMAL(12,6)),0)
    INTO husks_per_cft
    FROM pulse_settings
    WHERE team_id = NEW.team_id
      AND module = 'pith'
      AND `key` = 'HUSKS_PER_CFT'
    LIMIT 1;

    SET NEW.closing_stock =
          COALESCE(NEW.opening_stock,0)
        + COALESCE(NEW.husks_received,0)
        + COALESCE(NEW.husks_correction,0)
        - (COALESCE(NEW.cft,0) * COALESCE(husks_per_cft,0));
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `pulse_master_growbag_machines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_master_growbag_machines` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `device_id` int unsigned NOT NULL,
  `field_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `machine_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bags_per_punch` int unsigned NOT NULL DEFAULT '1',
  `is_active` tinyint NOT NULL DEFAULT '1',
  `created_by` int unsigned DEFAULT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_machine` (`team_id`,`device_id`,`machine_name`),
  KEY `idx_team_device` (`team_id`,`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_master_minibale_machines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_master_minibale_machines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `team_id` bigint unsigned NOT NULL,
  `device_id` bigint unsigned NOT NULL,
  `field_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'e.g. d10, d11 — matches device_data_fibre_bales.field_id',
  `machine_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Human-readable label, e.g. M1, M2',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_team_device_field` (`team_id`,`device_id`,`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_master_water_meters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_master_water_meters` (
  `id` int NOT NULL AUTO_INCREMENT,
  `device_id` int unsigned NOT NULL,
  `field_id` varchar(50) NOT NULL,
  `consumption_name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tank` (`device_id`,`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_master_water_tanks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_master_water_tanks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `device_id` int unsigned NOT NULL,
  `field_id` varchar(50) NOT NULL,
  `tank_name` varchar(100) DEFAULT NULL,
  `capacity_litres` int DEFAULT NULL,
  `low_alert_percent` decimal(5,2) NOT NULL DEFAULT '5.00',
  `overflow_alert_percent` decimal(5,2) NOT NULL DEFAULT '98.00',
  `tank_type` varchar(50) DEFAULT NULL,
  `cyl_height` decimal(10,3) DEFAULT NULL,
  `cyl_diameter` decimal(10,3) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tank` (`device_id`,`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_pith_inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_pith_inventory` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pith_production_id` int unsigned DEFAULT NULL,
  `pith_location_id` int unsigned NOT NULL,
  `date` date DEFAULT NULL,
  `cft_offloaded` float DEFAULT NULL,
  `is_archived` tinyint NOT NULL DEFAULT '0',
  `taken_cft_calculated` float DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pith_production_id` (`pith_production_id`) /*!80000 INVISIBLE */,
  KEY `idx_loads_location_aggregates` (`pith_location_id`,`cft_offloaded`,`is_archived`,`taken_cft_calculated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_pith_inventory_summary`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_pith_inventory_summary` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `summary_date` date NOT NULL,
  `team_id` int unsigned NOT NULL,
  `location_id` int unsigned NOT NULL,
  `inward` decimal(10,2) NOT NULL DEFAULT '0.00',
  `outward` decimal(10,2) NOT NULL DEFAULT '0.00',
  `in_stock` decimal(10,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pith_inventory_summary` (`summary_date`,`team_id`,`location_id`),
  KEY `idx_location_summary_date` (`location_id`,`summary_date`),
  KEY `idx_team_summary_date` (`team_id`,`summary_date`),
  CONSTRAINT `pulse_pith_inventory_summary_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `pulse_pith_locations` (`id`),
  CONSTRAINT `pulse_pith_inventory_summary_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_pith_location_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_pith_location_groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_order` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_team_location_group` (`team_id`,`name`),
  CONSTRAINT `pulse_pith_location_groups_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_pith_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_pith_locations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `group_id` int unsigned DEFAULT NULL,
  `group_key` int unsigned GENERATED ALWAYS AS (coalesce(`group_id`,0)) VIRTUAL,
  `name` varchar(20) NOT NULL,
  `is_active` tinyint DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `low_stock_alert_value` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_team_group_location` (`team_id`,`group_key`,`name`),
  KEY `idx_locations_team` (`id`,`team_id`),
  KEY `pulse_pith_locations_group_id_foreign` (`group_id`),
  CONSTRAINT `pulse_pith_locations_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `pulse_pith_location_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_pith_stock_depletions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_pith_stock_depletions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pith_location_id` int unsigned NOT NULL,
  `depletion_date` date NOT NULL,
  `first_pith_inventory_id` int unsigned DEFAULT NULL,
  `last_pith_inventory_id` int unsigned DEFAULT NULL,
  `total_cft_offloaded` double DEFAULT NULL,
  `total_cft_taken_calculated` double DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_depletions_location_offloaded_taken` (`pith_location_id`,`depletion_date`,`total_cft_offloaded`,`total_cft_taken_calculated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `module` varchar(50) DEFAULT NULL,
  `key` varchar(50) DEFAULT NULL,
  `text` text,
  `value` text,
  `options` text,
  `value_remark` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `unit_team_key` (`team_id`,`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_shifts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `device_id` int DEFAULT NULL,
  `name` varchar(50) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_active` tinyint DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_team_shift_name` (`team_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_summary_fibre_mega_bales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_summary_fibre_mega_bales` (
  `id` int NOT NULL AUTO_INCREMENT,
  `team_id` int NOT NULL,
  `device_id` int NOT NULL,
  `shift_date` date NOT NULL,
  `shift_name` varchar(45) NOT NULL,
  `shift_id` int DEFAULT NULL,
  `bales_produced` int DEFAULT '0',
  `bales_weighed` double DEFAULT NULL,
  `weight` double DEFAULT NULL,
  `eb_units` double DEFAULT '0',
  `eb_price` double DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_device_date_name` (`device_id`,`shift_date`,`shift_name`),
  KEY `idx_device_date` (`device_id`,`shift_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_summary_growbags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_summary_growbags` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `growbag_machine_id` int unsigned NOT NULL,
  `machine_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shift_date` date NOT NULL,
  `shift_id` int unsigned NOT NULL,
  `shift_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bags_produced` int unsigned NOT NULL DEFAULT '0',
  `eb_units` double NOT NULL DEFAULT '0',
  `eb_price` double NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_growbags_shift` (`team_id`,`growbag_machine_id`,`shift_date`,`shift_id`),
  KEY `idx_team_date` (`team_id`,`shift_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_summary_mini_bales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_summary_mini_bales` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int NOT NULL,
  `minibale_machine_id` bigint unsigned DEFAULT NULL COMMENT 'FK to master_fibrebale_machines.id',
  `machine_name` varchar(100) DEFAULT NULL COMMENT 'Denormalised from master_fibrebale_machines for fast dashboard queries',
  `staff_id` int DEFAULT NULL,
  `device_id` int DEFAULT NULL,
  `shift_date` date NOT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `bales_expected` int DEFAULT NULL,
  `bales_produced` mediumint NOT NULL DEFAULT '0',
  `sold` int unsigned DEFAULT NULL,
  `correction` int NOT NULL DEFAULT '0',
  `correction_comment` varchar(150) DEFAULT NULL,
  `bales_in_stock` mediumint DEFAULT NULL,
  `comments` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pulse_summary_mini_bales_team_id_shift_date_unique` (`team_id`,`shift_date`),
  UNIQUE KEY `uniq_fibre_bales_team_date_shift_machine` (`team_id`,`shift_date`,`minibale_machine_id`),
  KEY `idx_team_date_in_stock` (`team_id`,`shift_date`,`bales_in_stock`) /*!80000 INVISIBLE */,
  KEY `idx_team_date_prod` (`team_id`,`shift_date`,`bales_produced`) /*!80000 INVISIBLE */
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_summary_pith_blocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_summary_pith_blocks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int NOT NULL,
  `staff_id` int DEFAULT NULL,
  `device_id` int DEFAULT NULL,
  `shift_date` date NOT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `blocks_expected` int DEFAULT NULL,
  `blocks_produced` int DEFAULT '0',
  `sold` int unsigned DEFAULT NULL,
  `correction` int NOT NULL DEFAULT '0',
  `correction_comment` varchar(150) DEFAULT NULL,
  `blocks_in_stock` mediumint DEFAULT NULL,
  `comments` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pith_blocks_team_date` (`team_id`,`shift_date`),
  KEY `idx_team_date_prod` (`team_id`,`shift_date`,`blocks_expected`),
  KEY `idx_team_date_in_stock` (`team_id`,`shift_date`,`blocks_in_stock`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_summary_pith_productions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_summary_pith_productions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `device_id` bigint DEFAULT NULL,
  `shift_date` date NOT NULL,
  `shift_id` int NOT NULL,
  `shift_name` varchar(255) NOT NULL,
  `staff_id` int DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `cft_expected` double DEFAULT NULL,
  `cft_produced` double DEFAULT NULL,
  `load_hrs` double DEFAULT NULL,
  `eb_units` double DEFAULT NULL,
  `eb_price` double DEFAULT NULL,
  `comments` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pith_productions` (`team_id`,`shift_date`,`shift_id`),
  KEY `idx_end_time` (`end_time`),
  KEY `idx_pith_productions_team_date_loads` (`team_id`,`shift_date`,`cft_produced`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `trg_reconcile_after_insert_expected` AFTER INSERT ON `pulse_summary_pith_productions` FOR EACH ROW BEGIN
                IF NEW.cft_expected IS NOT NULL THEN
                    CALL reconcile_husks_stock(NEW.team_id, NEW.shift_date);
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `trg_reconcile_after_insert_produced` AFTER INSERT ON `pulse_summary_pith_productions` FOR EACH ROW BEGIN
                CALL reconcile_husks_stock(NEW.team_id, NEW.shift_date);
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `trg_reconcile_after_update_production` AFTER UPDATE ON `pulse_summary_pith_productions` FOR EACH ROW BEGIN
                IF (NEW.cft_produced <> OLD.cft_produced 
                    OR (OLD.cft_produced IS NULL AND NEW.cft_produced IS NOT NULL))
                   THEN
                    CALL reconcile_husks_stock(NEW.team_id, NEW.shift_date);
                END IF;

                IF (NEW.cft_expected <> OLD.cft_expected 
                    OR (OLD.cft_expected IS NULL AND NEW.cft_expected IS NOT NULL))
                   AND NEW.cft_produced IS NULL THEN
                    CALL reconcile_husks_stock(NEW.team_id, NEW.shift_date);
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50003 TRIGGER `trg_reconcile_after_delete_production` AFTER DELETE ON `pulse_summary_pith_productions` FOR EACH ROW BEGIN
                CALL reconcile_husks_stock(OLD.team_id, OLD.shift_date);
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `pulse_summary_power_states`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_summary_power_states` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int DEFAULT NULL,
  `device_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `state` varchar(20) NOT NULL,
  `duration` mediumint NOT NULL,
  `eb_units` mediumint DEFAULT NULL,
  `shift_date` date DEFAULT NULL,
  `eb_price` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_device_date_state` (`device_id`,`date`,`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_summary_water_meter`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_summary_water_meter` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `device_id` int DEFAULT NULL,
  `field_id` varchar(50) DEFAULT NULL,
  `date` date NOT NULL,
  `litres_consumed` double DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_water_meter_consumptions` (`team_id`,`date`,`field_id`),
  KEY `idx_water_date_litres` (`team_id`,`date`,`litres_consumed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_superadmin_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_superadmin_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_type` enum('string','number','boolean','json','enum') NOT NULL DEFAULT 'string',
  `setting_value` text,
  `remarks` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_team_color_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_team_color_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `module` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `colors` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `team_color_settings_team_id_module_unique` (`team_id`,`module`),
  KEY `team_color_settings_module_index` (`module`),
  CONSTRAINT `team_color_settings_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_user_notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_user_notification_preferences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `team_id` int unsigned NOT NULL,
  `notification_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_email_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `is_push_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pulse_user_team_notif_unique` (`user_id`,`team_id`,`notification_type`),
  KEY `pulse_user_notification_preferences_team_id_foreign` (`team_id`),
  CONSTRAINT `pulse_user_notification_preferences_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pulse_user_notification_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_weather_data_daily`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_weather_data_daily` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `temperature` float NOT NULL,
  `humidity` float NOT NULL,
  `rain_intensity` float NOT NULL,
  `precipitation_probability` float NOT NULL,
  `windspeed` float NOT NULL,
  `wind_direction` float NOT NULL,
  `uv_index` float NOT NULL,
  `dew_point` float DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_weather_data_hourly`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_weather_data_hourly` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `date` date NOT NULL,
  `hour` tinyint NOT NULL,
  `temperature` float NOT NULL,
  `humidity` float NOT NULL,
  `rain_intensity` float NOT NULL,
  `precipitation_probability` float NOT NULL,
  `windspeed` float NOT NULL,
  `wind_direction` float NOT NULL,
  `uv_index` float NOT NULL,
  `dew_point` float NOT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_team_date_hour` (`team_id`,`date`,`hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(100) NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL,
  `user_agent` text,
  `payload` text NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_UNIQUE` (`id`),
  KEY `sessions_last_activity_index` (`last_activity`) /*!80000 INVISIBLE */,
  KEY `sessions_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `staffs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `staffs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `staff_name` varchar(50) NOT NULL,
  `is_active` tinyint NOT NULL DEFAULT '1',
  `salary_per_hr` float DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_team_staff` (`team_id`,`staff_name`),
  KEY `idx_staff_name` (`staff_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_address`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_address` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `type` enum('factory','billing','shipping') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'factory',
  `address_line_1` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_line_2` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pincode` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `team_address_team_id_foreign` (`team_id`),
  CONSTRAINT `team_address_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_invitations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_team_email` (`team_id`,`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_pulse`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_pulse` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `factory_address_id` int unsigned DEFAULT NULL,
  `modules` json DEFAULT NULL,
  `enable_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `coordinates` text COLLATE utf8mb4_unicode_ci,
  `license_valid_till` date DEFAULT NULL,
  `installed_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `team_pulse_team_id_unique` (`team_id`),
  KEY `team_pulse_factory_address_id_foreign` (`factory_address_id`),
  CONSTRAINT `team_pulse_factory_address_id_foreign` FOREIGN KEY (`factory_address_id`) REFERENCES `team_address` (`id`) ON DELETE SET NULL,
  CONSTRAINT `team_pulse_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_user` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `role` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `product_access` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_team_user` (`team_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teams` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `products` json DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_device_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_device_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `device_id` varchar(255) DEFAULT NULL,
  `user_id` int NOT NULL,
  `device_platform` varchar(255) DEFAULT NULL,
  `fcm_token` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `is_active` tinyint NOT NULL DEFAULT '1',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `is_superadmin` tinyint DEFAULT '0',
  `is_internal` tinyint DEFAULT '0',
  `password` text NOT NULL,
  `remember_token` text,
  `current_team_id` int unsigned DEFAULT NULL,
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `two_factor_secret` text,
  `two_factor_recovery_codes` text,
  `two_factor_confirmed_at` datetime DEFAULT NULL,
  `phone_number` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email` (`email`),
  UNIQUE KEY `phone_number` (`phone_number`),
  UNIQUE KEY `uniq_mobile` (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vehicles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `vehicles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `imei` varchar(20) NOT NULL,
  `register_number` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `imei` (`imei`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `weather_color_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `weather_color_codes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `min` float NOT NULL,
  `max` float NOT NULL,
  `title` varchar(50) DEFAULT NULL,
  `color` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `weather_conditions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `weather_conditions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(50) DEFAULT NULL,
  `classification` varchar(50) DEFAULT NULL,
  `min_value` float DEFAULT NULL,
  `max_value` float DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 DROP PROCEDURE IF EXISTS `reconcile_husks_stock` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
CREATE PROCEDURE `reconcile_husks_stock`(
    IN p_team_id INT,
    IN p_start_date DATE
)
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE cur_date DATE;
    DECLARE prev_closing DECIMAL(12,2) DEFAULT NULL;
    DECLARE husks_per_cft DECIMAL(12,6) DEFAULT 0;

    DECLARE cur CURSOR FOR
        SELECT date
        FROM pulse_husks_daily_stock
        WHERE team_id = p_team_id
          AND date >= p_start_date
        ORDER BY date ASC;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    SELECT CAST(value AS DECIMAL(12,6))
    INTO husks_per_cft
    FROM pulse_settings
    WHERE team_id = p_team_id
      AND module = 'pith'
      AND `key` = 'HUSKS_PER_CFT'
    LIMIT 1;

    OPEN cur;

    read_loop: LOOP
        FETCH cur INTO cur_date;

        IF done = 1 THEN
            LEAVE read_loop;
        END IF;

        UPDATE pulse_husks_daily_stock hds
        LEFT JOIN (
            SELECT
                sp.shift_date AS date,
                SUM(
                    CASE
                        WHEN sp.cft_produced IS NOT NULL
                        THEN sp.cft_produced
                        ELSE sp.cft_expected
                    END
                ) AS total_cft
            FROM pulse_summary_pith_productions sp
            WHERE sp.team_id = p_team_id
              AND sp.shift_date = cur_date
            GROUP BY sp.shift_date
        ) sub
            ON hds.date = sub.date
           AND hds.team_id = p_team_id
        SET
            hds.cft = COALESCE(sub.total_cft, 0),
            hds.opening_stock = IFNULL(prev_closing, hds.opening_stock),
            hds.closing_stock =
                IFNULL(prev_closing, hds.opening_stock)
                + COALESCE(hds.husks_received, 0)
                + COALESCE(hds.husks_correction, 0)
                - (COALESCE(sub.total_cft, 0) * husks_per_cft),
            hds.prod_date = hds.date
        WHERE hds.team_id = p_team_id
          AND hds.date = cur_date;

        SELECT closing_stock
        INTO prev_closing
        FROM pulse_husks_daily_stock
        WHERE team_id = p_team_id
          AND date = cur_date
        LIMIT 1;

    END LOOP;

    CLOSE cur;

END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2026_02_28_000000_initial_database_schema',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2026_05_12_120458_add_software_only_to_teams_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2026_05_28_000001_drop_bags_expected_from_summary_growbags',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_06_01_000001_add_low_stock_alert_value_to_pith_locations_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_05_29_000001_create_team_color_settings_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_06_03_000001_add_missing_timestamp_columns',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_06_03_000002_fix_timestamp_types_and_defaults',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_06_03_100000_migrate_existing_device_ids_data',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_06_03_200000_change_device_id_column_to_integer',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_06_04_000003_fix_created_by_updated_by_types',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_05_15_000001_create_customers_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_06_01_114738_create_master_fibrebale_machines_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_06_12_094900_rename_tables_to_pulse_prefix',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_06_02_000001_create_growbag_productions_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_06_03_000001_create_fibre_minibale_productions_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_06_05_151851_add_production_hours_to_growbag_productions_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_06_05_151928_add_production_hours_to_fibre_minibale_productions_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_06_10_151121_create_pulse_user_notification_preferences_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_06_11_161319_move_team_features_to_json_structure',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_06_12_162000_drop_legacy_husks_daily_stock',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_06_12_164500_update_husks_daily_stock_triggers',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_06_12_164900_force_update_husks_stock_db_objects',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_06_12_165500_update_reconcile_husks_stock_procedure',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_06_15_100000_rename_alert_and_telemetry_tables_to_pulse_prefix',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_06_15_161613_rename_settings_to_pulse_settings',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_06_10_000000_create_pith_inventory_summary_table',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_06_18_000001_rename_coiiot_settings_to_pulse_superadmin_settings',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_06_19_000000_add_indexes_to_pulse_alerts_heartbeat_monitor',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_06_24_161540_add_last_login_at_to_users_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_07_04_102859_migrate_historical_outward_to_cft',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_07_04_124217_drop_pulse_pith_units_and_columns',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_07_04_171431_update_pith_production_triggers_for_cft',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_07_05_174500_set_default_production_unit_to_cft',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_07_05_230000_update_reconcile_husks_stock_for_cft',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_07_05_233000_set_eb_units_per_load_for_pith_teams',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_07_07_154416_add_off_th_to_pulse_devices_table',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_07_11_160000_add_machine_shutdown_notification_preferences',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_07_16_095415_update_pith_settings_labels_and_defaults',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_07_16_123000_rename_loads_to_cft_in_pulse_husks_daily_stock',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_07_16_123500_update_husk_stock_reconciliation_procedure_and_trigger',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_07_19_210000_move_water_tank_alert_thresholds_to_master_tanks',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_07_19_233222_rename_pulse_pith_loads_to_pulse_pith_inventory',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_07_19_233622_rename_pith_load_id_columns_on_pulse_pith_stock_depletions',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_07_20_165213_update_day_shift_to_shift1_in_fibre_mini_bales_table',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_07_20_170351_update_day_shift_to_shift1_in_pith_blocks_table',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_07_20_172519_update_day_shift_to_shift1_in_pith_productions_table',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_07_21_101423_backfill_shift_day_anchor_setting',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_07_21_101424_drop_next_day_flags_from_pulse_shifts',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_07_21_120458_drop_shift_id_from_pulse_device_data_pith_blocks',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_07_21_165551_fix_staffs_index_visibility_and_add_projects_team_index',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_07_22_012253_rename_per_load_settings_keys',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_07_22_160150_rename_fibre_minibale_tables_to_minibale',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_07_22_160155_rename_fibrebale_machine_id_to_minibale_machine_id',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_07_23_140941_drop_expense_and_project_tables',26);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_07_23_183741_drop_software_only_from_teams_table',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_07_23_183742_drop_pulse_minibale_productions_table',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_07_23_183743_backfill_show_actuals_setting',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_07_23_212703_drop_pulse_growbag_productions_table',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_07_27_000000_drop_personal_team_from_teams_table',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_07_29_000001_create_pulse_pith_location_groups_table',30);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_07_29_000002_seed_alam_cocos_pith_location_groups',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_07_29_000003_merge_duplicate_alam_cocos_pith_locations',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_07_29_000004_merge_alam_east_2_pith_location',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_07_29_000005_shorten_pith_location_name_column',34);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_07_29_000006_scope_pith_location_name_uniqueness_to_group',35);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_07_16_101500_add_attendance_module_to_products_json',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_07_27_104349_create_team_address_table',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_07_27_104404_create_team_pulse_table',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_07_27_104535_backfill_team_schema',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_07_27_105457_drop_legacy_columns_from_teams',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_07_27_124800_assign_team_owners_via_mobile',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_07_27_130000_drop_user_id_from_teams',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_07_27_173626_add_product_access_to_team_user_table',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_07_27_173713_backfill_product_access_in_team_user',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_07_28_120000_fix_team_schema_gaps',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_07_29_000000_remove_enable_notifications_from_teams',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_07_29_000001_refactor_team_pulse',36);

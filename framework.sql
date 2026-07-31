-- Jellyfin Dashboard schema
-- MySQL / MariaDB

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `jellyfin_dashboard`
--

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` mediumint(9) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users are created via `php bin/console.php user:add` (or seeded from
-- AUTH_ADMIN_USER/AUTH_ADMIN_PASSWORD by the container entrypoint).

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts` (brute-force throttle)
--

DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `identifier` varchar(190) NOT NULL,
  `attempts` int NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `play_history`
--

DROP TABLE IF EXISTS `play_history`;
CREATE TABLE IF NOT EXISTS `play_history` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `session_key` varchar(128) NOT NULL,
  `user_id` varchar(64) DEFAULT NULL,
  `user_name` varchar(128) DEFAULT NULL,
  `item_id` varchar(64) NOT NULL,
  `item_type` varchar(16) NOT NULL,
  `series_name` varchar(255) DEFAULT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `season_ep` varchar(32) DEFAULT NULL,
  `library` varchar(64) DEFAULT NULL,
  `play_method` varchar(32) NOT NULL,
  `play_method_detail` varchar(64) DEFAULT NULL,
  `client` varchar(64) DEFAULT NULL,
  `device` varchar(64) DEFAULT NULL,
  `source_video_codec` varchar(64) DEFAULT NULL,
  `source_audio_codec` varchar(64) DEFAULT NULL,
  `source_container` varchar(64) DEFAULT NULL,
  `target_video_codec` varchar(64) DEFAULT NULL,
  `target_audio_codec` varchar(64) DEFAULT NULL,
  `target_container` varchar(64) DEFAULT NULL,
  `is_video_direct` tinyint(1) DEFAULT NULL,
  `is_audio_direct` tinyint(1) DEFAULT NULL,
  `transcode_reasons` text DEFAULT NULL,
  `watched_sec` int NOT NULL DEFAULT 0,
  `runtime_sec` int NOT NULL DEFAULT 0,
  `started_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `is_finished` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_session_item` (`session_key`, `item_id`),
  KEY `idx_started_at` (`started_at`),
  KEY `idx_user_name` (`user_name`),
  KEY `idx_library` (`library`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

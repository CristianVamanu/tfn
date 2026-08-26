-- Schema-only SQL for CreatorPulse (no demo data)
-- --------------------------------------------------------

-- Table structure for table `i_admin_audit`

CREATE TABLE IF NOT EXISTS `i_admin_audit` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `action` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_ad_metrics`

CREATE TABLE IF NOT EXISTS `i_ad_metrics` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad_id` int UNSIGNED NOT NULL,
  `metric_date` date NOT NULL,
  `impressions` int UNSIGNED DEFAULT '0',
  `clicks` int UNSIGNED DEFAULT '0',
  `spend` decimal(10,4) DEFAULT '0.0000',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ad_date` (`ad_id`,`metric_date`),
  KEY `idx_ad_metrics_date` (`metric_date`),
  KEY `idx_ad_metrics_ad` (`ad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_ad_payments`

CREATE TABLE IF NOT EXISTS `i_ad_payments` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad_id` int UNSIGNED NOT NULL,
  `provider` varchar(32) NOT NULL,
  `reference` varchar(191) NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `currency` varchar(10) DEFAULT NULL,
  `status` varchar(32) NOT NULL,
  `event` varchar(64) DEFAULT NULL,
  `raw_payload` mediumtext,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  `fee_amount` decimal(10,2) DEFAULT NULL,
  `fee_currency` varchar(10) DEFAULT NULL,
  `tax_amount` decimal(10,2) DEFAULT NULL,
  `net_amount` decimal(10,2) DEFAULT NULL,
  `payment_type` varchar(32) NOT NULL DEFAULT 'advertisement',
  `object_id` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_ref` (`provider`,`reference`),
  KEY `idx_ad` (`ad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_announcements`

CREATE TABLE IF NOT EXISTS `i_announcements` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inactive',
  `start_at` int UNSIGNED DEFAULT NULL,
  `end_at` int UNSIGNED DEFAULT NULL,
  `allow_close` tinyint(1) NOT NULL DEFAULT '1',
  `cta_label` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cta_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_window` (`status`,`start_at`,`end_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_bookmarks`

CREATE TABLE IF NOT EXISTS `i_bookmarks` (
  `b_id` int NOT NULL AUTO_INCREMENT,
  `item_id` int DEFAULT NULL,
  `uid_fk` int DEFAULT NULL,
  `item_type` enum('video','image') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'image',
  `created_time` int DEFAULT NULL,
  PRIMARY KEY (`b_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_chat_typing`

CREATE TABLE IF NOT EXISTS `i_chat_typing` (
  `who_id` int NOT NULL,
  `with_id` int NOT NULL,
  `updated_at` int NOT NULL,
  PRIMARY KEY (`who_id`,`with_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_comments`

CREATE TABLE IF NOT EXISTS `i_comments` (
  `c_id` int NOT NULL AUTO_INCREMENT,
  `item_id` int DEFAULT NULL,
  `uid_fk` int DEFAULT NULL,
  `comment` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_time` int DEFAULT NULL,
  `updated_time` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`c_id`),
  KEY `idx_item_time` (`item_id`,`created_time` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_comment_liked`

CREATE TABLE IF NOT EXISTS `i_comment_liked` (
  `c_like_id` int NOT NULL AUTO_INCREMENT,
  `c_liked_post_id` int DEFAULT NULL,
  `c_liked_comment_id` int DEFAULT NULL,
  `liked_item_type` enum('video','image') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'video',
  `uid_fk` int DEFAULT NULL,
  `liked_time` int DEFAULT NULL,
  PRIMARY KEY (`c_like_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_comment_reports`

CREATE TABLE IF NOT EXISTS `i_comment_reports` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `comment_id` int UNSIGNED NOT NULL,
  `reporter_id` int UNSIGNED NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_comment_reporter` (`comment_id`,`reporter_id`),
  KEY `idx_comment` (`comment_id`),
  KEY `idx_reporter` (`reporter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_contact_messages`

CREATE TABLE IF NOT EXISTS `i_contact_messages` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED DEFAULT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `msg_type` enum('feedback','complaint','suggestion','bug') COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('new','read','resolved') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contact_messages_user_id` (`user_id`),
  KEY `idx_contact_messages_status` (`status`),
  KEY `idx_contact_messages_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_friends`

CREATE TABLE IF NOT EXISTS `i_friends` (
  `fr_id` int NOT NULL AUTO_INCREMENT,
  `fr_one` int DEFAULT NULL,
  `fr_two` int DEFAULT NULL,
  `fr_time` int DEFAULT NULL,
  `fr_status` enum('me','flwr','subscriber') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'me',
  PRIMARY KEY (`fr_id`),
  KEY `ixFriend` (`fr_one`,`fr_two`,`fr_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

-- Table structure for table `i_icons`

CREATE TABLE IF NOT EXISTS `i_icons` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `icon_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `icon_key_unique` (`icon_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `i_icons` (`id`, `icon_key`, `file_path`, `created_at`, `updated_at`) VALUES (1, 'activity', 'themes/default/icons/activity.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(2, 'admin', 'themes/default/icons/admin.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(3, 'back', 'themes/default/icons/back.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(4, 'ban', 'themes/default/icons/ban.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(5, 'bank', 'themes/default/icons/bank.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(6, 'block', 'themes/default/icons/block.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(7, 'close', 'themes/default/icons/close.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(8, 'close_slim', 'themes/default/icons/close_slim.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(9, 'comment', 'themes/default/icons/comment.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(10, 'complete', 'themes/default/icons/complete.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(11, 'create_post', 'themes/default/icons/create_post.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(12, 'creator_icon', 'themes/default/icons/creator_icon.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(13, 'dashboard_user', 'themes/default/icons/dashboard_user.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(14, 'delete', 'themes/default/icons/delete.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(15, 'dots', 'themes/default/icons/dots.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(16, 'down_arrow', 'themes/default/icons/down_arrow.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(17, 'draft', 'themes/default/icons/draft.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(18, 'email', 'themes/default/icons/email.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(19, 'explore', 'themes/default/icons/explore.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(20, 'eye', 'themes/default/icons/eye.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(21, 'failed', 'themes/default/icons/failed.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(22, 'filters', 'themes/default/icons/filters.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(23, 'follow', 'themes/default/icons/follow.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(24, 'follower', 'themes/default/icons/follower.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(25, 'following', 'themes/default/icons/following.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(26, 'grid_view', 'themes/default/icons/grid_view.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(27, 'header_menu_left', 'themes/default/icons/header_menu_left.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(28, 'header_menu_right', 'themes/default/icons/header_menu_right.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(29, 'help_center', 'themes/default/icons/help_center.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(30, 'home_page', 'themes/default/icons/home_page.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(31, 'image', 'themes/default/icons/image.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(32, 'images', 'themes/default/icons/images.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(33, 'in_progress', 'themes/default/icons/in_progress.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(34, 'landing_settings', 'themes/default/icons/landing_settings.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(35, 'like', 'themes/default/icons/like.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(36, 'liked', 'themes/default/icons/liked.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(37, 'link', 'themes/default/icons/link.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(38, 'link_out', 'themes/default/icons/link_out.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(39, 'list_menu', 'themes/default/icons/list_menu.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(40, 'live', 'themes/default/icons/live.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(41, 'locked', 'themes/default/icons/locked.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(42, 'logout', 'themes/default/icons/logout.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(43, 'make_user', 'themes/default/icons/make_user.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(44, 'media', 'themes/default/icons/media.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(45, 'messages', 'themes/default/icons/messages.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(46, 'moderator', 'themes/default/icons/moderator.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(47, 'next', 'themes/default/icons/next.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(48, 'not_founded', 'themes/default/icons/not_founded.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(49, 'notification', 'themes/default/icons/notification.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(50, 'original_view', 'themes/default/icons/original_view.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(51, 'password', 'themes/default/icons/password.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(52, 'payments', 'themes/default/icons/payments.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(53, 'payout_methods', 'themes/default/icons/payout_methods.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(54, 'plus_image', 'themes/default/icons/plus_image.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(55, 'post_commented', 'themes/default/icons/post_commented.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(56, 'post_liked', 'themes/default/icons/post_liked.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(57, 'preferences', 'themes/default/icons/preferences.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(58, 'prev', 'themes/default/icons/prev.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(59, 'profile', 'themes/default/icons/profile.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(60, 'reels', 'themes/default/icons/reels.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(61, 'save', 'themes/default/icons/save.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(62, 'saved', 'themes/default/icons/saved.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(63, 'search_icon', 'themes/default/icons/search_icon.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(64, 'security', 'themes/default/icons/security.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(65, 'send', 'themes/default/icons/send.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(66, 'send_comment', 'themes/default/icons/send_comment.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(67, 'settings', 'themes/default/icons/settings.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(68, 'site_setting', 'themes/default/icons/site_setting.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(69, 'site_settings', 'themes/default/icons/site_settings.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(70, 'smiley', 'themes/default/icons/smiley.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(71, 'smiley_chat', 'themes/default/icons/smiley_chat.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(72, 'sparkles', 'themes/default/icons/sparkles.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(73, 'subscriber', 'themes/default/icons/subscriber.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(74, 'subscription_fees', 'themes/default/icons/subscription_fees.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(75, 'super_thanks', 'themes/default/icons/super_thanks.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(76, 'switch_apperange', 'themes/default/icons/switch_apperange.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(77, 'tick', 'themes/default/icons/tick.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(78, 'tick_double', 'themes/default/icons/tick_double.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(79, 'tick_double_blue', 'themes/default/icons/tick_double_blue.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(80, 'time', 'themes/default/icons/time.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(81, 'time_alert', 'themes/default/icons/time_alert.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(82, 'trending', 'themes/default/icons/trending.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(83, 'unban', 'themes/default/icons/unban.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(84, 'unlocked', 'themes/default/icons/unlocked.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(85, 'up_arrow', 'themes/default/icons/up_arrow.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(86, 'upload_media', 'themes/default/icons/upload_media.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(87, 'users', 'themes/default/icons/users.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(88, 'verified', 'themes/default/icons/verified.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(89, 'video', 'themes/default/icons/video.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(90, 'view', 'themes/default/icons/view.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(91, 'world', 'themes/default/icons/world.svg', '2025-09-22 17:42:53', '2025-09-22 17:42:53'),
(92, 'svg_icons', 'themes/default/icons/site_svg_icons.svg', '2025-09-23 05:23:45', '2025-09-23 05:38:08'),
(93, 'upload_icon', 'themes/default/icons/upload_icon-e2eaf46272.svg', '2025-09-23 10:08:37', '2025-09-23 10:08:37'),
(94, 'social_media', 'themes/default/icons/social_media-3f0d824721.svg', '2025-09-24 08:15:46', '2025-09-24 08:15:46'),
(95, 'announcement', 'themes/default/icons/announcement-91c6a25a14.svg', '2025-09-24 12:42:31', '2025-09-24 12:42:31'),
(96, 'question_icon', 'themes/default/icons/question_icon-38d2e58f32.svg', '2025-09-26 05:53:27', '2025-09-26 05:53:27'),
(97, 'onesignal_icon', 'themes/default/icons/onesignal_icon-329d5388ff.svg', '2025-09-29 09:49:03', '2025-09-29 09:49:03'),
(98, 'storage_icon', 'themes/default/icons/storage_icon-a4eb305f78.svg', '2025-09-29 10:10:27', '2025-09-29 10:10:27'),
(99, 'local_storage_icon', 'themes/default/icons/local_storage_icon-1bc83f1547.svg', '2025-09-29 11:56:03', '2025-09-29 11:56:03'),
(100, 's3_icon', 'themes/default/icons/s3_icon-5828d7aa01.svg', '2025-09-29 11:58:30', '2025-09-29 11:58:30'),
(101, 'digital_ocean_icon', 'themes/default/icons/digital_ocean_icon-652d6d8915.svg', '2025-09-29 11:59:11', '2025-09-29 11:59:11'),
(102, 'wasabi_icon', 'themes/default/icons/wasabi_icon-922660b433.svg', '2025-09-29 12:03:20', '2025-09-29 12:03:20'),
(103, 'add_funds_icon', 'themes/default/icons/add_funds_icon-55b07580fc.svg', '2025-10-01 17:43:15', '2025-10-01 17:43:15'),
(104, 'backblaze_icon', 'themes/default/icons/backblaze_icon-b2a9b76d2b.svg', '2025-10-01 18:05:00', '2025-10-01 18:05:00')
ON DUPLICATE KEY UPDATE
  `file_path` = VALUES(`file_path`),
  `updated_at` = VALUES(`updated_at`);

INSERT INTO `i_icons` (`icon_key`, `file_path`, `created_at`, `updated_at`) VALUES
  ('reload', 'themes/default/icons/reload-f78833693b.svg', NOW(), NOW()),
  ('podcast', 'themes/default/icons/podcast-c1bea17a82.svg', NOW(), NOW()),
  ('podcastads', 'themes/default/icons/podcastads-9e558abb2a.svg', NOW(), NOW()),
  ('category', 'themes/default/icons/category-6a5cde0c03.svg', NOW(), NOW()),
  ('pin', 'themes/default/icons/pin.svg', NOW(), NOW()),
  ('report', 'themes/default/icons/report.svg', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `file_path` = VALUES(`file_path`),
  `updated_at` = VALUES(`updated_at`);

INSERT INTO `i_icons` (`icon_key`, `file_path`, `created_at`, `updated_at`) VALUES
  ('ebook', 'themes/default/icons/ebook-4d8c257662.svg', NOW(), NOW()),
  ('clear', 'themes/default/icons/clear-8b58fa2a77.svg', NOW(), NOW()),
  ('download', 'themes/default/icons/download-5790eb1d8c.svg', NOW(), NOW()),
  ('info', 'themes/default/icons/info-306414c3c1.svg', NOW(), NOW()),
  ('voice_call', 'themes/default/icons/voice-call.svg', NOW(), NOW()),
  ('voice_call_active', 'themes/default/icons/voice-call-active.svg', NOW(), NOW()),
  ('voice_call_off', 'themes/default/icons/voice-call-off.svg', NOW(), NOW()),
  ('video_call', 'themes/default/icons/video-call.svg', NOW(), NOW()),
  ('video_call_off', 'themes/default/icons/video-call-off.svg', NOW(), NOW()),
  ('pin', 'themes/default/icons/pin.svg', NOW(), NOW()),
  ('report', 'themes/default/icons/report.svg', NOW(), NOW()),
  ('speaker', 'themes/default/icons/speaker-717f6b6ae8.svg', NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `file_path` = VALUES(`file_path`),
  `updated_at` = VALUES(`updated_at`);


-- --------------------------------------------------------

-- Table structure for table `i_icon_aliases`

CREATE TABLE IF NOT EXISTS `i_icon_aliases` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `icon_id` int UNSIGNED NOT NULL,
  `alias_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `alias_key_unique` (`alias_key`),
  KEY `icon_alias_icon_fk` (`icon_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_landing_items`

CREATE TABLE IF NOT EXISTS `i_landing_items` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_id` int UNSIGNED NOT NULL,
  `item_type` enum('post','manual','feature','badge','faq','link') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'post',
  `post_id` int UNSIGNED DEFAULT NULL,
  `media_type` enum('image','video') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `media_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `thumb_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `lang_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sort_order` smallint UNSIGNED NOT NULL DEFAULT '0',
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_section` (`section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `i_landing_items` (`id`, `section_id`, `item_type`, `post_id`, `media_type`, `media_path`, `thumb_path`, `meta_json`, `lang_json`, `sort_order`, `created_at`, `updated_at`) VALUES (1, 3, 'manual', NULL, NULL, NULL, NULL, '{"icon":"","color":"#4f86f7","variant":"dark"}', NULL, 1, 1758134993, 1758135015),
(2, 3, 'manual', NULL, NULL, NULL, NULL, '{"icon":"","color":"#4f86f7","variant":"light"}', NULL, 2, 1758134993, 1758135015),
(3, 3, 'manual', NULL, NULL, NULL, NULL, '{"icon":"","color":"#4f86f7","variant":"light"}', NULL, 3, 1758134993, 1758135015),
(4, 3, 'manual', NULL, NULL, NULL, NULL, '{"icon":"","color":"#4f86f7","variant":"light"}', NULL, 4, 1758134993, 1758135015),
(5, 3, 'manual', NULL, NULL, NULL, NULL, '{"icon":"","color":"#4f86f7","variant":"light"}', NULL, 5, 1758134993, 1758135015),
(6, 3, 'manual', NULL, NULL, NULL, NULL, '{"icon":"","color":"#4f86f7","variant":"muted"}', NULL, 6, 1758134993, 1758135015),
(10, 8, 'link', NULL, NULL, NULL, NULL, '{"href":"login.php","target":"_self","rel":""}', '{"en":{"label":"Creator login"},"tr":{"label":"Oluşturucu girişi"}}', 1, 1758238835, 1758238835),
(11, 8, 'link', NULL, NULL, NULL, NULL, '{"href":"terms-of-use.php","target":"_self","rel":""}', '{"en":{"label":"Terms & conditions"},"tr":{"label":"Şartlar ve koşullar"}}', 2, 1758238835, 1758238835),
(12, 8, 'link', NULL, NULL, NULL, NULL, '{"href":"privacy-policy.php","target":"_self","rel":""}', '{"en":{"label":"Privacy policy"},"tr":{"label":"Gizlilik politikası"}}', 3, 1758238835, 1758238835) ON DUPLICATE KEY UPDATE `id` = VALUES(`id`), `section_id` = VALUES(`section_id`), `item_type` = VALUES(`item_type`), `post_id` = VALUES(`post_id`), `media_type` = VALUES(`media_type`), `media_path` = VALUES(`media_path`), `thumb_path` = VALUES(`thumb_path`), `meta_json` = VALUES(`meta_json`), `lang_json` = VALUES(`lang_json`), `sort_order` = VALUES(`sort_order`), `created_at` = VALUES(`created_at`), `updated_at` = VALUES(`updated_at`);


-- --------------------------------------------------------

-- Table structure for table `i_landing_pages`

CREATE TABLE IF NOT EXISTS `i_landing_pages` (
  `id` int UNSIGNED NOT NULL,
  `theme` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;INSERT INTO `i_landing_pages` (`id`, `theme`, `is_active`, `settings`, `created_at`, `updated_at`) VALUES (1, 'welcome_default', 1, '{"theme":"welcome_default","image_list":{"media_mode":"mixed","target":"profile","show_views":true,"show_likes":true},"marquee":{"list_count":4,"show_avatar":true,"name_mode":"full"},"features":{"items":[]},"samples":{"tags":[]},"pricing":{"sections":[]},"simulator":{"enabled":true,"title_key":"simulator_title","description_key":"simulator_description"},"no_hassle":{"items":[],"ma_color":"#4F86F7"},"faqs":{"managed_via":"settings"},"footer_cta":{"ma_color":"#FF3C3C"}}', 1758129086, 1758129086) ON DUPLICATE KEY UPDATE `id` = VALUES(`id`), `theme` = VALUES(`theme`), `is_active` = VALUES(`is_active`), `settings` = VALUES(`settings`), `created_at` = VALUES(`created_at`), `updated_at` = VALUES(`updated_at`);


-- --------------------------------------------------------

-- Table structure for table `i_landing_sections`

CREATE TABLE IF NOT EXISTS `i_landing_sections` (
  `id` int UNSIGNED NOT NULL,
  `theme` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `section_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cfg_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `sort_order` smallint UNSIGNED NOT NULL DEFAULT '0',
  `updated_at` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;INSERT INTO `i_landing_sections` (`id`, `theme`, `section_key`, `cfg_json`, `sort_order`, `updated_at`) VALUES (1, 'welcome_default', 'image_list', '{"media_mode":"mixed","target":"post","show_views":true,"show_likes":true}', 0, 1758360759),
(2, 'welcome_default', 'marquee', '{"list_count":4,"show_avatar":true,"name_mode":"full"}', 0, 1758127438),
(3, 'welcome_default', 'features', '[]', 0, 1758131552),
(4, 'welcome_default', 'samples', '{"layout":"grid"}', 0, 1758136034),
(5, 'welcome_default', 'pricing', '{"currency":"$","show_monthly":true,"show_yearly":true}', 0, 1758137459),
(6, 'welcome_default', 'no_hassle', '{"ma_color":"#4F86F7"}', 0, 1758141605),
(7, 'welcome_default', 'get_started', '{"data_ma_color":"#FF3C3C"}', 0, 1758186236),
(8, 'welcome_default', 'footer_links', '{"href":"","target":"_self","rel":""}', 0, 1758238835) ON DUPLICATE KEY UPDATE `id` = VALUES(`id`), `theme` = VALUES(`theme`), `section_key` = VALUES(`section_key`), `cfg_json` = VALUES(`cfg_json`), `sort_order` = VALUES(`sort_order`), `updated_at` = VALUES(`updated_at`);


-- --------------------------------------------------------

-- Table structure for table `i_languages`

CREATE TABLE IF NOT EXISTS `i_languages` (
  `id` int NOT NULL,
  `code` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `flag` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `lang_status` tinyint NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;INSERT INTO `i_languages` (`id`, `code`, `name`, `flag`, `lang_status`) VALUES (1, 'fr', 'French', '<?xml version="1.0"?>\n<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 36 36" aria-hidden="true" role="img" class="iconify iconify--twemoji" preserveAspectRatio="xMidYMid meet" fill="#000000"><g id="SVGRepo_bgCarrier" stroke-width="0"/><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"/><g id="SVGRepo_iconCarrier"><path fill="#ED2939" d="M36 27a4 4 0 0 1-4 4h-8V5h8a4 4 0 0 1 4 4v18z"/><path fill="#002495" d="M4 5a4 4 0 0 0-4 4v18a4 4 0 0 0 4 4h8V5H4z"/><path fill="#EEE" d="M12 5h12v26H12z"/></g></svg>\n', 1),
(2, 'eng', 'English', '<?xml version="1.0" encoding="utf-8"?>\n<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" version="1.1" id="Layer_1" x="0px" y="0px" viewBox="0 0 55.2 38.4" style="enable-background:new 0 0 55.2 38.4" xml:space="preserve"><style type="text/css">.stk{fill:#FEFEFE;} .st1k{fill:#C8102E;} .st2k{fill:#012169;}</style><g><path class="stk" d="M2.87,38.4h49.46c1.59-0.09,2.87-1.42,2.87-3.03V3.03c0-1.66-1.35-3.02-3.01-3.03H3.01 C1.35,0.01,0,1.37,0,3.03v32.33C0,36.98,1.28,38.31,2.87,38.4L2.87,38.4z"/><polygon class="st1k" points="23.74,23.03 23.74,38.4 31.42,38.4 31.42,23.03 55.2,23.03 55.2,15.35 31.42,15.35 31.42,0 23.74,0 23.74,15.35 0,15.35 0,23.03 23.74,23.03"/><path class="st2k" d="M33.98,12.43V0h18.23c1.26,0.02,2.34,0.81,2.78,1.92L33.98,12.43L33.98,12.43z"/><path class="st2k" d="M33.98,25.97V38.4h18.35c1.21-0.07,2.23-0.85,2.66-1.92L33.98,25.97L33.98,25.97z"/><path class="st2k" d="M21.18,25.97V38.4H2.87c-1.21-0.07-2.24-0.85-2.66-1.94L21.18,25.97L21.18,25.97z"/><path class="st2k" d="M21.18,12.43V0H2.99C1.73,0.02,0.64,0.82,0.21,1.94L21.18,12.43L21.18,12.43z"/><polygon class="st2k" points="0,12.8 7.65,12.8 0,8.97 0,12.8"/><polygon class="st2k" points="55.2,12.8 47.51,12.8 55.2,8.95 55.2,12.8"/><polygon class="st2" points="55.2,25.6 47.51,25.6 55.2,29.45 55.2,25.6"/><polygon class="st2k" points="0,25.6 7.65,25.6 0,29.43 0,25.6"/><polygon class="st1k" points="55.2,3.25 36.15,12.8 40.41,12.8 55.2,5.4 55.2,3.25"/><polygon class="st1k" points="19.01,25.6 14.75,25.6 0,32.98 0,35.13 19.05,25.6 19.01,25.6"/><polygon class="st1k" points="10.52,12.81 14.78,12.81 0,5.41 0,7.55 10.52,12.81"/><polygon class="st1k" points="44.63,25.59 40.37,25.59 55.2,33.02 55.2,30.88 44.63,25.59"/></g></svg>\n', 1),
(3, 'tr', 'Turkish', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800"><path fill="#E30A17" d="M0 0h1200v800H0z"/><circle cx="425" cy="400" r="200" fill="#fff"/><circle cx="475" cy="400" r="160" fill="#e30a17"/><path fill="#fff" d="M583.334 400l180.901 58.779-111.804-153.885v190.212l111.804-153.885z"/></svg>', 1),
(4, 'de', 'German', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 55.2 38.4"><g fill-rule="evenodd" clip-rule="evenodd"><path d="M3.03 0h49.13c1.67 0 3.03 1.36 3.03 3.03v32.33c0 1.66-1.36 3.02-3.02 3.03H3.02C1.36 38.4 0 37.03 0 35.37V3.03C0 1.36 1.36 0 3.03 0z"/><path d="M0 12.8h55.2v22.57c0 1.67-1.36 3.03-3.03 3.03H3.03C1.36 38.4 0 37.04 0 35.37V12.8z" fill="#d00"/><path d="M0 25.6h55.2v9.77c0 1.66-1.36 3.02-3.02 3.03H3.03A3.04 3.04 0 010 35.37V25.6z" fill="#ffce00"/></g></svg>', 1),
(5, 'es', 'Spanish', '<?xml version="1.0" encoding="utf-8"?><svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 55.2 38.4" style="enable-background:new 0 0 55.2 38.4" xml:space="preserve"><style type="text/css"><![CDATA[\n .st0{fill:#AC1F23;}\n .st1{fill:none;stroke:#000000;stroke-width:0.0419;stroke-linejoin:round;}\n .st2{fill:none;stroke:#000000;stroke-width:0.0326;stroke-linejoin:round;}\n .st3{fill:#EB71A9;}\n .st4{fill:#265EAC;}\n .st5{fill:none;stroke:#000000;stroke-width:4.250000e-003;}\n  .st6{fill:#C52026;}\n .st7{fill:#C6B52F;}\n .st8{fill:#C8B32F;}\n .st9{fill:#FFC60B;}\n .st10{fill:#FFD791;}\n  .st11{fill:#CDCCCB;}\n  .st12{fill:#008F6F;}\n  .st13{fill:none;stroke:#C8B32F;stroke-width:0.0218;}\n  .st14{fill:#3A57A7;}\n  .st15{fill:#DA4546;}\n  .st16{fill:none;stroke:#000000;stroke-width:0.0218;}\n  .st17{fill:none;stroke:#000000;stroke-width:0.0326;}\n  .st18{fill:none;stroke:#000000;stroke-width:0.0386;}\n  .st19{fill:none;stroke:#000000;stroke-width:0.0437;}\n]]></style><g><path class="st6" d="M3.03,0h49.13c1.67,0,3.03,1.36,3.03,3.03v32.33c0,1.67-1.37,3.03-3.03,3.03H3.03C1.37,38.4,0,37.04,0,35.37 V3.03C0,1.36,1.37,0,3.03,0L3.03,0z"/><polygon class="st9" points="0,29.68 55.2,29.68 55.2,8.72 0,8.72 0,29.68"/><polygon class="st8" points="9.95,14.79 11.82,14.79 11.82,14.3 9.95,14.3 9.95,14.79"/><polygon class="st17" points="9.95,14.79 11.82,14.79 11.82,14.3 9.95,14.3 9.95,14.79"/><path class="st8" d="M10.15,15.12c0.01-0.01,0.02-0.01,0.03-0.01h1.4c0.01,0,0.03,0,0.04,0.01c-0.05-0.02-0.08-0.06-0.08-0.11 c0-0.05,0.04-0.1,0.09-0.11c-0.01,0-0.03,0.01-0.04,0.01h-1.4c-0.01,0-0.03,0-0.04-0.01l0.01,0c0.05,0.02,0.08,0.06,0.08,0.11 C10.23,15.06,10.2,15.1,10.15,15.12L10.15,15.12z"/><path class="st2" d="M10.15,15.12c0.01-0.01,0.02-0.01,0.03-0.01h1.4c0.01,0,0.03,0,0.04,0.01c-0.05-0.02-0.08-0.06-0.08-0.11 c0-0.05,0.04-0.1,0.09-0.11c-0.01,0-0.03,0.01-0.04,0.01h-1.4c-0.01,0-0.03,0-0.04-0.01l0.01,0c0.05,0.02,0.08,0.06,0.08,0.11 C10.23,15.06,10.2,15.1,10.15,15.12L10.15,15.12L10.15,15.12z"/><path class="st8" d="M10.18,15.11h1.4c0.05,0,0.09,0.03,0.09,0.07c0,0.04-0.04,0.07-0.09,0.07h-1.4c-0.05,0-0.09-0.03-0.09-0.07 C10.1,15.14,10.14,15.11,10.18,15.11L10.18,15.11z"/><path class="st17" d="M10.18,15.11h1.4c0.05,0,0.09,0.03,0.09,0.07c0,0.04-0.04,0.07-0.09,0.07h-1.4c-0.05,0-0.09-0.03-0.09-0.07 C10.1,15.14,10.14,15.11,10.18,15.11L10.18,15.11z"/><path class="st8" d="M10.18,14.79h1.4c0.05,0,0.09,0.03,0.09,0.06c0,0.03-0.04,0.06-0.09,0.06h-1.4c-0.05,0-0.09-0.03-0.09-0.06 C10.1,14.82,10.14,14.79,10.18,14.79L10.18,14.79z"/></svg>', 1) ON DUPLICATE KEY UPDATE `id` = VALUES(`id`), `code` = VALUES(`code`), `name` = VALUES(`name`), `flag` = VALUES(`flag`), `lang_status` = VALUES(`lang_status`);


-- --------------------------------------------------------

-- Table structure for table `i_lang_overrides`

CREATE TABLE IF NOT EXISTS `i_lang_overrides` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `language` varchar(55) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lang_key` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lang_key` (`language`,`lang_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_live_chat_messages`

CREATE TABLE IF NOT EXISTS `i_live_chat_messages` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `live_id` int UNSIGNED NOT NULL,
  `uid_fk` int UNSIGNED NOT NULL,
  `message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_live_created` (`live_id`,`created_at`),
  KEY `idx_live_id` (`live_id`,`id`),
  KEY `idx_user_live_created` (`uid_fk`,`live_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_live_likes`

CREATE TABLE IF NOT EXISTS `i_live_likes` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `live_id` int UNSIGNED NOT NULL,
  `uid_fk` int UNSIGNED NOT NULL,
  `liked_time` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_live_user` (`live_id`,`uid_fk`),
  KEY `idx_live` (`live_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_live_streams`

CREATE TABLE IF NOT EXISTS `i_live_streams` (
  `live_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `title` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `audience` enum('everyone','followers','following','subscribers','only_me') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'everyone',
  `status` enum('created','live','ended') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'created',
  `created_at` int UNSIGNED NOT NULL,
  `started_at` int UNSIGNED DEFAULT NULL,
  `ended_at` int UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_live_tip_events`

CREATE TABLE IF NOT EXISTS `i_live_tip_events` (
  `id` int UNSIGNED NOT NULL,
  `live_id` int UNSIGNED NOT NULL,
  `buyer_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_live_viewers`

CREATE TABLE IF NOT EXISTS `i_live_viewers` (
  `id` int UNSIGNED NOT NULL,
  `live_id` int UNSIGNED NOT NULL,
  `session_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `uid_fk` int UNSIGNED DEFAULT NULL,
  `last_seen` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_audio_rooms`

CREATE TABLE IF NOT EXISTS `i_audio_rooms` (
  `room_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id` int UNSIGNED NOT NULL,
  `title` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audience` enum('everyone','followers','following','subscribers','only_me') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'everyone',
  `status` enum('created','live','ended','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'created',
  `is_paid` tinyint(1) NOT NULL DEFAULT '0',
  `entry_price` decimal(10,2) DEFAULT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agora_channel` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `max_speakers` int UNSIGNED DEFAULT NULL,
  `max_listeners` int UNSIGNED DEFAULT NULL,
  `started_at` int UNSIGNED DEFAULT NULL,
  `ended_at` int UNSIGNED DEFAULT NULL,
  `end_reason` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`room_id`),
  UNIQUE KEY `uniq_audio_room_channel` (`agora_channel`),
  KEY `idx_audio_room_owner_status` (`owner_id`,`status`,`created_at`),
  KEY `idx_audio_room_status_created` (`status`,`created_at`),
  KEY `idx_audio_room_audience_status` (`audience`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_audio_room_participants`

CREATE TABLE IF NOT EXISTS `i_audio_room_participants` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `session_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('host','moderator','speaker','listener') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'listener',
  `mic_muted` tinyint(1) NOT NULL DEFAULT '1',
  `hand_raised` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('joined','left','removed','banned') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'joined',
  `joined_at` int UNSIGNED NOT NULL,
  `last_seen` int UNSIGNED NOT NULL,
  `left_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_audio_room_session` (`room_id`,`session_key`),
  KEY `idx_audio_room_participants_room_status` (`room_id`,`status`,`last_seen`),
  KEY `idx_audio_room_participants_user` (`user_id`,`room_id`),
  KEY `idx_audio_room_participants_role` (`room_id`,`role`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_audio_room_moderators`

CREATE TABLE IF NOT EXISTS `i_audio_room_moderators` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `assigned_by` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_audio_room_moderator` (`room_id`,`user_id`),
  KEY `idx_audio_room_moderators_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_audio_room_speakers`

CREATE TABLE IF NOT EXISTS `i_audio_room_speakers` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `invited_by` int UNSIGNED DEFAULT NULL,
  `status` enum('invited','active','muted','removed','left') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_audio_room_speaker` (`room_id`,`user_id`),
  KEY `idx_audio_room_speakers_status` (`room_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_audio_room_speaker_requests`

CREATE TABLE IF NOT EXISTS `i_audio_room_speaker_requests` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `status` enum('pending','approved','rejected','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `requested_at` int UNSIGNED NOT NULL,
  `reviewed_at` int UNSIGNED DEFAULT NULL,
  `reviewed_by` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_audio_room_speaker_request` (`room_id`,`user_id`),
  KEY `idx_audio_room_speaker_requests_status` (`room_id`,`status`,`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_audio_room_messages`

CREATE TABLE IF NOT EXISTS `i_audio_room_messages` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` int UNSIGNED NOT NULL,
  `uid_fk` int UNSIGNED NOT NULL,
  `message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `message_type` enum('chat','system','tip') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'chat',
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audio_room_messages_created` (`room_id`,`created_at`),
  KEY `idx_audio_room_messages_id` (`room_id`,`id`),
  KEY `idx_audio_room_messages_user` (`uid_fk`,`room_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_audio_room_tickets`

CREATE TABLE IF NOT EXISTS `i_audio_room_tickets` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` int UNSIGNED NOT NULL,
  `buyer_id` int UNSIGNED NOT NULL,
  `owner_id` int UNSIGNED NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fee_amount` decimal(10,2) DEFAULT NULL,
  `fee_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_amount` decimal(10,2) DEFAULT NULL,
  `net_amount` decimal(10,2) DEFAULT NULL,
  `raw_payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `unlocked_at` int UNSIGNED DEFAULT NULL,
  `credited_at` int UNSIGNED DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_audio_room_ticket_provider_ref` (`provider`,`reference`),
  KEY `idx_audio_room_ticket_room_buyer` (`room_id`,`buyer_id`,`status`),
  KEY `idx_audio_room_ticket_owner` (`owner_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_audio_room_tip_events`

CREATE TABLE IF NOT EXISTS `i_audio_room_tip_events` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` int UNSIGNED NOT NULL,
  `buyer_id` int UNSIGNED DEFAULT NULL,
  `recipient_id` int UNSIGNED DEFAULT NULL,
  `buyer_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fee_amount` decimal(10,2) DEFAULT NULL,
  `tax_amount` decimal(10,2) DEFAULT NULL,
  `net_amount` decimal(10,2) DEFAULT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'succeeded',
  `credited_at` int UNSIGNED DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_audio_room_tip_event_payment` (`provider`,`reference`),
  KEY `idx_audio_room_tip_events_time` (`room_id`,`created_at`),
  KEY `idx_audio_room_tip_events_buyer` (`buyer_id`),
  KEY `idx_audio_room_tip_events_recipient` (`recipient_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_audio_room_usage_daily`

CREATE TABLE IF NOT EXISTS `i_audio_room_usage_daily` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `usage_date` date NOT NULL,
  `used_seconds` int UNSIGNED NOT NULL DEFAULT '0',
  `last_room_id` int UNSIGNED DEFAULT NULL,
  `updated_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_audio_room_usage_user_date` (`user_id`,`usage_date`),
  KEY `idx_audio_room_usage_date` (`usage_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_audio_room_bans`

CREATE TABLE IF NOT EXISTS `i_audio_room_bans` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `banned_by` int UNSIGNED NOT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_audio_room_ban` (`room_id`,`user_id`),
  KEY `idx_audio_room_bans_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_mail_logs`

CREATE TABLE IF NOT EXISTS `i_mail_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `event` enum('contact_send','smtp_test') COLLATE utf8mb4_unicode_ci NOT NULL,
  `ok` tinyint(1) NOT NULL,
  `error` text COLLATE utf8mb4_unicode_ci,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_sms_logs`

CREATE TABLE IF NOT EXISTS `i_sms_logs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED DEFAULT NULL,
  `phone` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'twilio',
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` varchar(320) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_code` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`,`status`,`created_at`),
  KEY `idx_phone_time` (`phone`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_mail_settings`

CREATE TABLE IF NOT EXISTS `i_mail_settings` (
  `id` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `host` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `port` smallint UNSIGNED NOT NULL,
  `secure` enum('tls','ssl','none') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `username` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_enc` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `allow_guest_messages` tinyint(1) NOT NULL DEFAULT '0',
  `guest_recaptcha_required` tinyint(1) NOT NULL DEFAULT '1',
  `max_message_length` int UNSIGNED NOT NULL DEFAULT '1000',
  `subject_label_feedback` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Feedback',
  `subject_label_complaint` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Complaint',
  `subject_label_suggestion` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Suggestion',
  `subject_label_bug` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Bug',
  `recaptcha_site_key` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recaptcha_secret_key` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recaptcha_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `rate_limit_per_hour` int UNSIGNED NOT NULL DEFAULT '1',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;INSERT INTO `i_mail_settings` (`id`, `host`, `port`, `secure`, `username`, `password_enc`, `from_email`, `from_name`, `to_email`, `allow_guest_messages`, `guest_recaptcha_required`, `max_message_length`, `subject_label_feedback`, `subject_label_complaint`, `subject_label_suggestion`, `subject_label_bug`, `recaptcha_site_key`, `recaptcha_secret_key`, `recaptcha_enabled`, `rate_limit_per_hour`, `updated_at`) VALUES (1, '', 587, 'none', '', NULL, '', '', '', 1, 1, 1000, 'Feedback', 'Complaint', 'Suggestion', 'Bug', '', '', 1, 1, '2025-09-30 16:42:38') ON DUPLICATE KEY UPDATE `id` = VALUES(`id`), `host` = VALUES(`host`), `port` = VALUES(`port`), `secure` = VALUES(`secure`), `username` = VALUES(`username`), `password_enc` = VALUES(`password_enc`), `from_email` = VALUES(`from_email`), `from_name` = VALUES(`from_name`), `to_email` = VALUES(`to_email`), `allow_guest_messages` = VALUES(`allow_guest_messages`), `guest_recaptcha_required` = VALUES(`guest_recaptcha_required`), `max_message_length` = VALUES(`max_message_length`), `subject_label_feedback` = VALUES(`subject_label_feedback`), `subject_label_complaint` = VALUES(`subject_label_complaint`), `subject_label_suggestion` = VALUES(`subject_label_suggestion`), `subject_label_bug` = VALUES(`subject_label_bug`), `recaptcha_site_key` = VALUES(`recaptcha_site_key`), `recaptcha_secret_key` = VALUES(`recaptcha_secret_key`), `recaptcha_enabled` = VALUES(`recaptcha_enabled`), `rate_limit_per_hour` = VALUES(`rate_limit_per_hour`), `updated_at` = VALUES(`updated_at`);


-- --------------------------------------------------------

-- Table structure for table `i_messages`

CREATE TABLE IF NOT EXISTS `i_messages` (
  `message_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_one` int DEFAULT NULL,
  `user_two` int DEFAULT NULL,
  `message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `file` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `message_type` enum('text','image','video','post_share','voice_note','video_note','paid_media','tip','call','system') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `reply_to_message_id` int UNSIGNED DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `message_time` int DEFAULT NULL,
  `delivered_at` int DEFAULT NULL,
  `read_at` int DEFAULT NULL,
  `deleted_at` int UNSIGNED DEFAULT NULL,
  `deleted_by` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`message_id`),
  KEY `idx_pair_time` (`user_one`,`user_two`,`message_time`),
  KEY `idx_pair_time_rev` (`user_two`,`user_one`,`message_time`),
  KEY `idx_user_one` (`user_one`),
  KEY `idx_user_two` (`user_two`),
  KEY `idx_time` (`message_time`),
  KEY `idx_messages_to_time` (`user_two`,`message_time`),
  KEY `idx_messages_seen` (`user_two`,`user_one`,`read_at`),
  KEY `idx_messages_reply` (`reply_to_message_id`),
  KEY `idx_messages_type_time` (`message_type`,`message_time`),
  KEY `idx_messages_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_chat_reactions`

CREATE TABLE IF NOT EXISTS `i_chat_reactions` (
  `reaction_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `reaction` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`reaction_id`),
  UNIQUE KEY `uniq_message_user` (`message_id`,`user_id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_chat_deletions`

CREATE TABLE IF NOT EXISTS `i_chat_deletions` (
  `deletion_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `deletion_scope` enum('self','everyone') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'self',
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`deletion_id`),
  UNIQUE KEY `uniq_message_user` (`message_id`,`user_id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_chat_conversation_states`

CREATE TABLE IF NOT EXISTS `i_chat_conversation_states` (
  `state_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `partner_id` int UNSIGNED NOT NULL,
  `cleared_at` int UNSIGNED DEFAULT NULL,
  `deleted_at` int UNSIGNED DEFAULT NULL,
  `pinned_at` int UNSIGNED DEFAULT NULL,
  `muted_until` int UNSIGNED DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`state_id`),
  UNIQUE KEY `uniq_user_partner` (`user_id`,`partner_id`),
  KEY `idx_user_pinned` (`user_id`,`pinned_at`),
  KEY `idx_user_deleted` (`user_id`,`deleted_at`),
  KEY `idx_partner` (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_chat_paid_media`

CREATE TABLE IF NOT EXISTS `i_chat_paid_media` (
  `paid_media_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` int UNSIGNED NOT NULL,
  `creator_id` int UNSIGNED NOT NULL,
  `recipient_id` int UNSIGNED NOT NULL,
  `file_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `preview_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `media_type` enum('image','video','audio','file') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `status` enum('active','void','refunded') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `metadata` json DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`paid_media_id`),
  UNIQUE KEY `uniq_message` (`message_id`),
  KEY `idx_creator_created` (`creator_id`,`created_at`),
  KEY `idx_recipient_created` (`recipient_id`,`created_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_chat_paid_media_unlocks`

CREATE TABLE IF NOT EXISTS `i_chat_paid_media_unlocks` (
  `unlock_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `paid_media_id` bigint UNSIGNED NOT NULL,
  `message_id` int UNSIGNED NOT NULL,
  `buyer_id` int UNSIGNED NOT NULL,
  `creator_id` int UNSIGNED NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `fee_amount` decimal(10,2) DEFAULT NULL,
  `tax_amount` decimal(10,2) DEFAULT NULL,
  `net_amount` decimal(10,2) DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `unlocked_at` int UNSIGNED DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`unlock_id`),
  UNIQUE KEY `uniq_paid_media_buyer` (`paid_media_id`,`buyer_id`),
  UNIQUE KEY `uniq_provider_reference` (`provider`,`reference`),
  KEY `idx_buyer_created` (`buyer_id`,`created_at`),
  KEY `idx_creator_created` (`creator_id`,`created_at`),
  KEY `idx_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_chat_tips`

CREATE TABLE IF NOT EXISTS `i_chat_tips` (
  `tip_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` int UNSIGNED DEFAULT NULL,
  `buyer_id` int UNSIGNED NOT NULL,
  `recipient_id` int UNSIGNED NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `fee_amount` decimal(10,2) DEFAULT NULL,
  `tax_amount` decimal(10,2) DEFAULT NULL,
  `net_amount` decimal(10,2) DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `credited_at` int UNSIGNED DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`tip_id`),
  UNIQUE KEY `uniq_provider_reference` (`provider`,`reference`),
  KEY `idx_message` (`message_id`),
  KEY `idx_buyer_created` (`buyer_id`,`created_at`),
  KEY `idx_recipient_created` (`recipient_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_chat_calls`

CREATE TABLE IF NOT EXISTS `i_chat_calls` (
  `call_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `caller_id` int UNSIGNED NOT NULL,
  `receiver_id` int UNSIGNED NOT NULL,
  `channel_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `call_type` enum('audio','video') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'audio',
  `billing_type` enum('free','fixed','per_minute') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'free',
  `price` decimal(10,2) DEFAULT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('ringing','accepted','rejected','missed','ended','failed','canceled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ringing',
  `started_at` int UNSIGNED NOT NULL,
  `accepted_at` int UNSIGNED DEFAULT NULL,
  `ended_at` int UNSIGNED DEFAULT NULL,
  `duration_seconds` int UNSIGNED DEFAULT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  PRIMARY KEY (`call_id`),
  UNIQUE KEY `uniq_channel_name` (`channel_name`),
  KEY `idx_caller_created` (`caller_id`,`started_at`),
  KEY `idx_receiver_created` (`receiver_id`,`started_at`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_message_requests`

CREATE TABLE IF NOT EXISTS `i_message_requests` (
  `request_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `sender_id` int UNSIGNED NOT NULL,
  `receiver_id` int UNSIGNED NOT NULL,
  `status` enum('pending','accepted','declined','blocked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `first_message_id` int UNSIGNED DEFAULT NULL,
  `last_message_id` int UNSIGNED DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  `acted_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  UNIQUE KEY `uniq_sender_receiver` (`sender_id`,`receiver_id`),
  KEY `idx_receiver_status` (`receiver_id`,`status`,`updated_at`),
  KEY `idx_sender_status` (`sender_id`,`status`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_chat_reports`

CREATE TABLE IF NOT EXISTS `i_chat_reports` (
  `report_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` int UNSIGNED DEFAULT NULL,
  `reporter_id` int UNSIGNED NOT NULL,
  `reported_user_id` int UNSIGNED NOT NULL,
  `reason` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `note` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('open','reviewed','resolved','dismissed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `admin_id` int UNSIGNED DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  `resolved_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`report_id`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_reporter_created` (`reporter_id`,`created_at`),
  KEY `idx_reported_created` (`reported_user_id`,`created_at`),
  KEY `idx_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_user_blocks`

CREATE TABLE IF NOT EXISTS `i_user_blocks` (
  `block_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `blocker_id` int UNSIGNED NOT NULL,
  `blocked_id` int UNSIGNED NOT NULL,
  `source` enum('profile','chat','audio_room','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'chat',
  `reason` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`block_id`),
  UNIQUE KEY `uniq_block_pair` (`blocker_id`,`blocked_id`),
  KEY `idx_blocked` (`blocked_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_notifications`

CREATE TABLE IF NOT EXISTS `i_notifications` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_id` int UNSIGNED NOT NULL,
  `actor_id` int UNSIGNED NOT NULL,
  `type` enum('post_like','comment','comment_like','mention','follow','subscriber','profile_view','tip_payment','tip_payment_receipt','post_purchase','subscription_payment','live_like','creator_status','payout_status','withdrawal_status','post_approval','podcast_ad_status') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `object_id` int UNSIGNED DEFAULT NULL,
  `parent_object_id` int UNSIGNED DEFAULT NULL,
  `extra_data` json DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` int UNSIGNED NOT NULL,
  `read_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_pages`

CREATE TABLE IF NOT EXISTS `i_pages` (
  `id` int UNSIGNED NOT NULL,
  `slug` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;INSERT INTO `i_pages` (`id`, `slug`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES (3, 'terms-of-use', 1, 10, 1758271387, 1758279312),
(4, 'privacy-policy', 1, 20, 1758271387, 1758279312),
(5, 'contact', 1, 0, 1758279312, 1758279312) ON DUPLICATE KEY UPDATE `id` = VALUES(`id`), `slug` = VALUES(`slug`), `is_active` = VALUES(`is_active`), `sort_order` = VALUES(`sort_order`), `created_at` = VALUES(`created_at`), `updated_at` = VALUES(`updated_at`);


-- --------------------------------------------------------

-- Table structure for table `i_page_content`

CREATE TABLE IF NOT EXISTS `i_page_content` (
  `id` int UNSIGNED NOT NULL,
  `page_id` int UNSIGNED NOT NULL,
  `lang` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_html` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;INSERT INTO `i_page_content` (`id`, `page_id`, `lang`, `title`, `content_html`) VALUES (41, 3, 'eng', 'Terms of Use', '<h1>Terms of Use</h1>\r\n<p><strong>Last updated:</strong> June 5, 2024</p>\r\n<p>CreatorPulse is a self-hosted monetization platform for short-form video creators. By installing, configuring, or using the application you acknowledge that you have read and agree to these Terms of Use. If you do not agree with the Terms, do not proceed with the installation or continue using the software.</p>\r\n<h2>1. Platform Scope</h2>\r\n<p>CreatorPulse supplies tools for publishing short-form videos, hosting live broadcasts, managing subscriptions, selling premium posts, processing tips, and engaging with fans. The software is delivered as-is; administrators are responsible for configuring payment gateways, storage, localization, and compliance according to local regulations.</p>\r\n<h2>2. Account Eligibility and Responsibilities</h2>\r\n<p>Only individuals who are at least 18 years old and capable of entering legally binding agreements may operate the platform. Administrators must vet creators, moderate uploads, and provide accurate business information to payment providers. Each creator is responsible for the legality of the content they distribute.</p>\r\n<h2>3. Content Rights and Licensing</h2>\r\n<p>Creators retain ownership of all media uploaded through CreatorPulse. By publishing, creators grant the platform a worldwide, non-exclusive licence to store, stream, and distribute the content to subscribed members and authorized viewers. Administrators must remove infringing material promptly when notified.</p>\r\n<h2>4. Payments, Fees, and Taxes</h2>\r\n<p>CreatorPulse integrates third-party processors such as Stripe, PayPal, Coinbase, and wallet balances. You must configure pricing, currencies, and payout settings within the admin dashboard. Platform fees, processor commissions, chargebacks, and applicable taxes are the responsibility of the site operator and will not be reversed by the script authors.</p>\r\n<h2>5. Acceptable Use</h2>\r\n<p>The platform may not be used to host or distribute unlawful content, including hate speech, harassment, exploitation, spam, or intellectual property violations. Automated scraping, reverse engineering, or attempts to breach security controls are strictly prohibited.</p>\r\n<h2>6. Suspension and Termination</h2>\r\n<p>Administrators may suspend or delete user accounts, remove content, or restrict features if these Terms are breached. Users whose access is terminated remain liable for outstanding payments or contractual obligations incurred prior to termination.</p>\r\n<h2>7. Data Protection and Security</h2>\r\n<p>You are responsible for securing servers, SSL certificates, backups, and privacy notices that inform end users about data processing. Enable optional security features such as two-factor authentication, IP restrictions, and audit logs to protect user accounts.</p>\r\n<h2>8. Support and Contact</h2>\r\n<p>Technical support covers installation guidance and documented configuration steps. Custom modifications, third-party integrations, or ongoing moderation are not included. Submit support requests through your Envato purchase channel or the contact details configured in the admin settings.</p>\r\n<h2>9. Changes to the Terms</h2>\r\n<p>CreatorPulse may update these Terms to reflect feature improvements or legal requirements. Continued use of the software after publication of revised Terms constitutes acceptance of the updates.</p>'),
(42, 3, 'tr', 'Kullanım Şartları', '<h1>Kullanım Şartları</h1>\r\n<p><strong>Son güncelleme:</strong> 5 Haziran 2024</p>\r\n<p>CreatorPulse tarafından sunulan içerik üretici platformuna hoş geldiniz. Bu kullanım şartları, içerik üreticilerinin, abonelerin ve ziyaretçilerin uygulama içinde nasıl çalıştığını, paylaşım yaptığını ve işlem gerçekleştirdiğini açıklar. Hizmete erişerek aşağıda anlatılan işleyişi anladığınızı ve kabul ettiğinizi belirtmiş olursunuz.</p>\r\n<h2>1. Platform Özeti</h2>\r\n<p>Script; TinyMCE tabanlı içerik editörü, canlı etkileşimler, abonelik yönetimi ve ödeme köprüleri sağlar. Bu belgede adı geçen tüm modüller ürünle birlikte gelir ve yönetim paneli üzerinden yapılandırılmalıdır.</p>\r\n<ul>\r\n<li><strong>Üretici Profilleri:</strong> Medya, reels ve ücretli gönderilerin yayınlandığı özelleştirilebilir profiller.</li>\r\n<li><strong>Abone Araçları:</strong> Abonelikler, bahşişler, tüket başına ödeme ve gizlilik kurallarına bağlı yer imleri.</li>\r\n<li><strong>Landing CMS:</strong> TinyMCE ile çalışan çok dilli sayfa yöneticisi; bu metinde anlatılan hukuki ve tanıtım sayfalarının oluşturulmasına imkân verir.</li>\r\n</ul>\r\n<h2>2. Hesap Uygunluğu</h2>\r\n<p>En az 18 yaşında olmalı ve bağlayıcı sözleşmeler yapabilecek durumda bulunmalısınız. Platform sahipleri ödeme öncesinde kimlik doğrulaması talep edebilir. Moderasyon araçları sayesinde politikayı ihlal eden profiller devre dışı bırakılabilir.</p>\r\n<h2>3. Kabul Edilebilir Kullanım</h2>\r\n<p>Kullanıcılar yasadışı, nefret içeren veya telif hakkı ihlali barındıran içerik yüklemeyeceklerini kabul eder. Otomasyon, veri madenciliği veya yazılımın tersine mühendisliği yasaktır. İhlaller geri ödeme yapılmaksızın askıya alma ile sonuçlanabilir.</p>\r\n<h2>4. İşlemler ve Ücretler</h2>\r\n<p>Tüm ücretli etkileşimler yapılandırılmış ödeme sağlayıcıları (Stripe, PayPal, Coinbase veya NowPayments) üzerinden gerçekleştirilir. Yönetici panelinde belirlenen platform ücretleri, üretici ödemelerinden önce düşülür. Ters ibraz ve uyuşmazlıklar ilgili sağlayıcı kurallarına göre yönetilir.</p>\r\n<h2>5. Fikri Mülkiyet</h2>\r\n<p>Üreticiler yükledikleri içeriklerin sahibi olmaya devam eder fakat platforma materyali barındırma ve abonelere sunma lisansı tanırlar. CreatorPulse kod tabanı ve tasarım varlıkları orijinal geliştiricilere aittir.</p>\r\n<h2>6. Sonlandırma</h2>\r\n<p>Yöneticiler bu şartları ihlal eden hesapları askıya alma veya silme hakkını saklı tutar. Aboneler, faturalandırma ayarlarından yenilemeleri istedikleri zaman iptal edebilir. Sonlandırma sonrasında bakiye iadesi yerel mevzuata göre gerçekleştirilir.</p>\r\n<h2>7. Güncellemeler</h2>\r\n<p>Platform zaman zaman geliştirmeler veya güvenlik yamaları yayınlayabilir. Güncellenmiş politikanın yayımlanmasından sonra hizmetin kullanılmaya devam edilmesi yeni koşulların kabul edildiği anlamına gelir.</p>\r\n<h2>8. İletişim</h2>\r\n<p>Destek ve hukuki bildirimler, yönetim panelinde tanımlanan iletişim adresi üzerinden iletilmelidir.</p>'),
(43, 4, 'eng', 'Privacy Policy', '<h1>Privacy Policy</h1>\r\n<p><strong>Last updated:</strong> June 5, 2024</p>\r\n<p>This policy explains how CreatorPulse collects, processes, and protects personal data when you browse, subscribe, tip, or publish content. It complements the technical safeguards built into the script.</p>\r\n<h2>1. Data We Collect</h2>\r\n<p>We store account credentials, profile details, uploaded media, billing events, IP logs for security, and communications exchanged through the TinyMCE powered messaging tools.</p>\r\n<h2>2. How Data Is Used</h2>\r\n<ul>\r\n<li><strong>Service Delivery:</strong> Authenticate sessions, personalise feeds, and display creator pages.</li>\r\n<li><strong>Payments:</strong> Process subscriptions, tips, and marketplace purchases with auditable logs.</li>\r\n<li><strong>Security:</strong> Detect abuse, enforce rate limiting, and comply with legal requests.</li>\r\n</ul>\r\n<h2>3. Legal Basis</h2>\r\n<p>Processing is based on contractual necessity, legitimate interest in securing the platform, and explicit consent where required (for example, marketing communications).</p>\r\n<h2>4. Data Sharing</h2>\r\n<p>We only share data with payment processors, cloud storage providers selected by the operator, or regulators when legally mandated. Third parties act as processors and must implement comparable safeguards.</p>\r\n<h2>5. International Transfers</h2>\r\n<p>Hosting locations are controlled via your infrastructure. When data is transferred across borders, the platform owner is responsible for ensuring appropriate safeguards (such as Standard Contractual Clauses).</p>\r\n<h2>6. Data Retention</h2>\r\n<p>Account data is retained while an account remains active. Backups follow the retention schedule configured by the administrator. Users may request deletion through support channels; residual obligations such as financial record keeping may apply.</p>\r\n<h2>7. Your Rights</h2>\r\n<p>Depending on your region you may request access, rectification, portability, restriction, or erasure of your data. Requests can be initiated via the contact email in the footer.</p>\r\n<h2>8. Cookies and Tracking</h2>\r\n<p>The script relies on strictly necessary cookies for session management and optionally uses analytics as configured by the site owner. Browser settings may be used to manage additional tracking.</p>\r\n<h2>9. Contact</h2>\r\n<p>For privacy inquiries contact the address defined in site settings.</p>'),
(44, 4, 'tr', 'Gizlilik Politikası', '<h1>Gizlilik Politikası</h1>\r\n<p><strong>Son güncelleme:</strong> 5 Haziran 2024</p>\r\n<p>Bu politika, CreatorPulse platformunu kullanırken kişisel verilerin nasıl toplandığını, işlendiğini ve korunduğunu açıklar. TinyMCE tabanlı mesajlaşma ve içerik üretim araçları dahil olmak üzere script içinde bulunan teknik önlemleri tamamlar.</p>\r\n<h2>1. Toplanan Veriler</h2>\r\n<p>Hesap bilgileri, profil detayları, yüklenen medya, faturalandırma kayıtları, güvenlik için IP logları ve mesajlaşma yoluyla iletilen içerikler saklanır.</p>\r\n<h2>2. Verilerin Kullanımı</h2>\r\n<ul>\r\n<li><strong>Hizmet Sunumu:</strong> Oturum açma, kişiselleştirilmiş akış ve üretici sayfalarının görüntülenmesi.</li>\r\n<li><strong>Ödemeler:</strong> Abonelik, bahşiş ve market işlemlerinin denetlenebilir kayıtlarla yürütülmesi.</li>\r\n<li><strong>Güvenlik:</strong> Kötüye kullanımı tespit etmek, hız limitleme uygulamak ve yasal talepleri yerine getirmek.</li>\r\n</ul>\r\n<h2>3. Hukuki Dayanak</h2>\r\n<p>Veri işleme, sözleşmenin gerekliliği, platformun güvenliğini sağlama konusundaki meşru menfaat ve gerekli durumlarda açık rıza esasına dayanır.</p>\r\n<h2>4. Veri Paylaşımı</h2>\r\n<p>Veriler yalnızca ödeme sağlayıcıları, site sahibinin seçtiği bulut depolama hizmetleri veya yasal zorunluluk hallerinde yetkili mercilerle paylaşılır. Üçüncü taraflar veri işleyen konumundadır ve benzer güvenlik önlemlerini uygulamakla yükümlüdür.</p>\r\n<h2>5. Uluslararası Aktarımlar</h2>\r\n<p>Barındırma lokasyonları altyapınıza bağlıdır. Veriler sınırlar arası aktarıldığında, uygun önlemlerin (örneğin Standart Sözleşme Maddeleri) uygulanmasından platform sahibi sorumludur.</p>\r\n<h2>6. Veri Saklama</h2>\r\n<p>Hesap etkin kaldığı sürece veriler saklanır. Yedekler, yönetici tarafından belirlenen saklama programına göre tutulur. Kullanıcılar destek kanalları üzerinden silme talebinde bulunabilir; finansal kayıt saklama gibi zorunluluklar devam edebilir.</p>\r\n<h2>7. Haklarınız</h2>\r\n<p>Bölgenize bağlı olarak verilere erişim, düzeltme, taşınabilirlik, kısıtlama veya silme talep edebilirsiniz. Talepler footer’da yer alan iletişim adresi üzerinden yapılabilir.</p>\r\n<h2>8. Çerezler ve Takip</h2>\r\n<p>Script, oturum yönetimi için zorunlu çerezler kullanır ve site sahibi tarafından yapılandırılmışsa analitik araçlarından yararlanabilir. Ek takip mekanizmalarını tarayıcı ayarlarınızdan yönetebilirsiniz.</p>\r\n<h2>9. İletişim</h2>\r\n<p>Gizlilik ile ilgili sorularınızı site ayarlarında belirtilen iletişim adresine iletebilirsiniz.</p>'),
(45, 5, 'eng', 'Contact Page', '') ON DUPLICATE KEY UPDATE `id` = VALUES(`id`), `page_id` = VALUES(`page_id`), `lang` = VALUES(`lang`), `title` = VALUES(`title`), `content_html` = VALUES(`content_html`);


-- --------------------------------------------------------

-- Table structure for table `i_post_liked`

CREATE TABLE IF NOT EXISTS `i_post_liked` (
  `like_id` int NOT NULL AUTO_INCREMENT,
  `liked_item_id` int DEFAULT NULL,
  `liked_item_type` enum('post','comment') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'post',
  `uid_fk` int DEFAULT NULL,
  `liked_time` int DEFAULT NULL,
  PRIMARY KEY (`like_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_post_media`

CREATE TABLE IF NOT EXISTS `i_post_media` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` int UNSIGNED NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `storage` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local',
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq` (`post_id`,`path`),
  KEY `idx_post` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_post_purchases`

CREATE TABLE IF NOT EXISTS `i_post_purchases` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `buyer_id` int UNSIGNED NOT NULL,
  `recipient_id` int UNSIGNED NOT NULL,
  `post_id` int UNSIGNED NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fee_amount` decimal(10,2) DEFAULT NULL,
  `fee_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_amount` decimal(10,2) DEFAULT NULL,
  `net_amount` decimal(10,2) DEFAULT NULL,
  `raw_payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `unlocked_at` int UNSIGNED DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_ref` (`provider`,`reference`),
  KEY `idx_post` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_post_reports`

CREATE TABLE IF NOT EXISTS `i_post_reports` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` int UNSIGNED NOT NULL,
  `reporter_id` int UNSIGNED NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_post` (`post_id`),
  KEY `idx_reporter` (`reporter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_post_views`

CREATE TABLE IF NOT EXISTS `i_post_views` (
  `view_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `post_id` int NOT NULL,
  `viewer_user_id` int DEFAULT NULL,
  `viewer_session` varbinary(32) DEFAULT NULL,
  `source` enum('feed','popup','reels') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'feed',
  `dwell_ms` int UNSIGNED NOT NULL DEFAULT '0',
  `visible_ratio` decimal(3,2) NOT NULL DEFAULT '0.00',
  `created_at` int NOT NULL,
  PRIMARY KEY (`view_id`),
  UNIQUE KEY `uq_post_user` (`post_id`,`viewer_user_id`),
  UNIQUE KEY `uq_post_sess` (`post_id`,`viewer_session`),
  KEY `idx_post` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_recent_searches`

CREATE TABLE IF NOT EXISTS `i_recent_searches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `keyword` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `search_time` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_user_keyword` (`user_id`,`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_security_events`

CREATE TABLE IF NOT EXISTS `i_security_events` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `event_type` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_meta` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`,`created_at`),
  KEY `idx_user_type` (`user_id`,`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_sessions`

CREATE TABLE IF NOT EXISTS `i_sessions` (
  `session_id` int NOT NULL AUTO_INCREMENT,
  `session_uid` int DEFAULT NULL,
  `session_key` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `session_time` int NOT NULL DEFAULT '1605484800',
  PRIMARY KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

-- Table structure for table `i_session_devices`

CREATE TABLE IF NOT EXISTS `i_session_devices` (
  `session_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `device_label` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `browser` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `created_at` int UNSIGNED NOT NULL,
  `last_active` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_site_configurations`

CREATE TABLE IF NOT EXISTS `i_site_configurations` (
  `id` int NOT NULL,
  `maintenance` enum('on','off') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off',
  `maintenance_eta` int UNSIGNED DEFAULT NULL,
  `maintenance_message` text COLLATE utf8mb4_unicode_ci,
  `maintenance_updated_at` int UNSIGNED DEFAULT NULL,
  `maintenance_updated_by` int UNSIGNED DEFAULT NULL,
  `maintenance_tiktok_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maintenance_instagram_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maintenance_facebook_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maintenance_twitter_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maintenance_support_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maintenance_status_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `site_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `site_title` text COLLATE utf8mb4_unicode_ci,
  `site_description` text COLLATE utf8mb4_unicode_ci,
  `site_keywords` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `site_base_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_white` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `logo_dark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `logo_mobile_dark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `logo_mobile_white` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `site_theme` varchar(55) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'default',
  `site_language` varchar(55) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'eng',
  `register_auto_username` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `register_phone_enabled` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `register_phone_dial_codes` text COLLATE utf8mb4_unicode_ci,
  `script_version` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '2.0',
  `ffmpeg_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ffmpeg_probe_bin` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `locked_preview_mode` enum('off','static','animated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off',
  `locked_preview_mode_images` enum('off','static','animated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off',
  `locked_preview_mode_videos` enum('off','static','animated') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'off',
  `premium_post_price_minimum` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '1',
  `premium_post_price_maximum` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '500',
  `available_video_extensions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `available_file_upload_size` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `maximum_video_duration` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message_scroll_limit` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '5',
  `page_scroll_limit` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '10',
  `stripe_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `stripe_secret` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `stripe_webhook_secret` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `stripe_currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `payments_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `paypal_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `paypal_client_id` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `paypal_client_secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `paypal_env` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'sandbox',
  `paypal_webhook_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paypal_currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `nowpayment_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `nowpayments_api_key` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `nowpayment_webhook_secret_key` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `now_payment_currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BTC',
  `coinbase_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `coinbase_commerce_api_key` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `coinbase_commerce_webhook_secret` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `coinbase_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'BTC',
  `flutterwave_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `flutterwave_currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `flutterwave_public_key` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `flutterwave_secret_key` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `flutterwave_encryption_key` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `flutterwave_secret_hash` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `google_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `google_client_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `google_client_secret` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facebook_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `facebook_app_id` text COLLATE utf8mb4_unicode_ci,
  `facebook_app_secret` text COLLATE utf8mb4_unicode_ci,
  `twitter_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `twitter_client_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `twitter_client_secret` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agora_app_id` text COLLATE utf8mb4_unicode_ci,
  `agora_app_certificate` text COLLATE utf8mb4_unicode_ci,
  `agora_region` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'GLOBAL',
  `agora_token_expire_seconds` int DEFAULT '7200',
  `agora_enable_rtm` tinyint(1) DEFAULT '1',
  `agora_allow_tokenless` enum('0','1') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `guest_feed_mode` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `guest_admin_ids` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payment_fee_percent` decimal(5,2) UNSIGNED DEFAULT '0.00',
  `payment_fee_fixed` decimal(10,2) UNSIGNED DEFAULT '0.00',
  `payment_tax_percent` decimal(5,2) UNSIGNED DEFAULT '0.00',
  `subscription_fee` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '5',
  `live_streaming_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `live_chat_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `audio_rooms_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `audio_room_chat_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `audio_room_paid_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `audio_room_custom_price_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `audio_room_price_presets` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '5,10,15,20',
  `audio_room_price_minimum` decimal(10,2) UNSIGNED NOT NULL DEFAULT '1.00',
  `audio_room_price_maximum` decimal(10,2) UNSIGNED NOT NULL DEFAULT '500.00',
  `audio_room_non_creator_daily_minutes` int UNSIGNED NOT NULL DEFAULT '60',
  `audio_room_max_speakers` int UNSIGNED NOT NULL DEFAULT '12',
  `audio_room_max_listeners` int UNSIGNED DEFAULT NULL,
  `audio_room_title_prefix` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ebooks_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `ebook_creator_uploads_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `agora_readonly_token` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `live_viewer_limit` int DEFAULT NULL,
  `live_title_prefix` varchar(180) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `onesignal_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `onesignal_app_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `onesignal_rest_api_key` text COLLATE utf8mb4_unicode_ci,
  `onesignal_safari_web_id` text COLLATE utf8mb4_unicode_ci,
  `onesignal_auto_prompt` tinyint(1) NOT NULL DEFAULT '1',
  `onesignal_welcome_title` text COLLATE utf8mb4_unicode_ci,
  `onesignal_welcome_message` text COLLATE utf8mb4_unicode_ci,
  `paystack_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `paystack_public_key` text COLLATE utf8mb4_unicode_ci,
  `paystack_secret_key` text COLLATE utf8mb4_unicode_ci,
  `paystack_webhook_secret` text COLLATE utf8mb4_unicode_ci,
  `paystack_currency` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'NGN',
  `paystack_merchant_email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `sms_provider` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT 'twilio',
  `sms_twilio_sid` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_twilio_token` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_twilio_from` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_rate_limit_per_hour` int UNSIGNED DEFAULT '30',
  `storage_settings` longtext COLLATE utf8mb4_unicode_ci,
  `wallet_topup_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `wallet_topup_minimum` decimal(10,2) NOT NULL DEFAULT '10.00',
  `wallet_topup_maximum` decimal(10,2) NOT NULL DEFAULT '1000.00',
  `iyzico_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `iyzico_api_key` text COLLATE utf8mb4_unicode_ci,
  `iyzico_secret_key` text COLLATE utf8mb4_unicode_ci,
  `iyzico_webhook_secret` text COLLATE utf8mb4_unicode_ci,
  `iyzico_currency` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT 'TRY',
  `iyzico_api_base` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payu_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `payu_merchant_pos_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payu_merchant_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payu_client_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payu_client_secret` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payu_signature_key` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payu_currency` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payu_api_base` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_posts_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `video_posts_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `ads_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `gtm_id` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gtm_disable_admins` tinyint(1) NOT NULL DEFAULT '0',
  `post_approval_required` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `suggested_users_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `suggested_users_limit` int UNSIGNED NOT NULL DEFAULT '5',
  `suggested_users_reload` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `suggested_users_mode` enum('creators','users','mixed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'creators',
  `podcast_posts_status` enum('open','close') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `maximum_audio_duration` int DEFAULT NULL,
  `currency_symbol_position` enum('left','right','left_space','right_space') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'left',
  `currency_decimal_places` tinyint(3) UNSIGNED NOT NULL DEFAULT '2',
  `currency_thousands_sep` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ',',
  `currency_decimal_sep` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '.'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;INSERT INTO `i_site_configurations` (
  `id`,
  `maintenance`,
  `site_name`,
  `site_title`,
  `site_description`,
  `site_keywords`,
  `logo_white`,
  `logo_dark`,
  `logo_mobile_dark`,
  `logo_mobile_white`,
  `site_theme`,
  `site_language`,
  `script_version`,
  `premium_post_price_minimum`,
  `premium_post_price_maximum`,
  `available_video_extensions`,
  `available_file_upload_size`,
  `maximum_video_duration`,
  `locked_preview_mode`,
  `locked_preview_mode_images`,
  `locked_preview_mode_videos`,
  `message_scroll_limit`,
  `page_scroll_limit`,
  `stripe_status`,
  `stripe_currency`,
  `payments_currency`,
  `paypal_status`,
  `paypal_env`,
  `paypal_currency`,
  `nowpayment_status`,
  `now_payment_currency`,
  `coinbase_status`,
  `coinbase_currency`,
  `flutterwave_status`,
  `flutterwave_currency`,
  `flutterwave_public_key`,
  `flutterwave_secret_key`,
  `flutterwave_encryption_key`,
  `flutterwave_secret_hash`,
  `google_status`,
  `facebook_status`,
  `twitter_status`,
  `agora_region`,
  `agora_token_expire_seconds`,
  `agora_enable_rtm`,
  `agora_allow_tokenless`,
  `guest_feed_mode`,
  `payment_fee_percent`,
  `payment_fee_fixed`,
  `payment_tax_percent`,
  `subscription_fee`,
  `live_streaming_enabled`,
  `live_chat_enabled`,
  `audio_rooms_enabled`,
  `audio_room_chat_enabled`,
  `audio_room_paid_enabled`,
  `audio_room_custom_price_enabled`,
  `audio_room_price_presets`,
  `audio_room_price_minimum`,
  `audio_room_price_maximum`,
  `audio_room_non_creator_daily_minutes`,
  `audio_room_max_speakers`,
  `audio_room_max_listeners`,
  `audio_room_title_prefix`,
  `ebooks_enabled`,
  `ebook_creator_uploads_enabled`,
  `onesignal_enabled`,
  `onesignal_auto_prompt`,
  `paystack_status`,
  `paystack_public_key`,
  `paystack_secret_key`,
  `paystack_webhook_secret`,
  `paystack_currency`,
  `paystack_merchant_email`,
  `sms_enabled`,
  `sms_provider`,
  `sms_twilio_sid`,
  `sms_twilio_token`,
  `sms_twilio_from`,
  `sms_rate_limit_per_hour`,
  `wallet_topup_status`,
  `wallet_topup_minimum`,
  `wallet_topup_maximum`
) VALUES (
  1,
  'off',
  'CreatorPulse',
  'CreatorPulse – Monetize Short-Form Creators | Subscription, Paywall & Live Video PHP Platform',
  'CreatorPulse is an end-to-end short-form video platform for launching subscription and pay-per-view communities under your own brand. Creators publish 7–14 second clips, run live sessions, and sell premium drops while fans unlock content via wallets, recurring plans, or tips.',
  'CreatorPulse, short video monetization, subscription video platform, pay-per-view reels, creator paywall, live video tips, premium short clips, fan subscriptions, creator wallet payouts, video membership site',
  'uploads/logo/logo-logo_white-6a62c44c80909fd1d372c563f3e5cde9.png',
  'uploads/logo/logo-dark.png',
  'uploads/logo/logo-mobile-dark.png',
  'uploads/logo/logo-mobile-white.png',
  'default',
  'eng',
  '2.0',
  '1.00',
  '500.00',
  'mp4,MP4,mp3,MP3,mpg,mov,m4v,avi,flv,mpeg,MPEG',
  '5120',
  '17',
  'off',
  'off',
  'off',
  '5',
  '10',
  'close',
  'USD',
  'USD',
  'close',
  'sandbox',
  'USD',
  'close',
  'BTC',
  'close',
  'USD',
  'close',
  'USD',
  NULL,
  NULL,
  NULL,
  NULL,
  'close',
  'close',
  'close',
  'GLOBAL',
  7200,
  1,
  '0',
  'admin_only',
  0.00,
  5.00,
  2.00,
  '5',
  1,
  1,
  1,
  1,
  1,
  1,
  '5,10,15,20',
  1.00,
  500.00,
  60,
  12,
  NULL,
  NULL,
  1,
  1,
  0,
  1,
  'close',
  NULL,
  NULL,
  NULL,
  'NGN',
  NULL,
  0,
  'twilio',
  NULL,
  NULL,
  NULL,
  30,
  'open',
  11.00,
  1000.00
) ON DUPLICATE KEY UPDATE `id` = VALUES(`id`), `maintenance` = VALUES(`maintenance`), `site_name` = VALUES(`site_name`), `site_title` = VALUES(`site_title`), `site_description` = VALUES(`site_description`), `site_keywords` = VALUES(`site_keywords`), `logo_white` = VALUES(`logo_white`), `logo_dark` = VALUES(`logo_dark`), `logo_mobile_dark` = VALUES(`logo_mobile_dark`), `logo_mobile_white` = VALUES(`logo_mobile_white`), `site_theme` = VALUES(`site_theme`), `site_language` = VALUES(`site_language`), `script_version` = VALUES(`script_version`), `premium_post_price_minimum` = VALUES(`premium_post_price_minimum`), `premium_post_price_maximum` = VALUES(`premium_post_price_maximum`), `available_video_extensions` = VALUES(`available_video_extensions`), `available_file_upload_size` = VALUES(`available_file_upload_size`), `maximum_video_duration` = VALUES(`maximum_video_duration`), `locked_preview_mode` = VALUES(`locked_preview_mode`), `locked_preview_mode_images` = VALUES(`locked_preview_mode_images`), `locked_preview_mode_videos` = VALUES(`locked_preview_mode_videos`), `message_scroll_limit` = VALUES(`message_scroll_limit`), `page_scroll_limit` = VALUES(`page_scroll_limit`), `stripe_status` = VALUES(`stripe_status`), `stripe_currency` = VALUES(`stripe_currency`), `payments_currency` = VALUES(`payments_currency`), `paypal_status` = VALUES(`paypal_status`), `paypal_env` = VALUES(`paypal_env`), `paypal_currency` = VALUES(`paypal_currency`), `nowpayment_status` = VALUES(`nowpayment_status`), `now_payment_currency` = VALUES(`now_payment_currency`), `coinbase_status` = VALUES(`coinbase_status`), `coinbase_currency` = VALUES(`coinbase_currency`), `flutterwave_status` = VALUES(`flutterwave_status`), `flutterwave_currency` = VALUES(`flutterwave_currency`), `flutterwave_public_key` = VALUES(`flutterwave_public_key`), `flutterwave_secret_key` = VALUES(`flutterwave_secret_key`), `flutterwave_encryption_key` = VALUES(`flutterwave_encryption_key`), `flutterwave_secret_hash` = VALUES(`flutterwave_secret_hash`), `paystack_status` = VALUES(`paystack_status`), `paystack_public_key` = VALUES(`paystack_public_key`), `paystack_secret_key` = VALUES(`paystack_secret_key`), `paystack_webhook_secret` = VALUES(`paystack_webhook_secret`), `paystack_currency` = VALUES(`paystack_currency`), `paystack_merchant_email` = VALUES(`paystack_merchant_email`), `google_status` = VALUES(`google_status`), `facebook_status` = VALUES(`facebook_status`), `twitter_status` = VALUES(`twitter_status`), `agora_region` = VALUES(`agora_region`), `agora_token_expire_seconds` = VALUES(`agora_token_expire_seconds`), `agora_enable_rtm` = VALUES(`agora_enable_rtm`), `agora_allow_tokenless` = VALUES(`agora_allow_tokenless`), `guest_feed_mode` = VALUES(`guest_feed_mode`), `payment_fee_percent` = VALUES(`payment_fee_percent`), `payment_fee_fixed` = VALUES(`payment_fee_fixed`), `payment_tax_percent` = VALUES(`payment_tax_percent`), `subscription_fee` = VALUES(`subscription_fee`), `live_streaming_enabled` = VALUES(`live_streaming_enabled`), `live_chat_enabled` = VALUES(`live_chat_enabled`), `audio_rooms_enabled` = VALUES(`audio_rooms_enabled`), `audio_room_chat_enabled` = VALUES(`audio_room_chat_enabled`), `audio_room_paid_enabled` = VALUES(`audio_room_paid_enabled`), `audio_room_custom_price_enabled` = VALUES(`audio_room_custom_price_enabled`), `audio_room_price_presets` = VALUES(`audio_room_price_presets`), `audio_room_price_minimum` = VALUES(`audio_room_price_minimum`), `audio_room_price_maximum` = VALUES(`audio_room_price_maximum`), `audio_room_non_creator_daily_minutes` = VALUES(`audio_room_non_creator_daily_minutes`), `audio_room_max_speakers` = VALUES(`audio_room_max_speakers`), `audio_room_max_listeners` = VALUES(`audio_room_max_listeners`), `audio_room_title_prefix` = VALUES(`audio_room_title_prefix`), `onesignal_enabled` = VALUES(`onesignal_enabled`), `onesignal_auto_prompt` = VALUES(`onesignal_auto_prompt`), `sms_enabled` = VALUES(`sms_enabled`), `sms_provider` = VALUES(`sms_provider`), `sms_twilio_sid` = VALUES(`sms_twilio_sid`), `sms_twilio_token` = VALUES(`sms_twilio_token`), `sms_twilio_from` = VALUES(`sms_twilio_from`), `sms_rate_limit_per_hour` = VALUES(`sms_rate_limit_per_hour`), `wallet_topup_status` = VALUES(`wallet_topup_status`), `wallet_topup_minimum` = VALUES(`wallet_topup_minimum`), `wallet_topup_maximum` = VALUES(`wallet_topup_maximum`);



-- --------------------------------------------------------

-- Table structure for table `i_subscription_payments`

CREATE TABLE IF NOT EXISTS `i_subscription_payments` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `buyer_id` int UNSIGNED NOT NULL,
  `recipient_id` int UNSIGNED NOT NULL,
  `plan_interval` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `interval_count` int UNSIGNED NOT NULL DEFAULT '1',
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_period_end` int UNSIGNED DEFAULT NULL,
  `started_at` int UNSIGNED DEFAULT NULL,
  `cancelled_at` int UNSIGNED DEFAULT NULL,
  `raw_payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  `provider_object_id` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_ref` (`provider`,`reference`),
  KEY `idx_recipient` (`recipient_id`),
  KEY `idx_buyer` (`buyer_id`),
  KEY `idx_provider_object` (`provider`,`provider_object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_tip_payments`

CREATE TABLE IF NOT EXISTS `i_tip_payments` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `buyer_id` int UNSIGNED NOT NULL,
  `recipient_id` int UNSIGNED NOT NULL,
  `post_id` int UNSIGNED NOT NULL,
  `provider` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fee_amount` decimal(10,2) DEFAULT NULL,
  `fee_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_amount` decimal(10,2) DEFAULT NULL,
  `net_amount` decimal(10,2) DEFAULT NULL,
  `raw_payload` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `credited_at` int UNSIGNED DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_ref` (`provider`,`reference`),
  KEY `idx_post` (`post_id`),
  KEY `idx_recipient` (`recipient_id`),
  KEY `idx_buyer` (`buyer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_users`

CREATE TABLE IF NOT EXISTS `i_users` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_fullname` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user_email` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user_phone` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_phone_verified_at` int UNSIGNED DEFAULT NULL,
  `user_password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_type` tinyint NOT NULL DEFAULT '1',
  `user_mode` enum('user','admin','moderator') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `admin_permissions` JSON NULL,
  `last_login_time` int DEFAULT NULL,
  `user_avatar` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `user_cover` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `verified_status` tinyint NOT NULL DEFAULT '1',
  `subscrition_status` enum('active','passive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'passive',
  `about_me` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `wallet` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `earned` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `for_billing_first_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `for_billing_last_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `for_billing_country` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `for_billing_city` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `for_billing_state` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `for_billing_postcode` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `for_billing_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `who_can_send_message` enum('everyone','followers','subscribers') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'everyone',
  `subscription_status` enum('open','close') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'close',
  `creator_status` enum('none','pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `creator_status_updated_at` int UNSIGNED DEFAULT NULL,
  `payout_method` enum('none','bank','paypal','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `payout_details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_banned` tinyint(1) NOT NULL DEFAULT '0',
  `is_fake` tinyint(1) NOT NULL DEFAULT '0',
  `google_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facebook_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `twitter_id` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `idx_i_users_google_id` (`google_id`),
  UNIQUE KEY `idx_i_users_facebook_id` (`facebook_id`),
  UNIQUE KEY `idx_i_users_twitter_id` (`twitter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_users_subscription_plans`

CREATE TABLE IF NOT EXISTS `i_users_subscription_plans` (
  `plan_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `price_weekly` decimal(10,2) DEFAULT NULL,
  `price_monthly` decimal(10,2) DEFAULT NULL,
  `price_halfyear` decimal(10,2) DEFAULT NULL,
  `price_yearly` decimal(10,2) DEFAULT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`plan_id`),
  UNIQUE KEY `uniq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_user_advertisements`

CREATE TABLE IF NOT EXISTS `i_user_advertisements` (
  `ad_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `media_type` enum('image','video') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ad_media` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ad_link` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_impressions` int UNSIGNED DEFAULT '0',
  `price_per_impression` decimal(10,4) DEFAULT '0.0000',
  `duration_days` smallint UNSIGNED DEFAULT '1',
  `total_budget` decimal(10,2) DEFAULT '0.00',
  `views` int UNSIGNED DEFAULT '0',
  `clicks` int UNSIGNED DEFAULT '0',
  `status` enum('draft','active','paused','ended') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'draft',
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`ad_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_user_payouts`

CREATE TABLE IF NOT EXISTS `i_user_payouts` (
  `payout_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `method` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `destination_json` longtext COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reference` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `admin_note` text COLLATE utf8mb4_unicode_ci,
  `requested_at` int UNSIGNED NOT NULL,
  `processed_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`payout_id`),
  UNIQUE KEY `reference` (`reference`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_user_time` (`user_id`,`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_user_payout_reviews`

CREATE TABLE IF NOT EXISTS `i_user_payout_reviews` (
  `review_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `method` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `admin_id` int UNSIGNED NOT NULL,
  `status_before` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_after` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `admin_note` text COLLATE utf8mb4_unicode_ci,
  `payload_snapshot` longtext COLLATE utf8mb4_unicode_ci,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`review_id`),
  KEY `idx_user_method` (`user_id`,`method`),
  KEY `idx_admin` (`admin_id`,`created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_user_posts`

CREATE TABLE IF NOT EXISTS `i_user_posts` (
  `post_id` int NOT NULL AUTO_INCREMENT,
  `post_owner_id` int DEFAULT NULL,
  `post_type` enum('image','video','podcast') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'video',
  `post_file` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `audio_duration_seconds` int UNSIGNED DEFAULT NULL,
  `podcast_category_id` int UNSIGNED DEFAULT NULL,
  `locked_preview_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locked_preview_type` enum('static','animated') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `post_text` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `post_visibility` enum('everyone','followers','subscribers','locked') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'everyone',
  `post_price` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment_status` enum('on','off') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'on',
  `like_status` enum('on','off') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'on',
  `post_created_time` int DEFAULT NULL,
  `post_views` int NOT NULL DEFAULT '0',
  `approval_status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'approved',
  `approval_notes` text COLLATE utf8mb4_unicode_ci,
  `approved_at` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  PRIMARY KEY (`post_id`),
  KEY `idx_owner` (`post_owner_id`),
  KEY `idx_created` (`post_created_time`),
  KEY `idx_visibility` (`post_visibility`),
  KEY `idx_type_created` (`post_type`,`post_created_time`),
  KEY `idx_approval_status` (`approval_status`,`post_created_time`),
  KEY `idx_podcast_category` (`podcast_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_user_preferences`

CREATE TABLE IF NOT EXISTS `i_user_preferences` (
  `user_id` int UNSIGNED NOT NULL,
  `prefs_json` longtext COLLATE utf8mb4_unicode_ci,
  `updated_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_user_security`

CREATE TABLE IF NOT EXISTS `i_user_security` (
  `user_id` int UNSIGNED NOT NULL,
  `totp_secret` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `totp_confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `pending_secret` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recovery_codes` longtext COLLATE utf8mb4_unicode_ci,
  `codes_generated_at` int UNSIGNED DEFAULT NULL,
  `totp_enabled_at` int UNSIGNED DEFAULT NULL,
  `updated_at` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `last_codes_downloaded_at` int UNSIGNED DEFAULT NULL,
  `last_codes_regen_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_verification_requests`

CREATE TABLE IF NOT EXISTS `i_verification_requests` (
  `request_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `documents` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `request_status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `admin_id` int UNSIGNED DEFAULT NULL,
  `admin_note` text COLLATE utf8mb4_unicode_ci,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  UNIQUE KEY `uniq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_wallet_ledger`

CREATE TABLE IF NOT EXISTS `i_wallet_ledger` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `type` enum('topup','spend','refund') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_minor` bigint NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref_provider` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` json DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_ref` (`ref_provider`,`ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_wallet_topups`

CREATE TABLE IF NOT EXISTS `i_wallet_topups` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `provider` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_minor` bigint NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fee_minor` bigint DEFAULT NULL,
  `tax_minor` bigint DEFAULT NULL,
  `net_minor` bigint DEFAULT NULL,
  `raw_payload` json DEFAULT NULL,
  `credited_at` int UNSIGNED DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_reference` (`provider`,`reference`),
  KEY `idx_user_status_created` (`user_id`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

-- Table structure for table `i_words`

CREATE TABLE IF NOT EXISTS `i_words` (
  `id` int NOT NULL AUTO_INCREMENT,
  `language_id` int NOT NULL,
  `w_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `w_key` (`w_key`,`language_id`),
  KEY `language_id` (`language_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `i_words` (`id`, `language_id`, `w_key`, `value`) VALUES (1, 1, 'please_fill_all_fields', 'Veuillez remplir tous les champs.'),
(2, 2, 'please_fill_all_fields', 'Please fill all fields.'),
(3, 3, 'please_fill_all_fields', 'Lütfen tüm alanları doldurun.'),
(4, 4, 'please_fill_all_fields', 'Bitte füllen Sie alle Felder aus.'),
(5, 5, 'please_fill_all_fields', 'Por favor, rellene todos los campos.'),
(6, 1, 'incorrect_password', 'Mot de passe incorrect.'),
(7, 2, 'incorrect_password', 'Incorrect password.'),
(8, 3, 'incorrect_password', 'Hatalı şifre.'),
(9, 4, 'incorrect_password', 'Falsches Passwort.'),
(10, 5, 'incorrect_password', 'Contraseña incorrecta.'),
(11, 1, 'user_not_found', 'Utilisateur non trouvé.'),
(12, 2, 'user_not_found', 'User not found.'),
(13, 3, 'user_not_found', 'Kullanıcı bulunamadı.'),
(14, 4, 'user_not_found', 'Benutzer nicht gefunden.'),
(15, 5, 'user_not_found', 'Usuario no encontrado.') ON DUPLICATE KEY UPDATE `id` = VALUES(`id`), `language_id` = VALUES(`language_id`), `w_key` = VALUES(`w_key`), `value` = VALUES(`value`);

-- --------------------------------------------------------

-- Table structure for table `i_podcast_categories`

CREATE TABLE IF NOT EXISTS `i_podcast_categories` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_slug` (`slug`),
  KEY `idx_status_sort` (`status`,`sort_order`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `i_podcast_category_translations`

CREATE TABLE IF NOT EXISTS `i_podcast_category_translations` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` int UNSIGNED NOT NULL,
  `language` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cat_lang` (`category_id`,`language`),
  KEY `idx_cat` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `i_podcast_ad_packages`

CREATE TABLE IF NOT EXISTS `i_podcast_ad_packages` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `duration_days` int UNSIGNED NOT NULL DEFAULT '7',
  `daily_cap` int UNSIGNED DEFAULT NULL,
  `total_cap` int UNSIGNED DEFAULT NULL,
  `premium_multiplier` decimal(6,2) NOT NULL DEFAULT '2.00',
  `targeting_fee` decimal(10,2) DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `i_podcast_ad_package_translations`

CREATE TABLE IF NOT EXISTS `i_podcast_ad_package_translations` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_id` int UNSIGNED NOT NULL,
  `language` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pkg_lang` (`package_id`,`language`),
  KEY `idx_pkg` (`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `i_podcast_ad_payments`

CREATE TABLE IF NOT EXISTS `i_podcast_ad_payments` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `package_id` int UNSIGNED NOT NULL,
  `provider` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','paid','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `event` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ad_id` int UNSIGNED DEFAULT NULL,
  `token_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `package_snapshot` json DEFAULT NULL,
  `daily_cap` int UNSIGNED DEFAULT NULL,
  `total_cap` int UNSIGNED DEFAULT NULL,
  `duration_days` int UNSIGNED NOT NULL DEFAULT '7',
  `premium_selected` tinyint(1) NOT NULL DEFAULT '0',
  `targeting_selected` tinyint(1) NOT NULL DEFAULT '0',
  `consumed_at` int UNSIGNED DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_reference` (`provider`,`reference`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_ad` (`ad_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table structure for table `i_podcast_ads`

CREATE TABLE IF NOT EXISTS `i_podcast_ads` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subtitle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `podcast_post_id` int UNSIGNED NOT NULL DEFAULT '0',
  `package_id` int UNSIGNED DEFAULT NULL,
  `payment_id` int UNSIGNED DEFAULT NULL,
  `is_premium` tinyint(1) NOT NULL DEFAULT '0',
  `daily_cap` int UNSIGNED DEFAULT NULL,
  `total_cap` int UNSIGNED DEFAULT NULL,
  `budget_amount` decimal(10,2) DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cta_primary_label` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cta_primary_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cta_secondary_label` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cta_secondary_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cover_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `start_at` int DEFAULT NULL,
  `end_at` int DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` int NOT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_at` int DEFAULT NULL,
  `impressions` int NOT NULL DEFAULT '0',
  `impressions_today` int NOT NULL DEFAULT '0',
  `impressions_day_start` int DEFAULT NULL,
  `clicks_primary` int NOT NULL DEFAULT '0',
  `clicks_secondary` int NOT NULL DEFAULT '0',
  `targeting_meta` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_podcast_post` (`podcast_post_id`),
  KEY `idx_status` (`status`),
  KEY `idx_status_dates` (`status`,`start_at`,`end_at`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_package` (`package_id`),
  KEY `idx_payment` (`payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'site_description' AND data_type = 'text'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  MODIFY `site_description` text COLLATE utf8mb4_unicode_ci');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'site_title' AND data_type = 'text'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  MODIFY `site_title` text COLLATE utf8mb4_unicode_ci');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'agora_app_id' AND data_type = 'text'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  MODIFY `agora_app_id` text COLLATE utf8mb4_unicode_ci');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'agora_app_certificate' AND data_type = 'text'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  MODIFY `agora_app_certificate` text COLLATE utf8mb4_unicode_ci');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'facebook_app_id' AND data_type = 'text'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  MODIFY `facebook_app_id` text COLLATE utf8mb4_unicode_ci');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'facebook_app_secret' AND data_type = 'text'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  MODIFY `facebook_app_secret` text COLLATE utf8mb4_unicode_ci');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'nowpayment_webhook_secret_key'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  ADD COLUMN `nowpayment_webhook_secret_key` text COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `nowpayments_api_key`');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'register_auto_username'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  ADD COLUMN `register_auto_username` enum("open","close") COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''open'' AFTER `site_language`');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'register_phone_enabled'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  ADD COLUMN `register_phone_enabled` enum("open","close") COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''open'' AFTER `register_auto_username`');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'register_phone_dial_codes'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  ADD COLUMN `register_phone_dial_codes` text COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `register_phone_enabled`');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_users' AND column_name = 'admin_permissions'), 'SELECT 1', 'ALTER TABLE `i_users`
  ADD COLUMN `admin_permissions` JSON NULL AFTER `user_mode`');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'onesignal_rest_api_key' AND data_type = 'text'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  MODIFY `onesignal_rest_api_key` text COLLATE utf8mb4_unicode_ci');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'onesignal_safari_web_id' AND data_type = 'text'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  MODIFY `onesignal_safari_web_id` text COLLATE utf8mb4_unicode_ci');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'onesignal_welcome_title' AND data_type = 'text'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  MODIFY `onesignal_welcome_title` text COLLATE utf8mb4_unicode_ci');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'onesignal_welcome_message' AND data_type = 'text'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  MODIFY `onesignal_welcome_message` text COLLATE utf8mb4_unicode_ci');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_admin_audit' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_admin_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_time` (`user_id`,`created_at`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_ad_metrics' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_ad_metrics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_ad_date` (`ad_id`,`metric_date`),
  ADD KEY `idx_ad_metrics_date` (`metric_date`),
  ADD KEY `idx_ad_metrics_ad` (`ad_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_ad_payments' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_ad_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_provider_ref` (`provider`,`reference`),
  ADD KEY `idx_ad` (`ad_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_announcements' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status_window` (`status`,`start_at`,`end_at`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_bookmarks' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_bookmarks`
  ADD PRIMARY KEY (`b_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_chat_typing' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_chat_typing`
  ADD PRIMARY KEY (`who_id`,`with_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_comments' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_comments`
  ADD PRIMARY KEY (`c_id`),
  ADD KEY `idx_item_time` (`item_id`,`created_time` DESC)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_comment_liked' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_comment_liked`
  ADD PRIMARY KEY (`c_like_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_comment_reports' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_comment_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_comment_reporter` (`comment_id`,`reporter_id`),
  ADD KEY `idx_comment` (`comment_id`),
  ADD KEY `idx_reporter` (`reporter_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_contact_messages' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_contact_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contact_messages_user_id` (`user_id`),
  ADD KEY `idx_contact_messages_status` (`status`),
  ADD KEY `idx_contact_messages_created_at` (`created_at`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_friends' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_friends`
  ADD PRIMARY KEY (`fr_id`),
  ADD KEY `ixFriend` (`fr_one`,`fr_two`,`fr_status`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_icons' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_icons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `icon_key_unique` (`icon_key`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_icon_aliases' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_icon_aliases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `alias_key_unique` (`alias_key`),
  ADD KEY `icon_alias_icon_idx` (`icon_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_landing_items' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_landing_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_section` (`section_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_landing_pages' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_landing_pages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_theme` (`theme`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_landing_sections' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_landing_sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_theme_section` (`theme`,`section_key`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_languages' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_languages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code_UNIQUE` (`code`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_lang_overrides' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_lang_overrides`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_lang_key` (`language`,`lang_key`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_live_chat_messages' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_live_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_live_created` (`live_id`,`created_at`),
  ADD KEY `idx_live_id` (`live_id`,`id`),
  ADD KEY `idx_user_live_created` (`uid_fk`,`live_id`,`created_at`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_live_likes' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_live_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_live_user` (`live_id`,`uid_fk`),
  ADD KEY `idx_live` (`live_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_live_streams' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_live_streams`
  ADD PRIMARY KEY (`live_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_status` (`status`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_live_tip_events' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_live_tip_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_live_time` (`live_id`,`created_at`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_live_viewers' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_live_viewers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq` (`live_id`,`session_key`),
  ADD KEY `idx_live` (`live_id`),
  ADD KEY `idx_seen` (`last_seen`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_mail_logs' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_mail_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mail_logs_event` (`event`),
  ADD KEY `idx_mail_logs_ok` (`ok`),
  ADD KEY `idx_mail_logs_created_at` (`created_at`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_mail_settings' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_mail_settings`
  ADD PRIMARY KEY (`id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_messages' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `idx_pair_time` (`user_one`,`user_two`,`message_time`),
  ADD KEY `idx_pair_time_rev` (`user_two`,`user_one`,`message_time`),
  ADD KEY `idx_user_one` (`user_one`),
  ADD KEY `idx_user_two` (`user_two`),
  ADD KEY `idx_time` (`message_time`),
  ADD KEY `idx_messages_to_time` (`user_two`,`message_time`),
  ADD KEY `idx_messages_seen` (`user_two`,`user_one`,`read_at`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_notifications' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recipient_idx` (`recipient_id`),
  ADD KEY `type_idx` (`type`),
  ADD KEY `is_read_idx` (`is_read`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_pages' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_pages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_pages_slug` (`slug`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_page_content' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_page_content`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_page_lang` (`page_id`,`lang`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_post_liked' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_post_liked`
  ADD PRIMARY KEY (`like_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_post_media' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_post_media`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq` (`post_id`,`path`),
  ADD KEY `idx_post` (`post_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_post_purchases' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_post_purchases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_provider_ref` (`provider`,`reference`),
  ADD KEY `idx_post` (`post_id`),
  ADD KEY `idx_recipient` (`recipient_id`),
  ADD KEY `idx_buyer` (`buyer_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_post_reports' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_post_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq` (`post_id`,`reporter_id`),
  ADD KEY `idx_post` (`post_id`),
  ADD KEY `idx_user` (`reporter_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_post_views' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_post_views`
  ADD PRIMARY KEY (`view_id`),
  ADD UNIQUE KEY `uq_post_user` (`post_id`,`viewer_user_id`),
  ADD UNIQUE KEY `uq_post_sess` (`post_id`,`viewer_session`),
  ADD KEY `idx_post` (`post_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_recent_searches' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_recent_searches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ux_user_keyword` (`user_id`,`keyword`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_security_events' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_security_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_time` (`user_id`,`created_at`),
  ADD KEY `idx_user_type` (`user_id`,`event_type`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_sessions' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_sessions`
  ADD PRIMARY KEY (`session_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_session_devices' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_session_devices`
  ADD PRIMARY KEY (`session_key`),
  ADD KEY `idx_sd_user` (`user_id`,`last_active` DESC)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  ADD PRIMARY KEY (`id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_subscription_payments' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_subscription_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_provider_ref` (`provider`,`reference`),
  ADD KEY `idx_recipient` (`recipient_id`),
  ADD KEY `idx_buyer` (`buyer_id`),
  ADD KEY `idx_provider_object` (`provider`,`provider_object_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_tip_payments' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_tip_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_provider_ref` (`provider`,`reference`),
  ADD KEY `idx_post` (`post_id`),
  ADD KEY `idx_recipient` (`recipient_id`),
  ADD KEY `idx_buyer` (`buyer_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_users' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `idx_i_users_google_id` (`google_id`),
  ADD UNIQUE KEY `idx_i_users_facebook_id` (`facebook_id`),
  ADD UNIQUE KEY `idx_i_users_twitter_id` (`twitter_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_users_subscription_plans' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_users_subscription_plans`
  ADD PRIMARY KEY (`plan_id`),
  ADD UNIQUE KEY `uniq_user` (`user_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_user_advertisements' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_user_advertisements`
  ADD PRIMARY KEY (`ad_id`),
  ADD KEY `idx_user` (`user_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_user_payouts' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_user_payouts`
  ADD PRIMARY KEY (`payout_id`),
  ADD UNIQUE KEY `reference` (`reference`),
  ADD KEY `idx_user_status` (`user_id`,`status`),
  ADD KEY `idx_user_time` (`user_id`,`requested_at`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_user_payout_reviews' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_user_payout_reviews`
  ADD PRIMARY KEY (`review_id`),
  ADD KEY `idx_user_method` (`user_id`,`method`),
  ADD KEY `idx_admin` (`admin_id`,`created_at`),
  ADD KEY `idx_created` (`created_at`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_user_posts' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_user_posts`
  ADD PRIMARY KEY (`post_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_user_preferences' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_user_preferences`
  ADD PRIMARY KEY (`user_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_user_security' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_user_security`
  ADD PRIMARY KEY (`user_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_verification_requests' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_verification_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD UNIQUE KEY `uniq_user` (`user_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_wallet_ledger' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_wallet_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_ref` (`ref_provider`,`ref_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_wallet_topups' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_wallet_topups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_provider_reference` (`provider`,`reference`),
  ADD KEY `idx_user_status_created` (`user_id`,`status`,`created_at`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_words' AND constraint_type = 'PRIMARY KEY'), 'SELECT 1', 'ALTER TABLE `i_words`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `w_key` (`w_key`,`language_id`),
  ADD KEY `language_id` (`language_id`)');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_admin_audit' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_admin_audit`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ad_metrics' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_ad_metrics`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ad_payments' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_ad_payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_announcements' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_announcements`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_bookmarks' AND column_name = 'b_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_bookmarks`
  MODIFY `b_id` int NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_comments' AND column_name = 'c_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_comments`
  MODIFY `c_id` int NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_comment_liked' AND column_name = 'c_like_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_comment_liked`
  MODIFY `c_like_id` int NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_comment_reports' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_comment_reports`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_contact_messages' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_contact_messages`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_friends' AND column_name = 'fr_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_friends`
  MODIFY `fr_id` int NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_icons' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_icons`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=104');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_icon_aliases' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_icon_aliases`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_landing_items' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_landing_items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_landing_pages' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_landing_pages`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_landing_sections' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_landing_sections`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_languages' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_languages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_lang_overrides' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_lang_overrides`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_live_chat_messages' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_live_chat_messages`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_live_likes' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_live_likes`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_live_streams' AND column_name = 'live_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_live_streams`
  MODIFY `live_id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_live_tip_events' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_live_tip_events`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_live_viewers' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_live_viewers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_mail_logs' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_mail_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_messages' AND column_name = 'message_id' AND extra LIKE '%auto_increment%' AND column_type LIKE '%unsigned%'), 'SELECT 1', 'ALTER TABLE `i_messages`
  MODIFY `message_id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_notifications' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_notifications`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_pages' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_pages`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_page_content' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_page_content`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_post_liked' AND column_name = 'like_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_post_liked`
  MODIFY `like_id` int NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_post_media' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_post_media`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_post_purchases' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_post_purchases`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_post_reports' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_post_reports`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_post_views' AND column_name = 'view_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_post_views`
  MODIFY `view_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_recent_searches' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_recent_searches`
  MODIFY `id` int NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_security_events' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_security_events`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_sessions' AND column_name = 'session_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_sessions`
  MODIFY `session_id` int NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_subscription_payments' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_subscription_payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_tip_payments' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_tip_payments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_users' AND column_name = 'user_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_users`
  MODIFY `user_id` int NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_users_subscription_plans' AND column_name = 'plan_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_users_subscription_plans`
  MODIFY `plan_id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_user_advertisements' AND column_name = 'ad_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_user_advertisements`
  MODIFY `ad_id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_user_payouts' AND column_name = 'payout_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_user_payouts`
  MODIFY `payout_id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_user_payout_reviews' AND column_name = 'review_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_user_payout_reviews`
  MODIFY `review_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_user_posts' AND column_name = 'post_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_user_posts`
  MODIFY `post_id` int NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_verification_requests' AND column_name = 'request_id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_verification_requests`
  MODIFY `request_id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_wallet_ledger' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_wallet_ledger`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_wallet_topups' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_wallet_topups`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_words' AND column_name = 'id' AND extra LIKE '%auto_increment%'), 'SELECT 1', 'ALTER TABLE `i_words`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_icon_aliases' AND constraint_name = 'icon_alias_icon_fk'), 'SELECT 1', 'ALTER TABLE `i_icon_aliases`
  ADD CONSTRAINT `icon_alias_icon_fk` FOREIGN KEY (`icon_id`) REFERENCES `i_icons` (`id`) ON DELETE CASCADE');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_landing_items' AND constraint_name = 'fk_landing_items_section'), 'SELECT 1', 'ALTER TABLE `i_landing_items`
  ADD CONSTRAINT `fk_landing_items_section` FOREIGN KEY (`section_id`) REFERENCES `i_landing_sections` (`id`) ON DELETE CASCADE');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_page_content' AND constraint_name = 'fk_page_content_page'), 'SELECT 1', 'ALTER TABLE `i_page_content`
  ADD CONSTRAINT `fk_page_content_page` FOREIGN KEY (`page_id`) REFERENCES `i_pages` (`id`) ON DELETE CASCADE');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'i_words' AND constraint_name = 'translations_ibfk_1'), 'SELECT 1', 'ALTER TABLE `i_words`
  ADD CONSTRAINT `translations_ibfk_1` FOREIGN KEY (`language_id`) REFERENCES `i_languages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- eBook store v2
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'ebooks_enabled'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  ADD COLUMN `ebooks_enabled` tinyint(1) NOT NULL DEFAULT 1');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'ebook_creator_uploads_enabled'), 'SELECT 1', 'ALTER TABLE `i_site_configurations`
  ADD COLUMN `ebook_creator_uploads_enabled` tinyint(1) NOT NULL DEFAULT 1');
PREPARE stmt FROM @sql_to_run;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `i_ebooks` (
  `ebook_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id` int UNSIGNED NOT NULL,
  `title` varchar(190) NOT NULL,
  `slug` varchar(220) NOT NULL,
  `description` text NULL,
  `cover_path` varchar(255) NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(190) NULL,
  `file_size` bigint UNSIGNED NOT NULL DEFAULT 0,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) NOT NULL DEFAULT 'USD',
  `status` varchar(24) NOT NULL DEFAULT 'active',
  `download_count` int UNSIGNED NOT NULL DEFAULT 0,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NULL,
  PRIMARY KEY (`ebook_id`),
  UNIQUE KEY `uniq_ebook_slug` (`slug`),
  KEY `idx_owner_status_created` (`owner_id`, `status`, `created_at`),
  KEY `idx_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `i_ebook_purchases` (
  `purchase_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `ebook_id` int UNSIGNED NOT NULL,
  `buyer_id` int UNSIGNED NOT NULL,
  `seller_id` int UNSIGNED NOT NULL,
  `provider` varchar(32) NOT NULL DEFAULT 'wallet',
  `reference` varchar(191) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) NOT NULL DEFAULT 'USD',
  `status` varchar(32) NOT NULL DEFAULT 'succeeded',
  `event` varchar(64) NULL,
  `fee_amount` decimal(10,2) NULL,
  `fee_currency` varchar(10) NULL,
  `tax_amount` decimal(10,2) NULL,
  `net_amount` decimal(10,2) NULL,
  `raw_payload` mediumtext NULL,
  `credited_at` int UNSIGNED NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NULL,
  PRIMARY KEY (`purchase_id`),
  UNIQUE KEY `uniq_buyer_ebook` (`buyer_id`, `ebook_id`),
  UNIQUE KEY `uniq_provider_reference` (`provider`, `reference`),
  KEY `idx_ebook` (`ebook_id`),
  KEY `idx_seller` (`seller_id`),
  KEY `idx_ebook_purchases_seller_status_created` (`seller_id`, `status`, `created_at`),
  KEY `idx_ebook_purchases_ebook_status_created` (`ebook_id`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- CreatorPulse v2 professional eBook foundation
-- Safe to run more than once.

SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'short_description'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `short_description` varchar(500) NULL AFTER `slug`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'sample_path'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `sample_path` varchar(255) NULL AFTER `file_size`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'isbn'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `isbn` varchar(32) NULL AFTER `sample_path`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'language_code'), 'ALTER TABLE `i_ebooks` MODIFY COLUMN `language_code` varchar(35) NOT NULL DEFAULT ''en''', 'ALTER TABLE `i_ebooks` ADD COLUMN `language_code` varchar(35) NOT NULL DEFAULT ''en'' AFTER `isbn`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `i_ebooks` SET `language_code` = 'en' WHERE `language_code` = 'eng';

CREATE TABLE IF NOT EXISTS `i_ebook_languages` (
  `language_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `language_tag` varchar(35) NOT NULL,
  `english_name` varchar(120) NOT NULL,
  `native_name` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NULL,
  PRIMARY KEY (`language_id`),
  UNIQUE KEY `uniq_ebook_language_tag` (`language_tag`),
  KEY `idx_ebook_language_active_sort` (`is_active`, `sort_order`, `english_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `i_ebook_language_translations` (
  `translation_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `language_id` int UNSIGNED NOT NULL,
  `locale_code` varchar(16) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NULL,
  PRIMARY KEY (`translation_id`),
  UNIQUE KEY `uniq_ebook_language_translation` (`language_id`, `locale_code`),
  KEY `idx_ebook_language_translation_locale` (`locale_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `i_ebook_languages` (`language_tag`, `english_name`, `native_name`, `is_active`, `is_default`, `sort_order`, `created_at`, `updated_at`) VALUES
  ('en', 'English', 'English', 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('tr', 'Turkish', 'Türkçe', 1, 0, 10, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('ar', 'Arabic', 'العربية', 1, 0, 20, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('de', 'German', 'Deutsch', 1, 0, 30, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('es', 'Spanish', 'Español', 1, 0, 40, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('fr', 'French', 'Français', 1, 0, 50, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('pt', 'Portuguese', 'Português', 1, 0, 60, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('it', 'Italian', 'Italiano', 1, 0, 70, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('ru', 'Russian', 'Русский', 1, 0, 80, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('zh-Hans', 'Chinese (Simplified)', '简体中文', 1, 0, 90, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('zh-Hant', 'Chinese (Traditional)', '繁體中文', 1, 0, 100, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('ja', 'Japanese', '日本語', 1, 0, 110, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('ko', 'Korean', '한국어', 1, 0, 120, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('hi', 'Hindi', 'हिन्दी', 1, 0, 130, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('ur', 'Urdu', 'اردو', 1, 0, 140, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('fa', 'Persian', 'فارسی', 1, 0, 150, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('nl', 'Dutch', 'Nederlands', 1, 0, 160, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('pl', 'Polish', 'Polski', 1, 0, 170, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('sv', 'Swedish', 'Svenska', 1, 0, 180, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('da', 'Danish', 'Dansk', 1, 0, 190, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('no', 'Norwegian', 'Norsk', 1, 0, 200, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('fi', 'Finnish', 'Suomi', 1, 0, 210, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('el', 'Greek', 'Ελληνικά', 1, 0, 220, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('he', 'Hebrew', 'עברית', 1, 0, 230, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('id', 'Indonesian', 'Bahasa Indonesia', 1, 0, 240, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('ms', 'Malay', 'Bahasa Melayu', 1, 0, 250, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('th', 'Thai', 'ไทย', 1, 0, 260, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('vi', 'Vietnamese', 'Tiếng Việt', 1, 0, 270, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('uk', 'Ukrainian', 'Українська', 1, 0, 280, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
  ('ro', 'Romanian', 'Română', 1, 0, 290, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE
  `english_name` = VALUES(`english_name`),
  `native_name` = VALUES(`native_name`),
  `updated_at` = VALUES(`updated_at`);

SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'page_count'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `page_count` int UNSIGNED NOT NULL DEFAULT 0 AFTER `language_code`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'publication_date'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `publication_date` date NULL AFTER `page_count`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'version_label'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `version_label` varchar(40) NULL AFTER `publication_date`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'moderation_note'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `moderation_note` varchar(500) NULL AFTER `status`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'submitted_at'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `submitted_at` int UNSIGNED NULL AFTER `moderation_note`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'approved_at'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `approved_at` int UNSIGNED NULL AFTER `submitted_at`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'approved_by'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `approved_by` int UNSIGNED NULL AFTER `approved_at`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'featured'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `featured` tinyint(1) NOT NULL DEFAULT 0 AFTER `approved_by`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'download_limit'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `download_limit` int UNSIGNED NOT NULL DEFAULT 5 AFTER `featured`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'access_days'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `access_days` int UNSIGNED NOT NULL DEFAULT 0 AFTER `download_limit`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'seo_title'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `seo_title` varchar(190) NULL AFTER `access_days`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'seo_description'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `seo_description` varchar(320) NULL AFTER `seo_title`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'view_count'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `view_count` int UNSIGNED NOT NULL DEFAULT 0 AFTER `seo_description`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'sales_count'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `sales_count` int UNSIGNED NOT NULL DEFAULT 0 AFTER `view_count`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'rating_average'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `rating_average` decimal(3,2) NOT NULL DEFAULT 0.00 AFTER `sales_count`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'rating_count'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `rating_count` int UNSIGNED NOT NULL DEFAULT 0 AFTER `rating_average`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `i_ebook_categories` (
  `category_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` int UNSIGNED NULL,
  `name` varchar(120) NOT NULL,
  `slug` varchar(140) NOT NULL,
  `description` varchar(500) NULL,
  `icon_key` varchar(100) NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NULL,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uniq_ebook_category_slug` (`slug`),
  KEY `idx_ebook_category_status_sort` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `i_ebook_category_map` (
  `ebook_id` int UNSIGNED NOT NULL,
  `category_id` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`ebook_id`, `category_id`),
  KEY `idx_ebook_category_map_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `i_ebook_files` (
  `file_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `ebook_id` int UNSIGNED NOT NULL,
  `version_label` varchar(40) NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(190) NULL,
  `file_size` bigint UNSIGNED NOT NULL DEFAULT 0,
  `mime_type` varchar(120) NULL,
  `checksum_sha256` char(64) NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`file_id`),
  KEY `idx_ebook_files_current` (`ebook_id`, `is_current`),
  KEY `idx_ebook_files_created` (`ebook_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `i_ebook_entitlements` (
  `entitlement_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `ebook_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `purchase_id` int UNSIGNED NULL,
  `source` varchar(32) NOT NULL DEFAULT 'purchase',
  `status` varchar(24) NOT NULL DEFAULT 'active',
  `download_limit` int UNSIGNED NOT NULL DEFAULT 5,
  `download_count` int UNSIGNED NOT NULL DEFAULT 0,
  `starts_at` int UNSIGNED NOT NULL,
  `expires_at` int UNSIGNED NULL,
  `revoked_at` int UNSIGNED NULL,
  `revoke_reason` varchar(255) NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NULL,
  PRIMARY KEY (`entitlement_id`),
  UNIQUE KEY `uniq_ebook_entitlement_user` (`ebook_id`, `user_id`),
  KEY `idx_ebook_entitlement_user_status` (`user_id`, `status`),
  KEY `idx_ebook_entitlement_purchase` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `i_ebook_downloads` (
  `download_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `entitlement_id` int UNSIGNED NOT NULL,
  `ebook_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `file_id` int UNSIGNED NULL,
  `token_hash` char(64) NULL,
  `ip_address` varchar(64) NULL,
  `user_agent` varchar(500) NULL,
  `bytes_sent` bigint UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(24) NOT NULL DEFAULT 'started',
  `created_at` int UNSIGNED NOT NULL,
  `completed_at` int UNSIGNED NULL,
  PRIMARY KEY (`download_id`),
  KEY `idx_ebook_download_entitlement` (`entitlement_id`, `created_at`),
  KEY `idx_ebook_download_user` (`user_id`, `created_at`),
  KEY `idx_ebook_download_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `i_ebook_files`
  (`ebook_id`, `version_label`, `file_path`, `file_name`, `file_size`, `is_current`, `created_by`, `created_at`)
SELECT e.`ebook_id`, COALESCE(NULLIF(e.`version_label`, ''), '1.0'), e.`file_path`, e.`file_name`, e.`file_size`, 1, e.`owner_id`, e.`created_at`
FROM `i_ebooks` e
WHERE e.`file_path` <> ''
  AND NOT EXISTS (SELECT 1 FROM `i_ebook_files` f WHERE f.`ebook_id` = e.`ebook_id` AND f.`file_path` = e.`file_path`);

INSERT INTO `i_ebook_entitlements`
  (`ebook_id`, `user_id`, `purchase_id`, `source`, `status`, `download_limit`, `download_count`, `starts_at`, `expires_at`, `created_at`, `updated_at`)
SELECT p.`ebook_id`, p.`buyer_id`, p.`purchase_id`, 'purchase', 'active', COALESCE(NULLIF(e.`download_limit`, 0), 5), 0,
       p.`created_at`, CASE WHEN e.`access_days` > 0 THEN p.`created_at` + (e.`access_days` * 86400) ELSE NULL END,
       p.`created_at`, COALESCE(p.`updated_at`, p.`created_at`)
FROM `i_ebook_purchases` p
INNER JOIN `i_ebooks` e ON e.`ebook_id` = p.`ebook_id`
WHERE p.`status` IN ('succeeded', 'paid')
ON DUPLICATE KEY UPDATE
  `purchase_id` = VALUES(`purchase_id`), `status` = 'active', `updated_at` = VALUES(`updated_at`);

UPDATE `i_ebooks` e
SET e.`sales_count` = (
  SELECT COUNT(*) FROM `i_ebook_purchases` p
  WHERE p.`ebook_id` = e.`ebook_id` AND p.`status` IN ('succeeded', 'paid')
);

INSERT INTO `i_ebook_categories`
  (`name`, `slug`, `description`, `icon_key`, `sort_order`, `status`, `created_at`, `updated_at`)
VALUES
  ('General', 'general', 'General eBooks', 'ebook', 0, 'active', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `status` = 'active', `updated_at` = VALUES(`updated_at`);

INSERT IGNORE INTO `i_ebook_category_map` (`ebook_id`, `category_id`, `created_at`)
SELECT e.`ebook_id`, c.`category_id`, UNIX_TIMESTAMP()
FROM `i_ebooks` e CROSS JOIN `i_ebook_categories` c
WHERE c.`slug` = 'general';

-- Shared invoice ledger used by eBooks and Audio Rooms.
CREATE TABLE IF NOT EXISTS `i_invoices` (
  `invoice_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint UNSIGNED NOT NULL,
  `buyer_id` int UNSIGNED NOT NULL,
  `seller_id` int UNSIGNED DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gross_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fee_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `provider` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'paid',
  `billing_snapshot` mediumtext COLLATE utf8mb4_unicode_ci,
  `issued_at` int UNSIGNED NOT NULL,
  `paid_at` int UNSIGNED DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`invoice_id`),
  UNIQUE KEY `uniq_invoice_number` (`invoice_number`),
  UNIQUE KEY `uniq_invoice_source` (`source_type`,`source_id`),
  KEY `idx_invoice_buyer_created` (`buyer_id`,`created_at`),
  KEY `idx_invoice_seller_created` (`seller_id`,`created_at`),
  KEY `idx_invoice_status_created` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CreatorPulse v2 schema parity (kept in sync with UpdateSQL.sql).
-- These statements are idempotent so the installer can be safely re-run.
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_audio_rooms' AND column_name = 'duration_seconds'), 'SELECT 1', 'ALTER TABLE `i_audio_rooms` ADD COLUMN `duration_seconds` int UNSIGNED DEFAULT NULL AFTER `max_listeners`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_audio_rooms' AND column_name = 'auto_end_at'), 'SELECT 1', 'ALTER TABLE `i_audio_rooms` ADD COLUMN `auto_end_at` int UNSIGNED DEFAULT NULL AFTER `duration_seconds`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_audio_room_participants' AND column_name = 'chat_muted_until'), 'SELECT 1', 'ALTER TABLE `i_audio_room_participants` ADD COLUMN `chat_muted_until` int UNSIGNED DEFAULT NULL AFTER `hand_raised`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_audio_room_participants' AND column_name = 'removed_by'), 'SELECT 1', 'ALTER TABLE `i_audio_room_participants` ADD COLUMN `removed_by` int UNSIGNED DEFAULT NULL AFTER `chat_muted_until`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_audio_room_participants' AND column_name = 'moderation_reason'), 'SELECT 1', 'ALTER TABLE `i_audio_room_participants` ADD COLUMN `moderation_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `removed_by`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_user_posts' AND column_name = 'post_title'), 'SELECT 1', 'ALTER TABLE `i_user_posts` ADD COLUMN `post_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `post_owner_id`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'i_user_posts' AND index_name = 'idx_post_title'), 'SELECT 1', 'ALTER TABLE `i_user_posts` ADD INDEX `idx_post_title` (`post_title`)');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `i_audio_room_moderation_actions` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `room_id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `actor_id` int UNSIGNED NOT NULL,
  `action` enum('kick','ban','chat_mute','speaker_mute','remove_speaker','unmute_chat') COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration_seconds` int UNSIGNED DEFAULT NULL,
  `expires_at` int UNSIGNED DEFAULT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audio_room_mod_actions_room_user` (`room_id`,`user_id`,`created_at`),
  KEY `idx_audio_room_mod_actions_expires` (`room_id`,`action`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `i_hashtags` (
  `hashtag_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `tag` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_tag` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `use_count` int UNSIGNED NOT NULL DEFAULT 0,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`hashtag_id`),
  UNIQUE KEY `uniq_tag` (`tag`),
  KEY `idx_use_count` (`use_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `i_post_hashtags` (
  `post_id` int NOT NULL,
  `hashtag_id` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`post_id`,`hashtag_id`),
  KEY `idx_hashtag` (`hashtag_id`),
  KEY `idx_post` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'ebook_min_price'), 'SELECT 1', 'ALTER TABLE `i_site_configurations` ADD COLUMN `ebook_min_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'ebook_max_price'), 'SELECT 1', 'ALTER TABLE `i_site_configurations` ADD COLUMN `ebook_max_price` DECIMAL(10,2) NOT NULL DEFAULT 500.00');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'ebook_max_file_mb'), 'SELECT 1', 'ALTER TABLE `i_site_configurations` ADD COLUMN `ebook_max_file_mb` INT UNSIGNED NOT NULL DEFAULT 120');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'ebook_download_limit'), 'SELECT 1', 'ALTER TABLE `i_site_configurations` ADD COLUMN `ebook_download_limit` INT UNSIGNED NOT NULL DEFAULT 5');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_site_configurations' AND column_name = 'ebook_access_days'), 'SELECT 1', 'ALTER TABLE `i_site_configurations` ADD COLUMN `ebook_access_days` INT UNSIGNED NOT NULL DEFAULT 0');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `i_ebook_reviews` (
  `review_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `ebook_id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `rating` tinyint UNSIGNED NOT NULL,
  `review_text` text NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `verified_purchase` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`review_id`),
  UNIQUE KEY `uniq_ebook_review_user` (`ebook_id`,`user_id`),
  KEY `idx_ebook_review_status` (`ebook_id`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `i_ebook_wishlist` (
  `ebook_id` int UNSIGNED NOT NULL,
  `user_id` int NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`ebook_id`,`user_id`),
  KEY `idx_ebook_wishlist_user` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `i_ebook_coupons` (
  `coupon_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `ebook_id` int UNSIGNED NULL,
  `discount_type` varchar(16) NOT NULL DEFAULT 'percent',
  `discount_value` DECIMAL(10,2) NOT NULL,
  `min_order` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `max_uses` int UNSIGNED NOT NULL DEFAULT 0,
  `used_count` int UNSIGNED NOT NULL DEFAULT 0,
  `starts_at` int UNSIGNED NULL,
  `ends_at` int UNSIGNED NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` int UNSIGNED NOT NULL,
  `updated_at` int UNSIGNED NULL,
  PRIMARY KEY (`coupon_id`),
  UNIQUE KEY `uniq_ebook_coupon_code` (`code`),
  KEY `idx_ebook_coupon_status` (`status`,`starts_at`,`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'owner_hidden'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `owner_hidden` tinyint(1) NOT NULL DEFAULT 0 AFTER `status`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'owner_hidden_at'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `owner_hidden_at` int UNSIGNED NULL AFTER `owner_hidden`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'deleted_at'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `deleted_at` int UNSIGNED NULL AFTER `owner_hidden_at`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql_to_run := IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'i_ebooks' AND column_name = 'deleted_by'), 'SELECT 1', 'ALTER TABLE `i_ebooks` ADD COLUMN `deleted_by` int UNSIGNED NULL AFTER `deleted_at`');
PREPARE stmt FROM @sql_to_run; EXECUTE stmt; DEALLOCATE PREPARE stmt;

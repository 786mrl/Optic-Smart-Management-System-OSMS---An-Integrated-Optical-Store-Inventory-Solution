-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 16, 2026 at 02:11 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `optic_pos_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_id` varchar(255) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `changed_by` varchar(100) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `synced` tinyint(1) DEFAULT 0,
  `sync_flag` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_examinations`
--

CREATE TABLE `customer_examinations` (
  `id` int(11) NOT NULL,
  `examination_date` date NOT NULL,
  `examination_code` varchar(25) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `gender` enum('MALE','FEMALE') NOT NULL,
  `age` int(11) DEFAULT NULL,
  `symptoms` text DEFAULT NULL,
  `old_r_sph` varchar(5) DEFAULT NULL,
  `old_r_cyl` varchar(5) DEFAULT NULL,
  `old_r_ax` varchar(3) DEFAULT NULL,
  `old_r_add` varchar(5) DEFAULT NULL,
  `old_l_sph` varchar(5) DEFAULT NULL,
  `old_l_cyl` varchar(5) DEFAULT NULL,
  `old_l_ax` varchar(3) DEFAULT NULL,
  `old_l_add` varchar(5) DEFAULT NULL,
  `new_r_sph` varchar(5) DEFAULT NULL,
  `new_r_cyl` varchar(5) DEFAULT NULL,
  `new_r_ax` varchar(3) DEFAULT NULL,
  `new_r_add` varchar(5) DEFAULT NULL,
  `new_r_visus` varchar(6) DEFAULT NULL,
  `new_l_sph` varchar(5) DEFAULT NULL,
  `new_l_cyl` varchar(5) DEFAULT NULL,
  `new_l_ax` varchar(3) DEFAULT NULL,
  `new_l_add` varchar(5) DEFAULT NULL,
  `new_l_visus` varchar(6) DEFAULT NULL,
  `pd_dist` varchar(10) DEFAULT '62/60',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `invoice_number` varchar(20) DEFAULT '00',
  `exam_notes` text DEFAULT NULL,
  `visual_habit` tinyint(1) DEFAULT 1 COMMENT '1:Indoor, 2:Outdoor, 3:Both',
  `digital_usage` tinyint(1) DEFAULT 1 COMMENT '1:Low, 2:Moderate, 3:High',
  `ucva_r` varchar(10) DEFAULT '20/20',
  `ucva_l` varchar(10) DEFAULT '20/20',
  `lens_modification` tinyint(1) DEFAULT 0,
  `need_distance` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=Yes, 0=No — Kebutuhan jarak jauh',
  `need_intermediate` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=Yes, 0=No — Kebutuhan jarak menengah',
  `need_near` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=Yes, 0=No — Kebutuhan jarak dekat',
  `created_by` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_orders`
--

CREATE TABLE `customer_orders` (
  `id` int(11) UNSIGNED NOT NULL,
  `customer_number` varchar(40) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `is_modified` tinyint(1) NOT NULL DEFAULT 0,
  `frame_ufc` varchar(50) DEFAULT NULL,
  `lens_name` varchar(150) DEFAULT NULL,
  `customer_phone` varchar(30) DEFAULT NULL,
  `customer_address` text DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `order_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `order_status` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `packaging_cost` int(11) NOT NULL DEFAULT 19500,
  `created_by` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Confirmed purchase orders — saved when operator clicks Yes Shopping';

-- --------------------------------------------------------

--
-- Table structure for table `custom_frames`
--

CREATE TABLE `custom_frames` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL COMMENT 'Nomor invoice yang terkait',
  `brand_key` varchar(100) NOT NULL COMMENT 'Pola: dd/mm/yyyy+brand_name, contoh: 05/04/2026+brenden',
  `sell_price` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Harga jual frame',
  `is_purchased` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = belum dibeli, 1 = dibeli',
  `created_by` varchar(100) NOT NULL DEFAULT 'system',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `buy_price` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Frame custom yang tidak ada di frames_main maupun frame_staging';

-- --------------------------------------------------------

--
-- Table structure for table `deleted_records`
--

CREATE TABLE `deleted_records` (
  `id` int(11) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_id` varchar(255) NOT NULL,
  `deleted_by` varchar(100) NOT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `synced` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `frames_main`
--

CREATE TABLE `frames_main` (
  `ufc` varchar(100) NOT NULL,
  `brand` varchar(50) DEFAULT NULL,
  `frame_code` varchar(50) DEFAULT NULL,
  `frame_size` varchar(50) DEFAULT NULL,
  `color_code` varchar(50) DEFAULT NULL,
  `material` varchar(50) DEFAULT NULL,
  `lens_shape` varchar(50) DEFAULT NULL,
  `structure` enum('full-rim','semi-rimless','rimless') DEFAULT NULL,
  `size_range` enum('small','medium','large') DEFAULT NULL,
  `gender_category` enum('men','female','unisex') NOT NULL DEFAULT 'unisex',
  `buy_price` decimal(15,2) DEFAULT NULL,
  `sell_price` decimal(15,2) DEFAULT NULL,
  `price_secret_code` varchar(20) DEFAULT NULL,
  `stock` int(11) DEFAULT 0,
  `stock_age` enum('very old','old','new') DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `frame_staging`
--

CREATE TABLE `frame_staging` (
  `ufc` varchar(100) NOT NULL,
  `brand` varchar(50) NOT NULL,
  `frame_code` varchar(50) DEFAULT 'lz-786',
  `frame_size` varchar(50) DEFAULT '00-00-786',
  `color_code` varchar(50) DEFAULT NULL,
  `material` varchar(50) DEFAULT NULL,
  `lens_shape` varchar(50) DEFAULT NULL,
  `structure` enum('full-rim','semi-rimless','rimless') DEFAULT NULL,
  `size_range` enum('small','medium','large') DEFAULT NULL,
  `gender_category` enum('men','female','unisex') NOT NULL DEFAULT 'unisex',
  `buy_price` decimal(15,2) DEFAULT 0.00,
  `sell_price` decimal(15,2) DEFAULT 0.00,
  `price_secret_code` varchar(20) DEFAULT NULL,
  `stock` int(11) DEFAULT 1,
  `stock_age` enum('very old','old','new') DEFAULT 'new',
  `created_by` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `last_sync`
--

CREATE TABLE `last_sync` (
  `id` int(11) NOT NULL,
  `direction` enum('push','pull') NOT NULL,
  `target_ip` varchar(100) NOT NULL,
  `synced_at` datetime NOT NULL,
  `total_rows` int(11) DEFAULT 0,
  `total_dels` int(11) DEFAULT 0,
  `done_by` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pending_sync`
--

CREATE TABLE `pending_sync` (
  `id` int(11) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_id` varchar(255) NOT NULL,
  `action` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `data_snapshot` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `prescription_modifications`
--

CREATE TABLE `prescription_modifications` (
  `modification_id` int(11) NOT NULL,
  `invoice_number` varchar(20) NOT NULL,
  `od_sph` varchar(10) DEFAULT NULL,
  `od_cyl` varchar(10) DEFAULT NULL,
  `od_axis` varchar(10) DEFAULT NULL,
  `od_add` varchar(10) DEFAULT NULL,
  `os_sph` varchar(10) DEFAULT NULL,
  `os_cyl` varchar(10) DEFAULT NULL,
  `os_axis` varchar(10) DEFAULT NULL,
  `os_add` varchar(10) DEFAULT NULL,
  `modified_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('backup_location', '/var/www/backup/optic_pos/', 'Server Path for Backup File Storage'),
('barcode_guide_image_location', 'image/barcode_guide_1777292613.png', 'Path to the comprehensive guide barcode image file.'),
('brand_image_location', 'image/brand_logo_1769435885.png', 'File path or URL for the company brand logo image.'),
('copyright_footer', '© 2026 LENZA OPTIC. All rights reserved.', 'The copyright message displayed in the footer of all application pages.'),
('invoice_format_prefix', '[data_sequence]/LO-C/[invoice_number]/[test_result_table_number]/[month]/[year]', 'Prefix for Invoice Numbering (e.g., INV-2026-...)'),
('last_backup_date', '2026-01-01', 'Date of the Last System Backup (Auto-updated)'),
('lens_lab_lead_time_days', '10', 'Default estimated waiting time (in days) for lab-order lens orders'),
('lens_stock_lead_time_days', '2', 'Default estimated waiting time (in days) for stock lens orders'),
('main_admin_shortcut_password', '8643262924', 'Plaintext copy of the Main Admin password. Update manually whenever the real password changes.'),
('main_admin_shortcut_password_init', '1', 'Triggers password shortcut translation.'),
('main_admin_shortcut_username', 'LenZa786', 'The real Main Admin username that the shortcut translates to.'),
('main_admin_shortcut_username_init', '1', 'Triggers username shortcut translation.'),
('main_admin_username', 'LenZa786', 'Username of the user designated as Main Admin. Changing this value requires the current Main Admin login password.'),
('starting_invoice_number', '15.40', 'The starting sequence/text string for invoice numbering (resets automatically).'),
('store_address', 'Jl. Apel Raya, No. 50, Kuranji, Padang, Sumatera Barat, 25157, Indonesia', 'Store Physical Address'),
('store_name', 'LENZA OPTIC', 'Store Name for Receipts and Reports'),
('store_phone', '+62 812 6764 6916', 'Store Contact Phone Number');

-- --------------------------------------------------------

--
-- Table structure for table `sync_status`
--

CREATE TABLE `sync_status` (
  `id` int(11) NOT NULL,
  `log_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `synced_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','staff','viewer') NOT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `session_token` varchar(64) DEFAULT NULL,
  `session_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password_hash`, `role`, `is_approved`, `created_at`, `last_login`, `session_token`, `session_expires`) VALUES
(1, 'LenZa786', '$2y$10$E5ZXU41IpXcB443wtKCIou/cpEaFMa7k2tuOx83ZAQ9soeUPagGWm', 'admin', 1, '2026-01-12 05:15:58', '2026-06-15 19:02:16', NULL, NULL),
(17, 'rais786', '$2y$10$Nvp2WWM.r5i1uM7VQD9t8eTZzeGNtDEz.A0NhEdUjPGzeZ8z7bFeO', 'staff', 1, '2026-05-28 13:17:44', '2026-06-08 07:27:52', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `customer_examinations`
--
ALTER TABLE `customer_examinations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `examination_code` (`examination_code`),
  ADD KEY `invoice_number` (`invoice_number`);

--
-- Indexes for table `customer_orders`
--
ALTER TABLE `customer_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_customer_number` (`customer_number`),
  ADD UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  ADD KEY `idx_order_status` (`order_status`),
  ADD KEY `idx_order_date` (`order_date`);

--
-- Indexes for table `custom_frames`
--
ALTER TABLE `custom_frames`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice_number` (`invoice_number`),
  ADD KEY `idx_brand_key` (`brand_key`),
  ADD KEY `idx_is_purchased` (`is_purchased`);

--
-- Indexes for table `deleted_records`
--
ALTER TABLE `deleted_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_deleted` (`table_name`,`record_id`);

--
-- Indexes for table `frames_main`
--
ALTER TABLE `frames_main`
  ADD PRIMARY KEY (`ufc`);

--
-- Indexes for table `frame_staging`
--
ALTER TABLE `frame_staging`
  ADD PRIMARY KEY (`ufc`);

--
-- Indexes for table `last_sync`
--
ALTER TABLE `last_sync`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pending_sync`
--
ALTER TABLE `pending_sync`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `prescription_modifications`
--
ALTER TABLE `prescription_modifications`
  ADD PRIMARY KEY (`modification_id`),
  ADD KEY `fk_invoice_mod` (`invoice_number`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `sync_status`
--
ALTER TABLE `sync_status`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_log_id` (`log_id`),
  ADD KEY `idx_username` (`username`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_examinations`
--
ALTER TABLE `customer_examinations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_orders`
--
ALTER TABLE `customer_orders`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `custom_frames`
--
ALTER TABLE `custom_frames`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deleted_records`
--
ALTER TABLE `deleted_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `last_sync`
--
ALTER TABLE `last_sync`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pending_sync`
--
ALTER TABLE `pending_sync`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prescription_modifications`
--
ALTER TABLE `prescription_modifications`
  MODIFY `modification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sync_status`
--
ALTER TABLE `sync_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `prescription_modifications`
--
ALTER TABLE `prescription_modifications`
  ADD CONSTRAINT `fk_invoice_mod` FOREIGN KEY (`invoice_number`) REFERENCES `customer_examinations` (`invoice_number`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 22, 2026 at 07:35 AM
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
-- Database: `lisani_aos_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `id` int(10) UNSIGNED NOT NULL,
  `activity_name` varchar(150) NOT NULL,
  `cashflow` enum('inflow','outflow','in-out') NOT NULL,
  `relative_path` varchar(255) NOT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activities`
--

INSERT INTO `activities` (`id`, `activity_name`, `cashflow`, `relative_path`, `created_by`, `created_at`) VALUES
(1, 'SAYYER I', 'outflow', 'input/2026/dates/001/', 1, '2026-09-14 17:49:48'),
(4, 'SUKKARI LISANI', 'outflow', 'input/2026/dates/002/', 1, '2026-09-18 20:03:48');

-- --------------------------------------------------------

--
-- Table structure for table `company_documents`
--

CREATE TABLE `company_documents` (
  `id` int(10) UNSIGNED NOT NULL,
  `document_name` varchar(191) NOT NULL COMMENT 'Final name, pattern: [name]_[year]',
  `document_date` date NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL COMMENT 'Actual filename on disk',
  `file_path` varchar(255) NOT NULL COMMENT 'Relative path under storage/company/legal_document/',
  `file_ext` varchar(20) NOT NULL,
  `file_size` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `company_documents`
--

INSERT INTO `company_documents` (`id`, `document_name`, `document_date`, `original_filename`, `stored_filename`, `file_path`, `file_ext`, `file_size`, `uploaded_by`, `created_at`, `updated_at`) VALUES
(1, 'SK Menkumham perubahan anggaran dasar_2026', '2026-09-03', 'cetak_sk_4026090312240633.pdf', 'sk_menkumham_perubahan_anggaran_dasar_2026.pdf', 'company/legal_document/sk_menkumham_perubahan_anggaran_dasar_2026.pdf', 'pdf', 329485, 1, '2026-09-16 08:27:17', '2026-09-16 08:27:17');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(10) UNSIGNED NOT NULL,
  `year` smallint(5) UNSIGNED NOT NULL,
  `customer_name` varchar(150) NOT NULL,
  `phone_number` varchar(30) NOT NULL,
  `total_inflow` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_outflow` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `profit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `year`, `customer_name`, `phone_number`, `total_inflow`, `total_outflow`, `total_paid`, `profit`, `created_at`) VALUES
(1, 2026, 'TOKO AN-NAJIHAH HERBAL (KAK PUTRI)', '+6281265472547', 33375000.00, 0.00, 0.00, 0.00, '2026-09-18 10:55:33'),
(2, 2026, 'RAIS', '+6281267646916', 2650000.00, 0.00, 0.00, 0.00, '2026-09-21 04:36:37');

-- --------------------------------------------------------

--
-- Table structure for table `customer_item_prices`
--

CREATE TABLE `customer_item_prices` (
  `id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `logistic_id` int(10) UNSIGNED NOT NULL,
  `price` decimal(15,2) NOT NULL,
  `price_date` date NOT NULL,
  `unit_label` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customer_item_prices`
--

INSERT INTO `customer_item_prices` (`id`, `customer_id`, `logistic_id`, `price`, `price_date`, `unit_label`, `created_at`, `updated_at`) VALUES
(1, 1, 2, 136000.00, '2026-09-18', 'MASTER CARTON', '2026-09-18 13:26:04', '2026-09-18 13:26:04'),
(2, 1, 3, 345000.00, '2026-09-18', 'MASTER CARTON', '2026-09-18 13:26:21', '2026-09-18 13:26:21'),
(3, 1, 2, 145000.00, '2026-09-18', 'MASTER CARTON', '2026-09-18 13:26:42', '2026-09-18 13:26:42'),
(4, 2, 2, 140000.00, '2026-09-21', 'MASTER CARTON', '2026-09-21 04:47:41', '2026-09-21 04:47:41'),
(5, 2, 2, 130000.00, '2026-09-21', 'MASTER CARTON', '2026-09-21 09:58:51', '2026-09-21 09:58:51'),
(6, 2, 3, 260000.00, '2026-09-21', 'MASTER CARTON', '2026-09-21 10:49:43', '2026-09-21 10:49:43');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `invoice_number` varchar(60) NOT NULL,
  `sequence_number` smallint(5) UNSIGNED NOT NULL,
  `period_month` tinyint(3) UNSIGNED NOT NULL,
  `period_year` smallint(5) UNSIGNED NOT NULL,
  `status` enum('open','paid') NOT NULL DEFAULT 'open',
  `total_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `customer_id`, `invoice_number`, `sequence_number`, `period_month`, `period_year`, `status`, `total_amount`, `paid_amount`, `paid_at`, `created_at`, `updated_at`) VALUES
(1, 1, '001/inv/laj-TAHKP-1/IX/2026', 1, 9, 2026, 'open', 33375000.00, 0.00, NULL, '2026-09-21 04:39:50', '2026-09-21 12:47:18'),
(2, 2, '001/inv/laj-R-1/IX/2026', 1, 9, 2026, 'open', 2650000.00, 0.00, NULL, '2026-09-21 04:47:41', '2026-09-21 10:53:55');

-- --------------------------------------------------------

--
-- Table structure for table `logistics`
--

CREATE TABLE `logistics` (
  `id` int(10) UNSIGNED NOT NULL,
  `activity_id` int(10) UNSIGNED NOT NULL,
  `incoming_date` date DEFAULT NULL,
  `primary_qty` decimal(12,2) DEFAULT NULL,
  `primary_unit_label` varchar(100) DEFAULT NULL,
  `primary_unit_weight_kg` decimal(12,3) DEFAULT NULL,
  `remaining_primary_qty` decimal(12,2) DEFAULT NULL,
  `total_taken_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `secondary_unit_label` varchar(100) DEFAULT NULL,
  `secondary_unit_weight_kg` decimal(12,3) DEFAULT NULL,
  `secondary_ratio_per_primary` decimal(12,3) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logistics`
--

INSERT INTO `logistics` (`id`, `activity_id`, `incoming_date`, `primary_qty`, `primary_unit_label`, `primary_unit_weight_kg`, `remaining_primary_qty`, `total_taken_qty`, `secondary_unit_label`, `secondary_unit_weight_kg`, `secondary_ratio_per_primary`, `created_by`, `created_at`, `updated_at`) VALUES
(2, 4, NULL, 1500.00, 'MASTER CARTON', 12.000, 1355.00, 145.00, 'BABY CARTON', 3.000, 4.000, 1, '2026-09-18 20:06:51', '2026-09-21 19:46:02'),
(3, 1, NULL, 5000.00, 'MASTER CARTON', 10.000, 4955.00, 45.00, 'NO PRIMARY CARTON', 10.000, 1.000, 1, '2026-09-18 20:25:26', '2026-09-21 19:47:18');

-- --------------------------------------------------------

--
-- Table structure for table `logistic_documents`
--

CREATE TABLE `logistic_documents` (
  `id` int(10) UNSIGNED NOT NULL,
  `activity_id` int(10) UNSIGNED NOT NULL,
  `document_type` enum('shipper','custom','consignee') NOT NULL,
  `document_name` varchar(150) NOT NULL,
  `document_date` date DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_by` int(10) UNSIGNED DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logistic_documents`
--

INSERT INTO `logistic_documents` (`id`, `activity_id`, `document_type`, `document_name`, `document_date`, `file_path`, `uploaded_by`, `uploaded_at`) VALUES
(4, 4, 'shipper', 'DO', '2026-09-14', 'input/2026/dates/002/import_documents/shipper/20260918152311_do.pdf', 1, '2026-09-18 20:23:11');

-- --------------------------------------------------------

--
-- Table structure for table `logistic_movements`
--

CREATE TABLE `logistic_movements` (
  `id` int(10) UNSIGNED NOT NULL,
  `logistic_id` int(10) UNSIGNED NOT NULL,
  `source_movement_id` int(10) UNSIGNED DEFAULT NULL,
  `customer_id` int(10) UNSIGNED DEFAULT NULL,
  `movement_type` enum('in','out') NOT NULL,
  `movement_date` date NOT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `driver_name` varchar(150) DEFAULT NULL,
  `police_number` varchar(30) DEFAULT NULL,
  `qty_primary_package` decimal(12,2) NOT NULL,
  `price` decimal(15,2) DEFAULT NULL COMMENT 'Harga satuan per unit primary package',
  `total_price` decimal(15,2) DEFAULT NULL COMMENT 'qty_primary_package x price',
  `invoice_id` int(10) UNSIGNED DEFAULT NULL,
  `batch_id` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logistic_movements`
--

INSERT INTO `logistic_movements` (`id`, `logistic_id`, `source_movement_id`, `customer_id`, `movement_type`, `movement_date`, `customer_name`, `driver_name`, `police_number`, `qty_primary_package`, `price`, `total_price`, `invoice_id`, `batch_id`, `created_by`, `created_at`) VALUES
(1, 2, NULL, 1, 'out', '2026-09-21', 'TOKO AN-NAJIHAH HERBAL (KAK PUTRI)', 'PAK FADLUN', 'BK 1284 AXE', 5.00, 145000.00, 725000.00, 1, 1, 1, '2026-09-21 11:39:50'),
(2, 3, NULL, 1, 'out', '2026-09-21', 'TOKO AN-NAJIHAH HERBAL (KAK PUTRI)', 'PAK FADLUN', 'BK 1284 AXE', 8.00, 345000.00, 2760000.00, 1, 1, 1, '2026-09-21 11:39:50'),
(3, 2, NULL, 1, 'out', '2026-09-21', 'TOKO AN-NAJIHAH HERBAL (KAK PUTRI)', 'RENDI', 'BL 2114 BNN', 50.00, 145000.00, 7250000.00, 1, 3, 1, '2026-09-21 11:45:13'),
(4, 2, NULL, 2, 'out', '2026-09-21', 'RAIS', 'PAK RE', 'BA 3421 AAS', 5.00, 140000.00, 700000.00, 2, 4, 1, '2026-09-21 11:47:41'),
(5, 3, NULL, 1, 'out', '2026-09-21', 'TOKO AN-NAJIHAH HERBAL (KAK PUTRI)', 'ADI', 'BK 4124 BL', 2.00, 345000.00, 690000.00, 1, 5, 1, '2026-09-21 11:52:34'),
(6, 2, NULL, 2, 'out', '2026-09-21', 'RAIS', 'RAIS', 'BA 1687 AWN', 5.00, 130000.00, 650000.00, 2, 6, 1, '2026-09-21 17:01:54'),
(7, 3, NULL, 2, 'out', '2026-09-21', 'RAIS', 'RAIS', 'BA 1687 AWN', 5.00, 260000.00, 1300000.00, 2, 7, 1, '2026-09-21 17:49:43'),
(8, 2, NULL, 1, 'out', '2026-09-21', 'TOKO AN-NAJIHAH HERBAL (KAK PUTRI)', 'PAK FADLUN', 'BL 3415 AHY', 80.00, 145000.00, 11600000.00, 1, 8, 1, '2026-09-21 19:44:40'),
(9, 3, NULL, 1, 'out', '2026-09-21', 'TOKO AN-NAJIHAH HERBAL (KAK PUTRI)', 'PAK FADLUN', 'BL 3415 AHY', 30.00, 345000.00, 10350000.00, 1, 8, 1, '2026-09-21 19:44:40');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `category` enum('disbursement','other') NOT NULL,
  `transaction_date` date NOT NULL,
  `source_bank` varchar(100) NOT NULL DEFAULT '',
  `destination_bank` varchar(100) NOT NULL DEFAULT '',
  `source_account_number` varchar(60) NOT NULL DEFAULT '',
  `source_account_name` varchar(150) NOT NULL DEFAULT '',
  `destination_account_number` varchar(60) NOT NULL DEFAULT '',
  `destination_account_name` varchar(150) NOT NULL DEFAULT '',
  `notes` varchar(500) NOT NULL DEFAULT '',
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `amount` decimal(18,2) NOT NULL,
  `exchange_rate` decimal(18,6) DEFAULT NULL,
  `final_amount_idr` decimal(18,2) NOT NULL,
  `document_path` varchar(255) NOT NULL,
  `document_original_name` varchar(255) NOT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `category`, `transaction_date`, `source_bank`, `destination_bank`, `source_account_number`, `source_account_name`, `destination_account_number`, `destination_account_name`, `notes`, `currency`, `amount`, `exchange_rate`, `final_amount_idr`, `document_path`, `document_original_name`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'disbursement', '2026-06-16', 'MAYBANK', 'BANK MUAMALAT', '', '', '1205 0003 5177 19', 'RAMIN AKBARINAKHIJAVANI', 'Maher Rostam', 'RM', 7000.00, 4325.000000, 30275000.00, 'input/2026/dates/001/disbursement/20260616_453546b7.pdf', 'M2U_20260615_1401.pdf', 1, '2026-09-20 15:11:22', '2026-09-20 15:11:22'),
(2, 'disbursement', '2026-06-15', 'MANDIRI', 'CIMB BANK BERHAD', '1060030131984.', 'LISANI ALAF JAYA', '850002311940', 'EDGE SPIRAL SDN BHD', 'RFB PAYMENT FOR INVOICE NO. 423', 'USD', 17005.00, 17690.000000, 300818450.00, 'input/2026/dates/001/disbursement/second_payment_20260615.pdf', 'USD 17K Edge Spiral Sayer.pdf', 1, '2026-09-20 15:55:37', '2026-09-20 15:55:37');

-- --------------------------------------------------------

--
-- Table structure for table `transaction_disbursements`
--

CREATE TABLE `transaction_disbursements` (
  `id` int(10) UNSIGNED NOT NULL,
  `transaction_id` int(10) UNSIGNED NOT NULL,
  `activity_id` int(10) UNSIGNED NOT NULL,
  `cashflow_type` enum('inflow','outflow','in-out') NOT NULL,
  `transaction_purpose` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transaction_disbursements`
--

INSERT INTO `transaction_disbursements` (`id`, `transaction_id`, `activity_id`, `cashflow_type`, `transaction_purpose`, `created_at`) VALUES
(1, 1, 1, 'outflow', 'FIRST PAYMENT', '2026-09-20 15:11:22'),
(2, 2, 1, 'outflow', 'SECOND PAYMENT', '2026-09-20 15:55:37');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','staff') NOT NULL DEFAULT 'staff',
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `session_token` varchar(64) DEFAULT NULL,
  `session_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password_hash`, `role`, `is_approved`, `created_at`, `last_login`, `session_token`, `session_expires`) VALUES
(1, 'Rais786', '$2y$10$QciWVGPK9aGHjy05rBoXgOWfCAesfocowc0vt4QCMHVeVzsVuGDS6', 'admin', 1, '2026-09-10 21:34:57', '2026-09-22 06:25:01', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_relative_path` (`relative_path`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indexes for table `company_documents`
--
ALTER TABLE `company_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_document_date` (`document_date`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_year_customer` (`year`,`customer_name`);

--
-- Indexes for table `customer_item_prices`
--
ALTER TABLE `customer_item_prices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_logistic` (`logistic_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_invoice_number` (`invoice_number`),
  ADD UNIQUE KEY `uniq_customer_period_seq` (`customer_id`,`period_year`,`period_month`,`sequence_number`),
  ADD KEY `idx_customer_status` (`customer_id`,`status`);

--
-- Indexes for table `logistics`
--
ALTER TABLE `logistics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_activity` (`activity_id`);

--
-- Indexes for table `logistic_documents`
--
ALTER TABLE `logistic_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_activity` (`activity_id`);

--
-- Indexes for table `logistic_movements`
--
ALTER TABLE `logistic_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_logistic` (`logistic_id`),
  ADD KEY `idx_customer` (`customer_id`),
  ADD KEY `idx_invoice` (`invoice_id`),
  ADD KEY `idx_movements_batch` (`batch_id`),
  ADD KEY `idx_lm_source_movement` (`source_movement_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_txn_date` (`transaction_date`),
  ADD KEY `idx_txn_category` (`category`);

--
-- Indexes for table `transaction_disbursements`
--
ALTER TABLE `transaction_disbursements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_txn` (`transaction_id`),
  ADD KEY `idx_activity` (`activity_id`);

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
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `company_documents`
--
ALTER TABLE `company_documents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customer_item_prices`
--
ALTER TABLE `customer_item_prices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `logistics`
--
ALTER TABLE `logistics`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `logistic_documents`
--
ALTER TABLE `logistic_documents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `logistic_movements`
--
ALTER TABLE `logistic_movements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `transaction_disbursements`
--
ALTER TABLE `transaction_disbursements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `logistics`
--
ALTER TABLE `logistics`
  ADD CONSTRAINT `fk_logistics_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `logistic_documents`
--
ALTER TABLE `logistic_documents`
  ADD CONSTRAINT `fk_logdoc_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `logistic_movements`
--
ALTER TABLE `logistic_movements`
  ADD CONSTRAINT `fk_lm_source_movement` FOREIGN KEY (`source_movement_id`) REFERENCES `logistic_movements` (`id`),
  ADD CONSTRAINT `fk_logmov_logistic` FOREIGN KEY (`logistic_id`) REFERENCES `logistics` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

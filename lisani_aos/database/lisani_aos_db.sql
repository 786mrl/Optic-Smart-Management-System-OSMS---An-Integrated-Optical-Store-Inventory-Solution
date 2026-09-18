-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 18, 2026 at 02:51 PM
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
(3, 'SUKKARI LISANI', 'outflow', 'input/2025/dates/001/', 1, '2026-09-17 19:52:08');

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
  `profit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `year`, `customer_name`, `phone_number`, `total_inflow`, `total_outflow`, `profit`, `created_at`) VALUES
(1, 2026, 'TOKO AN-NAJIHAH HERBAL (KAK PUTRI)', '+6281265472547', 0.00, 0.00, 0.00, '2026-09-18 10:55:33');

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
  `secondary_unit_label` varchar(100) DEFAULT NULL,
  `secondary_unit_weight_kg` decimal(12,3) DEFAULT NULL,
  `secondary_ratio_per_primary` decimal(12,3) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- --------------------------------------------------------

--
-- Table structure for table `logistic_movements`
--

CREATE TABLE `logistic_movements` (
  `id` int(10) UNSIGNED NOT NULL,
  `logistic_id` int(10) UNSIGNED NOT NULL,
  `movement_type` enum('in','out') NOT NULL,
  `movement_date` date NOT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `driver_name` varchar(150) DEFAULT NULL,
  `police_number` varchar(30) DEFAULT NULL,
  `qty_primary_package` decimal(12,2) NOT NULL,
  `price` decimal(15,2) DEFAULT NULL COMMENT 'Harga satuan per unit primary package',
  `total_price` decimal(15,2) DEFAULT NULL COMMENT 'qty_primary_package x price',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
(1, 'Rais786', '$2y$10$QciWVGPK9aGHjy05rBoXgOWfCAesfocowc0vt4QCMHVeVzsVuGDS6', 'admin', 1, '2026-09-10 21:34:57', '2026-09-18 12:54:00', '44b9f302a2e01e8bc9de45d055d4784182a79f9534263d1f7db706f9133b5785', '2026-09-18 20:54:00');

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
  ADD KEY `idx_logistic` (`logistic_id`);

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
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `company_documents`
--
ALTER TABLE `company_documents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customer_item_prices`
--
ALTER TABLE `customer_item_prices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `logistics`
--
ALTER TABLE `logistics`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `logistic_documents`
--
ALTER TABLE `logistic_documents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `logistic_movements`
--
ALTER TABLE `logistic_movements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

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
  ADD CONSTRAINT `fk_logmov_logistic` FOREIGN KEY (`logistic_id`) REFERENCES `logistics` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

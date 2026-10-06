-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 06, 2026 at 06:17 PM
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
  `total_price_adjustments` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `credit_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `profit` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
-- Table structure for table `defective_stock_events`
--

CREATE TABLE `defective_stock_events` (
  `id` int(10) UNSIGNED NOT NULL,
  `logistic_id` int(10) UNSIGNED NOT NULL,
  `event_type` enum('repaired_to_normal','reference_price_set') NOT NULL,
  `qty` decimal(12,2) DEFAULT NULL,
  `old_price` decimal(15,2) DEFAULT NULL,
  `new_price` decimal(15,2) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `disbursement_categories`
--

CREATE TABLE `disbursement_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `disbursement_categories`
--

INSERT INTO `disbursement_categories` (`id`, `category_name`, `created_by`, `created_at`) VALUES
(1, 'PURCHASE PAYMENT', NULL, '2026-10-06 22:21:36'),
(2, 'CLEARANCE FEES', NULL, '2026-10-06 22:21:36'),
(3, 'OPERATIONAL EXPENSES', NULL, '2026-10-06 22:21:36');

-- --------------------------------------------------------

--
-- Table structure for table `investors`
--

CREATE TABLE `investors` (
  `id` int(10) UNSIGNED NOT NULL,
  `investor_name` varchar(150) NOT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `investor_activity_allocations`
--

CREATE TABLE `investor_activity_allocations` (
  `id` int(10) UNSIGNED NOT NULL,
  `investor_id` int(10) UNSIGNED NOT NULL,
  `activity_id` int(10) UNSIGNED NOT NULL,
  `allocation_percent` decimal(5,2) NOT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `investor_activity_settings`
--

CREATE TABLE `investor_activity_settings` (
  `activity_id` int(10) UNSIGNED NOT NULL,
  `profit_distribution_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `investor_deposits`
--

CREATE TABLE `investor_deposits` (
  `id` int(10) UNSIGNED NOT NULL,
  `investor_id` int(10) UNSIGNED NOT NULL,
  `deposit_date` date NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `amount` decimal(18,2) NOT NULL,
  `exchange_rate` decimal(18,6) DEFAULT NULL,
  `final_amount_idr` decimal(18,2) NOT NULL,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `investor_profit_payments`
--

CREATE TABLE `investor_profit_payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `investor_id` int(10) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `amount_idr` decimal(18,2) NOT NULL,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `investor_support_expenses`
--

CREATE TABLE `investor_support_expenses` (
  `id` int(10) UNSIGNED NOT NULL,
  `investor_id` int(10) UNSIGNED NOT NULL,
  `expense_date` date NOT NULL,
  `category` enum('return_capital','aid','other') NOT NULL,
  `amount_idr` decimal(18,2) NOT NULL,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- --------------------------------------------------------

--
-- Table structure for table `invoice_payments`
--

CREATE TABLE `invoice_payments` (
  `id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `source_bank` varchar(100) NOT NULL DEFAULT '',
  `source_account_name` varchar(150) NOT NULL DEFAULT '',
  `destination_bank` varchar(100) NOT NULL DEFAULT '',
  `destination_account_number` varchar(100) NOT NULL DEFAULT '',
  `destination_account_name` varchar(150) NOT NULL DEFAULT '',
  `notes` varchar(500) NOT NULL DEFAULT '',
  `proof_path` varchar(500) DEFAULT NULL,
  `proof_original_name` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_refunds`
--

CREATE TABLE `invoice_refunds` (
  `id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `refund_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `method` varchar(100) NOT NULL DEFAULT '',
  `source_bank` varchar(100) DEFAULT NULL,
  `source_account_number` varchar(60) DEFAULT NULL,
  `source_account_name` varchar(150) DEFAULT NULL,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `proof_path` varchar(500) DEFAULT NULL,
  `proof_original_name` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `logistics`
--

CREATE TABLE `logistics` (
  `id` int(10) UNSIGNED NOT NULL,
  `activity_id` int(10) UNSIGNED NOT NULL,
  `product_name` varchar(150) NOT NULL,
  `incoming_date` date DEFAULT NULL,
  `primary_qty` decimal(12,2) DEFAULT NULL,
  `primary_unit_label` varchar(100) DEFAULT NULL,
  `primary_unit_weight_kg` decimal(12,3) DEFAULT NULL,
  `remaining_primary_qty` decimal(12,2) DEFAULT NULL,
  `defective_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_taken_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `defective_taken_qty` decimal(12,2) NOT NULL DEFAULT 0.00,
  `defective_reference_price` decimal(15,2) DEFAULT NULL,
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
  `source_movement_id` int(10) UNSIGNED DEFAULT NULL,
  `customer_id` int(10) UNSIGNED DEFAULT NULL,
  `movement_type` enum('in','out','price_adjustment') NOT NULL,
  `stock_source` enum('normal','defective') NOT NULL DEFAULT 'normal',
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

-- --------------------------------------------------------

--
-- Table structure for table `transaction_activities`
--

CREATE TABLE `transaction_activities` (
  `id` int(10) UNSIGNED NOT NULL,
  `transaction_id` int(10) UNSIGNED NOT NULL,
  `activity_id` int(10) UNSIGNED NOT NULL,
  `amount_idr` decimal(18,2) NOT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transaction_disbursements`
--

CREATE TABLE `transaction_disbursements` (
  `id` int(10) UNSIGNED NOT NULL,
  `transaction_id` int(10) UNSIGNED NOT NULL,
  `activity_id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `cashflow_type` enum('inflow','outflow','in-out') NOT NULL,
  `transaction_purpose` varchar(255) NOT NULL,
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
(1, 'Rais786', '$2y$10$QciWVGPK9aGHjy05rBoXgOWfCAesfocowc0vt4QCMHVeVzsVuGDS6', 'admin', 1, '2026-09-10 21:34:57', '2026-10-06 17:55:22', NULL, NULL);

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
-- Indexes for table `defective_stock_events`
--
ALTER TABLE `defective_stock_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_defective_stock_events_logistic` (`logistic_id`);

--
-- Indexes for table `disbursement_categories`
--
ALTER TABLE `disbursement_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_disbursement_category_name` (`category_name`);

--
-- Indexes for table `investors`
--
ALTER TABLE `investors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_investors_name` (`investor_name`);

--
-- Indexes for table `investor_activity_allocations`
--
ALTER TABLE `investor_activity_allocations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_alloc_investor_activity` (`investor_id`,`activity_id`),
  ADD KEY `idx_alloc_activity` (`activity_id`);

--
-- Indexes for table `investor_activity_settings`
--
ALTER TABLE `investor_activity_settings`
  ADD PRIMARY KEY (`activity_id`);

--
-- Indexes for table `investor_deposits`
--
ALTER TABLE `investor_deposits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_deposits_investor` (`investor_id`),
  ADD KEY `idx_deposits_date` (`deposit_date`);

--
-- Indexes for table `investor_profit_payments`
--
ALTER TABLE `investor_profit_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_profitpay_investor` (`investor_id`),
  ADD KEY `idx_profitpay_date` (`payment_date`);

--
-- Indexes for table `investor_support_expenses`
--
ALTER TABLE `investor_support_expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_support_investor` (`investor_id`),
  ADD KEY `idx_support_date` (`expense_date`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_invoice_number` (`invoice_number`),
  ADD UNIQUE KEY `uniq_customer_period_seq` (`customer_id`,`period_year`,`period_month`,`sequence_number`),
  ADD KEY `idx_customer_status` (`customer_id`,`status`);

--
-- Indexes for table `invoice_payments`
--
ALTER TABLE `invoice_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice_payments_invoice` (`invoice_id`),
  ADD KEY `idx_invoice_payments_customer` (`customer_id`);

--
-- Indexes for table `invoice_refunds`
--
ALTER TABLE `invoice_refunds`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invoice_refunds_customer` (`customer_id`);

--
-- Indexes for table `logistics`
--
ALTER TABLE `logistics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_activity_product` (`activity_id`,`product_name`),
  ADD KEY `idx_logistics_activity` (`activity_id`);

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
-- Indexes for table `transaction_activities`
--
ALTER TABLE `transaction_activities`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_txact_tx_activity` (`transaction_id`,`activity_id`),
  ADD KEY `idx_txact_activity` (`activity_id`);

--
-- Indexes for table `transaction_disbursements`
--
ALTER TABLE `transaction_disbursements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_txn` (`transaction_id`),
  ADD KEY `idx_activity` (`activity_id`),
  ADD KEY `idx_td_category` (`category_id`);

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
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_documents`
--
ALTER TABLE `company_documents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_item_prices`
--
ALTER TABLE `customer_item_prices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `defective_stock_events`
--
ALTER TABLE `defective_stock_events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `disbursement_categories`
--
ALTER TABLE `disbursement_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `investors`
--
ALTER TABLE `investors`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `investor_activity_allocations`
--
ALTER TABLE `investor_activity_allocations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `investor_deposits`
--
ALTER TABLE `investor_deposits`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `investor_profit_payments`
--
ALTER TABLE `investor_profit_payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `investor_support_expenses`
--
ALTER TABLE `investor_support_expenses`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_payments`
--
ALTER TABLE `invoice_payments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_refunds`
--
ALTER TABLE `invoice_refunds`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `logistics`
--
ALTER TABLE `logistics`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `logistic_documents`
--
ALTER TABLE `logistic_documents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `logistic_movements`
--
ALTER TABLE `logistic_movements`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transaction_activities`
--
ALTER TABLE `transaction_activities`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transaction_disbursements`
--
ALTER TABLE `transaction_disbursements`
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
-- Constraints for table `investor_activity_allocations`
--
ALTER TABLE `investor_activity_allocations`
  ADD CONSTRAINT `fk_alloc_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`),
  ADD CONSTRAINT `fk_alloc_investor` FOREIGN KEY (`investor_id`) REFERENCES `investors` (`id`);

--
-- Constraints for table `investor_activity_settings`
--
ALTER TABLE `investor_activity_settings`
  ADD CONSTRAINT `fk_settings_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`);

--
-- Constraints for table `investor_deposits`
--
ALTER TABLE `investor_deposits`
  ADD CONSTRAINT `fk_deposits_investor` FOREIGN KEY (`investor_id`) REFERENCES `investors` (`id`);

--
-- Constraints for table `investor_profit_payments`
--
ALTER TABLE `investor_profit_payments`
  ADD CONSTRAINT `fk_profitpay_investor` FOREIGN KEY (`investor_id`) REFERENCES `investors` (`id`);

--
-- Constraints for table `investor_support_expenses`
--
ALTER TABLE `investor_support_expenses`
  ADD CONSTRAINT `fk_support_investor` FOREIGN KEY (`investor_id`) REFERENCES `investors` (`id`);

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

--
-- Constraints for table `transaction_activities`
--
ALTER TABLE `transaction_activities`
  ADD CONSTRAINT `fk_txact_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`),
  ADD CONSTRAINT `fk_txact_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

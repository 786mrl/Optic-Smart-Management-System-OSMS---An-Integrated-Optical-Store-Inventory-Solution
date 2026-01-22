-- phpMyAdmin SQL Dump
-- version 4.8.5
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 22, 2026 at 05:14 AM
-- Server version: 10.1.38-MariaDB
-- PHP Version: 7.3.2

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
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
  `buy_price` decimal(15,2) DEFAULT NULL,
  `sell_price` decimal(15,2) DEFAULT NULL,
  `price_secret_code` varchar(20) DEFAULT NULL,
  `stock` int(11) DEFAULT '0',
  `stock_age` enum('very old','old','new') DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `frames_main`
--

INSERT INTO `frames_main` (`ufc`, `brand`, `frame_code`, `frame_size`, `color_code`, `material`, `lens_shape`, `structure`, `size_range`, `buy_price`, `sell_price`, `price_secret_code`, `stock`, `stock_age`, `created_at`, `updated_at`) VALUES
('BOSS-E0419-54-17-140-C2', 'BOSS', 'E0419', '54-17-140', 'C2', 'B TITANIUM', 'AVIATOR', 'rimless', 'large', '66000.00', '320000.00', 'K20LZ', 5, 'very old', '2026-01-21 21:50:31', '2026-01-21 22:35:26'),
('BRENDEN-brn-8654-50-18-138-col.1', 'BRENDEN', 'brn-8654', '50-18-138', 'col.1', 'B TITANIUM', 'AVIATOR', 'rimless', 'large', '23000.00', '105000.00', 'I05LZ', 5, 'very old', '2026-01-21 22:03:11', '2026-01-22 09:24:58'),
('BVLGARI-1303-49-17-138-col.4', 'BVLGARI', '1303', '49-17-138', 'col.4', 'PLASTIC', 'Oval', 'semi-rimless', 'small', '88000.00', '440000.00', 'KH00LZ', 4, 'new', '2026-01-21 21:50:31', '2026-01-21 22:16:08'),
('CEVIRO-lz-786-00-00-786-col.1', 'CEVIRO', 'lz-786', '00-00-786', 'col.1', 'METAL', 'Aviator', 'full-rim', 'medium', '30000.00', '135000.00', 'I35LZ', 2, 'new', '2026-01-21 21:50:31', '2026-01-21 21:50:31'),
('CHANEL-58472-52-16-145-c5', 'CHANEL', '58472', '52-16-145', 'c5', 'PLASTIC', 'Square', 'full-rim', 'medium', '68000.00', '340000.00', 'K40LZ', 1, 'new', '2026-01-21 21:50:31', '2026-01-21 21:50:31'),
('HUMANSKULL-H1520-45-23-140-C2', 'HUMAN SKULL', 'H1520', '45-23-140', 'C2', 'PLASTIC', 'Oval', 'full-rim', 'medium', '38000.00', '175000.00', 'IH25LZ', 6, 'new', '2026-01-21 21:50:31', '2026-01-21 22:21:15'),
('MARXSTUDIO-Mstm-161-50-18-C5', 'MARX STUDIO', 'Mst m-161', '50-18', 'C5', 'PLASTIC', 'Square', 'full-rim', 'medium', '105000.00', '630000.00', 'MI30LZ', 1, 'new', '2026-01-21 21:50:31', '2026-01-21 21:50:31'),
('MISSMAGDA-m99-19-51-18-145-C6', 'MISSMAGDA', 'M99-19', '51-18-145', 'c6', 'METAL', 'SQUARE', 'full-rim', 'medium', '35000.00', '160000.00', 'IH10LZ', 2, 'new', '2026-01-21 21:50:31', '2026-01-21 21:50:31'),
('RAIS-Umi-786-00-00-786-C1', 'RAIS', 'Umi-786', '00-00-786', 'C1', 'METAL', 'SQUARE', 'full-rim', 'medium', '120000.00', '760000.00', 'NIH10LZ', 1, 'new', '2026-01-21 21:50:31', '2026-01-21 21:50:31'),
('TAKEYAMA-TAKE700-52-15-140-col.4', 'TAKEYAMA', 'TAKE 700', '52-15-140', 'col.4', 'METAL', 'SQUARE', 'semi-rimless', 'medium', '45000.00', '205000.00', 'IH15LZ', 6, 'old', '2026-01-21 21:50:31', '2026-01-21 22:16:08');

-- --------------------------------------------------------

--
-- Table structure for table `frame_sales`
--

CREATE TABLE `frame_sales` (
  `id` int(11) NOT NULL,
  `ufc` varchar(100) DEFAULT NULL,
  `customer_code` varchar(30) DEFAULT NULL,
  `sale_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

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
  `buy_price` decimal(15,2) DEFAULT '0.00',
  `sell_price` decimal(15,2) DEFAULT '0.00',
  `price_secret_code` varchar(20) DEFAULT NULL,
  `stock` int(11) DEFAULT '1',
  `stock_age` enum('very old','old','new') DEFAULT 'new',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `frame_staging`
--

INSERT INTO `frame_staging` (`ufc`, `brand`, `frame_code`, `frame_size`, `color_code`, `material`, `lens_shape`, `structure`, `size_range`, `buy_price`, `sell_price`, `price_secret_code`, `stock`, `stock_age`, `created_at`, `updated_at`) VALUES
('BEIDUIBEI-TR9123-50-22-137-C2', 'BEIDUIBEI', 'TR9123', '50-22-137', 'C2', 'PLASTIC', 'AVIATOR', 'full-rim', 'medium', '34000.00', '155000.00', 'IH05LZ', 1, 'very old', '2026-01-22 04:05:49', '2026-01-22 04:05:49'),
('CHANEL-58472-52-16-145-c5', 'CHANEL', '58472', '52-16-145', 'c5', 'PRC', 'AVIATOR', 'semi-rimless', 'medium', '45000.00', '205000.00', 'J05LZ', 9, 'old', '2026-01-22 02:26:55', '2026-01-22 02:26:55'),
('GNA-G083543-52-20-143-COL.9', 'GNA', 'G08 3543', '52-20-143', 'COL.9', 'B TITANIUM', 'SQUARE', 'full-rim', 'medium', '105000.00', '665000.00', 'NH15LZ', 1, 'old', '2026-01-22 04:13:42', '2026-01-22 04:13:42'),
('PORSCHEDESIGN-Pd8517-57-16-140-col.8', 'PORSCHE DESIGN', 'Pd8517', '57-16-140', 'col.8', 'TITANIUM', 'SQUARE', 'semi-rimless', 'medium', '56000.00', '255000.00', 'JH05LZ', 1, 'old', '2026-01-22 02:29:47', '2026-01-22 02:29:47');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_logs`
--

CREATE TABLE `inventory_logs` (
  `id` int(11) NOT NULL,
  `action_type` enum('new_entry','update_stock') DEFAULT NULL,
  `ufc` varchar(100) DEFAULT NULL,
  `qty_moved` int(11) DEFAULT NULL,
  `admin_name` varchar(50) DEFAULT NULL,
  `moved_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('backup_location', '/var/www/backup/optic_pos/', 'Server Path for Backup File Storage'),
('brand_image_location', 'image/brand_logo_1768752417.png', 'File path or URL for the company brand logo image.'),
('copyright_footer', '© 2026 LENZA OPTIC. All rights reserved.', 'The copyright message displayed in the footer of all application pages.'),
('currency_code', 'IDR', 'Currency Code (e.g., IDR, USD)'),
('invoice_format_prefix', '[data_sequence]/LO-C/[invoice_number]/[test_result_table_number]/[month]/[year]', 'Prefix for Invoice Numbering (e.g., INV-2026-...)'),
('last_backup_date', '2026-01-01', 'Date of the Last System Backup (Auto-updated)'),
('low_stock_threshold', '5', 'Global Low Stock Warning Limit (Units)'),
('receipt_footer_msg', 'Terima kasih telah berbelanja di LENZA OPTIC!', 'Custom Message at the Receipt Footer'),
('starting_invoice_number', '16.31', 'The starting sequence/text string for invoice numbering (resets automatically).'),
('store_address', 'Jl. Apel Raya, No. 51, Kuranji, Padang, Sumatera Barat, 25157, Indonesia', 'Store Physical Address'),
('store_name', 'LENZA OPTIC', 'Store Name for Receipts and Reports'),
('store_phone', '+62 812 6764 6916', 'Store Contact Phone Number'),
('tax_rate_percent', '11.0', 'Sales Tax / VAT Percentage'),
('timezone', 'Asia/Jakarta', 'Server/Application Timezone'),
('uom_frame_default', 'Pcs', 'Default Unit of Measure (UOM) for Frame Category'),
('uom_lens_default', 'Pair', 'Default Unit of Measure (UOM) for Lens Category'),
('uom_other_default', 'Pcs', 'Default Unit of Measure (UOM) for Other Product Categories');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','staff','viewer') NOT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password_hash`, `role`, `is_approved`, `created_at`) VALUES
(1, 'LenZa786', '$2y$10$E5ZXU41IpXcB443wtKCIou/cpEaFMa7k2tuOx83ZAQ9soeUPagGWm', 'admin', 1, '2026-01-12 05:15:58'),
(16, 'Rais786', '$2y$10$oytb0PrQF9VUXlVjV8B9eu.1OEYFDIKXO8GqkDDVVZbcATJmteHIu', 'staff', 1, '2026-01-18 14:08:35');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `frames_main`
--
ALTER TABLE `frames_main`
  ADD PRIMARY KEY (`ufc`);

--
-- Indexes for table `frame_sales`
--
ALTER TABLE `frame_sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ufc` (`ufc`);

--
-- Indexes for table `frame_staging`
--
ALTER TABLE `frame_staging`
  ADD PRIMARY KEY (`ufc`);

--
-- Indexes for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`);

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
-- AUTO_INCREMENT for table `frame_sales`
--
ALTER TABLE `frame_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `frame_sales`
--
ALTER TABLE `frame_sales`
  ADD CONSTRAINT `frame_sales_ibfk_1` FOREIGN KEY (`ufc`) REFERENCES `frames_main` (`ufc`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

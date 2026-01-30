-- phpMyAdmin SQL Dump
-- version 4.8.5
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 30, 2026 at 02:42 AM
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
-- Table structure for table `customer_examinations`
--

CREATE TABLE `customer_examinations` (
  `id` int(11) NOT NULL,
  `examination_date` date NOT NULL,
  `examination_code` varchar(20) NOT NULL,
  `customer_name` varchar(100) NOT NULL,
  `gender` enum('MALE','FEMALE') NOT NULL,
  `age` int(11) DEFAULT NULL,
  `symptoms` text,
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
  `invoice_number` varchar(8) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

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
  `stock` int(11) DEFAULT '0',
  `stock_age` enum('very old','old','new') DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `frames_main`
--

INSERT INTO `frames_main` (`ufc`, `brand`, `frame_code`, `frame_size`, `color_code`, `material`, `lens_shape`, `structure`, `size_range`, `gender_category`, `buy_price`, `sell_price`, `price_secret_code`, `stock`, `stock_age`, `created_at`, `updated_at`) VALUES
('BRENDEN-BR-3543-52-20-143-C1', 'BRENDEN', 'BR-3543', '52-20-143', 'C1', 'METAL', 'SQUARE', 'full-rim', 'medium', 'men', '36000.00', '165000.00', 'IH15LZ', 1, 'old', '2026-01-24 17:52:01', '2026-01-24 17:52:01'),
('BVLGARI-1303-49-17-138-COL.4', 'BVLGARI', '1303', '49-17-138', 'COL.4', 'PLASTIC', 'WAYFARER', 'full-rim', 'small', 'unisex', '68000.00', '330000.00', 'K30LZ', 1, 'very old', '2026-01-24 17:50:04', '2026-01-24 17:50:04'),
('BVLGARI-1376-50-23-137-COL.7', 'BVLGARI', '1376', '50-23-137', 'COL.7', 'TR90', 'WAYFARER', 'rimless', 'large', 'female', '58000.00', '265000.00', 'JH15LZ', 0, 'very old', '2026-01-23 16:50:03', '2026-01-23 16:50:03'),
('CEVIRO-lz-786-00-00-786-col.1', 'CEVIRO', 'lz-786', '00-00-786', 'col.1', 'METAL', 'Aviator', 'full-rim', 'medium', 'unisex', '30000.00', '135000.00', 'I35LZ', 2, 'new', '2026-01-21 21:50:31', '2026-01-21 21:50:31'),
('CHANEL-58472-52-16-145-c5', 'CHANEL', '58472', '52-16-145', 'c5', 'PRC', 'AVIATOR', 'semi-rimless', 'medium', 'unisex', '45000.00', '205000.00', 'J05LZ', 10, 'new', '2026-01-21 21:50:31', '2026-01-22 14:16:40'),
('DIOR-AT1021-50-20-150-C6', 'DIOR', 'AT1021', '50-20-150', 'C6', 'PLASTIC', 'RECTANGLE', 'full-rim', 'medium', 'unisex', '30000.00', '135000.00', 'I35LZ', 1, 'old', '2026-01-22 17:32:06', '2026-01-22 17:32:06'),
('EYEWEAR-TAKE648-52-16-145-COL.MBLK', 'EYE WEAR', 'TAKE 648', '52-16-145', 'COL. MBLK', 'TR', 'CAT-EYE', 'semi-rimless', 'medium', 'men', '78000.00', '390000.00', 'KH40LZ', 1, 'old', '2026-01-23 17:01:48', '2026-01-23 17:01:48'),
('GNA-G083543-52-20-143-COL.9', 'GNA', 'G08 3543', '52-20-143', 'COL.9', 'B TITANIUM', 'SQUARE', 'full-rim', 'medium', 'unisex', '105000.00', '665000.00', 'NH15LZ', 1, 'old', '2026-01-22 14:16:40', '2026-01-22 14:16:40'),
('HANSHA-9384-50-22-143-C08', 'HAN SHA', '9384', '50-22-143', 'C08', 'PLASTIC', 'ROUND', 'full-rim', 'medium', 'unisex', '38000.00', '175000.00', 'IH25LZ', 1, 'very old', '2026-01-22 17:32:06', '2026-01-22 17:32:06'),
('HUMANSKULL-H1520-45-23-140-C2', 'HUMAN SKULL', 'H1520', '45-23-140', 'C2', 'PLASTIC', 'Oval', 'full-rim', 'medium', 'female', '38000.00', '175000.00', 'IH25LZ', 6, 'new', '2026-01-21 21:50:31', '2026-01-21 22:21:15'),
('MARTINJOY-23235-48-22-143-COL.02', 'MARTIN JOY', '23235', '48-22-143', 'COL. 02', 'PLASTIC', 'ROUND', 'full-rim', 'medium', 'men', '59000.00', '270000.00', 'JH20LZ', 1, 'old', '2026-01-22 17:32:06', '2026-01-22 17:32:06'),
('MARXSTUDIO-Mstm-161-50-18-C5', 'MARX STUDIO', 'Mst m-161', '50-18', 'C5', 'PLASTIC', 'Square', 'full-rim', 'medium', 'unisex', '105000.00', '630000.00', 'MI30LZ', 1, 'new', '2026-01-21 21:50:31', '2026-01-21 21:50:31'),
('MISSMAGDA-2518-52-20-142-COL.10', 'MISS MAGDA', '2518', '52-20-142', 'COL.10', 'METAL', 'SQUARE', 'full-rim', 'medium', 'men', '38000.00', '175000.00', 'IH25LZ', 1, 'new', '2026-01-22 17:32:06', '2026-01-22 17:32:06'),
('MISSMAGDA-m99-19-51-18-145-C6', 'MISSMAGDA', 'M99-19', '51-18-145', 'c6', 'METAL', 'SQUARE', 'full-rim', 'medium', 'unisex', '35000.00', '160000.00', 'IH10LZ', 2, 'new', '2026-01-21 21:50:31', '2026-01-21 21:50:31'),
('PLAYKIDS-T181250-47-17-125-M.BLK', 'PLAY KIDS', 'T18 1250', '47-17-125', 'M.BLK', 'PLASTIC', 'ROUND', 'full-rim', 'small', 'unisex', '38000.00', '175000.00', 'IH25LZ', 1, 'very old', '2026-01-22 17:32:06', '2026-01-22 17:32:06'),
('PORSCHEDESIGN-OR7294-52-18-140-C5', 'PORSCHE DESIGN', 'OR 7294', '52-18-140', 'C5', 'METAL', 'SQUARE', 'semi-rimless', 'medium', 'men', '58000.00', '265000.00', 'JH15LZ', 1, 'old', '2026-01-24 19:51:00', '2026-01-24 19:51:00'),
('PORSCHEDESIGN-Pd8517-57-16-140-col.8', 'PORSCHE DESIGN', 'Pd8517', '57-16-140', 'col.8', 'TITANIUM', 'SQUARE', 'semi-rimless', 'medium', 'female', '56000.00', '255000.00', 'JH05LZ', 1, 'old', '2026-01-22 14:16:40', '2026-01-22 14:16:40'),
('RAIS-Umi-786-00-00-786-C1', 'RAIS', 'Umi-786', '00-00-786', 'C1', 'METAL', 'SQUARE', 'full-rim', 'medium', 'unisex', '120000.00', '760000.00', 'NIH10LZ', 1, 'new', '2026-01-21 21:50:31', '2026-01-21 21:50:31'),
('REDSMART-RS16062-50-18-138-COL.12', 'RED SMART', 'RS16062', '50-18-138', 'COL.12', 'METAL', 'OVAL', 'full-rim', 'medium', 'unisex', '36000.00', '165000.00', 'IH15LZ', 1, 'new', '2026-01-22 17:32:06', '2026-01-22 17:32:06'),
('SOOPER-5004-53-17-139-COL.11', 'SOOPER', '5004', '53-17-139', 'COL.11', 'PLASTIC', 'BUTTERFLY', 'full-rim', 'medium', 'female', '36000.00', '165000.00', 'IH15LZ', 1, 'new', '2026-01-22 17:32:06', '2026-01-22 17:32:06'),
('SWAROVSKI-1515-50-17-138-COL.4', 'SWAROVSKI', '1515', '50-17-138', 'COL.4', 'PLASTIC', 'OVAL', 'full-rim', 'medium', 'unisex', '62000.00', '300000.00', 'K00LZ', 2, 'very old', '2026-01-22 17:32:06', '2026-01-22 17:32:06'),
('TAKEYAMA-TAKE648-52-15-140-C4', 'TAKEYAMA', 'TAKE 648', '52-15-140', 'C4', 'METAL', 'BUTTERFLY', 'full-rim', 'medium', 'female', '36000.00', '165000.00', 'IH15LZ', 1, 'old', '2026-01-24 19:05:48', '2026-01-24 19:05:48'),
('TAKEYAMA-TAKE648-52-15-140-C5', 'TAKEYAMA', 'TAKE 648', '52-15-140', 'C5', 'OPTYL', 'OVAL', 'semi-rimless', 'medium', 'unisex', '38000.00', '175000.00', 'IH25LZ', 2, 'old', '2026-01-23 17:01:48', '2026-01-23 17:01:48'),
('TAKEYAMA-TAKE700-52-15-140-col.4', 'TAKEYAMA', 'TAKE 700', '52-15-140', 'col.4', 'METAL', 'SQUARE', 'semi-rimless', 'medium', 'female', '45000.00', '205000.00', 'IH15LZ', 6, 'old', '2026-01-21 21:50:31', '2026-01-21 22:16:08'),
('Z-GENERATION-ZG-437235-53-17-148-C16', 'Z-GENERATION', 'ZG-437235', '53-17-148', 'C16', 'METAL', 'GEOMETRIC', 'full-rim', 'medium', 'female', '58000.00', '265000.00', 'JH15LZ', 3, 'very old', '2026-01-24 19:51:00', '2026-01-24 19:51:00');

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
  `gender_category` enum('men','female','unisex') NOT NULL DEFAULT 'unisex',
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

INSERT INTO `frame_staging` (`ufc`, `brand`, `frame_code`, `frame_size`, `color_code`, `material`, `lens_shape`, `structure`, `size_range`, `gender_category`, `buy_price`, `sell_price`, `price_secret_code`, `stock`, `stock_age`, `created_at`, `updated_at`) VALUES
('EYEWEAR-TAKE700-52-16-145-COL.6', 'EYE WEAR', 'TAKE 700', '52-16-145', 'COL.6', 'METAL-PLASTIC', 'WAYFARER', 'semi-rimless', 'medium', 'female', '36000.00', '165000.00', 'IH15LZ', 5, 'new', '2026-01-26 14:07:35', '2026-01-26 14:44:17');

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
('brand_image_location', 'image/brand_logo_1769435885.png', 'File path or URL for the company brand logo image.'),
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
-- Indexes for table `customer_examinations`
--
ALTER TABLE `customer_examinations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `examination_code` (`examination_code`);

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
-- AUTO_INCREMENT for table `customer_examinations`
--
ALTER TABLE `customer_examinations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `frame_sales`
--
ALTER TABLE `frame_sales`
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

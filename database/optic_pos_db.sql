-- phpMyAdmin SQL Dump
-- version 4.8.5
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 21, 2026 at 04:53 AM
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
  `examination_code` varchar(25) NOT NULL,
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
  `pd_dist` varchar(10) DEFAULT '62/60',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `invoice_number` varchar(20) DEFAULT '00',
  `exam_notes` text,
  `visual_habit` tinyint(1) DEFAULT '1' COMMENT '1:Indoor, 2:Outdoor, 3:Both',
  `digital_usage` tinyint(1) DEFAULT '1' COMMENT '1:Low, 2:Moderate, 3:High',
  `ucva_r` varchar(10) DEFAULT '20/20',
  `ucva_l` varchar(10) DEFAULT '20/20',
  `lens_modification` tinyint(1) DEFAULT '0',
  `need_distance` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=Yes, 0=No — Kebutuhan jarak jauh',
  `need_intermediate` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=Yes, 0=No — Kebutuhan jarak menengah',
  `need_near` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=Yes, 0=No — Kebutuhan jarak dekat'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `customer_examinations`
--

INSERT INTO `customer_examinations` (`id`, `examination_date`, `examination_code`, `customer_name`, `gender`, `age`, `symptoms`, `old_r_sph`, `old_r_cyl`, `old_r_ax`, `old_r_add`, `old_l_sph`, `old_l_cyl`, `old_l_ax`, `old_l_add`, `new_r_sph`, `new_r_cyl`, `new_r_ax`, `new_r_add`, `new_r_visus`, `new_l_sph`, `new_l_cyl`, `new_l_ax`, `new_l_add`, `new_l_visus`, `pd_dist`, `created_at`, `invoice_number`, `exam_notes`, `visual_habit`, `digital_usage`, `ucva_r`, `ucva_l`, `lens_modification`, `need_distance`, `need_intermediate`, `need_near`) VALUES
(1, '2026-05-07', 'LZ/EC/001/V/2026', 'RAIS', 'MALE', 30, 'MYOPIA, ASTIGMATISM, HEADACHE', '0.00', '0.00', '0', '0.00', '0.00', '0.00', '0', '0.00', '-50', '-25', '75', '0.00', '20/20', '-25', '-25', '5', '0.00', '20/20', '62', '2026-05-07 03:30:53', '001', 'Lensa suka berembun', 3, 3, '20/50', '20/50', 1, 0, 0, 0),
(2, '2026-05-08', 'LZ/EC/002/V/2026', 'RAIS', 'MALE', 30, 'MYOPIA, ASTIGMATISM, HEADACHE', '0.00', '0.00', '0', '0.00', '0.00', '0.00', '0', '0.00', '-50', '-25', '75', '0.00', '20/20', '-25', '0.00', '0', '0.00', '20/20', '62', '2026-05-08 04:02:35', '002', '', 3, 3, '20/50', '20/50', 0, 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `customer_orders`
--

CREATE TABLE `customer_orders` (
  `id` int(11) UNSIGNED NOT NULL,
  `customer_number` varchar(40) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `is_modified` tinyint(1) NOT NULL DEFAULT '0',
  `frame_ufc` varchar(50) DEFAULT NULL,
  `lens_name` varchar(150) DEFAULT NULL,
  `customer_phone` varchar(30) DEFAULT NULL,
  `customer_address` text,
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(15,2) NOT NULL DEFAULT '0.00',
  `order_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `order_status` int(11) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `packaging_cost` int(11) NOT NULL DEFAULT '19500'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Confirmed purchase orders — saved when operator clicks Yes Shopping';

--
-- Dumping data for table `customer_orders`
--

INSERT INTO `customer_orders` (`id`, `customer_number`, `invoice_number`, `is_modified`, `frame_ufc`, `lens_name`, `customer_phone`, `customer_address`, `total_amount`, `amount_paid`, `order_date`, `due_date`, `order_status`, `created_at`, `updated_at`, `packaging_cost`) VALUES
(1, '1/LZ-C/16.31/001/V/26', '001', 1, 'TAKEYAMA-TAKE700-52-15-140-col.4', 'SINGLE VISION — ONE-DRIVE', '+62 812 6764 6916', 'JL. APEL RAYA NO. 51', '700000.00', '300000.00', '2026-05-07', '2026-05-09', 5, '2026-05-07 03:32:39', '2026-05-20 14:37:55', 26500),
(2, '2/LZ-C/16.32/002/V/26', '002', 0, '51-32-144+08/05+brenden', 'SINGLE VISION — ONE-DRIVE', '+62 812 6764 6916', NULL, '550000.00', '200000.00', '2026-05-08', '2026-05-10', 5, '2026-05-08 04:03:22', '2026-05-20 14:00:19', 26500);

-- --------------------------------------------------------

--
-- Table structure for table `custom_frames`
--

CREATE TABLE `custom_frames` (
  `id` int(11) NOT NULL,
  `invoice_number` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nomor invoice yang terkait',
  `brand_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Pola: dd/mm/yyyy+brand_name, contoh: 05/04/2026+brenden',
  `sell_price` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'Harga jual frame',
  `is_purchased` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 = belum dibeli, 1 = dibeli',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `buy_price` decimal(12,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Frame custom yang tidak ada di frames_main maupun frame_staging';

--
-- Dumping data for table `custom_frames`
--

INSERT INTO `custom_frames` (`id`, `invoice_number`, `brand_key`, `sell_price`, `is_purchased`, `created_at`, `buy_price`) VALUES
(1, '002', '51-32-144+08/05+brenden', '160000.00', 1, '2026-05-08 04:03:02', '33000.00');

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
('BRENDEN-BR-3543-52-20-143-C1', 'BRENDEN', 'BR-3543', '52-20-143', 'C1', 'METAL', 'SQUARE', 'full-rim', 'medium', 'men', '36000.00', '165000.00', 'IH15LZ', 0, 'old', '2026-01-24 17:52:01', '2026-01-24 17:52:01'),
('BVLGARI-1303-49-17-138-COL.4', 'BVLGARI', '1303', '49-17-138', 'COL.4', 'PLASTIC', 'WAYFARER', 'full-rim', 'small', 'unisex', '68000.00', '330000.00', 'K30LZ', 1, 'very old', '2026-01-24 17:50:04', '2026-01-24 17:50:04'),
('BVLGARI-1376-50-23-137-COL.7', 'BVLGARI', '1376', '50-23-137', 'COL.7', 'TR90', 'WAYFARER', 'rimless', 'large', 'female', '58000.00', '265000.00', 'JH15LZ', 1, 'very old', '2026-01-23 16:50:03', '2026-01-23 16:50:03'),
('CEVIRO-lz-786-00-00-786-col.1', 'CEVIRO', 'lz-786', '00-00-786', 'col.1', 'METAL', 'Aviator', 'full-rim', 'medium', 'unisex', '30000.00', '135000.00', 'I35LZ', 0, 'new', '2026-01-21 21:50:31', '2026-01-21 21:50:31'),
('CHANEL-58472-52-16-145-c5', 'CHANEL', '58472', '52-16-145', 'c5', 'PRC', 'AVIATOR', 'semi-rimless', 'medium', 'unisex', '45000.00', '205000.00', 'J05LZ', 10, 'new', '2026-01-21 21:50:31', '2026-01-22 14:16:40'),
('DIOR-AT1021-50-20-150-C6', 'DIOR', 'AT1021', '50-20-150', 'C6', 'PLASTIC', 'RECTANGLE', 'full-rim', 'medium', 'unisex', '30000.00', '135000.00', 'I35LZ', 1, 'old', '2026-01-22 17:32:06', '2026-01-22 17:32:06'),
('EYEWEAR-TAKE648-52-16-145-COL.MBLK', 'EYE WEAR', 'TAKE 648', '52-16-145', 'COL. MBLK', 'TR', 'CAT-EYE', 'semi-rimless', 'medium', 'men', '78000.00', '390000.00', 'KH40LZ', 1, 'old', '2026-01-23 17:01:48', '2026-01-23 17:01:48'),
('GNA-G083543-52-20-143-COL.9', 'GNA', 'G08 3543', '52-20-143', 'COL.9', 'B TITANIUM', 'SQUARE', 'full-rim', 'medium', 'unisex', '105000.00', '665000.00', 'NH15LZ', 0, 'old', '2026-01-22 14:16:40', '2026-01-22 14:16:40'),
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
('TAKEYAMA-TAKE648-52-15-140-C4', 'TAKEYAMA', 'TAKE 648', '52-15-140', 'C4', 'METAL', 'BUTTERFLY', 'full-rim', 'medium', 'female', '36000.00', '165000.00', 'IH15LZ', 0, 'old', '2026-01-24 19:05:48', '2026-01-24 19:05:48'),
('TAKEYAMA-TAKE648-52-15-140-C5', 'TAKEYAMA', 'TAKE 648', '52-15-140', 'C5', 'OPTYL', 'OVAL', 'semi-rimless', 'medium', 'unisex', '38000.00', '175000.00', 'IH25LZ', 2, 'old', '2026-01-23 17:01:48', '2026-01-23 17:01:48'),
('TAKEYAMA-TAKE700-52-15-140-col.4', 'TAKEYAMA', 'TAKE 700', '52-15-140', 'col.4', 'METAL', 'SQUARE', 'semi-rimless', 'medium', 'female', '45000.00', '205000.00', 'IH15LZ', 4, 'old', '2026-01-21 21:50:31', '2026-01-21 22:16:08'),
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
('ELEGANT-EG-9069-52-19-140-C6', 'ELEGANT', 'EG-9069', '52-19-140', 'C6', 'METAL-PLASTIC', 'CAT-EYE', 'full-rim', 'medium', 'female', '50000.00', '225000.00', 'J25LZ', 1, 'very old', '2026-03-29 12:26:10', '2026-03-29 12:26:10'),
('EYEWEAR-TAKE700-52-16-145-COL.6', 'EYE WEAR', 'TAKE 700', '52-16-145', 'COL.6', 'METAL-PLASTIC', 'SQUARE', 'semi-rimless', 'medium', 'female', '36000.00', '165000.00', 'IH15LZ', 5, 'old', '2026-01-26 14:07:35', '2026-02-25 12:12:36'),
('KELLYS-03-52-17-142-COL.16', 'KELLYS', '03', '52-17-142', 'COL.16', 'METAL', 'WAYFARER', 'full-rim', 'medium', 'female', '45000.00', '205000.00', 'J05LZ', 1, 'very old', '2026-03-29 12:27:21', '2026-03-29 12:27:21'),
('KERASTATE-W156000-52-19-142-COL.13', 'KERASTATE', 'W15 6000', '52-19-142', 'COL.13', 'TR90', 'GEOMETRIC', 'full-rim', 'large', 'female', '80000.00', '400000.00', 'L00LZ', 1, 'new', '2026-03-29 12:20:16', '2026-03-29 12:20:16'),
('KERASTATE-W156002-51-17-142-COL.15', 'KERASTATE', 'W15 6002', '51-17-142', 'COL.15', 'TR90', 'ROUND', 'full-rim', 'large', 'female', '80000.00', '400000.00', 'L00LZ', 1, 'new', '2026-03-29 12:23:02', '2026-03-29 12:23:02'),
('MAXUYA-MAT8875-51-19-145-C4', 'MAXUYA', 'MAT8875', '51-19-145', 'C4', 'METAL', 'GEOMETRIC', 'full-rim', 'medium', 'female', '60000.00', '290000.00', 'JH40LZ', 1, 'very old', '2026-03-29 12:28:17', '2026-03-29 12:28:17'),
('MIABELLOS-MB-2809-52-19-144-C3', 'MIA BELLOS', 'MB-2809', '52-19-144', 'C3', 'METAL-PLASTIC', 'BUTTERFLY', 'full-rim', 'large', 'female', '86000.00', '430000.00', 'L30LZ', 1, 'old', '2026-03-29 12:24:24', '2026-03-29 12:24:24'),
('ROSALITE-JL2251-52-16-145-COL.14', 'ROSALITE', 'JL 2251', '52-16-145', 'COL.14', 'METAL', 'BUTTERFLY', 'full-rim', 'medium', 'female', '65000.00', '315000.00', 'K15LZ', 1, 'new', '2026-03-29 12:21:56', '2026-03-29 12:21:56'),
('SAMEIR-18060-50-20-148-C5', 'SAMEIR', '18060', '50-20-148', 'C5', 'PLASTIC', 'ROUND', 'full-rim', 'large', 'female', '55000.00', '250000.00', 'JH00LZ', 1, 'old', '2026-03-29 12:25:10', '2026-03-29 12:25:10');

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
  `modified_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `prescription_modifications`
--

INSERT INTO `prescription_modifications` (`modification_id`, `invoice_number`, `od_sph`, `od_cyl`, `od_axis`, `od_add`, `os_sph`, `os_cyl`, `os_axis`, `os_add`, `modified_at`) VALUES
(1, '001', '-50', '-25', '75', '0.00', '-25', '-50', '5', '0.00', '2026-05-07 03:31:18');

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
('barcode_guide_image_location', 'image/barcode_guide_1777292613.png', 'Path to the comprehensive guide barcode image file.'),
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

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_customer_orders`
-- (See below for the actual view)
--
CREATE TABLE `v_customer_orders` (
);

-- --------------------------------------------------------

--
-- Structure for view `v_customer_orders`
--
DROP TABLE IF EXISTS `v_customer_orders`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_customer_orders`  AS  select `co`.`id` AS `id`,`co`.`customer_number` AS `customer_number`,`co`.`invoice_number` AS `invoice_number`,`co`.`invoice_sheet` AS `invoice_sheet`,`co`.`is_modified` AS `is_modified`,`co`.`frame_ufc` AS `frame_ufc`,`co`.`frame_name` AS `frame_name`,`co`.`frame_price` AS `frame_price`,`co`.`lens_name` AS `lens_name`,`co`.`lens_price` AS `lens_price`,`co`.`customer_phone` AS `customer_phone`,`co`.`customer_address` AS `customer_address`,`co`.`total_amount` AS `total_amount`,`co`.`amount_paid` AS `amount_paid`,`co`.`order_date` AS `order_date`,`co`.`due_date` AS `due_date`,`co`.`order_status` AS `order_status`,`co`.`created_at` AS `created_at`,`co`.`updated_at` AS `updated_at`,(`co`.`total_amount` - `co`.`amount_paid`) AS `balance`,(case `co`.`order_status` when 1 then 'On Progress' when 2 then 'Manufactured' when 3 then 'Shipping' when 4 then 'Finish' else 'Unknown' end) AS `status_label` from `customer_orders` `co` ;

--
-- Indexes for dumped tables
--

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `customer_orders`
--
ALTER TABLE `customer_orders`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `custom_frames`
--
ALTER TABLE `custom_frames`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `frame_sales`
--
ALTER TABLE `frame_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `prescription_modifications`
--
ALTER TABLE `prescription_modifications`
  MODIFY `modification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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

--
-- Constraints for table `prescription_modifications`
--
ALTER TABLE `prescription_modifications`
  ADD CONSTRAINT `fk_invoice_mod` FOREIGN KEY (`invoice_number`) REFERENCES `customer_examinations` (`invoice_number`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

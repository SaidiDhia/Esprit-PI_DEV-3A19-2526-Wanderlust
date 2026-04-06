-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 06, 2026 at 06:13 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `wonderlust_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `booking`
--

CREATE TABLE `booking` (
  `id` int(11) NOT NULL,
  `place_id` int(11) NOT NULL,
  `user_id` char(36) NOT NULL,
  `host_id` varchar(36) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `total_price` double DEFAULT NULL,
  `guests_count` int(11) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `refund_amount` double NOT NULL DEFAULT 0,
  `cancelled_by` varchar(10) DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `booking`
--

INSERT INTO `booking` (`id`, `place_id`, `user_id`, `host_id`, `start_date`, `end_date`, `total_price`, `guests_count`, `status`, `cancelled_at`, `refund_amount`, `cancelled_by`, `cancel_reason`) VALUES
(2, 5, 'ee711622-c4b7-4d3a-ad48-2f6733eafc30', '7c9365aa-3324-4d50-bf23-8c6095ffdca2', '2026-03-03', '2026-03-04', 1, 1, 'CONFIRMED', NULL, 0, NULL, NULL),
(3, 4, 'ee711622-c4b7-4d3a-ad48-2f6733eafc30', 'ee711622-c4b7-4d3a-ad48-2f6733eafc30', '2026-02-23', '2026-02-27', 40, 1, 'CONFIRMED', NULL, 0, NULL, NULL);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

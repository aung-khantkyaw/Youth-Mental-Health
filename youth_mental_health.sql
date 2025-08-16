-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 16, 2025 at 03:50 PM
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
-- Database: `youth_mental_health`
--

-- --------------------------------------------------------

--
-- Table structure for table `prediction_history`
--

CREATE TABLE `prediction_history` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `age` int(11) NOT NULL,
  `screen_time` decimal(4,1) NOT NULL,
  `sleep_hours` decimal(4,1) NOT NULL,
  `study_hours` decimal(4,1) NOT NULL,
  `physical_activity` int(11) NOT NULL,
  `mental_clarity_score` int(11) NOT NULL,
  `mood` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `prediction_history`
--

INSERT INTO `prediction_history` (`id`, `user_id`, `age`, `screen_time`, `sleep_hours`, `study_hours`, `physical_activity`, `mental_clarity_score`, `mood`, `created_at`) VALUES
(13, 2, 18, 5.0, 7.5, 4.0, 30, 7, 'Happy', '2025-08-04 23:26:51'),
(14, 3, 18, 5.0, 7.5, 4.0, 30, 7, 'Happy', '2025-08-04 23:27:13'),
(15, 4, 18, 6.5, 9.0, 8.0, 50, 5, 'Neutral', '2025-08-05 00:02:40'),
(16, 4, 18, 5.0, 7.5, 4.0, 30, 7, 'Happy', '2025-08-05 00:02:54'),
(17, 3, 18, 5.0, 7.5, 4.0, 30, 7, 'Happy', '2025-08-10 19:08:36'),
(18, 3, 18, 5.0, 7.5, 4.0, 30, 7, 'Happy', '2025-08-10 19:18:43'),
(19, 3, 18, 5.0, 7.5, 4.0, 1, 7, 'Stressed', '2025-08-16 17:29:04'),
(20, 3, 18, 5.0, 7.5, 16.0, 20, 7, 'Stressed', '2025-08-16 17:29:17'),
(21, 3, 22, 5.0, 4.0, 2.0, 20, 7, 'Neutral', '2025-08-16 17:29:29'),
(22, 3, 22, 5.0, 4.0, 2.0, 20, 7, 'Neutral', '2025-08-16 17:31:20'),
(23, 3, 22, 9.0, 2.0, 14.0, 10, 4, 'Neutral', '2025-08-16 18:01:14'),
(24, 4, 22, 10.0, 2.0, 16.0, 2, 1, 'Neutral', '2025-08-16 19:59:02');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(100) NOT NULL,
  `role` enum('ADMIN','USER') NOT NULL DEFAULT 'USER',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `created_at`) VALUES
(1, 'admin', 'admin@gmail.com', '$2y$10$Jl0islVN7lCfzbiTdTsuuedrW73cHI0ZQKweXzfaxEIsPM2EsZcCG', 'ADMIN', '2025-07-28 16:32:10'),
(2, 'jonedoe', 'jonedoe@gmail.com', '$2y$10$4hxt1F8iw92cwFZAWHKIGOcXeQOj7ipDItnSnxt6/Y9qaT2dgvCEC', 'USER', '2025-07-31 03:13:18'),
(3, 'bob', 'bob@gmail.com', '$2y$10$xAnUFSFvEtRBZj6xCGi8LOKD/O6//l/sCafzCM0zo/A6GhokEfeYy', 'USER', '2025-08-04 11:14:37'),
(4, 'alice', 'alice@gmail.com', '$2y$10$JfieiXrtV7TveHeKtT3vPOwmKlOEVqGIBII93TkiYb14qBrp0cA5.', 'USER', '2025-08-04 23:58:49'),
(5, 'hanry', 'hanry@gmail.com', '$2y$10$ai7lHh83r1YqKstoKBDMO.6gKWuZmz/PDwun1ogleMRwNsjfNC9Bq', 'USER', '2025-08-16 19:51:14');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `prediction_history`
--
ALTER TABLE `prediction_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `prediction_history`
--
ALTER TABLE `prediction_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `prediction_history`
--
ALTER TABLE `prediction_history`
  ADD CONSTRAINT `prediction_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

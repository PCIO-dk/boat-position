-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Vært: pcio.dk.mysql.service.one.com:3306
-- Genereringstid: 22. 05 2026 kl. 09:57:43
-- Serverversion: 10.6.23-MariaDB-ubu2204
-- PHP-version: 8.1.2-1ubuntu2.23

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pcio_dkwordpress`
--

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `www_boat_harbours`
--

CREATE TABLE `www_boat_harbours` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(128) NOT NULL,
  `lat` double NOT NULL,
  `lon` double NOT NULL,
  `radius_m` int(11) NOT NULL DEFAULT 300
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Data dump for tabellen `www_boat_harbours`
--

INSERT INTO `www_boat_harbours` (`id`, `name`, `lat`, `lon`, `radius_m`) VALUES
(1, 'Frederikssund Lystbådehavn', 55.827227, 12.059813, 300),
(2, 'Jyllinge Lystbådehavn', 55.745086, 12.095947, 300),
(3, 'Holbæk Marina', 55.723823, 11.760006, 300),
(4, 'Anholt Havn', 56.714587, 11.518822, 300),
(5, 'Assens Havn', 55.267552, 9.885764, 300),
(6, 'Ballen Lystbådehavn', 55.815817, 10.63925, 300),
(7, 'Bogense Havn og Marina', 55.567354, 10.079269, 300),
(8, 'Bogø Havn', 54.912639, 12.050881, 300),
(9, 'Brejning Lystbådehavn', 55.674656, 9.690875, 300),
(10, 'Brøndby Lystbådehavn', 55.608802, 12.442017, 300),
(11, 'Christiansminde Bro', 55.059292, 10.63505, 300),
(12, 'Doverodde Havn', 56.717984, 8.472349, 300),
(13, 'Dragør Havn', 55.593355, 12.681317, 300),
(14, 'Dragør Lystbådehavn', 55.591612, 12.679188, 300),
(15, 'Dybvig Havn', 54.944548, 11.43561, 300),
(16, 'Dyreborg Havn', 55.071697, 10.217471, 300),
(17, 'Dyvig Bådelaug Lystbådehavn', 55.041967, 9.704833, 300),
(18, 'Ebeltoft Havn', 56.19546, 10.669527, 300),
(19, 'Ebeltoft Skudehavn', 56.191066, 10.668368, 300),
(20, 'Egå Marina', 56.20952, 10.289726, 300),
(21, 'Esbjerg Havn', 55.474111, 8.424171, 300),
(22, 'Fanø Lystbådehavn', 55.443336, 8.408162, 300),
(23, 'Faxe Ladeplads Havn', 55.21387, 12.161631, 300),
(24, 'Fredericia Lystbådehavn', 55.553593, 9.729252, 300),
(25, 'Frederikshavn Marina', 57.425534, 10.529816, 300),
(26, 'Frederikssund Havn', 55.835711, 12.054663, 300),
(27, 'Frederiksværk Lystbådehavn', 55.964612, 11.999345, 300),
(28, 'Faaborg Havn', 55.097329, 10.232241, 300),
(29, 'Gambøt Lystbådehavn', 55.041782, 10.668304, 300),
(30, 'Gilleleje Havn', 56.127496, 12.311253, 300),
(31, 'Glyngøre Havn', 56.763968, 8.865023, 300),
(32, 'Grenaa Lystbådehavn', 56.404297, 10.923629, 300),
(33, 'Gråsten Havn', 54.918833, 9.597552, 300),
(34, 'Gråsten Sejlklubs Havn', 54.912417, 9.600635, 300),
(35, 'Haderslev Havn', 55.248219, 9.49723, 300),
(36, 'Hadsund Lystbådehavn', 56.716801, 10.126455, 300),
(37, 'Hals Havn', 56.991944, 10.302222, 300),
(38, 'Havnsø Havn', 55.75424, 11.324415, 300),
(39, 'Hejlsminde Lystbådehavn', 55.361033, 9.600388, 300),
(40, 'Hellerup Lystbådehavn', 55.731911, 12.581319, 300),
(41, 'Helsingør Nordhavn', 56.042754, 12.614107, 300),
(42, 'Hjarbæk Havn', 56.531294, 9.318617, 300),
(43, 'Hobro Lystbådehavn', 56.643534, 9.812247, 300),
(44, 'Holbæk Havn', 55.720234, 11.709366, 300),
(45, 'Holstebro-Struer Havn', 56.494651, 8.59147, 300),
(46, 'Hornbæk Havn', 56.094952, 12.458103, 300),
(47, 'Horsens Havn', 55.858438, 9.859058, 300),
(48, 'Horsens Lystbådehavn', 55.856731, 9.873203, 300),
(49, 'Hou Lystbådehavn', 55.910325, 10.254107, 300),
(50, 'Hov Havn (Hou Havn)', 57.055926, 10.377545, 300),
(51, 'Humlebæk Havn', 55.971006, 12.546061, 300),
(52, 'Hundige Lystbådehavn', 55.593043, 12.353826, 300),
(53, 'Hvalpsund Lystbådehavn', 56.705, 9.201586, 300),
(54, 'Hvide Sande Lystbådehavn', 56.008363, 8.136073, 300),
(55, 'Hvidovre Lystbådehavn', 55.625488, 12.500639, 300),
(56, 'Høruphav Havn', 54.906687, 9.888595, 300),
(57, 'Ishøj Havn', 55.6095, 12.387, 300),
(58, 'Jegindø Havn', 56.651516, 8.636143, 300),
(59, 'Juelsminde Havn og Marina', 55.715556, 10.016012, 300),
(60, 'Kalvehave Havn', 54.994358, 12.166286, 300),
(61, 'Kaløvig Bådehavn', 56.243278, 10.340796, 300),
(62, 'Kampeløkke Havn', 55.289221, 14.782577, 300),
(63, 'Karrebæksminde Havn', 55.175951, 11.644349, 300),
(64, 'Kastrup Strandpark', 55.641767, 12.652538, 300),
(65, 'Kerteminde Havn', 55.453102, 10.666416, 300),
(66, 'Kolding Lystbådehavn', 55.488945, 9.499441, 300),
(67, 'Kongsdal Lystbådehavn', 56.684028, 10.07163, 300),
(68, 'Korsør Lystbådehavn', 55.327521, 11.130692, 300),
(69, 'Københavns Havn', 55.637783, 12.543898, 300),
(70, 'Københavns Havn (Kalkbrænderihavnen)', 55.71158, 12.590336, 300),
(71, 'Køge Marina', 55.469489, 12.197099, 300),
(72, 'Langelinie Lystbådehavn', 55.694138, 12.599323, 300),
(73, 'Lemvig Marina', 56.566142, 8.297374, 300),
(74, 'Lynæs Havn', 55.942519, 11.867723, 300),
(75, 'Løgstør Havn', 56.967626, 9.245078, 300),
(76, 'Marbæk Lystbådehavn', 55.8276, 12.061915, 300),
(77, 'Margretheholms Havn', 55.687952, 12.616274, 300),
(78, 'Mariager Lystbådehavn', 56.653791, 9.982528, 300),
(79, 'Marina Minde Havn', 54.896823, 9.618223, 300),
(80, 'Marselisborg Lystbådehavn', 56.138018, 10.215479, 300),
(81, 'Mellerup Lystbådehavn', 56.524027, 10.223656, 300),
(82, 'Middelfart Marina', 55.491863, 9.727493, 300),
(83, 'Mosede Fiskerihavn', 55.566383, 12.286449, 300),
(84, 'Nappedam Lystbådehavn', 56.276995, 10.494619, 300),
(85, 'Nibe Lystbådehavn', 56.987622, 9.633336, 300),
(86, 'Nivå Havn', 55.939683, 12.526731, 300),
(87, 'Nyborg Lystbådehavn', 55.306092, 10.786847, 300),
(88, 'Nykøbing Falster Havn', 54.771105, 11.86131, 300),
(89, 'Nykøbing Mors Havn', 56.79277, 8.865533, 300),
(90, 'Nykøbing Sjælland Lystbådehavn', 55.913668, 11.672759, 300),
(91, 'Næstved Kanalhavn', 55.208044, 11.714417, 300),
(92, 'Nørre Uttrup Lystbådehavn', 57.070906, 9.95769, 300),
(93, 'Odden Havn', 55.972057, 11.369755, 300),
(94, 'Otterup Lystbådehavn', 55.526712, 10.471966, 300),
(95, 'Præstø Havn', 55.124613, 12.041947, 300),
(96, 'Randers Lystbådehavn', 56.462367, 10.05344, 300),
(97, 'Rantzausminde Lystbådehavn', 55.033901, 10.541511, 300),
(98, 'Reersø Fiskerihavn', 55.517001, 11.119488, 300),
(99, 'Ringkøbing Havn', 56.086465, 8.239667, 300),
(100, 'Rudkøbing Havn', 54.941763, 10.711298, 300),
(101, 'Rungsted Havn', 55.885469, 12.546966, 300),
(102, 'Rønne Havn', 55.104388, 14.692498, 300),
(103, 'Rønnerhavnen', 57.464343, 10.537026, 300),
(104, 'Rørvig Havn', 55.943811, 11.767538, 300),
(105, 'Skagen Havn', 57.715484, 10.587376, 300),
(106, 'Skive Søsports Havn', 56.575312, 9.053185, 300),
(107, 'Skovshoved Havn', 55.761364, 12.601383, 300),
(108, 'Skuldelev Havn', 55.795505, 12.056957, 300),
(109, 'Skærbæk Havn', 55.513082, 9.626663, 300),
(110, 'Snaptun Havn', 55.822695, 10.05174, 300),
(111, 'Strandby Havn', 57.494314, 10.505464, 300),
(112, 'Strib Bådehavn', 55.538564, 9.763112, 300),
(113, 'Stubbekøbing Havn', 54.891641, 12.04771, 300),
(114, 'Svanemøllehavnen', 55.716306, 12.588272, 300),
(115, 'Svendborg Sund Marina', 55.055003, 10.644179, 300),
(116, 'Sæby Havn', 57.333216, 10.532885, 300),
(117, 'Sønderborg Lystbådehavn', 54.899439, 9.794354, 300),
(118, 'Thisted Havn', 56.95237, 8.697067, 300),
(119, 'Troense Dampskibsbro og Bådehavn', 55.034183, 10.645409, 300),
(120, 'Tuborg Havn', 55.726319, 12.585107, 300),
(121, 'Vallensbæk Havn', 55.612032, 12.396205, 300),
(122, 'Vedbæk Havn', 55.849566, 12.572265, 300),
(123, 'Veddelev Lystbådehavn', 55.678843, 12.068653, 300),
(124, 'Vejle Lystbådehavn', 55.706248, 9.55641, 300),
(125, 'Vindeby Lystbådehavn', 55.049343, 10.61496, 300),
(126, 'Virksund Havn', 56.60896, 9.291138, 300),
(127, 'Vive Havn', 56.698618, 10.044351, 300),
(128, 'Øer Havn', 56.151622, 10.689698, 300),
(129, 'Øster Hurup Fiskeri- og Lystbådehavn', 56.804826, 10.282516, 300),
(130, 'Aabenraa Lystbådehavn', 55.036581, 9.423866, 300),
(131, 'Aalborg Havn', 57.048761, 10.05661, 300),
(132, 'Aalborg Skudehavn og Vestre Bådehavn', 57.058517, 9.896697, 300),
(133, 'Århus Lystbådehavn', 56.165961, 10.222049, 300),
(134, 'Årøsund Havn', 55.262686, 9.709425, 300),
(135, 'Sundby Hvorup', 57.07093, 9.95769, 300),
(136, 'Kignæs Lystbådehavn', 55.857154, 12.009172, 300),
(137, 'Sejerø Havn', 55.880318, 11.135867, 300),
(138, 'Herslev Havn', 55.668685, 11.939124, 300),
(139, 'Kulhuse Havn', 55.936654, 11.906519, 300),
(140, 'Roskilde Havn', 55.651587, 12.076206, 300),
(141, 'Aalborg Marina Fjordparken', 57.056183, 9.874993, 300),
(142, 'Søndre Frihavn', 55.696539, 12.595786, 300),
(143, 'Thurøbund Marina', 55.059698, 10.617954, 300),
(144, 'Hanbjerg Marina', 56.47542, 8.71815, 300),
(145, 'Greve Marina', 55.596395, 12.356208, 300),
(146, 'NyHavn 1', 55.506137, 9.735787, 300),
(147, 'NyHavn 2', 55.50607, 9.738083, 300),
(148, 'Horsens Marina', 55.857528, 9.87508, 300),
(150, 'Hundested Lystbådehavn', 55.965244, 11.84642, 300),
(151, 'Maarup Havn', 55.936716, 10.551398, 300),
(152, '(unknown)', 55.931855, 10.545287, 300);

--
-- Begrænsninger for dumpede tabeller
--

--
-- Indeks for tabel `www_boat_harbours`
--
ALTER TABLE `www_boat_harbours`
  ADD PRIMARY KEY (`id`);

--
-- Brug ikke AUTO_INCREMENT for slettede tabeller
--

--
-- Tilføj AUTO_INCREMENT i tabel `www_boat_harbours`
--
ALTER TABLE `www_boat_harbours`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=153;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

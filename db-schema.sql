CREATE TABLE IF NOT EXISTS `mob_db` (
  `uid` int(11) DEFAULT NULL,
  `msisdn` varchar(12) COLLATE utf8_czech_ci NOT NULL,
  `fup` varchar(20) COLLATE utf8_czech_ci NOT NULL,
  `ip` varchar(20) COLLATE utf8_czech_ci NOT NULL,
  `tmpid` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_czech_ci;

ALTER TABLE `mob_db`
 ADD UNIQUE KEY `msisdn` (`msisdn`), ADD UNIQUE KEY `ip` (`ip`);

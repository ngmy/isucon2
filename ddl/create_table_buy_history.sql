DROP TABLE IF EXISTS `buy_history`;

CREATE TABLE `buy_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `stock_seat_id` varchar(255) NOT NULL,
  `variation_name` varchar(255) NOT NULL,
  `ticket_name` varchar(255) NOT NULL,
  `artist_name` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=40961 DEFAULT CHARSET=utf8;

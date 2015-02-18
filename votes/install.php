<?php

    if ( !defined('K_COUCH_DIR') ) die(); // cannot be loaded directly

    /* Table couch_votes_raw */
    $_sql = "CREATE TABLE `".K_TBL_VOTES_RAW."` (
      `id`         int AUTO_INCREMENT NOT NULL,
      `page_id`    int NOT NULL,
      `field_id`   int NOT NULL,
      `value`      decimal(65,2) DEFAULT '0.00',
      `member_id`  int DEFAULT '-1',
      `ip_addr`    varchar(100),
      `timestamp`  int(10) UNSIGNED NOT NULL DEFAULT '0',
      PRIMARY KEY (`id`)
    ) ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci;";
    $DB->_query( $_sql );

    $_sql = "CREATE INDEX `".K_TBL_VOTES_RAW."_index01` ON `".K_TBL_VOTES_RAW."` (`page_id`, `field_id`, `value`);";
    $DB->_query( $_sql );

    $_sql = "CREATE INDEX `".K_TBL_VOTES_RAW."_index02` ON `".K_TBL_VOTES_RAW."` (`page_id`, `field_id`, `member_id`, `timestamp`);";
    $DB->_query( $_sql );

    $_sql = "CREATE INDEX `".K_TBL_VOTES_RAW."_index03` ON `".K_TBL_VOTES_RAW."` (`page_id`, `field_id`, `ip_addr`, `timestamp`);";
    $DB->_query( $_sql );

    $_sql = "CREATE INDEX `".K_TBL_VOTES_RAW."_index04` ON `".K_TBL_VOTES_RAW."` (`field_id`);";
    $DB->_query( $_sql );


    /* Table couch_votes_calc */
    $_sql = "CREATE TABLE `".K_TBL_VOTES_CALC."` (
      `id`        int AUTO_INCREMENT NOT NULL,
      `page_id`   int NOT NULL,
      `field_id`  int NOT NULL,
      `label`     varchar(100),
      `value`     decimal(65,2) DEFAULT '0.00',
      PRIMARY KEY (`id`)
    ) ENGINE = InnoDB CHARACTER SET utf8 COLLATE utf8_general_ci;";
    $DB->_query( $_sql );

    $_sql = "CREATE INDEX `".K_TBL_VOTES_CALC."_index01` ON `".K_TBL_VOTES_CALC."` (`page_id`, `field_id`);";
    $DB->_query( $_sql );

    $_sql = "CREATE INDEX `".K_TBL_VOTES_CALC."_index02` ON `".K_TBL_VOTES_CALC."` (`field_id`);";
    $DB->_query( $_sql );

    $_sql = "CREATE INDEX `".K_TBL_VOTES_CALC."_index03` ON `".K_TBL_VOTES_CALC."` (`field_id`, `label`, `value`);";
    $DB->_query( $_sql );

    /* finish installation */
    $_sql = "INSERT INTO ".K_TBL_SETTINGS." (k_key, k_value) VALUES ('k_votes_version', '".K_VOTE_VERSION."');";
    $DB->_query( $_sql );

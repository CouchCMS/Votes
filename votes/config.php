<?php

    if ( !defined('K_COUCH_DIR') ) die(); // cannot be loaded directly

    ///////////EDIT BELOW THIS////////////////////////////////////////

    // 1.
    // Time window (in seconds) available for a registered member after which another vote cast by her
    // would be considered a separate new vote. In other words - multiple votes cast by the member within this
    // time-window would be considered as one vote (with the last voted value overwriting the previous one).
    //
    // Default is '-1' which makes the window infinite i.e. the member can vote only once.
    define( 'K_VOTE_WINDOW_MEMBER', -1 ); // time in seconds. Default -1 for infinite

    // 2.
    // Same as above but applies to anonymous voters (i.e. visitors that are not registered members).
    // Since non-members can only be tracked by their IPs, this window should be kept small enough to allow
    // visitors potentially sharing the same IP address to vote,
    define( 'K_VOTE_WINDOW_ANON', 6 * 60 * 60 ); // time in seconds. Default 6 hours

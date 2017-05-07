<?php

    if ( !defined('K_COUCH_DIR') ) die(); // cannot be loaded directly

    require_once( K_COUCH_DIR.'addons/data-bound-form/data-bound-form.php' );
    if( file_exists(K_COUCH_DIR.'addons/member/member.php') ){
        require_once( K_COUCH_DIR.'addons/member/member.php' );
    }

    define( 'K_VOTE_VERSION', '1.0' );
    define( 'K_TBL_VOTES_RAW', K_DB_TABLES_PREFIX . 'couch_votes_raw' );
    define( 'K_TBL_VOTES_CALC', K_DB_TABLES_PREFIX . 'couch_votes_calc' );

    // get modules's version
    $_rs = @mysql_query( "select k_value from ".K_TBL_SETTINGS." where k_key='k_votes_version'", $DB->conn );
    if( $_rs && ($_row = mysql_fetch_row( $_rs )) ){

        $_ver = $_row[0];
        if( version_compare(K_VOTE_VERSION, $_ver, ">") ){ // Upgrade required
            // make updates
            $DB->begin();

            // provision for future..

            // Finally update version number
            $_rs = $DB->update( K_TBL_SETTINGS, array('k_value'=>K_VOTE_VERSION), "k_key='k_votes_version'" );
            if( $_rs==-1 ) die( "ERROR: Unable to update version number" );
            $DB->commit( 1 );

        }
    }
    else{
        // installation required (or database messed-up? )
        require_once( K_COUCH_DIR.'addons/votes/install.php' );
    }

    if( file_exists(K_COUCH_DIR.'addons/votes/config.php') ){
        require_once( K_COUCH_DIR.'addons/votes/config.php' );
    }

    // UDF for 'vote_updown' editable region
    class KVoteUpDown extends KUserDefinedField{

        var $window_member = -1; // infinite;
        var $window_anon =  21600; // 6 hours
        var $calc_results = null;
        var $vote_value;
        var $config = null;

        function __construct( $row, &$page, &$siblings ){
            global $FUNCS;

            // call parent
            parent::__construct( $row, $page, $siblings );

            // config
            if( defined('K_VOTE_WINDOW_MEMBER') && ($FUNCS->is_natural(K_VOTE_WINDOW_MEMBER) || K_VOTE_WINDOW_MEMBER=='-1') ){
                $this->window_member = K_VOTE_WINDOW_MEMBER;
            }
            if( defined('K_VOTE_WINDOW_ANON') && ($FUNCS->is_natural(K_VOTE_WINDOW_ANON) || K_VOTE_WINDOW_ANON=='-1') ){
                $this->window_anon = K_VOTE_WINDOW_ANON;
            }
        }

        // Output to admin panel
        function _render( $input_name, $input_id, $extra='', $dynamic_insertion=0 ){
            global $FUNCS, $CTX;

            if( !defined('VOTES_URL') ){
                define( 'VOTES_URL', K_ADMIN_URL . 'addons/votes/' );
                $FUNCS->load_css( VOTES_URL . 'votes.css' );
            }

            $html = $FUNCS->render( 'field_'.$this->k_type, $this, $input_name, $input_id, $extra, $dynamic_insertion );

            return $html;
        }

        // Output to front-end via $CTX
        function get_data( $for_ctx=0 ){
            global $CTX, $DB;

            if( $for_ctx ){

                // Data not a simple string hence
                // we'll store it into '_obj_' of CTX directly
                // to be used by the auxiliary tag (cms:show_vote_updown) which knows how to display it
                $CTX->set_object( $this->name, $this->_get_votes_calc() );
            }

            return $this->_format( $this->data );
        }

        function _get_named_vars( $into, $from, $format=1 ){
            foreach( $from as $rec ){
                if( isset($into[$rec['label']]) ){
                    $data = $rec['value'];
                    if( $format ){
                        $data = $this->_format( $data );
                    }
                    $into[$rec['label']] = $data;
                }
            }
            return $into;
        }

        function _format( $data ){
            $pos = strpos( $data, ".00");
            $data = ( $pos!==false ) ? substr( $data, 0, $pos ) : substr( $data, 0, -1 );

            return $data;
        }

        // Handle posted data
        function store_posted_changes( $post_val ){
            if( $this->deleted || is_null($post_val) ) return; // no need to store

            $val = trim( $post_val );
            if( $val==='1' || $val==='-1' ){
                $this->vote_value = $val;
                $this->modified = true;
            }
        }

        function is_empty(){
            return !strlen( $this->data );
        }

        function _prep_cached(){
            $this->calc_results = null;
        }

        // Save to database.
        function get_data_to_save(){
            global $DB;

            if( strlen(trim($this->vote_value)) ){
                // save the raw vote
                $this->_save_vote( $this->vote_value );

                // re-calculate aggregate scores..
                $rec = $this->_recalc();

                // .. and save them
                $this->_save_votes_calc( $rec );

                // save the 'sum' in the field
                return $rec['sum'];
            }
            else{
                // being called by the clone routine
                return $this->data;
            }
        }

        function _recalc(){
            global $DB;

            $sql = "SELECT count(id) as count, sum(value) as sum FROM ".K_TBL_VOTES_RAW."
            WHERE page_id='".$DB->sanitize( $this->page->id )."' AND field_id='".$DB->sanitize( $this->id )."'";

            $rs = $DB->raw_select( $sql );

            $rec = array();
            $rec['count'] = $rs[0]['count'];
            $rec['sum'] = $rs[0]['sum'];

            return $rec;

        }

        function _get_votes_calc(){
            global $DB;

            if( is_null($this->calc_results) ){

                // fetch in the stored calculated results
                $rs = $DB->select( K_TBL_VOTES_CALC, array('label', 'value'), "page_id='".$DB->sanitize($this->page->id)."' AND field_id='".$DB->sanitize($this->id)."'" );

                $vars = $this->_get_named_vars(
                    array(
                        'count'=>'0',
                        'sum'=>'0',
                        ),
                    $rs);

                $vars['count_up'] = ($vars['count'] + $vars['sum']) / 2;
                $vars['count_down'] = $vars['count'] - $vars['count_up'];

                // values in percentage
                $vars['percent_up'] = ( $vars['count'] ) ? sprintf( "%d", $vars['count_up'] / $vars['count'] * 100 ) : 0;
                $vars['percent_down'] = ( $vars['count'] ) ? sprintf( "%d", $vars['count_down'] / $vars['count'] * 100 ) : 0;

                // has the visitor already voted?
                $rs = $this->_get_last_vote();
                if( count($rs) ){
                    $vars['already_voted'] = '1';
                    $vars['last_vote_value'] = ($rs[0]['value']>0) ? '1' : '-1';
                }
                else{
                    $vars['already_voted'] = '0';
                }
                $vars['vote_type'] = 'updown';

                $this->calc_results = $vars;
            }

            return $this->calc_results;

        }

        function _save_votes_calc( $rec ){
            global $DB;

            // delete existing records first
            $DB->delete( K_TBL_VOTES_CALC, "page_id='".$DB->sanitize($this->page->id)."' AND field_id='".$DB->sanitize($this->id)."'" );

            // store new records
            foreach( $rec as $k=>$v ){
                $calc_vote = array(
                    'page_id'=>$this->page->id,
                    'field_id'=>$this->id,
                    'label'=>$k,
                    'value'=>$v,
                );

                $DB->insert( K_TBL_VOTES_CALC, $calc_vote );
            }

        }

        function _save_vote( $val ){
            global $FUNCS, $DB, $CTX;

            $ip_addr = trim( $FUNCS->cleanXSS(strip_tags($_SERVER['REMOTE_ADDR'])) );

            // is it a logged-in member casting the vote?
            $member_id = '-1';
            if( $CTX->get('k_member_logged_in')=='1' ){
                $member_id = $CTX->get('k_member_id');
            }

            $vote = array(
                'page_id'=>$this->page->id,
                'field_id'=>$this->id,
                'value'=>$val,
                'member_id'=>$member_id,
                'ip_addr'=>$ip_addr,
                'timestamp'=>time(),
            );

            // make sure the visitor (loggedin or anon)is allowed only one vote within the specified 'window'
            $rs = $this->_get_last_vote( $member_id, $ip_addr );

            // store vote
            if( count($rs) ){
                $DB->update( K_TBL_VOTES_RAW, $vote, "id='" . $DB->sanitize( $rs[0]['id'] ). "'" );
            }
            else{
                $DB->insert( K_TBL_VOTES_RAW, $vote );
            }

        }

        function _get_last_vote( $member_id='', $ip_addr='' ){
            global $DB, $CTX, $FUNCS;

            if( !$member_id ){
                $member_id = ($CTX->get('k_member_logged_in')=='1') ? $CTX->get('k_member_id') : '-1';
            }
            if( !$ip_addr ){
                $ip_addr = trim( $FUNCS->cleanXSS(strip_tags($_SERVER['REMOTE_ADDR'])) );
            }

            $sql = "page_id='" . $DB->sanitize( $this->page->id ). "' AND ";
            $sql .= "field_id='" . $DB->sanitize( $this->id ). "' AND ";
            if( $member_id!='-1' ){
                $sql .= "member_id='" . $DB->sanitize( $member_id ). "' ";
                $window = $this->window_member;
            }
            else{
                $sql .= "ip_addr='" . $DB->sanitize( $ip_addr ). "' ";
                $window = $this->window_anon;
            }

            if( $window!='-1' ){
                $sql .= "AND timestamp>='" . $DB->sanitize( time()-$window ). "' ";
            }
            $sql .= "ORDER BY timestamp DESC LIMIT 1";

            $rs = $DB->select( K_TBL_VOTES_RAW, array('id', 'value'), $sql );

            return $rs;
        }

        // Search value
        function get_search_data(){
            return '';
        }

        // Called either from a page being deleted
        // or when this field's definition gets removed from a template (in which case the $page_id param would be '-1' )
        function _delete( $page_id ){
            global $DB;

            if( $page_id==-1 ){
                // field being deleted ..
                // Remove its records from the tables (for all the pages)
                $rs = $DB->delete( K_TBL_VOTES_RAW, "field_id='" . $DB->sanitize($this->id) . "'" );
                $rs = $DB->delete( K_TBL_VOTES_CALC, "field_id='" . $DB->sanitize($this->id) . "'" );
            }
            else{
                // page being deleted ..
                // Remove its records from the tables (for only the page being deleted)
                $rs = $DB->delete( K_TBL_VOTES_RAW, "page_id='" . $DB->sanitize($this->page->id). "' AND field_id='" . $DB->sanitize($this->id) . "'" );
                $rs = $DB->delete( K_TBL_VOTES_CALC, "page_id='" . $DB->sanitize($this->page->id). "' AND field_id='" . $DB->sanitize($this->id) . "'" );
            }
            $this->calc_results = null;

            return;
        }

        ////////////////////////////////////////////////////////////////////////

        // Handles 'cms:show_votes' tag
        static function show_handler( $params, $node ){
            global $FUNCS, $CTX, $DB;
            if( !count($node->children) ) return;

            extract( $FUNCS->get_named_vars(
                array(
                    'var'=>'',
                ),
                $params)
            );
            $var = trim( $var );

            if( $var ){
                // get the data array from CTX
                $obj = &$CTX->get_object( $var );

                if( is_array($obj) ){
                    // set component values as $CTX variables
                    $CTX->set_all( $obj );

                    // and call the children tags
                    foreach( $node->children as $child ){
                        $html .= $child->get_HTML();
                    }
                }

                return $html;
            }
        }

        // renderable theme functions
        static function register_renderables(){
            global $FUNCS;

            $FUNCS->register_render( 'field_vote_updown', array('template_path'=>K_COUCH_DIR.'addons/votes/theme/', 'template_ctx_setter'=>array('KVoteUpDown', '_render_votes')) );
        }

        static function _render_votes( $f, $input_name, $input_id, $extra, $dynamic_insertion ){
            global $CTX;

            KField::_set_common_vars( $f->k_type, $input_name, $input_id, $extra, $dynamic_insertion, $f->simple_mode );

            $vars = $f->_get_votes_calc();

            $CTX->set_all( $vars );

            $CTX->set( 'sum_plural', $vars['sum'] != 1 && $vars['sum'] != -1 ? 1 : 0 );
            $CTX->set( 'count_plural', $vars['count'] != 1 ? 1 : 0 );
        }

    } /* end KVoteUpDown */

    // UDF for 'vote_stars' editable region
    class KVoteStars extends KVoteUpDown{

        static function handle_params( $params ){
            global $FUNCS, $AUTH;
            if( $AUTH->user->access_level < K_ACCESS_LEVEL_SUPER_ADMIN ) return;

            $attr = $FUNCS->get_named_vars(
                array(
                    'allow_zero_stars'=>'0',
                  ),
                $params
            );
            $attr['allow_zero_stars'] = ( $attr['allow_zero_stars']==1 ) ? 1 : 0;

            return $attr;

        }

        // Handle posted data
        function store_posted_changes( $post_val ){
            global $FUNCS;

            if( $this->deleted || is_null($post_val) ) return; // no need to store

            $val = trim( $post_val );
            $is_ok = ( $this->allow_zero_stars ) ? $FUNCS->is_natural( $val ) : $FUNCS->is_non_zero_natural( $val );
            if( $is_ok && $val<=5){
                $this->vote_value = $val;
                $this->modified = true;
            }
        }

        // Save to database.
        function get_data_to_save(){
            global $DB;

            if( strlen(trim($this->vote_value)) ){
                // save the raw vote
                $this->_save_vote( $this->vote_value );

                // re-calculate aggregate scores..
                $rec = $this->_recalc();

                // .. and save them
                $this->_save_votes_calc( $rec );

                // save the 'avg' in the field
                return $rec['avg'];
            }
            else{
                // being called by the clone routine
                return $this->data;
            }
        }

        function _recalc(){
            global $DB;

            $sql = "SELECT count(id) as count, sum(value) as sum FROM ".K_TBL_VOTES_RAW."
            WHERE page_id='".$DB->sanitize( $this->page->id )."' AND field_id='".$DB->sanitize( $this->id )."'";

            $rs = $DB->raw_select( $sql );

            $rec = array();
            $rec['count'] = $rs[0]['count'];
            $rec['avg'] = ( $rec['count'] ) ? $rs[0]['sum']/$rec['count'] : 0;

            // now the count of individual stars
            $sql = "SELECT value, count(id) as count FROM ".K_TBL_VOTES_RAW."
            WHERE page_id='".$DB->sanitize( $this->page->id )."' AND field_id='".$DB->sanitize( $this->id )."' GROUP BY value";

            $rs = $DB->raw_select( $sql );
            foreach( $rs as $k ){
                $rec['count_'.$this->_format($k['value'])] = $k['count'];
            }

            return $rec;

        }

        function _get_votes_calc(){
            global $DB;

            if( is_null($this->calc_results) ){

                // fetch in the stored calculated results
                $rs = $DB->select( K_TBL_VOTES_CALC, array('label', 'value'), "page_id='".$DB->sanitize($this->page->id)."' AND field_id='".$DB->sanitize($this->id)."'" );

                $vars = $this->_get_named_vars(
                    array(
                        'count'=>'0',
                        'avg'=>'0',
                        'count_0'=>'0',
                        'count_1'=>'0',
                        'count_2'=>'0',
                        'count_3'=>'0',
                        'count_4'=>'0',
                        'count_5'=>'0',
                        ),
                    $rs);

                // values in percentage
                for( $x=0; $x<=5; $x++ ){
                    $vars['percent_'.$x] = ( $vars['count'] ) ? sprintf( "%d", $vars['count_'.$x] / $vars['count'] * 100 ) : 0;
                }

                // has the visitor already voted?
                $rs = $this->_get_last_vote();
                if( count($rs) ){
                    $vars['already_voted'] = '1';
                    $vars['last_vote_value'] = $this->_format( $rs[0]['value'] );
                }
                else{
                    $vars['already_voted'] = '0';
                }
                $vars['vote_type'] = 'stars';
                $vars['allow_zero_stars'] = $this->allow_zero_stars;

                $this->calc_results = $vars;
            }

            return $this->calc_results;

        }

        // renderable theme functions
        static function register_renderables(){
            global $FUNCS;

            $FUNCS->register_render( 'field_vote_stars', array('template_path'=>K_COUCH_DIR.'addons/votes/theme/', 'template_ctx_setter'=>array('KVoteStars', '_render_votes')) );
        }

        static function _render_votes( $f, $input_name, $input_id, $extra, $dynamic_insertion ){
            global $CTX;

            KField::_set_common_vars( $f->k_type, $input_name, $input_id, $extra, $dynamic_insertion, $f->simple_mode );

            $vars = $f->_get_votes_calc();

            $sum = $vars['count_1'] + $vars['count_2'] * 2 + $vars['count_3'] * 3 + $vars['count_4'] * 4 + $vars['count_5'] * 5;

            $CTX->set_all( $vars );

            $CTX->set( 'sum', $sum );
            $CTX->set( 'sum_plural', $sum != 1 ? 1 : 0 );

            $CTX->set( 'count_plural', $vars['count'] != 1 ? 1 : 0 );
        }

    } /* end KVoteStars */


    // UDF for 'vote_poll' editable region
    class KVotePoll extends KVoteUpDown{

        // Handle posted data
        function store_posted_changes( $post_val ){
            global $FUNCS;

            if( $this->deleted || is_null($post_val) ) return; // no need to store

            $val = trim( $post_val );
            if( $FUNCS->is_natural($val) && $val<10){
                $this->vote_value = $val;
                $this->modified = true;
            }
        }

        // Save to database.
        function get_data_to_save(){
            global $DB;

            if( strlen(trim($this->vote_value)) ){
                // save the raw vote
                $this->_save_vote( $this->vote_value );

                // re-calculate aggregate scores..
                $rec = $this->_recalc();

                // .. and save them
                $this->_save_votes_calc( $rec );

                // save the 'avg' in the field
                return $rec['count'];
            }
            else{
                // being called by the clone routine
                return $this->data;
            }
        }

        function _recalc(){
            global $DB;

            $sql = "SELECT count(id) as count FROM ".K_TBL_VOTES_RAW."
            WHERE page_id='".$DB->sanitize( $this->page->id )."' AND field_id='".$DB->sanitize( $this->id )."'";

            $rs = $DB->raw_select( $sql );

            $rec = array();
            $rec['count'] = $rs[0]['count'];

            // now the count of individual poll options
            $sql = "SELECT value, count(id) as count FROM ".K_TBL_VOTES_RAW."
            WHERE page_id='".$DB->sanitize( $this->page->id )."' AND field_id='".$DB->sanitize( $this->id )."' GROUP BY value";

            $rs = $DB->raw_select( $sql );
            foreach( $rs as $k ){
                $rec['count_'.$this->_format($k['value'])] = $k['count'];
            }

            return $rec;

        }

        function _get_votes_calc(){
            global $DB;

            if( is_null($this->calc_results) ){

                // fetch in the stored calculated results
                $rs = $DB->select( K_TBL_VOTES_CALC, array('label', 'value'), "page_id='".$DB->sanitize($this->page->id)."' AND field_id='".$DB->sanitize($this->id)."'" );

                $vars = $this->_get_named_vars(
                    array(
                        'count'=>'0',
                        'count_0'=>'0',
                        'count_1'=>'0',
                        'count_2'=>'0',
                        'count_3'=>'0',
                        'count_4'=>'0',
                        'count_5'=>'0',
                        'count_6'=>'0',
                        'count_7'=>'0',
                        'count_8'=>'0',
                        'count_9'=>'0',
                        ),
                    $rs);

                // values in percentage
                for( $x=0; $x<10; $x++ ){
                    $vars['percent_'.$x] = ( $vars['count'] ) ? sprintf( "%d", $vars['count_'.$x] / $vars['count'] * 100 ) : 0;
                }

                // has the visitor already voted?
                $rs = $this->_get_last_vote();
                if( count($rs) ){
                    $vars['already_voted'] = '1';
                    $vars['last_vote_value'] = $this->_format( $rs[0]['value'] );
                }
                else{
                    $vars['already_voted'] = '0';
                }
                $vars['vote_type'] = 'poll';

                $this->calc_results = $vars;
            }

            return $this->calc_results;

        }

        // renderable theme functions
        static function register_renderables(){
            global $FUNCS;

            $FUNCS->register_render( 'field_vote_poll', array('template_path'=>K_COUCH_DIR.'addons/votes/theme/', 'template_ctx_setter'=>array('KVotePoll', '_render_votes')) );
        }

        static function _render_votes( $f, $input_name, $input_id, $extra, $dynamic_insertion ){
            global $CTX;

            KField::_set_common_vars( $f->k_type, $input_name, $input_id, $extra, $dynamic_insertion, $f->simple_mode );

            $vars = $f->_get_votes_calc();

            $CTX->set_all( $vars );

            $CTX->set( 'count_plural', $vars['count'] != 1 ? 1 : 0 );
        }

    }  /* end KVotePoll */


    $FUNCS->register_udf( 'vote_updown', 'KVoteUpDown', 0/*repeatable*/ );
    $FUNCS->register_udf( 'vote_stars', 'KVoteStars', 0/*repeatable*/ );
    $FUNCS->register_udf( 'vote_poll', 'KVotePoll', 0/*repeatable*/ );
    $FUNCS->register_tag( 'show_votes', array('KVoteUpDown', 'show_handler'), 1, 0 ); // The helper tag that shows the variables via CTX

    $FUNCS->add_event_listener( 'register_renderables', array('KVoteUpDown',  'register_renderables') );
    $FUNCS->add_event_listener( 'register_renderables', array('KVoteStars', 'register_renderables') );
    $FUNCS->add_event_listener( 'register_renderables', array('KVotePoll',  'register_renderables') );

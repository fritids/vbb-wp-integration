<?php
define('WP_INSTALLING', true);  // to disable loadding all WP plugins 
define('WP_PATH', $vbulletin->options['wtt_vbbwp_wp_path']); 

require_once(WP_PATH . '/wp-load.php');

$table_prefix = $vbulletin->options['wtt_vbbwp_wp_db_prefix'];

if ($table_prefix)
	$wpdb->set_prefix($table_prefix); 


function logging($message)
{
	$date = new DateTime();
	$result = $date->format('Y-m-d');
	$logfile = '/tmp/homepage_' . $result . '.log';
	
	error_log ( "[" . date ( "Y-m-d H:i:s" ) . "] " . $message . "\n", 3, $logfile );
}

/*
 * sync thanks amount to WP
 */
function wtt_vbb_wp_update_post_thanks_amount($post_thanks_amount, $postid) 
{
	global $wpdb;
	try {
		$wp_postid = $wpdb->get_var ( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'firstpostid' AND meta_value = '$postid'" );
		
		if ($wp_postid) {
			$wpdb->query ( "UPDATE $wpdb->postmeta SET meta_value = '$post_thanks_amount' WHERE post_id = $wp_postid AND meta_key = 'thanks'" );
		}
	} catch ( Exception $e ) {
		logging ( "sync thanks amount vbb postid #" . $postid );
		logging ( print_r($e, true) );
		return false;		
	}	
}

/*
 * sync thread's replycount to WP 
 */
function wtt_vbb_wp_newreply_post_complete_hook($replycount, $threadid)
{
	global $wpdb;
	
	try {
		$wp_postid = $wpdb->get_var ( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'threadid' AND meta_value = '$threadid'" );
		
		if ($wp_postid) {
			$wpdb->query ( "UPDATE $wpdb->posts SET comment_count = '$replycount' WHERE ID = $wp_postid" );
		}
	} catch ( Exception $e ) {
		logging ( "sync replycount vbb threadid #" . $threadid );
		logging ( print_r($e, true) );
		return false;		
	}
}

/*
 * sync newly created thread to WP
 */
function wtt_vbb_wp_newthread_post_complete_hook($thread)
{
	global $wpdb, $vbulletin;	

	require_once(DIR . '/includes/class_bbcode.php');
	
	$bbcode_parser = new vB_BbCodeParser($vbulletin, fetch_tag_list());
	$post_content = $bbcode_parser->do_parse( $thread['description'] );
	$post_content = str_replace('<a href="http:///forum/links.php?url=', '<a href="http://www.webtretho.com/forum/links.php?url=', $post_content);
	date_default_timezone_set("Asia/Ho_Chi_Minh");
	$post_date = date("Y-m-d H:i:s", $thread['dateline']);
	
	try {
		// insert to posts
		$wpdb->insert ( $wpdb->posts, array (
				'post_author' => $thread['postuserid'],
				'post_date' => $post_date,
				'post_date_gmt' => $post_date,
				'post_content' => $post_content,
				'post_title' => $thread ['title'],
				'post_type' => 'post',
				'comment_status' => 'open',
				'post_name' => sanitize_title ( $thread ['title'] ),
				'comment_count' => $thread ['replycount'],
				'post_status' => 'publish',
				'post_modified' => $post_date,
				'post_modified_gmt' => $post_date 
		) );
		
		$post_id = $wpdb->insert_id;
		
		if (! $post_id) {
			error_log ( "[" . date ( "Y-m-d H:i:s" ) . "] sync thread #" . $thread ['threadid'] . " - inserting post failed\n", 3, $logfile );
			return false;
		}
		
		// insert to postmeta
		$insert = $wpdb->prepare ( "(%d, %s, %s),(%d, %s, %s),(%d, %s, %s),(%d, %s, %s),(%d, %s, %s)", $post_id, 'threadid', $thread ['threadid'], $post_id, 'firstpostid', $thread ['firstpostid'], $post_id, 'postuserid', $thread ['postuserid'], $post_id, 'views', $thread ['views'], $post_id, 'thanks', $thread ['thanks'] );
		
		$wpdb->query ( "INSERT INTO $wpdb->postmeta (post_id, meta_key, meta_value ) VALUES " . $insert );
		
		// insert to term_relationships
		$sql = $wpdb->prepare ( "SELECT term_taxonomy_id
			FROM $wpdb->term_taxonomy
			WHERE term_id = (SELECT term_id FROM $wpdb->terms WHERE slug LIKE %s LIMIT 1)", $thread ['forumid'] . '-%' );
		$term_taxonomy_id = $wpdb->get_var ( $sql );
		
		if ($term_taxonomy_id)
			$wpdb->insert ( $wpdb->term_relationships, array (
					'object_id' => $post_id,
					'term_taxonomy_id' => $term_taxonomy_id 
			) );
		else {
			logging ( "sync thread #" . $thread ['threadid'] . " - term_taxonomy_id doesn't exist" );
			return false;
		}
	} catch ( Exception $e ) {
		logging ( "sync newly created thread #" . $thread ['threadid'] );
		logging ( print_r($e, true) );
		return false;
	}
}

/*
 * sync thread moving to WP
*/
function wtt_vbb_wp_move_thread($threadid, $forumid)
{
	global $wpdb;
	
	try {
		$wp_postid = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'threadid' AND meta_value = '$threadid'");
		$sql = $wpdb->prepare ( "SELECT term_taxonomy_id
				FROM $wpdb->term_taxonomy
				WHERE term_id = (SELECT term_id FROM $wpdb->terms WHERE slug LIKE %s LIMIT 1)", $forumid . '-%' );
		$term_taxonomy_id = $wpdb->get_var ( $sql );
		
		if ($wp_postid AND $term_taxonomy_id)
		{
			$wpdb->query ( "UPDATE $wpdb->term_relationships SET term_taxonomy_id = $term_taxonomy_id WHERE object_id = $wp_postid" );
		}
		else
		{
			logging ( "move thread $threadid to forum $forumid - post or term doesn't exist" );
			return false;
		}				
	} catch (Exception $e) {
		logging ( "move thread #" . print_r ( $threadid, true ) . " to forum: $forumid" );
		logging ( print_r($e, true) );
		return false;		
	}
}

/*
 * sync threads moving to WP
*/
function wtt_vbb_wp_move_threads($threadids, $forumid) {
	global $wpdb;

	try {
		$sql = $wpdb->prepare ( "SELECT term_taxonomy_id
			FROM $wpdb->term_taxonomy
			WHERE term_id = (SELECT term_id FROM $wpdb->terms WHERE slug LIKE %s LIMIT 1) LIMIT 1", $forumid . '-%' );
		
		$term_taxonomy_id = $wpdb->get_var ( $sql );
		$tids = "'" . implode ( "', '", $threadids ) . "'";
		$postids = $wpdb->get_col ( "SELECT DISTINCT post_id FROM $wpdb->postmeta WHERE meta_key = 'threadid' AND meta_value IN ($tids)" );
		$pids = "'" . implode ( "', '", $postids ) . "'";
		$wpdb->query ( "UPDATE $wpdb->term_relationships SET term_taxonomy_id = $term_taxonomy_id WHERE object_id IN ($pids)" );
	} catch ( Exception $e ) {
		logging ( "move threads #" . print_r ( $threadids, true ) . " to forum: $forumid" );
		logging ( print_r($e, true) );
		return false;
	}
}

/*
 * sync single thread deleting to WP
*/
function wtt_vbb_wp_delete_thread($threadid, $delete = true)
{
	global $wpdb;
	try {
		$wpdb->query ( "UPDATE $wpdb->posts SET post_status = '" . ($delete ? 'trash' : 'publish') . "' WHERE ID = (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'threadid' AND meta_value = '$threadid' LIMIT 1)" );
	} catch ( Exception $e ) {
		logging ( ($delete ? 'delete' : 'undelete') . " thread #" . $threadid );
		logging ( print_r ( $e, true ) );
		return false;
	}
}

/*
 * sync multiple threads deleting to WP
*/
function wtt_vbb_wp_delete_threads($threadids, $delete = true)
{
	global $wpdb;
	try {
		$tids = "'" . implode ( "', '", $threadids ) . "'";
		$wpdb->query ( "UPDATE $wpdb->posts SET post_status = '" . ($delete ? 'trash' : 'publish') . "' WHERE ID IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'threadid' AND meta_value IN ($tids))" );
	} catch ( Exception $e ) {
		logging ( ($delete ? 'delete' : 'undelete') . " threads #" . print_r($threadids, true) );
		logging ( print_r ( $e, true ) );
		return false;
	}
}

/*
 * sync single thread opening/closing to WP
*/
function wtt_vbb_wp_openclose_thread($threadid, $open = true)
{
	global $wpdb;
	try {
		$wpdb->query ( "UPDATE $wpdb->posts SET comment_status = '" . ($open ? 'open' : 'close') . "' WHERE ID = (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'threadid' AND meta_value = '$threadid' LIMIT 1)" );
	} catch ( Exception $e ) {
		logging ( "openclose thread #" . $threadid );
		logging ( print_r ( $e, true ) );
		return false;
	}
}

/*
 * sync multiple threads opening/closing to WP
*/
function wtt_vbb_wp_openclose_threads($threadids, $open = true)
{
	global $wpdb;
	try {
		$tids = "'" . implode ( "', '", $threadids ) . "'";
		$wpdb->query ( "UPDATE $wpdb->posts SET comment_status = '" . ($open ? 'open' : 'close') . "' WHERE ID IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'threadid' AND meta_value IN ($tids))" );
	} catch ( Exception $e ) {
		logging ( "openclose threads #" . print_r($threadids, true) );
		logging ( print_r ( $e, true ) );
		return false;
	}
}

/*
 * sync single thread approving/unapproving to WP
*/
function wtt_vbb_wp_approve_thread($threadid, $approve = true)
{
	global $wpdb;
	try {
		$wpdb->query ( "UPDATE $wpdb->posts SET post_status = '" . ($approve ? 'publish' : 'trash') . "' WHERE ID = (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'threadid' AND meta_value = '$threadid' LIMIT 1)" );
	} catch ( Exception $e ) {
		logging ( ($approve ? 'approve' : 'unapprove') . " thread #" . $threadid );
		logging ( print_r ( $e, true ) );
		return false;
	}
}

/*
 * sync multiple threads approving/unapproving to WP
*/
function wtt_vbb_wp_approve_threads($threadids, $approve = true)
{	
	global $wpdb;
	try {
		$tids = "'" . implode ( "', '", $threadids ) . "'";
		$wpdb->query ( "UPDATE $wpdb->posts SET post_status = '" . ($approve ? 'publish' : 'trash') . "' WHERE ID IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'threadid' AND meta_value IN ($tids))" );
	} catch ( Exception $e ) {
		logging ( ($approve ? 'approve' : 'unapprove') . " threads #" . print_r($threadids, true) );
		logging ( print_r ( $e, true ) );
		return false;
	}
}


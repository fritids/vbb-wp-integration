<?php

define('WP_INSTALLING', true);  // to disable loadding all WP plugins 
define('WP_PATH', $vbulletin->options['wtt_vbbwp_wp_path']); 

require_once(WP_PATH . '/wp-load.php');

// logfile
$date = new DateTime();
$result = $date->format('Y-m-d');
$logfile = '/tmp/homepage_' . $result . '.log';

/*
 * sync thanks amount to WP
 */
function wtt_vbb_wp_update_post_thanks_amount($post_thanks_amount, $postid) 
{
	global $wpdb; 
	
	$wp_postid = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'firstpostid' AND meta_value = '$postid'"); 

	if ($wp_postid) 
	{
		$wpdb->query ( "UPDATE $wpdb->postmeta SET meta_value = '$post_thanks_amount' WHERE post_id = $wp_postid AND meta_key = 'thanks'" );		
	}
}

/*
 * sync thread's replycount to WP 
 */
function wtt_vbb_wp_newreply_post_complete_hoook($replycount, $threadid)
{
	global $wpdb;
	
	$wp_postid = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'threadid' AND meta_value = '$threadid'");
	
	if ($wp_postid)
	{
		$wpdb->query ( "UPDATE $wpdb->posts SET comment_count = '$replycount' WHERE ID = $wp_postid" );
	}	
}

/*
 * sync newly created thread to WP
 */
function wtt_vbb_wp_newthread_post_complete_hoook($thread)
{
	global $wpdb, $vbulletin, $logfile;	

	error_log("wtt_vbb_wp_newthread_post_complete_hoook\n", 3, '/tmp/homepage.log');


	require_once(DIR . '/includes/class_bbcode.php');
	
	$bbcode_parser = new vB_BbCodeParser($vbulletin, fetch_tag_list());
	$post_content = $bbcode_parser->do_parse( $thread['description'] );
	$post_content = str_replace('<a href="http:///forum/links.php?url=', '<a href="http://www.webtretho.com/forum/links.php?url=', $post_content);
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
			error_log ( "[" . date ( "Y-m-d H:i:s" ) . "] sync thread #" . $thread ['threadid'] . " - term_taxonomy_id doesn't exist\n", 3, $logfile );
			return false;
		}
	} catch ( Exception $e ) {
		error_log ( "[" . date ( "Y-m-d H:i:s" ) . "] sync thread #" . $thread ['threadid'] . " - " . print_r($e, true) . "\n", 3, $logfile );
		return false;
	}
}




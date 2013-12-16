<?php

define('WP_INSTALLING', true);  // to disable loadding all WP plugins 
define('WP_PATH', $vbulletin->options['wtt_vbbwp_wp_path']); 

require_once(WP_PATH . '/wp-load.php');


function wtt_vbb_wp_update_post_thanks_amount($post_thanks_amount, $postid) 
{
	global $wpdb; 
	
	$wp_postid = $wpdb->get_var("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'firstpostid' AND meta_value = '$postid'"); 

	if ($wp_postid) 
	{
		$wpdb->query ( "UPDATE $wpdb->postmeta SET meta_value = '$post_thanks_amount' WHERE post_id = $wp_postid AND meta_key = 'thanks'" );		
	}
}


<?php
/*
Plugin Name: WP Model
Description: A plugin that demonstrates various uses of the WordPressModel class.  
Version: 1.0
Plugin URI: https://github.com/TrevorMills/WPModel
Author URI: http://topquark.com
Author: Trevor Mills
 */

add_shortcode('wp-model','wp_model_shortcodes');
function wp_model_shortcodes($atts=array(),$content=null,$code=''){
	$messages = array();
	switch($code){
	case 'wp-model':
		$defaults = array(
			'the_post_type' => 'post',
			'the_taxonomy' => 'category,tag',
			'the_post_status' => 'publish',
			'the_meta_keys' => '_thumbnail_id,_edit_lock',
		);
		$atts = shortcode_atts($defaults,$atts);
		$atts = array_map(create_function('$a','return explode(",",$a);'),$atts);
		
		require_once( plugin_dir_path( __FILE__ ) . 'lib/model/WordPressModel.php' );
		$Model = new WordPressModel();
		
		$beginning_queries = $Model->getQueryCount();
		$Model->apply('bound',$atts);
		$start = $grand_start = microtime(true);
		$posts = $Model->getAll();	
		$end = microtime(true);
		
		$end_queries = $Model->getQueryCount();

		$messages[] = sprintf( __('Found %s %s in %s seconds.', 'wp-model'), count($posts), 'posts',  $end - $grand_start );
		// the -2 is to account for the two queries run to get the query counts
		$messages[] = sprintf( __('Query Count: %s (the number of MySQL queries that were run to achieve this result)', 'wp-model'), $end_queries - $beginning_queries - 2 );
		$messages[] = sprintf( __('Using the following binding: %s', 'wp-model'), '<pre>' . print_r( array_filter( $Model->get( 'bound' ), 'is_array' ), true ) . '</pre>' );


		$messages[] = sprintf( __('Here is the query: %s', 'wp-model'), '<pre>' . print_r( $Model->buildQuery() , true ) . '</pre>' );

		$messages[] = sprintf( __('A random post: %s', 'wp-model'), '<pre>' . print_r($posts[array_rand($posts)],true) . '</pre>' );
		break;
	}
	return implode( "<br/>", $messages);
}

?>
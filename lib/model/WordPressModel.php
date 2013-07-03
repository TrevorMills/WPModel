<?php
if (!class_exists('WordPressModel')) : 
require_once('DBModel.php');

class WordPressModel extends DBModel{

	public function __construct(){
		parent::__construct();
		$this->set('model',(object) array(
			'id',
			'date',
			'modified_date', // Necessary to allow incremental updates to the result set
			'title',
			'content',
			'attachments',
			'categories',
			'tags'
		));

		global $wpdb;
		$this->set('primary_key','id');
		$this->set('map',array(
			'id' => array(
					'table' => $wpdb->posts,
					'column' => 'ID',
					'where' => array(
						'post_type' => '{{the_post_type}}',
						'post_status' => '{{the_post_status}}'
					)
				),
			'date' => array(
					'table' => $wpdb->posts,
					'column' => 'post_date'
				),
			'modified_date' => array(
					'table' => $wpdb->posts,
					'column' => 'post_modified'
				),
			'title' => array(
					'table' => $wpdb->posts,
					'column' => 'post_title'
				),
			'content' => array(
					'table' => $wpdb->posts,
					'column' => 'post_content'
				),
			'attachments' => array(
					'hasMany' => true,
					'table' => $wpdb->posts,
					'column' => array('ID','{{attachment.metadata}}'),
					'where' => array(
							'post_type' => 'attachment',
							'post_parent' => '{{id}}',
							'attachment.metadata' => array(
								'table' => $wpdb->postmeta,
								'column' => 'meta_value',
								'where' => array(
									'meta_key' => '_wp_attachment_metadata',
									'post_id' => '{{attachment.ID}}'
								)
							)
						),
					'factory' => array(&$this,'attachmentFactory')
				),
			'terms' => $this->termMap(array('category','tag')),
			'meta' => array(
				'hasMany' => true,
				'table' => $wpdb->postmeta,
				'column' => array('meta_key','meta_value'),
				'where' => array(
					'post_id' => '{{id}}',
					'meta_key' => '{{the_meta_keys}}'
				),
				'factory' => array(&$this,'metaFactory')
			)
		));

		$this->apply('bound',array(
			'the_post_type' => array('post','page'),
			'the_post_status' => array('publish'),
			'the_meta_keys' => array('_thumbnail_id'),
			'relationships.term_taxonomy_id' => "{$wpdb->term_relationships}.term_taxonomy_id",
			'taxonomy.taxonomy' => "$wpdb->term_taxonomy.taxonomy",
			'taxonomy.term_id' => "$wpdb->term_taxonomy.term_id",
			'terms.name' => "$wpdb->terms.name",
			'terms.object_id' => "$wpdb->terms.object_id",
			'attachment.ID' => $wpdb->posts.'_sub.ID'
		));
	}

	public function termMap($taxonomy='category'){
		global $wpdb;
		$this->bind('the_taxonomy',$taxonomy);
		return array(
				'hasMany' => true,
				'table' => $wpdb->term_relationships,
				'column' => array('{{terms.term_id}}','{{taxonomy.taxonomy}}','{{terms.name}}'),
				'where' => array(
						'taxonomy.term_id' => array(
							'table' => $wpdb->term_taxonomy,
							'column' => 'term_id',
							'where' => array(
								'taxonomy' => '{{the_taxonomy}}',
								'term_taxonomy_id' => '{{relationships.term_taxonomy_id}}'
							)
						),
						'terms.term_id' => array(
							'table' => $wpdb->terms,
							'column' => 'term_id',
							'where' => array(
								'term_id' => '{{taxonomy.term_id}}'
							)
						),
						'object_id' => '{{id}}'
					),
				'factory' => array(&$this,'termFactory')
			);
	}

	public function attachmentFactory($_attachments){
		return array_map(create_function('$a','
			$a["id"] =$a[0];
			$a["meta"] = maybe_unserialize($a[1]);
			unset($a[0]);
			unset($a[1]);
			if (!empty($a["meta"]["file"])){
				$a["base"] = WP_CONTENT_URL."/uploads/".dirname($a["meta"]["file"])."/";
				$a["meta"]["file"] = basename($a["meta"]["file"]);
			}
			if (isset($a["meta"]["image_meta"])){
				unset($a["meta"]["image_meta"]);
			}
			return $a;
		'),$_attachments);
	}

	public function metaFactory($meta){
		$metas = array();
		foreach ($meta as $meta){
			list($key,$value) = $meta;
			$metas[$key] = maybe_unserialize($value);
		}
		return $metas;
	}

	public function termFactory($terms){
		$new_terms = array();
		foreach ($terms as $term){
			list($id,$taxonomy,$name) = $term;
			if (!isset($new_terms[$taxonomy])){
				$new_terms[$taxonomy] = array();
			}
			$new_terms[$taxonomy][$id] = $name;
		}
		return $new_terms;
	}
}

endif; // class_exists
?>
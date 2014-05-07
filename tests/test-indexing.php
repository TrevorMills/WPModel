<?php
	
class WPModelIndexingTest extends WP_UnitTestCase {
	
	/** 
	 * @private function for creating data corpus for the following tests
	 * 
	 * returns an array of the ids that were created
	 */
	private function createPosts(){
		$this->setupIndexer();
		
		// Create a small (10) corpus of test posts
		$ids = array();
		for ( $p = 1; $p <= 10; $p++ ){
			$post = array(
				'post_title' => "I am post #$p",
				'post_content' => "Simple content for post $p",
				'post_status' => 'publish',
				'post_type' => 'post',
				'post_author' => 1
			);
			
			$id = $this->factory->post->create( $post );
			
			// Add some unique meta
			add_post_meta( $id, 'meta_key_string', $p * 2, true );
			add_post_meta( $id, 'meta_key_integer', $p * 2, true );
			add_post_meta( $id, 'meta_key_float', ($p + $p / 13) * 2, true );
			
			$ids[] = $id;
			
			//$this->indexer->buildIndex( $id ); // @TODO need to have Indexer build on add|delete|update_post_meta
		}		
		
		return $ids;
	}
	
	function tearDown(){
		parent::tearDown();
		$this->indexer->__destruct();
	}
	
	/** 
	 * Generates the proper index.
	 */ 
	private function setupIndexer(){
		$this->indexer = new WordPressIndexer( 'post' );
		$this->indexer->addIndex( 'meta_key_integer', 'integer' );
		$this->indexer->addIndex( 'meta_key_float', 'float' );
	}
	
	/** 
	 * Create 10 posts and then fetch them all
	 */
	function testOrderByIsNowNatural() {
		$this->createPosts();
		
		$Model = new WordPressModel();
		$Model->apply( 'args', array(
			'order_by' => 'meta.meta_key_integer',
			'order' => 'DESC'
		));

		$expected = array( '2','4','6','8','10','12','14','16','18','20' );
		rsort( $expected );

		$posts = $Model->getAll();
		$metas = array();
		foreach ( $posts as $post ){
			$metas[] = $post->meta['meta_key_integer'];
		}
		$this->assertEquals( $expected, $metas );
		
		// Now, just to be sure, grab it ordered by the non-indexed meta field
		// and make sure the metas does NOT equal the expected
		$Model->apply( 'args', array(
			'order_by' => 'meta.meta_key_string'
		));

		$posts = $Model->getAll();
		$metas = array();
		foreach ( $posts as $post ){
			$metas[] = $post->meta['meta_key_integer'];
		}
		$this->assertThat( $metas, 
			$this->logicalNot( 
				$this->equalTo( $expected ) 
			)
		);
	}
	
	function testOrderByFloat(){
		$this->createPosts();
		
		$Model = new WordPressModel();
		$Model->apply( 'args', array(
			'order_by' => 'meta.meta_key_float',
			'order' => 'DESC'
		));
		
		$posts = $Model->getAll();
		$sorted = array();
		$unsorted = array();
		foreach ( $posts as $post ){
			$sorted[] = floatval($post->meta[ 'meta_key_float' ]);
			$unsorted[] = floatval($post->meta[ 'meta_key_float' ]);
		}
		rsort( $sorted );
		$this->assertEquals( $unsorted, $sorted );
	}
	
	function testAfterDeletingPost(){
		$ids = $this->createPosts();
		global $wpdb;
		
		$target_id = current( $ids );
		
		$Model = new WordPressModel();
		$target = $Model->getOne( $target_id );
		
		wp_delete_post( $target_id, true );
		
		$this->assertNull( $Model->getOne( $target_id ) );
		
		$Model->apply( 'args', array(
			'where' => array(
				'meta.meta_key_integer' => $target->meta['meta_key_integer']
			)
		));

		global $wpdb;
		$this->assertEmpty( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . $this->indexer->getTableName( 'meta_key_integer' ) . " WHERE post_id = %d", $target_id ) ) );
		
		// Make sure we don't pull anything back
		$this->assertEquals( 0, $Model->getCount() );
	}
	
	
}
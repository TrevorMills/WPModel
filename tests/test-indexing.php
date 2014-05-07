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
		return;
		static $indexer;
		if ( !isset( $indexer ) ){
			// Only build and keep one of these
			$indexer = new WordPressIndexer( 'post' );
			$indexer->addIndex( 'meta_key_integer', 'integer' );
			$indexer->addIndex( 'meta_key_float', 'float' );
		}
		else{
			$indexer->createIndexTables( true ); // force the recreation of the index tables.
		}
	}
	
	/** 
	 * Create 10 posts and then fetch them all
	 */
	function testOrderByIsNowNatural() {
		$this->createPosts();
		
		$Model = new WordPressModel();
		$Model->apply( 'args', array(
			'order_by' => 'meta.meta_key_integer',
			'order' => 'ASC'
		));

		$expected = array( '2','4','6','8','10','12','14','16','18','20' );

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
			'order' => 'ASC'
		));
		
		$posts = $Model->getAll();
		$sorted = array();
		$unsorted = array();
		foreach ( $posts as $post ){
			$floats[] = floatval($post->meta[ 'meta_key_float' ]);
		}
		sort( $floats );
		$this->assertEquals( $unsorted, $sorted );
	}
	
	
}
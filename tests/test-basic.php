<?php

class WPModelTest extends WP_UnitTestCase {
	
	/** 
	 * @private function for creating data corpus for the following tests
	 * 
	 * returns an array of the ids that were created
	 */
	private function createPosts(){
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
			add_post_meta( $id, 'meta_key_1', 'shared by all posts', true );
			add_post_meta( $id, 'meta_key_2', $p, true );
			
			$ids[] = $id;
		}		
		
		return $ids;
	}
	
	/** 
	 * Create 10 posts and then fetch them all
	 */
	function testFetchAll() {
		$this->createPosts();
		
		$Model = new WordPressModel();
		$Model->bind( 'post_type', array( 'post' ) );
		
		$this->assertEquals( 10, $Model->getCount() );
		$this->assertEquals( 10, count( $Model->getAll() ) );
	}
	
	/** 
	 * Create 10 posts and then fetch based on values in meta_key_1 and meta_key_2
	 */
	function testFetchBasedOnMeta(){
		$this->createPosts();
		
		$Model = new WordPressModel();

		// First, we'll get all posts based on the common value in meta_key_1
		$Model->apply( 'args', array(
			'where' => array(
				'meta.meta_key_1' => 'shared by all posts'
			)
		));
		
		$this->assertEquals( 10, $Model->getCount() );
		$this->assertEquals( 10, count( $Model->getAll() ) );
		

		// Now, get just two posts based on values in meta_key_2
		$Model->apply( 'args', array(
			'where' => array(
				'meta.meta_key_1' => null, // unset this one 
				'meta.meta_key_2' => array( 2, 4 )
			)
		));		
		
		
		$posts = $Model->getAll();
		$this->assertEquals( 2, $Model->getCount() );
		$this->assertEquals( 2, count( $posts ) );
		
		$titles = array();
		foreach ( $posts as $post ){
			$titles[] = $post->title;
		}
		sort( $titles );
		
		// Make sure we're getting only the posts we're expecting
		$this->assertEquals( array( 'I am post #2', 'I am post #4' ), $titles );
				
	}
	
	/** 
	 * Test that we can order by a column in the main table, as well as
	 * by meta values.  We'll also test that the order_by column can be a 
	 * reference
	 */
	function testOrderBy(){
		$this->createPosts();
		
		$Model = new WordPressModel();
		
		$Model->apply( 'args', array( 
			'order_by' => 'post_title', // note this is the actual column name
			'order' => 'ASC'
		));
		
		$expected = array( 
			'I am post #1',
			'I am post #10', // Note the non-natural sorting here
			'I am post #2',
			'I am post #3',
			'I am post #4',
			'I am post #5',
			'I am post #6',
			'I am post #7',
			'I am post #8',
			'I am post #9',
		);
		
		$posts = $Model->getAll();
		$titles = array();
		foreach ( $posts as $post ){
			$titles[] = $post->title;
		}
		$this->assertEquals( $expected, $titles );
		
		// Run it again, but this time use a reference for the title (instead of the actual column name)
		$Model->apply( 'args', array( 
			'order_by' => '{{title}}', // note this is now a reference
		));

		$posts = $Model->getAll();
		$titles = array();
		foreach ( $posts as $post ){
			$titles[] = $post->title;
		}
		$this->assertEquals( $expected, $titles );

		// Now, order by one of the metas
		$Model->apply( 'args', array( 
			'order_by' => 'meta.meta_key_2',
			'order' => 'DESC'
		));
		$expected = array( '9', '8', '7', '6', '5', '4', '3', '2', '10', '1' );
		$posts = $Model->getAll();
		$metas = array();
		foreach ( $posts as $post ){
			$metas[] = $post->meta['meta_key_2'];
		}
		$this->assertEquals( $expected, $metas );
	}
	
	/** 
	 * Test that we can change which meta keys we retrieve when we retrieve all
	 */ 
	function testBindingTheMetaKeys(){
		$this->createPosts();
		
		$Model = new WordPressModel();
		$Model->bind( 'the_meta_keys', array( 'meta_key_2' ) );
		
		$posts = $Model->getAll();
		foreach ( $posts as $post ){
			// Make sure we ONLY got meta_key_1
			$this->assertFalse( isset($post->meta['meta_key_1']) );
			$this->assertTrue( isset($post->meta['meta_key_2']) );
			break; // only need to do this once
		}
	}
	
	/** 
	 * Test that we can retrieve all posts, but NOT some
	 */ 
	function testNot(){
		$ids = $this->createPosts();
		$not = array_splice( $ids, 0, 5 );
		
		$Model = new WordPressModel();
		
		$Model->apply( 'args', array(
			'order_by' => '{{id}}', // Just make sure we're getting them back in ID order
			'order' => 'ASC',
			'not' => $not
		));
		
		$posts = $Model->getAll();
		$this->assertEquals( 5, count( $posts ) );
		$this->assertEquals( $ids, array_keys( $posts ) );
	}
	

}


<?php
	
class WordPressIndexer{
	var $ready_transient_timeout = 100; // short for now, we'll increase this later
	var $index_batch_size = 250;
	var $created_tables = false;
	
	var $keys = array();
	var $integers = array();
	var $floats = array();
	var $allow_multiple = array();
	var $post_type;
	var $actions_added = false;
	
	public function __construct( $post_type = 'post' ){
		add_action( 'admin_notices', array( &$this, 'admin_notices' ) );
		add_action( 'wp_ajax_wp_indexer', array( &$this, 'ajax' ) );		
		add_action( 'save_post', array( &$this, 'buildIndex' ), 100, 2 ); // Do it late in the game
		
		$this->post_type = $post_type;
	}
	
	/** 
	 * buildIndex
	 * 
	 * See http://backchannel.org/blog/friendfeed-schemaless-mysql
	 */
	public function buildIndex( $post_id = null ){
		if ( empty( $this->getIndexableMetaKeys() ) || ( isset( $post_id ) && $this->post_type != get_post_type( $post_id ) ) ){
			return;
		}
		
		if ( isset( $post_id ) ){
			$post = get_post( $post_id );
		}
		
		do_action_ref_array( 'before_building_index', array( &$this ) );

		
		$this->createIndexTables();

		$Model = new WordPressModel();
		
		global $wpdb;
		
		set_time_limit( 0 );
		$Model->bind( 'the_meta_keys', $this->getIndexableMetaKeys() );
		$Model->bind( 'the_post_type', array($this->post_type) );
		
		if ( isset( $post_id ) ){
			if ( !is_array( $post_id ) ){
				$post_id = array( $post_id );
			}
		}
		
		$total = isset( $post_id ) ? count( $post_id ) : $Model->getCount();
		$batch_size = $this->index_batch_size;
		
		$from = isset( $_REQUEST['offset'] ) ? intval( $_REQUEST['offset'] ) : 0;
		$to = isset( $_REQUEST['offset'] ) ? intval( $_REQUEST['offset'] ) + $batch_size : $total;
		
		global $wpdb;		
		$counter = 0;
		
		$queries = array();
		
		$indexable = get_transient( "_wp_indexable_keys_$this->post_type" );
		if ( $indexable === false ){
			$indexable = array();
		}
		for ( $offset = $from; $offset < $to; $offset += $batch_size ){
			$Model->apply( 'args', array( 
				'limit' => $batch_size,
				'offset' => $offset
			));
			
			$posts = isset( $post_id ) ? $Model->getSome( array_slice( $post_id, $offset, $batch_size) ) : $Model->getAll();
			if ( !$posts ){
				break;
			}
			
			foreach ( $posts as $post ){
				$counts = array();

				$got_one = false;
				foreach ( $this->getIndexableMetaKeysWithColumnType() as $att => $definition ){
					if ( !isset( $post_id ) && isset( $indexable[ $att ] ) && $indexable[ $att ] == $definition ){
						// We are not working on a single post and the column definition for the attribute's index
						// has not changed.  We can skip this attribute 
						continue;
					}
					$got_one = true;
					$table_name = $this->getTableName( $att );
					$query = "INSERT INTO `$table_name` ( `post_id`, `meta_value` ) VALUES ";
					$wpdb->query( "DELETE FROM `$table_name` WHERE `post_id` = $post->id" );

					if ( !empty( $post->meta[ $att ] ) ){
						if ( !is_array( $post->meta[ $att ] ) ){
							$post->meta[ $att ] = array( $post->meta[ $att ] );
						}
						if ( !isset( $queries[ $query ] ) ){
							$queries[ $query ] = array();
						}

						foreach ( $post->meta[ $att ] as $value ){
							$queries[ $query ][] = $wpdb->prepare( "( %d, " . self::placeholder( $value ) . " )", $post->id, $value );
						}
					}
				}
				
				if ( !$got_one ){
					// There are no tables that need to be updated, let's just skip forward and see if any indices need to be dropped
					$offset = $total;
					break;
				}
				
				
			}
			
		}
		
		foreach ( $queries as $query => $inserts ){
			$chunks = array_chunk( $inserts, 50 );
			foreach ( $chunks as $chunk ){
				$wpdb->query( $query . "\n" . implode( ",\n", $chunk ) );
			}
		}
		
		if ( $offset >= $total ){
			// Remove any tables no longer needed
			$current_indexable = $this->getIndexableMetaKeys();
			foreach ( array_keys($indexable) as $att ){
				if ( !in_array( $att, $current_indexable ) ){
					$table_name = $this->getTableName( $att );
					$wpdb->query( "DROP TABLE `$table_name`" );
				}
			}		

			set_transient( "_wp_indexable_keys_$this->post_type", $this->getIndexableMetaKeysWithColumnType() );
			set_transient( "_wp_is_index_ready_$this->post_type", 1, $this->ready_transient_timeout ); // Short timeout for this inexpensive
		}
		
		do_action_ref_array( 'after_building_index', array( &$this ) );
		
		return array( 
			'completed' => min( $offset, $total ),
			'total' => $total
		);
	}
	
	/** 
	 * createIndexTables 
	 * 
	 * Note: the parameter $force, if set to true, will definitely try to create the tables
	 * I only put this in there for Unit Testing purposes, where temporary tables were getting duplicated, etc.
	 */
	public function createIndexTables( $force = false ){
		global $wpdb;

		// only do this once - no need to do it everytime we run buildIndex
		if ( !$this->created_tables || $force ){
			foreach ( $this->getIndexableMetaKeysWithColumnType() as $att => $definition ){
				$table_name = $this->getTableName( $att ); 
			
				// If table exists and the type is the same, then we are good
				if ( !$force && $wpdb->query( "SHOW TABLES LIKE '$table_name'" ) ){
					$column = $wpdb->get_row( "SHOW COLUMNS FROM `$table_name` LIKE 'meta_value'" );
					if ( strtoupper($column->Null) == 'NO' ){
						$column->Type .= ' NOT NULL';
					}
					if ( strtoupper($column->Type) == $definition ){
						// All's well
						continue;
					}
					else{
						// Alas, the type has changed.  
						$wpdb->query( "ALTER TABLE `$table_name` MODIFY `meta_value` $definition" );
					}
				}
				else{
					$unique = ( in_array( $att, $this->allow_multiple ) ? '' : 'UNIQUE' );
					$sql = "
						CREATE TABLE `$table_name` (
						    `meta_value` $definition,
							`post_id` BIGINT(20) UNSIGNED NOT NULL $unique,
						    PRIMARY KEY ( `meta_value`, `post_id`)
						) ENGINE=InnoDB DEFAULT CHARSET=utf8
					";
					$wpdb->query( $sql );
				}
			}
			$this->created_tables = true;
		}
	}
	
	public function getTableName( $att ){
		global $wpdb;
		return "{$wpdb->prefix}index_{$att}";
	}
	

	public function admin_notices(){
		global $wpdb;
		$notices = array();
		if ( !$this->isReady( 'index' )){
			$notices[] = array(
				'message' => sprintf( __('The WordPress Indexer (used for sorting and searching posts with post_type of %s) is not currently ready.', 'wp-model' ), $this->post_type ),
				'action' => 'perform_indexing'
			);
		}
		
		if ( count( $notices ) ){
			$template = '
				<div class="wp-indexer-notice" style="padding:8px 0">
					%s
					<form style="display:inline-block;vertical-align:middle" action="' . admin_url( 'admin-ajax.php' ) . '" method="post">
						<input type="hidden" name="action" value="wp_indexer">
						<input type="hidden" name="indexing_action" value="%s">
						<input type="submit" class="button" value="Fix this">
						' . wp_nonce_field( 'wp_indexer', '_wpnonce', true, false ) . '
					</form>
					<span class="spinner" style="vertical-align:middle;float:none"></span>
					<span class="message" style="display:none;vertical-align:middle"></span>
				</div>
			'; 
			echo '<div id="wp-indexer-notices" class="error">';
			foreach( $notices as $notice ){
				printf( $template, $notice['message'], $notice['action'] );
			}
			echo '</div>';
			echo <<<SCRIPT
<script type="text/javascript">				
	jQuery( function($){
		function ajaxHandler( form, response ){
			if ( response.success ){
				if ( response.data.message ){
					form.parent().find( '.message' ).text( response.data.message ).show();
				}
				if ( response.data.call_again ){
					var options = form.serializeArray(),
						config = {}
					;
			
					$.each( options, function( index, option ){
						config[ option.name ] = option.value;
					});
					
					$.extend( config, response.data.call_parms || {} );
					$.get( ajaxurl, config, function( response){
						ajaxHandler( form, response );
					});
				}
				else{
					form.next( '.spinner' ).hide();
					form.remove();
				}
			}
		}
		
		$( '#wp-indexer-notices' ).on( 'submit', 'form', function(e){
			var form = $(this),
				options = form.serializeArray(),
				config = {}
			;
			
			$.each( options, function( index, option ){
				config[ option.name ] = option.value;
			});
			
			form.hide();
			form.next( '.spinner' ).css({display:'inline-block'});
			
			$.get( ajaxurl, config, function( response ){
				ajaxHandler( form, response );
			});
			
			e.preventDefault();
			return false;
		});
	});
</script>			
SCRIPT;
		}
	}
	
	public function ajax(){
		if ( !isset( $_REQUEST['_wpnonce'] ) || !wp_verify_nonce( $_REQUEST['_wpnonce'], 'wp_indexer' ) ){
			return;
		}
		
		global $wpdb;

		switch( $_REQUEST[ 'indexing_action' ] ){
		case 'perform_indexing':
			if ( !isset( $_REQUEST['offset'] ) ){
				$_REQUEST['offset'] = 0;
			}
			$result = $this->buildIndex();
			$return = array(
				'call_again' => ( $result['completed'] < $result['total'] ),
				'call_parms' => array(
					'offset' => $result['completed']
				),
				'message' => sprintf( __( 'Completed indexing of %s of %s posts with post_type of %s', 'wp-model' ), $result['completed'], $result['total'], $this->post_type )
			);
			break;
		}
		
		wp_send_json_success( $return );
		exit;
	}
	
	public function isReady( $what ){

		switch( $what ){
		case 'index':
			// The index is considered ready if 
			// 	a) the index table exists
			//  b) the transient holding which fields get indexed in the index table has not changed
			$indexable = get_transient( "_wp_indexable_keys_$this->post_type" );
			if ( $indexable == $this->getIndexableMetaKeysWithColumnType() ){
				$ready = true;
			}
			break;
		}

		return (bool)$ready;
		
	}
	
	/** 
	 * getIndexableMetaKeys
	 * 
	 * Determines which meta keys we'll build an index for
	 */
	public function getIndexableMetaKeys(){
		return array_keys( $this->keys );
	}
	
	public function getIndexableMetaKeysWithColumnType(){
		return $this->keys;
	}
	
	public function addIndex( $attribute, $type = 'string', $unique = true ){
		switch ( $type ){
		case 'float':
			$definition = 'FLOAT NOT NULL';
			break;
		case 'integer':
			$definition = 'BIGINT(20) NOT NULL';
			break;
		case 'string':
		default:
			$definition = 'VARCHAR(255) NOT NULL';
			break;
		}	
		
		$this->keys[ $attribute ] = $definition;
		
		if ( !$unique ){
			$this->allow_multiple[] = $attribute;
		}
		
		if ( !$this->actions_added ){
			add_action( 'build_query_setup_for_key', array( &$this, 'setupForKey' ), 10, 6 );
			$this->actions_added = true;
		}
	}
	
	public function setupForKey( $meta_key, & $Model, & $table_name, & $key_column, & $id_column, & $placeholder ){
		$bound = $Model->get( 'bound' );
		$post_types = $bound['the_post_type'];
		
		if ( !in_array( $meta_key, $this->getIndexableMetaKeys() ) ){
			return;
		}

		$indexable = get_transient( "_wp_indexable_keys_$this->post_type" );
		if ( !$indexable || !array_key_exists( $meta_key, $indexable ) ){
			return;
		}
		
		foreach ( (array)$post_types as $post_type ){
			if ( $post_type == $this->post_type ){
				$table_name = $this->getTableName( $meta_key );
				$key_column = false;
				$id_column = 'post_id';
				if ( in_array( $meta_key, $this->integers ) ){
					$placeholder = '%d';
				}
				elseif ( in_array( $meta_key, $this->floats ) ){
					$placeholder = '%f';
				}
				else{
					$placeholder = '%s';
				}
			}
		}
	}
	
	public static function placeholder( $value ){
		return !is_numeric( $value ) ? '%s' : ((strpos( $value, '.' ) !== false) ? '%f' : '%d'); 
	}
	
}

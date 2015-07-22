<?php
if (!class_exists('DBModel')) : 
require_once('AbstractModel.php');

class DBModel extends AbstractModel{
	const GROUP_SEP = "ϚϚ";
	const FIELD_SEP = "ΘΘ";
	
	public function __construct( $args = array() ){
		parent::__construct( $args );
	}
	
	public static function getDefaults(){
		return array(
			'order_by' => '{{id}}',
			'order' => 'DESC',
			'limit' => null,
			'offset' => 0,
			'not' => null,
			'where' => array()
		);
	}

	public function getOne($id){
		$result = $this->getResults($this->buildQuery($id));
		return is_array($result) ? array_shift($result) : false;
	}
	
	public function getSome($ids){
		return $this->getResults($this->buildQuery($ids));
	}
	
	public function getAll(){
		return $this->getResults($this->buildQuery());
	}
	
	public function getSimplifiedResults( $select, $method = 'get_results' ){
		$original_map = $map = $this->get( 'map' );
		$original_args = $args = $this->get( 'args' );
		
		$in_where = array();
		foreach ( array_keys((array)$args['where']) as $where ){
			if ( strpos( $where, '.' ) !== false ){
				list( $foo, $bar ) = explode( '.', $where );
				$in_where[] = $foo;
			}
		}
		
		foreach ( $map as $key => $value ){
			if ( $key !== 'id' && !in_array( $key, $in_where ) ){
				unset( $map[ $key ]);
			}
		}
		$this->set( 'map', $map );
		
		if ( isset( $args['limit'] ) ){
			unset( $args['limit'] );
		}
		if ( isset( $args['order_by'] ) ){
			unset( $args['order_by'] );
		}
		$this->set( 'args', $args );
		
		$query = $this->buildQuery();
		global $wpdb;
		$query = preg_replace( '/^SELECT(.*?)-- END OF MAIN SELECT/s', $this->resolveReferences($map['id']['table'], "SELECT $select", 'select'), $query);
	
		$this->set( 'map', $original_map );
		$this->set( 'args', $original_args );
		
		return $wpdb->$method( $query );
	}
	
	public function getCount(){
		return intval( $this->getSimplifiedResults( 'COUNT( DISTINCT {{id}} )', 'get_var' ) );
	}
	
	public function getIds(){
		return $this->getSimplifiedResults( 'DISTINCT {{id}}', 'get_col' );
	}
	
	public function getResults($query){
		global $wpdb;
		
		// Necessary to allow for long group concatenations to be returned properly
		$wpdb->query('SET SESSION group_concat_max_len = 100000000');

		$results = false; // placeholder for caching solution
		if (!$results){
			$_results = $wpdb->get_results($query);
			$results = array();
			$primary_key = $this->get('primary_key');
			foreach ($_results as $r => $result){
				$results[$result->$primary_key] = $result;
				unset($_results[$r]); // cleanup
			}
			unset($_results);
			
			$factories = $this->get('factories');
			if (!empty($factories)){
				foreach ($results as $r => $row){
					foreach ($row as $field => $value){
						if (isset($factories[$field])){
							foreach ($factories[$field] as $factory){
								if (!isset($value)){
									$value = array(); // just default to an empty array
								}
								else{
									$value = call_user_func($factory,$value);
								}
							}
							$results[$r]->$field = $value;
						}
					}
				}
			}
		}
		
		return $results;
	}
	
	public function buildQuery( $key = null ){
		// If not supplied with an ID, then we need to build the query based on $this->get( 'args' )
		extract( $this->get( 'args' ) );

		if ( isset( $ordered_ids ) && !isset( $key ) ){
			$key = $ordered_ids;
		}
		
		if ( isset( $key ) ){
			if ( is_array( $key ) ){
				// Requesting Some in the form of an array of IDs
				global $wpdb;
			
				if ( isset( $limit ) ){
					$key = array_slice( $key, ( isset( $offset ) ? $offset : 0 ), $limit );
				}
			
				$query = $this->buildBasicQuery( $key );
				if ( !empty( $key ) ){
					if ( $order_by == 'ordered_ids' ){
						$query.= $wpdb->prepare(" ORDER BY FIND_IN_SET( id, %s )", implode( ',', $key ) );
					}
				}
				else{ 
					$query.= ' AND 1=2'; // no matches found
				}
			}
			else{
				// Requesting One ID, just use the BasicQuery
				$query = $this->buildBasicQuery( $key );
			}
			
			// that's all we need if we've been supplied with a single id or list of ids	
			return $query;
		}

		// Setup the basic query
		$query = $this->buildBasicQuery();
		
		global $wpdb;
		
		$joins = array();
		$wheres = array();
		$query_wheres = array();
		$query_order = array();
		$w = 0;
		$map = $this->get( 'map' );

		// $where comes from $this->get( 'args' )
		if ( !empty( $where ) ){
			
			if ( !is_array( $where ) ){
				// Single ID
				$query_wheres[] = $wpdb->prepare( "`id` = %d", $where );
			}
			elseif ( !self::is_assoc( $where ) ){
				// It's just an array of ids
				$query_wheres[] = $wpdb->prepare( "`id` IN (" . implode(',',array_fill( 0, count( $where ), '%d' )) . ")", $where );
			}
			else{
				// It's an associative array, let's go through each element therein and winnow down the IDs that we might want to pass to the Model->getSome()
				foreach( $where as $key => $value ){
					$as = "w{$w}"; $w++;

					if ( strpos( $key, '.' ) !== false ){
						list( $index, $meta_key ) = explode( '.', $key );
						$table_name = $map[ $index ]['table'];
						list( $key_column, $value_column ) = $map[ $index ]['column']; // an assumption here is that the map column is setup as, i.e. array( 'meta_key', 'meta_value' )
						foreach ( (array)$map[ $index ][ 'where' ] as $id_column => $assignment ){
							if ( $assignment == '{{id}}' ){
								// found the $id_column
								break;
							}
						}

						$placeholder = '%s';
						do_action_ref_array( 'build_query_setup_for_key', array( $meta_key, & $this, & $table_name, & $key_column, & $id_column, & $placeholder ) );

						$joins[] = "INNER JOIN `$table_name` $as ON $as.$id_column = p.{$map['id']['column']}";
						if ( !empty( $key_column ) ){
							$wheres[] = $wpdb->prepare( "$as.$key_column = %s", $meta_key );
						}
						
						if ( is_bool( $value ) ){
							if ( $value ){
								// Just checking if the row exists
								continue;
							}
							else{
								// want it where the row DOES NOT exist
								array_pop( $joins ); // pop off the INNER JOIN from above
								$joins[] = "LEFT JOIN `$table_name` $as ON $as.id = sub_id AND $as.id IS NULL";
							}
						}
						elseif( is_array( $value ) ){
							// They want one of any of these values
							$wheres[] = $wpdb->prepare( "$as.$value_column IN (" . implode(',',array_fill( 0, count( $value ), $placeholder )) . ")", $value );
						}
						elseif ( is_object( $value ) ){
							// They want a range (object)array( $from, $to )
							switch( true ){
							case !isset( $value->to ):
								$placeholder = self::placeholder( $value->from );
								$wheres[] = $wpdb->prepare( "$as.$value_column >= $placeholder", $value->from );
								break;
							case !isset( $value->from ):
								$placeholder = self::placeholder( $value->to );
								$wheres[] = $wpdb->prepare( "$as.$value_column <= $placeholder", $value->to );
								break;
							case isset( $value->from ) && isset( $value->to ):
								$pf = self::placeholder( $value->from );
								$pt = self::placeholder( $value->to );
								$wheres[] = $wpdb->prepare( "$as.$value_column BETWEEN $pf AND $pt", $value->from, $value->to );
								break;
							}
						}
						else{
							// Just looking for a value
							$placeholder = self::placeholder( $value );
							$wheres[] = $wpdb->prepare( "$as.$value_column = $placeholder", $value );
						}
					}
					else{
						// It must be in the main table 
						$table_name = $map['id']['table'];
						if ( is_bool( $value ) ){
							$query_wheres[] = "$table_name.`$key` IS " . ( $value ? 'NOT' : '' ) . " NULL";
						}
						elseif( is_array( $value ) ){
							// It's a range
							$query_wheres[] = $wpdb->prepare( "$table_name.`$key` BETWEEN %s AND %s)", $value );
						}
						else{
							$query_wheres[] = $wpdb->prepare( "$table_name.`$key` = %s)", $value );
						}
					}
				}
			}
			
		}

		$sql_order = array();
		if ( !empty( $order_by ) ){
			if ( $order_by === 'random'){ // @DEBUG
				// Note, this is a nice way to get random results from a large dataset
				// ORDER BY RAND() is not efficient at all.  See my comment at http://davidwalsh.name/mysql-random
				$sql_order[] = 'MD5( CONCAT( sub_id, CURRENT_TIMESTAMP ) )';
			}
			else{
				if ( !is_array( $order_by ) ){
					$order_by = array( $order_by );
				}
				foreach ( $order_by as $number => $ob ){
					if ( strpos( $ob, '.' ) !== false ){						
						list( $index, $meta_key ) = explode( '.', $ob );
						foreach ( $map[ $index ][ 'where' ] as $id_column => $assignment ){
							if ( $assignment == '{{id}}' ){
								// found the $id_column
								break;
							}
						}

						$table_name = $map[ $index ]['table'];
						list( $key_column, $value_column ) = $map[ $index ]['column']; // an assumption here is that the map column is setup as, i.e. array( 'meta_key', 'meta_value' )
						$as = "o$number";
						
						do_action_ref_array( 'build_query_setup_for_key', array( $meta_key, & $this, & $table_name, & $key_column, & $id_column, & $placeholder ) );
						$joins[] = "LEFT JOIN `$table_name` $as ON $as.$id_column = p.{$map['id']['column']}";
						if ( !empty( $key_column ) ){
							$wheres[] = $wpdb->prepare( "$as.$key_column = %s", $meta_key );
						}
						$sql_order[] = "$as.$value_column $order";
					}
					else{
						$table_name = $map['id']['table'];
						$query_order[] = $this->resolveReferences( $table_name, $ob, 'select' ). " $order";
					}
				}
			}
		}
		
		if ( isset( $not ) && !is_array( $not ) ){
			$not = array( $not );
		}
		
		$this->buildQueryExtras( $joins, $wheres, $sql_order ); // allow extending classes to modify these
		do_action_ref_array( 'build_query_' . get_class( $this ), array( & $this, & $joins, & $wheres, & $sql_order ) );  // allow plugins to modify them generally for this type of Model
		
		if ( count( $joins ) || count( $wheres ) || count( $sql_order ) ){
			$id_table = $map['id']['table'];
			$id_column = $map['id']['column'];
			
			if ( isset( $not ) ){
				$wheres[] = $wpdb->prepare( "p.$id_column NOT IN (" . implode(',',array_fill( 0, count( $not ), '%d' )) . ")", $not );
				unset( $not );
			}
		
			$where_subquery = "SELECT p.$id_column as sub_id FROM $id_table p\n\t" . implode( "\n\t", $joins );
			if ( count( $wheres ) ){
				$where_subquery.= " \nWHERE\n\t" . implode( "\nAND ", $wheres );
			}
			if ( count( $sql_order ) ){
				$where_subquery .= "\nORDER BY " . implode( ",\n", $sql_order );
			}
		
			if ( !empty( $limit ) ){
				$where_subquery .= " LIMIT " . ( !empty( $offset ) ? "$offset, " : '' ) . " $limit";
				unset( $limit );
			}
		
			if ( !empty( $query_wheres ) ){
				$query.= " AND " . implode( ' AND ', $query_wheres );
			}
		
			$query = preg_replace( "/-- END OF MAIN SELECT.*FROM $id_table/s", "-- END OF MAIN SELECT\nFROM $id_table\nRIGHT JOIN ( $where_subquery ) x ON x.sub_id = `id`", $query );
		}		
		
		if ( isset( $not ) ){
			$query .= $this->resolveReferences( $map['id']['table'], $wpdb->prepare( " AND {{id}} NOT IN (" . implode(',',array_fill( 0, count( $not ), '%d' )) . ")", $not ), 'select' );
		}
		
		if ( count( $query_order ) ){
			$query .= "\nORDER BY " . implode( ",\n", $query_order );
		}
		if ( !empty( $limit ) ){
			$query .= " LIMIT " . ( !empty( $offset ) ? "$offset, " : '' ) . " $limit";
		}

		return $query;
	}
	
	public function buildQueryExtras( & $joins, & $wheres, & $sql_order ){
		// if you have any extra logic when building your query, overload this method in your extending class
	}
	
	public static function range( $from = null, $to = null ){
		$range = new stdClass;
		$range->from = ( is_array( $from ) ? $from['min'] : $from );
		$range->to = ( is_array( $to ) ? $to['max'] : $to );
		return $range;
	}
	
	public static function placeholder( $value ){
		return !is_numeric( $value ) ? '%s' : ((strpos( $value, '.' ) !== false) ? '%f' : '%d'); 
	}
	
	public function buildBasicQuery($key = null){
		$map = $this->get('map');
		$this->set('primary_table',$primary_table = $map[$this->get('primary_key')]['table']);
		
		$references = $this->collectReferences();
		$factories = $this->collectFactories();
		
		$select = array();
		$from = array($primary_table);
		$where = array();
		
		foreach ($map as $field => $field_map){
			if ($field_map['table'] == $primary_table){
				if (isset($field_map['hasMany']) and $field_map['hasMany']){
					// There are many of this field for the record.  Build a subselect
					$select[] = $this->buildSubSelect($field,$field_map);
				}
				else{
					$select[] = $field_map['table'].'.'.$field_map['column'].' as `'.$field.'`';
					if (isset($field_map['where'])){
						foreach($field_map['where'] as $where_field => $where_condition){
							if (is_array($where_condition)){
								$where[] = $this->buildSubSelectForWhere($where_field,$where_condition);
							}
							else{
								$where[] = $this->resolveReferences($field_map['table'],$where_field,'select').' '.$this->resolveReferences($field_map['table'],$where_condition);
							}
						}
					}
				}
			}
			else{
				// It is not from the primary table.  Build a subselect
				$select[] = $this->buildSubSelect($field,$field_map);
			}
		}
		
		if (!empty($key)){
			$where[] = $references[$this->get('primary_key')].' '.$this->resolveReferences($primary_table,$key);
		}
		
		if (empty($where)){
			$where[] = '1 = 1';
		}
		
		
		$query = "SELECT ".implode(",\n",$select)."\n-- END OF MAIN SELECT\n FROM ".implode(",\n",$from)."\nWHERE ".implode("\nAND ",$where);
		
		return $query;
	}
	
	private function buildSubSelect($field,$map){
		$group_concat = "GROUP_CONCAT(";
		$table_ref = ($map['table'] == $this->get('primary_table') ? $map['table'].'_sub' : $map['table']); //.'_sub';
		if (is_array($map['column'])){
			$group_concat.="CONCAT(";
			$sep = "";
			foreach ($map['column'] as $column){
				$group_concat.=$sep.$this->resolveReferences($table_ref,$column,'select');
				$sep = ',"'.self::getFieldSep().'",';
			}
			$group_concat.= ')';
		}
		else{
			$group_concat.= $this->resolveReferences($table_ref,$map['column'],'select');
		}
		$group_concat.= " SEPARATOR '".self::getGroupSep()."')";
		
		$from = $map['table'].' '.$table_ref;
		$join = array();
		
		$references = $this->get('references');
		
		$where = array();
		$table_references = array();
		if (isset($map['where'])){
			foreach ($map['where'] as $where_field => $where_condition){
				if (is_array($where_condition) and isset($where_condition['table'])){
					$where_table_ref = $where_condition['table'];
					$r = '';
					while(in_array($where_table_ref.$r,$table_references)){
						$r = (!$r ? 1 : $r+1);
					}
					$where_table_ref = $where_table_ref.$r;
					$table_references[] = $where_table_ref;
					$join_stmt = "LEFT JOIN ".$where_condition['table']." $where_table_ref ON ";
					$sep = "";
					foreach ($where_condition['where'] as $join_field => $join_condition){
						$join_stmt.= $sep.$this->resolveReferences($where_table_ref,$join_field,'select').' '.$this->resolveReferences($where_table_ref,$join_condition);
						$sep = " AND ";
					}
					$join[] = $join_stmt;
				}
				else{
					$condition = $this->resolveReferences($table_ref,$where_condition);
					if ($condition !== false){
						$where[] = $this->resolveReferences($table_ref,$where_field,'select').' '.$condition;
					}
				}
			}
		}
		
		$subselect = "(SELECT $group_concat FROM $from ".implode("\n",$join).(count($where) ? " WHERE ".implode(' AND ',$where) : '').') as `'.$field.'`';
		
		return $subselect;
	}
	
	private function buildSubSelectForWhere($field,$condition){
		$target = $this->resolveReferences($this->get('primary_table'),$field,'select');
		$map = $this->get('map');
		$references = $this->get('references');
		$clauses = array();
		foreach ($condition as $where_field => $where_condition){
			$where = array();
			$join = array();
			$test = preg_replace('/^\++/','',$where_field);
			if (isset($map[$test])){
				// The field exists in the map, need to reverse engineer the subselect
				// We need to find where $field occurs in the where clause for $map[$where_field]
				foreach ($map[$test]['where'] as $key => $reference){
					$table_ref = ($map[$test]['table'] == $this->get('primary_table') ? $map[$test]['table'].'_sub' : $map[$test]['table']); //.'_sub';
					if ($target == $this->resolveReferences($map[$test]['table'],$reference,'select')){
						$subselect = "SELECT ".$this->resolveReferences($table_ref,$key,'select')." FROM ".$map[$test]['table'].' '.$table_ref;
					}
					elseif(is_array($reference) and isset($reference['table'])){
						$where_table_ref = $reference['table'];
						$join_stmt = "LEFT JOIN ".$reference['table']." ON ";
						$sep = "";
						foreach ($reference['where'] as $join_field => $join_condition){
							$join_stmt.= $sep.$this->resolveReferences($where_table_ref,$join_field,'select').' '.$this->resolveReferences($where_table_ref,$join_condition);
							$sep = " AND ";
						}
						$join[] = $join_stmt;
					}
					else{
						$where[] = $this->resolveReferences($table_ref,$key,'select')." ".$this->resolveReferences($map[$test]['table'],$reference);
					}
				}
				
				$not = '';
				foreach ($where_condition as $key => $reference){
					if (isset($references[$key])){
						$where[] = $references[$key].$this->resolveReferences('',$reference);
					}
					else{
						if (is_bool($reference) and !$reference){
							$not = "NOT";
						}
						else{
							$where[] = $this->resolveReferences($map[$test]['table'],$key,'select').$this->resolveReferences('',$reference);;
						}
					}
				}
			}
			
			$clauses[] = "$target $not IN ($subselect ".implode("\n",$join)." WHERE ".implode(' AND ',$where).")";
		}
		
		return implode("\nAND ",$clauses);
	}
		
	private function unGroupConcat($string){
		$values = explode(self::getGroupSep(),$string);
		$rows = array();
		if (strpos($string,self::getFieldSep()) !== false){
			foreach ($values as $i => $value){
				$rows[] = explode(self::getFieldSep(),$value);
			}
		}
		else{
			$rows = $values;
		}
		return $rows;
	}
	
	private function resolveReferences($table,$column,$for = 'where'){
		$_column = preg_replace_callback(
			'/{{(.*)}}/',
			array(&$this,'resolveReference'),
			$column
		);
		if (!$_column){
			return false;
		}
		if ($_column == $column){
			// No change in preg_replace, therefore, no references.
			// Treat $column as a string
			global $wpdb;
			switch ($for){
			case 'where':
				// This is resolving for a WHERE clause
				if (is_array($column)){
					return $wpdb->prepare(' IN (%s'.str_repeat(',%s',count($column)-1).')',$column);
				}
				else{
					if (is_bool($column)){
						return ($column ? ' IS NOT NULL' : ' IS NULL');
					}
					else{
						return $wpdb->prepare('= %s',$column);				
					}
				}
				break;
			case 'select':
				return "$table.$column";
			}
		}
		else{
			switch ($for){
			case 'where':
				if (substr($_column,0,3) == 'IN '){
					return "$_column";
				} 
				else{
					return " = $_column";
				}
				break;
			case 'select':
				return "$_column";
				break;
			}
		}
	}
	
	private function resolveReference($matches){
		$references = $this->get('references');
		if (isset($references[$matches[1]])){
			$ref = & $references[$matches[1]];
			if (is_array($ref)){
				global $wpdb;
				if (empty($ref)){
					return false;
				}
				return $wpdb->prepare('IN (%s'.str_repeat(',%s',count($ref)-1).')',$ref);
			}
			else{
				return $ref;
			}
		}
		else{
			return $matches[1];
		}
	}
	
	public function collectFactories(){
		$map = $this->get('map');
		$factories = array();
		foreach ($map as $field => $field_map){
			if ($field_map['table'] != $this->get('primary_table') or (isset($field_map['hasMany']) and $field_map['hasMany'])){
				if (!isset($factories[$field])){
					$factories[$field] = array();
				}
				$factories[$field][] = array(&$this,'unGroupConcat');
			}
			if (isset($field_map['factory'])){
				if (!isset($factories[$field])){
					$factories[$field] = array();
				}
				$factories[$field][] = $field_map['factory'];
			}
		}
		$this->set('factories',$factories);
	}
	
	public function collectReferences(){
		$map = $this->get('map');
		$references = array();
		foreach ($map as $field => $field_map){
			if (is_array($field_map['column'])){
				// It's a reference to more than one value.  Just make the reference the field name;
				$references[$field] = $field;
			}
			else{
				$references[$field] = $field_map['table'].'.'.$field_map['column'];
			}
			if (isset($field_map['where'])){
				$table_references = array();
				foreach ($field_map['where'] as $where_field => $where_condition){
					if (is_array($where_condition) and isset($where_condition['table'])){
						$where_table_ref = $where_condition['table'];
						$r = '';
						while(in_array($where_table_ref.$r,$table_references)){
							$r = (!$r ? 1 : $r+1);
						}
						$where_table_ref = $where_table_ref.$r;
						$table_references[] = $where_table_ref;
						if (is_array($where_condition['column'])){
							$references[$where_field] = $where_field;
						}
						else{
							$references[$where_field] = $where_table_ref.'.'.$where_condition['column'];
						}
					}
					else{
						$references[$where_field] = $field_map['table'].'.'.$where_field;
					}
				}
			}
			if (isset($field_map['references'])){
				foreach ($field_map['references'] as $reference_field => $reference){
					$references[$reference_field] = $reference;
				}
			}
		}
		$this->set('references',$references);
		if ($this->get('bound')){
			$references = $this->apply('references',$this->get('bound'));
		}
		return $references;
	}
	
	private function buildWhere($where,$prefix = null){
		$sep = '';
		foreach ($where as $field => $value){
			$_join.= (isset($prefix) ? "$prefix." : '').$field;
		}
	}
	
	public function bind($reference,$value){
		$this->apply('bound',array($reference => $value));
	}
	
	public function addBinding($reference,$value){
		$bound = $this->get('bound');
		if (!isset($bound[$reference])){
			$bound[$reference] = array();
		}
		if (!is_array($value)){
			$value = array($value);
		}
		foreach ($value as $v){
			if (!in_array($v,$bound[$reference])){
				$bound[$reference][] = $v;
			}
		}
		$this->set('bound',$bound);
		
	}
	
	private function getGroupSep(){
		return (defined('DBMODEL_GROUP_SEP') ? DBMODEL_GROUP_SEP : self::GROUP_SEP);
	}

	private function getFieldSep(){
		return (defined('DBMODEL_FIELD_SEP') ? DBMODEL_FIELD_SEP : self::FIELD_SEP);
	}
	
	/** 
	 * Returns current number of MySQL calls made during current execution. 
	 */
	public function getQueryCount(){
		global $wpdb;

		$result = $wpdb->get_results('SHOW SESSION STATUS LIKE \'Questions\'');
		return $result[0]->Value;
	}
	
}
endif; // class_exists

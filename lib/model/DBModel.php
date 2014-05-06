<?php
if (!class_exists('DBModel')) : 
require_once('AbstractModel.php');

class DBModel extends AbstractModel{
	const GROUP_SEP = "ϚϚ";
	const FIELD_SEP = "ΘΘ";
	
	public function __construct(){
		parent::__construct();
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
		else{
			$time = $results['time'];
			$results = $results['results'];

			$map = $this->get('map');
			if (isset($map['modified_date'])){
				$delta_query = $query . $wpdb->prepare(' AND '.$map['modified_date']['table'].'.'.$map['modified_date']['column'].' > %s',date("Y-m-d H:i:s", $time));
				$delta = $this->getResults($delta_query,false); // All data that has changed since last run
				
				if (!empty($delta)){
					foreach ($delta as $key => $result){
						$results[$key] = $result;
					}
					
					// I'd rather use the set_transient function, but it seems it's a memory abuser.  When I called set_transient, then I got memory
					// allocation errors (max memory reached).  So, instead, I'm running the $wpdb->update method directly.  Lame.  
					// Also, for some reason, this update process takes up to 3 seconds (for my testing with 2200 records).  Double lame.  
					// At least that will only happen when there's an update made.  Lame lame lame.
					$wpdb->update( $wpdb->options, array( 'option_value' => serialize(array('time' => time(),'results' => $results)) ), array( 'option_name' => '_transient_dbModel_'.md5($query) ) );
					//set_transient('dbModel_'.md5($query),array('time' => time(),'results' => $results));
				}
			}
		}
		
		return $results;
	}
	
	public function buildQuery($key = null){
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
		
		
		$query = "SELECT ".implode(",\n",$select)."\nFROM ".implode(",\n",$from)."\nWHERE ".implode("\nAND ",$where);
		
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
?>
<?php
if (!class_exists('AbstractModel')) : 
/**
 * AbstractModel
 *
 * This is an abstract class that when extended, allows for modelling.
 *
 * The goal of this class is to be able to define models where the rules for
 * getting the data are defined within the model class.  For example, a WP
 * Model will know how to talk to the WP database, knowing how to retrieve
 * information from the various tables (posts, postmeta, post_terms, etc).
 *
 * The model will have the expected CRUD functions and will allow each of these
 * to be done for One, Some or All.
 *
 * The model will also allow for filtering (basically a where clause).
 *
 * Example:
 *
 * class MyModel extends AbstractModel{
 * 		private static function foo($arg){
 *			// Do something
 *		}
 * }
 *
 */
abstract class AbstractModel{
	var $parms;

	public function __construct( $args = array() ){
		$this->set( 'map', array() );
		$this->set( 'primary_key', 'id' );
		$this->set( 'args', wp_parse_args( $args, $this->getDefaults() ) );
	}

	/**
	 * getOne
	 *
	 *
	 **/
	abstract public function getOne($id);
	
	abstract public function getDefaults();

	public function get($what=null){
		if ($what != null){
			return isset( $this->parms[$what] ) ? $this->parms[$what] : null;
		}
		else{
			return $this->parms;
		}
	}

	public function set($what,$value){
		$this->parms[$what] = $value;
	}

	public function is($what,$bool=null){
		if (!isset($bool)){
			return $this->get('_is'.$what);
		}
		else{
			$this->set('_is'.$what,(bool)$bool);
		}
	}

	public function apply($what,$value){
		$current = $this->get($what);
		if (empty($current)){
			$current = array();
		}
		$this->set($what,$this->array_merge_recursive($current,$value));
		return $this->get($what);
	}

	public function remove($value,$what){
		if (isset($this->parms[$what]) and array_key_exists($value,$this->parms[$what])){
			unset($this->parms[$what][$value]);
		}
	}

	// Exactly the same as the PHP array_merge_recursize, unless it's not an associative
	// array (i.e. numeric indexes), in which case, it just fully replaces the previous
	// with the next's value.
	//
	// Adapted from @walf's comment at http://www.php.net/manual/en/function.array-merge-recursive.php
	public function array_merge_recursive() {

	    if (func_num_args() < 2) {
	        trigger_error(__FUNCTION__ .' needs two or more array arguments', E_USER_WARNING);
	        return;
	    }
	    $arrays = func_get_args();
	    $merged = array();
	    while ($arrays) {
	        $array = array_shift($arrays);
	        if (!is_array($array)) {
	            trigger_error(__FUNCTION__ .' encountered a non array argument', E_USER_WARNING);
	            return;
	        }
	        if (!$array)
	            continue;

			if ($this->is_assoc($array)){
		        foreach ($array as $key => $value)
	                if (is_array($value) && array_key_exists($key, $merged) && is_array($merged[$key]))
	                    $merged[$key] = call_user_func(array(&$this,'array_merge_recursive'), $merged[$key], $value);
	                elseif ( !isset( $value ) )
						unset( $merged[$key] );
					else
	                    $merged[$key] = $value;
			}
			else{
				$merged = $array;
			}
	    }
	    return $merged;
	}

	// Thanks http://stackoverflow.com/questions/173400/php-arrays-a-good-way-to-check-if-an-array-is-associative-or-numeric
	public function is_assoc($array) {
	  return (bool)count(array_filter(array_keys($array), 'is_string'));
	}
}

endif; // class_exists
?>
<?php
/**
 * Fuel
 *
 * Fuel is a fast, lightweight, community driven PHP5 framework.
 *
 * @package    Fuel
 * @version    1.7
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2013 Fuel Development Team
 * @link       http://fuelphp.com
 */

namespace Orm;

class ManyMany extends Relation
{
	protected $key_from = array('id');

	protected $key_to = array('id');

	/**
	 * @var string   table name of model from
	 */
	protected $table_from;

	/**
	 * @var  string  table alias of model from
	 */
	protected $alias_from;

	/**
	 * @var  string  classname of model to use as connection
	 */
	protected $model_through;

	/**
	 * @var  string  table name of table to use as connection, alternative to $model_through setting
	 */
	protected $table_through;

	/**
	 * @var  string  table alias of table to use as connection
	 */
	protected $alias_through;

	/**
	 * @var  string  foreign key of from model in connection table
	 */
	protected $key_through_from;

	/**
	 * @var  string  foreign key of to model in connection table
	 */
	protected $key_through_to;

	/**
	 * @var  string  order key on the connection table
	 */
	protected $key_through_order;

	/**
	 * @var  string  table name of model to
	 */
	protected $table_to;

	/**
	 * @var  string  table alias of model to
	 */
	protected $alias_to;

	/**
	 * Initializes the relation specified by $name on the item $from
	 *
	 * @param string $from
	 * @param string $name
	 * @param array $config
	 * @throws \FuelException
	 */
	public function __construct($from, $name, array $config)
	{
		$this->name        = $name;

		// Sets the properties of the model from
		$this->model_from = $from;
		$this->table_from = call_user_func(array($this->model_from, 'table'));
		$this->key_from = array_key_exists('key_from', $config) ? (array) $config['key_from'] : $this->key_from;

		// Sets the properties of the model to
		$this->model_to = array_key_exists('model_to', $config) ? $config['model_to'] : $this->getRelationModel($name, $from);
		$this->table_to = call_user_func(array($this->model_to, 'table'));
		$this->key_to = array_key_exists('key_to', $config) ? (array) $config['key_to'] : $this->key_to;

		// Sets the conditions
		$this->conditions = array_key_exists('conditions', $config) ? (array) $config['conditions'] : array();

		// Sets the properties of the table through
		if (!empty($config['table_through']))
		{
			$this->table_through = $config['table_through'];
		}
		else
		{
			$table_name = array($this->model_from, $this->model_to);
			natcasesort($table_name);
			$table_name = array_merge($table_name);
			$this->table_through = \Inflector::tableize($table_name[0]).'_'.\Inflector::tableize($table_name[1]);
		}
		$this->key_through_from = !empty($config['key_through_from'])
			? (array) $config['key_through_from'] : (array) \Inflector::foreign_key($this->model_from);
		$this->key_through_to = !empty($config['key_through_to'])
			? (array) $config['key_through_to'] : (array) \Inflector::foreign_key($this->model_to);
		$this->key_through_order = (string) \Arr::get($config, 'key_through_order');

		// Sets the cascade properties
		$this->cascade_save = array_key_exists('cascade_save', $config) ? $config['cascade_save'] : $this->cascade_save;
		$this->cascade_delete = array_key_exists('cascade_delete', $config) ? $config['cascade_delete'] : $this->cascade_delete;

		// Checks if the model to exists
		if (!class_exists($this->model_to))
		{
			throw new \FuelException('Related model not found by Many_Many relation "'.$this->name.'": '.$this->model_to);
		}
		$this->model_to = get_real_class($this->model_to);
	}

	/**
	 * Gets the related items for the item $from
	 *
	 * @param Model $from
	 * @param array $conditions
	 * @return array|object
	 */
	public function get(Model $from, array $conditions = array())
	{
		$this->alias_to = 't0';
		$this->alias_from = null;
		$this->alias_through = $this->alias_to.'_through';

		// Merges the conditions of the relation with the specific conditions
		$conditions = \Arr::merge($this->conditions, $conditions);

		// Creates the query on the model_through
		$query = call_user_func(array($this->model_to, 'query'));

		// Builds the join on the table through
		if (!$this->_build_query_join($query, $conditions, $this->alias_to, $from))
		{
			return array();
		}

		// Builds the where conditions
		if (!$this->_build_query_where($query, $conditions, $this->alias_to, $from))
		{
			return array();
		}

		// Builds the order_by conditions
		if (!$this->_build_query_orderby($query, $conditions, $this->alias_to, $from))
		{
			return array();
		}

		return $query->get();
	}

	/**
	 * Builds the join on the table through for a query
	 *
	 * @param $query
	 * @param $conditions
	 * @param $alias_to
	 * @param $model_from
	 * @return array
	 */
	protected function _build_query_join($query, $conditions, $alias_to, $model_from)
	{
		$join = array(
			'table'      => array($this->table_through, $this->alias_through),
			'join_type'  => null,
			'join_on'    => array(),
			'columns'    => $this->select_through($this->alias_through)
		);

		// Creates the native conditions on the join
		reset($this->key_to);
		foreach ($this->key_through_to as $key)
		{
			$join['join_on'][] = array($this->alias_through.'.'.$key, '=', $alias_to.'.'.current($this->key_to));
			next($this->key_to);
		}

		// Creates the custom conditions on the join
		foreach (\Arr::get($conditions, 'join_on', array()) as $key => $condition)
		{
			is_array($condition) or $condition = array($key, '=', $condition);
			reset($condition);
			$condition[key($condition)] = $this->getAliasedField(current($condition), $this->alias_through);
			$join['join_on'][] = $condition;
		}

		// Creates the join on the query
		$query->_join($join);

		return true;
	}

	/**
	 * Builds the where conditions for a query
	 *
	 * @param $query
	 * @param $conditions
	 * @param $alias_to
	 * @param $model_from
	 * @return bool
	 */
	protected function _build_query_where($query, $conditions, $alias_to, $model_from)
	{
		// Creates the native conditions on the query
		reset($this->key_from);
		foreach ($this->key_through_from as $key)
		{
			if ($model_from->{current($this->key_from)} === null)
			{
				return false;
			}
			$query->where($this->alias_through.'.'.$key, $model_from->{current($this->key_from)});
			next($this->key_from);
		}

		// Creates the custom conditions related to the table through on the query
		foreach (\Arr::get($conditions, 'through_where', array()) as $key => $condition)
		{
			is_array($condition) or $condition = array($key, '=', $condition);
			$condition[0] = $this->alias_through.'.'.$condition[0];
			$query->where($condition);
		}

		// Creates the custom conditions on the query
		foreach (\Arr::get($conditions, 'where', array()) as $key => $condition)
		{
			is_array($condition) or $condition = array($key, '=', $condition);
			$condition[0] = $this->getAliasedField($condition[0], $alias_to);
			
			if (!($condition[0] instanceof \Fuel\Core\Database_Expression)) {
				// Handles the special case of a condition whose field is on the table from
				// by replacing it with the value of the field property on the $model_from
				if (\Str::starts_with($condition[0], $this->table_from.'.')) {
					list(, $field) = explode('.', $condition[0], 2);
					if (!isset($model_from->{$field})) {
						// The field does not exists on $model_from
						throw new \FuelException('The field '.$field.' does not exists on the model '.get_class($model_from));
					}
					$condition[0] = \DB::expr(\DB::quote($model_from->{$field}));
				}
			}
    
			$query->where($condition);
		}

		return true;
	}

	/**
	 * Builds the order_by conditions for a query
	 *
	 * @param $query
	 * @param $conditions
	 * @param $alias_to
	 * @param $model_from
	 * @return bool
	 */
	protected function _build_query_orderby($query, $conditions, $alias_to, $model_from)
	{
		// Creates the custom order_by conditions on the query
		foreach (\Arr::get($conditions, 'order_by', array()) as $field => $direction)
		{
			if (is_numeric($field))
			{
				$query->order_by($direction);
			}
			else
			{
				$field = $this->getAliasedField($field, $alias_to);
				$query->order_by($field, $direction);
			}
		}

		return true;
	}

	/**
	 * Gets the properties to join the related items on a query
	 *
	 * @param $alias_from
	 * @param $rel_name
	 * @param $alias_to_nr
	 * @param array $conditions
	 * @return array
	 */
	public function join($alias_from, $rel_name, $alias_to_nr, $conditions = array())
	{
		$this->alias_to = 't'.$alias_to_nr;
		$this->alias_from = $alias_from;
		$this->alias_through = $this->alias_to.'_through';

		// Merges the conditions of the relation with the specific conditions
		$conditions = \Arr::merge($this->conditions, $conditions);

		// Creates the joins to the model_to
		$joins = array(
			$rel_name.'_through' => array(
				'model'        => null,
				'connection'   => call_user_func(array($this->model_to, 'connection',)),
				'table'        => array($this->table_through, $this->alias_through),
				'primary_key'  => null,
				'join_type'    => \Arr::get($conditions, 'join_type', 'left'),
				'join_on'      => array(),
				'columns'      => $this->select_through($this->alias_through),
				'rel_name'     => $this->model_through,
				'relation'     => $this
			),
			$rel_name => array(
				'model'        => $this->model_to,
				'connection'   => call_user_func(array($this->model_to, 'connection')),
				'table'        => array(call_user_func(array($this->model_to, 'table')), $this->alias_to),
				'primary_key'  => call_user_func(array($this->model_to, 'primary_key')),
				'join_type'    => \Arr::get($conditions, 'join_type', 'left'),
				'join_on'      => array(),
				'columns'      => $this->select($this->alias_to),
				'rel_name'     => $this->getRelationName($rel_name),
				'relation'     => $this,
				'where'        => array(),
			)
		);

		// Builds the join conditions on the table_through
		if (!$this->_build_join_through($joins, $rel_name, $this->alias_through, $this->alias_from, $conditions))
		{
			return array();
		}

		// Builds the join conditions on the model_to
		if (!$this->_build_join_to($joins, $rel_name, $this->alias_to, $this->alias_through, $conditions))
		{
			return array();
		}

		// Builds the where conditions on the table_through
		if (!$this->_build_join_where_through($joins, $rel_name, $this->alias_to, $this->alias_from, $conditions))
		{
			return array();
		}

		// Builds the where conditions on the model_to
		if (!$this->_build_join_where_to($joins, $rel_name, $this->alias_to, $this->alias_from, $conditions))
		{
			return array();
		}

		// Builds the order_by conditions
		if (!$this->_build_join_orderby($joins, $rel_name, $this->alias_to, $this->alias_from, $conditions))
		{
			return array();
		}

		return $joins;
	}

	/**
	 * Builds the conditions for the table_through join
	 *
	 * @param $joins
	 * @param $rel_name
	 * @param $alias_to
	 * @param $alias_from
	 * @param $conditions
	 * @return bool
	 */
	protected function _build_join_through(&$joins, $rel_name, $alias_to, $alias_from, $conditions)
	{
		reset($this->key_from);
		foreach ($this->key_through_from as $key)
		{
			$joins[$rel_name.'_through']['join_on'][] = array($alias_from.'.'.current($this->key_from), '=', $alias_to.'.'.$key);
			next($this->key_from);
		}

		return true;
	}

	/**
	 * Builds the conditions for the model_to join
	 *
	 * @param $joins
	 * @param $rel_name
	 * @param $alias_to
	 * @param $alias_from
	 * @param $conditions
	 * @return bool
	 */
	protected function _build_join_to(&$joins, $rel_name, $alias_to, $alias_from, $conditions)
	{
		reset($this->key_to);
		foreach ($this->key_through_to as $key)
		{
			$joins[$rel_name]['join_on'][] = array($alias_from.'.'.$key, '=', $alias_to.'.'.current($this->key_to));
			next($this->key_to);
		}

		return true;
	}

	/**
	 * Builds the where conditions for a join
	 *
	 * @param $joins
	 * @param $rel_name
	 * @param $alias_to
	 * @param $alias_from
	 * @param $conditions
	 * @return bool
	 */
	protected function _build_join_where_through(&$joins, $rel_name, $alias_to, $alias_from, $conditions)
	{
		// Creates the custom conditions on the table_through join
		foreach (\Arr::get($conditions, 'through_where', array()) as $key => $condition)
		{
			!is_array($condition) and $condition = array($key, '=', $condition);
			is_string($condition[2]) and $condition[2] = \Db::quote($condition[2], $joins[$rel_name]['connection']);
			$condition[0] = $this->getAliasedField($condition[0], $this->alias_through);
			$joins[$rel_name.'_through']['join_on'][] = $condition;
		}

		return true;
	}

	/**
	 * Builds the where conditions for a join
	 *
	 * @param $joins
	 * @param $rel_name
	 * @param $alias_to
	 * @param $alias_from
	 * @param $conditions
	 * @return bool
	 */
	protected function _build_join_where_to(&$joins, $rel_name, $alias_to, $alias_from, $conditions)
	{
		// Creates the custom conditions on the model_to join
		foreach (\Arr::get($conditions, array('where', 'join_on'), array()) as $where)
		{
			foreach ($where as $key => $condition)
			{
				!is_array($condition) and $condition = array($key, '=', $condition);
				is_string($condition[2]) and $condition[2] = \Db::quote($condition[2], $joins[$rel_name]['connection']);
				$condition[0] = $this->getAliasedField($condition[0], $alias_to);
				$joins[$rel_name]['join_on'][] = $condition;
			}
		}

		return true;
	}

	/**
	 * Builds the order_by conditions for a join
	 *
	 * @param $joins
	 * @param $rel_name
	 * @param $alias_to
	 * @param $alias_from
	 * @param $conditions
	 * @return bool
	 */
	protected function _build_join_orderby(&$joins, $rel_name, $alias_to, $alias_from, $conditions)
	{
		// Builds the order_by conditions
		foreach (\Arr::get($conditions, 'order_by', array()) as $key => $direction)
		{
			$key = $this->getAliasedField($key, $alias_to);
			$joins[$rel_name]['order_by'][$key] = $direction;
		}

		return true;
	}

	/**
	 * Returns the columns to select through a table
	 *
	 * @param $table
	 * @return array
	 */
	public function select_through($table)
	{
		foreach ($this->key_through_to as $to)
		{
			$properties[] = $table.'.'.$to;
		}
		foreach ($this->key_through_from as $from)
		{
			$properties[] = $table.'.'.$from;
		}

		return $properties;
	}

	/**
	 * Saves the relation
	 *
	 * @param Model $model_from
	 * @param Model $models_to
	 * @param $original_model_ids
	 * @param $parent_saved
	 * @param bool|null $cascade
	 * @throws \FuelException
	 */
	public function save($model_from, $models_to, $original_model_ids, $parent_saved, $cascade)
	{
		if (!$parent_saved)
		{
			return;
		}

		if (!is_array($models_to) and ($models_to = is_null($models_to) ? array() : $models_to) !== array())
		{
			throw new \FuelException('Assigned relationships must be an array or null, given relationship value for '.
				$this->name.' is invalid.');
		}
		$original_model_ids === null and $original_model_ids = array();
		$del_rels = $original_model_ids;

		$order_through = 0;
		foreach ($models_to as $key => $model_to)
		{
			if (!$model_to instanceof $this->model_to)
			{
				throw new \FuelException('Invalid Model instance added to relations in this model.');
			}

			// Save if it's a yet unsaved object
			if ($model_to->is_new())
			{
				$model_to->save(false);
			}

			// Builds the primary keys of the table through
			$through_pks = array();
			reset($this->key_from);
			foreach ($this->key_through_from as $pk)
			{
				$through_pks[$pk] = $model_from->{current($this->key_from)};
				next($this->key_from);
			}

			reset($this->key_to);
			foreach ($this->key_through_to as $pk)
			{
				$through_pks[$pk] = $model_to->{current($this->key_to)};
				next($this->key_to);
			}

			// Insert the relationships if not already assigned
			$current_model_id = $model_to ? $model_to->implode_pk($model_to) : null;
			if (!in_array($current_model_id, $original_model_ids))
			{
				$values = $through_pks;

				// Set order
				if (!empty($this->key_through_order))
				{
					$values[$this->key_through_order] = $order_through;
				}

				// Insert the relation
				\DB::insert($this->table_through)
					->set($values)
					->execute(call_user_func(array($model_from, 'connection')))
				;

				// Prevents inserting it a second time
				$original_model_ids[] = $current_model_id;
			}

			// Otherwise update the relationships if needed
			else
			{
				// Set order
				if (!empty($this->key_through_order))
				{
					\DB::update($this->table_through)
						->value($this->key_through_order, $order_through)
						->where($through_pks)
						->execute(call_user_func(array($model_from, 'connection')))
					;
				}

				// unset current model from from array of new relations
				unset($del_rels[array_search($current_model_id, $original_model_ids)]);
			}

			// ensure correct pk assignment
			if ($key != $current_model_id)
			{
				$model_from->unfreeze();
				$rel = $model_from->_relate();
				if (!empty($rel[$this->name][$key]) and $rel[$this->name][$key] === $model_to)
				{
					unset($rel[$this->name][$key]);
				}
				$rel[$this->name][$current_model_id] = $model_to;
				$model_from->_relate($rel);
				$model_from->freeze();
			}

			$order_through++;
		}

		// If any ids are left in $del_rels they are no longer assigned, DELETE the relationships:
		foreach ($del_rels as $original_model_id)
		{
			$query = \DB::delete($this->table_through);

			reset($this->key_from);
			foreach ($this->key_through_from as $key)
			{
				$query->where($key, '=', $model_from->{current($this->key_from)});
				next($this->key_from);
			}

			$to_keys = count($this->key_to) == 1 ? array($original_model_id) : explode('][', substr($original_model_id, 1, -1));
			reset($to_keys);
			foreach ($this->key_through_to as $key)
			{
				$query->where($key, '=', current($to_keys));
				next($to_keys);
			}

			$query->execute(call_user_func(array($model_from, 'connection')));
		}

		$cascade = is_null($cascade) ? $this->cascade_save : (bool) $cascade;
		if ($cascade and !empty($models_to))
		{
			foreach ($models_to as $m)
			{
				$m->save();
			}
		}
	}

	/**
	 * Deletes all relationship entries with cascade for the $model_from
	 *
	 * @param Model $model_from
	 * @param array|Model $models_to
	 * @param bool $parent_deleted
	 * @param bool|null $cascade
	 */
	public function delete($model_from, $models_to, $parent_deleted, $cascade)
	{
		if (!$parent_deleted)
		{
			return;
		}

		// Remove relations
		$model_from->unfreeze();
		$rels = $model_from->_relate();
		$rels[$this->name] = array();
		$model_from->_relate($rels);
		$model_from->freeze();

		// Delete all relationship entries for the model_from
		$this->delete_related($model_from);

		$cascade = is_null($cascade) ? $this->cascade_delete : (bool) $cascade;
		if ($cascade and !empty($models_to))
		{
			foreach ($models_to as $m)
			{
				$m->delete();
			}
		}
	}

	/**
	 * Deletes all relationship entries for the $model_from
	 *
	 * @param $model_from
	 */
	public function delete_related($model_from)
	{
		// Delete all relationship entries for the model_from
		$query = \DB::delete($this->table_through);
		reset($this->key_from);
		foreach ($this->key_through_from as $key)
		{
			$query->where($key, '=', $model_from->{current($this->key_from)});
			next($this->key_from);
		}
		$query->execute(call_user_func(array($model_from, 'connection')));
	}

	/**
	 * Return $field after setting/replacing the table alias
	 *
	 * @param $field
	 * @param null|string $defaut_alias
	 * @return mixed|string
	 */
	public function getAliasedField($field, $defaut_alias = null)
	{
		if ($field instanceof \Fuel\Core\Database_Expression)
		{
			return $field;
		}

		if (strpos($field, '.') !== false)
		{
			$replaces = array(
				array($this->table_to.'.'),
				array($this->alias_to.'.'),
			);
			if ($this->table_through && $this->alias_through)
			{
				$replaces[0][] = $this->table_through.'.';
				$replaces[1][] = $this->alias_through.'.';
			}
			if ($this->table_from && $this->alias_from)
			{
				$replaces[0][] = $this->table_from.'.';
				$replaces[1][] = $this->alias_from.'.';
			}

			// Replace each table name by the corresponding alias
			$field = str_replace($replaces[0], $replaces[1], $field);
		}
		else
		{
			// Set the alias on the field
			$field = ($defaut_alias ? $defaut_alias : $this->alias_to).'.'.$field;
		}

		return $field;
	}

	/**
	 * Gets the relation name from a relation path
	 *
	 * @param $path
	 * @return string
	 */
	public function getRelationName($path)
	{
		// Removes the path before the relation name
		if (strrpos($path, '.') !== false)
		{
			$path = substr($path, strrpos($path, '.') + 1);
		}
		return $path;
	}

	/**
	 * Gets the model of the $relation_name on the item $from
	 *
	 * @param $relation_name
	 * @param $from
	 * @return string
	 */
	protected function getRelationModel($relation_name, $from)
	{
		return \Inflector::get_namespace($from).'Model_'.\Inflector::classify($relation_name);
	}
}

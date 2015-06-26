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
	 * @var  string  classname of model to use as connection
	 */
	protected $model_through;

	/**
	 * @var  string  table name of table to use as connection, alternative to $model_through setting
	 */
	protected $table_through;

	/**
	 * @var  string  foreign key of from model in connection table
	 */
	protected $key_through_from;

	/**
	 * @var  string  foreign key of to model in connection table
	 */
	protected $key_through_to;

	public function __construct($from, $name, array $config)
	{
		$this->name        = $name;
		$this->model_from  = $from;
		$this->model_to    = array_key_exists('model_to', $config)
			? $config['model_to'] : \Inflector::get_namespace($from).'Model_'.\Inflector::classify($name);
		$this->key_from    = array_key_exists('key_from', $config)
			? (array) $config['key_from'] : $this->key_from;
		$this->key_to      = array_key_exists('key_to', $config)
			? (array) $config['key_to'] : $this->key_to;
		$this->conditions  = array_key_exists('conditions', $config)
			? (array) $config['conditions'] : array();

		if ( ! empty($config['table_through']))
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
		$this->key_through_from = ! empty($config['key_through_from'])
			? (array) $config['key_through_from'] : (array) \Inflector::foreign_key($this->model_from);
		$this->key_through_to = ! empty($config['key_through_to'])
			? (array) $config['key_through_to'] : (array) \Inflector::foreign_key($this->model_to);

		$this->cascade_save    = array_key_exists('cascade_save', $config)
			? $config['cascade_save'] : $this->cascade_save;
		$this->cascade_delete  = array_key_exists('cascade_delete', $config)
			? $config['cascade_delete'] : $this->cascade_delete;

		if ( ! class_exists($this->model_to))
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
		$alias_to = 't0';

		// Merges the conditions of the relation with the specific conditions
		$conditions = \Arr::merge($this->conditions, $conditions);

		// Creates the query on the model_through
		$query = call_user_func(array($this->model_to, 'query'));

		// Builds the join on the table through
		if (!$this->_query_build_join($query, $conditions, $alias_to, $from)) {
			return array();
		}

		// Builds the where conditions
		if (!$this->_query_build_where($query, $conditions, $alias_to, $from)) {
			return array();
		}

		// Builds the order_by conditions
		if (!$this->_query_build_orderby($query, $conditions, $alias_to, $from)) {
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
	protected function _query_build_join($query, $conditions, $alias_to, $model_from)
	{
		$join = array(
			'table'      => array($this->table_through, $alias_to.'_through'),
			'join_type'  => null,
			'join_on'    => array(),
			'columns'    => $this->select_through($alias_to.'_through')
		);

		// Creates the native conditions on the join
		reset($this->key_to);
		foreach ($this->key_through_to as $key)
		{
			$join['join_on'][] = array($alias_to.'_through.'.$key, '=', $alias_to.'.'.current($this->key_to));
			next($this->key_to);
		}

		// Creates the custom conditions on the join
		foreach (\Arr::get($conditions, 'join_on', array()) as $key => $condition) {
			is_array($condition) or $condition = array($key, '=', $condition);
			reset($condition);
			$condition[key($condition)] = $alias_to.'_through.'.current($condition);
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
	protected function _query_build_where($query, $conditions, $alias_to, $model_from)
	{
		// Creates the native conditions on the query
		reset($this->key_from);
		foreach ($this->key_through_from as $key)
		{
			if ($model_from->{current($this->key_from)} === null)
			{
				return false;
			}
			$query->where($alias_to.'_through.'.$key, $model_from->{current($this->key_from)});
			next($this->key_from);
		}

		// Creates the custom conditions related to the table through on the query
		foreach (\Arr::get($conditions, 'through_where', array()) as $key => $condition)
		{
			is_array($condition) or $condition = array($key, '=', $condition);
			$condition[0] = $alias_to.'_through.'.$condition[0];
			$query->where($condition);
		}

		// Creates the custom conditions on the query
		foreach (\Arr::get($conditions, 'where', array()) as $key => $condition)
		{
			is_array($condition) or $condition = array($key, '=', $condition);
			$condition[0] = $this->getAliasedField($condition[0], $alias_to);
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
	protected function _query_build_orderby($query, $conditions, $alias_to, $model_from)
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
	 * Gets the properties to join the related items for a query
	 *
	 * @param $alias_from
	 * @param $rel_name
	 * @param $alias_to_nr
	 * @param array $conditions
	 * @return array
	 */
	public function join($alias_from, $rel_name, $alias_to_nr, $conditions = array())
	{
		$alias_to = 't'.$alias_to_nr;

		// Merges the conditions of the relation with the specific conditions
		$conditions = \Arr::merge($this->conditions, $conditions);

		// Creates the joins to the model_to
		$models = array(
			$rel_name.'_through' => array(
				'model'        => null,
				'connection'   => call_user_func(array($this->model_to, 'connection',)),
				'table'        => array($this->table_through, $alias_to.'_through'),
				'primary_key'  => null,
				'join_type'    => \Arr::get($conditions, 'join_type', 'left'),
				'join_on'      => array(),
				'columns'      => $this->select_through($alias_to.'_through'),
				'rel_name'     => $this->model_through,
				'relation'     => $this
			),
			$rel_name => array(
				'model'        => $this->model_to,
				'connection'   => call_user_func(array($this->model_to, 'connection')),
				'table'        => array(call_user_func(array($this->model_to, 'table')), $alias_to),
				'primary_key'  => call_user_func(array($this->model_to, 'primary_key')),
				'join_type'    => \Arr::get($conditions, 'join_type', 'left'),
				'join_on'      => array(),
				'columns'      => $this->select($alias_to),
				'rel_name'     => strpos($rel_name, '.') ? substr($rel_name, strrpos($rel_name, '.') + 1) : $rel_name,
				'relation'     => $this,
				'where'        => \Arr::get($conditions, 'where', array()),
			)
		);

		// Builds the join conditions on the table_through
		if (!$this->_join_build_join_through($models, $rel_name, $alias_to, $alias_from, $conditions))
		{
			return array();
		}

		// Builds the join conditions on the model_to
		if (!$this->_join_build_join_to($models, $rel_name, $alias_to, $alias_from, $conditions))
		{
			return array();
		}

		// Builds the where conditions on the table_through
		if (!$this->_join_build_where_through($models, $rel_name, $alias_to, $alias_from, $conditions))
		{
			return array();
		}

		// Builds the where conditions on the model_to
		if (!$this->_join_build_where_to($models, $rel_name, $alias_to, $alias_from, $conditions))
		{
			return array();
		}

		// Builds the order_by conditions
		if (!$this->_join_build_orderby($models, $rel_name, $alias_to, $alias_from, $conditions))
		{
			return array();
		}

		return $models;
	}

	/**
	 * Builds the conditions for the table_through join
	 *
	 * @param $models
	 * @param $rel_name
	 * @param $alias_to
	 * @param $alias_from
	 * @param $conditions
	 * @return bool
	 */
	protected function _join_build_join_through(&$models, $rel_name, $alias_to, $alias_from, $conditions)
	{
		reset($this->key_from);
		foreach ($this->key_through_from as $key)
		{
			$models[$rel_name.'_through']['join_on'][] = array($alias_from.'.'.current($this->key_from), '=', $alias_to.'_through.'.$key);
			next($this->key_from);
		}

		return true;
	}

	/**
	 * Builds the conditions for the model_to join
	 *
	 * @param $models
	 * @param $rel_name
	 * @param $alias_to
	 * @param $alias_from
	 * @param $conditions
	 * @return bool
	 */
	protected function _join_build_join_to(&$models, $rel_name, $alias_to, $alias_from, $conditions)
	{
		reset($this->key_to);
		foreach ($this->key_through_to as $key)
		{
			$models[$rel_name]['join_on'][] = array($alias_to.'_through.'.$key, '=', $alias_to.'.'.current($this->key_to));
			next($this->key_to);
		}

		return true;
	}

	/**
	 * Builds the where conditions for a join
	 *
	 * @param $models
	 * @param $rel_name
	 * @param $alias_to
	 * @param $alias_from
	 * @param $conditions
	 * @return bool
	 */
	protected function _join_build_where_through(&$models, $rel_name, $alias_to, $alias_from, $conditions)
	{
		// Creates the custom conditions on the table_through join
		foreach (\Arr::get($conditions, 'through_where', array()) as $key => $condition)
		{
			! is_array($condition) and $condition = array($key, '=', $condition);
			is_string($condition[2]) and $condition[2] = \Db::quote($condition[2], $models[$rel_name]['connection']);
			$condition[0] = $this->getAliasedField($condition[0], $alias_to);
			$models[$rel_name.'_through']['join_on'][] = $condition;
		}

		return true;
	}

	/**
	 * Builds the where conditions for a join
	 *
	 * @param $models
	 * @param $rel_name
	 * @param $alias_to
	 * @param $alias_from
	 * @param $conditions
	 * @return bool
	 */
	protected function _join_build_where_to(&$models, $rel_name, $alias_to, $alias_from, $conditions)
	{
		// Creates the custom conditions on the model_to join
		foreach (\Arr::get($conditions, array('where', 'join_on')) as $where)
		{
			foreach ($where as $key => $condition)
			{
				! is_array($condition) and $condition = array($key, '=', $condition);
				is_string($condition[2]) and $condition[2] = \Db::quote($condition[2], $models[$rel_name]['connection']);
				$condition[0] = $this->getAliasedField($condition[0], $alias_to);
				$models[$rel_name]['join_on'][] = $condition;
			}
		}

		return true;
	}

	/**
	 * Builds the order_by conditions for a join
	 *
	 * @param $models
	 * @param $rel_name
	 * @param $alias_to
	 * @param $alias_from
	 * @param $conditions
	 * @return bool
	 */
	protected function _join_build_orderby(&$models, $rel_name, $alias_to, $alias_from, $conditions)
	{
		// Builds the order_by conditions
		foreach (\Arr::get($conditions, 'order_by', array()) as $key => $direction)
		{
			$key = $this->getAliasedField($key, $alias_to);
			$models[$rel_name]['order_by'][$key] = $direction;
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
		if ( ! $parent_saved)
		{
			return;
		}

		if ( ! is_array($models_to) and ($models_to = is_null($models_to) ? array() : $models_to) !== array())
		{
			throw new \FuelException('Assigned relationships must be an array or null, given relationship value for '.
				$this->name.' is invalid.');
		}
		$original_model_ids === null and $original_model_ids = array();
		$del_rels = $original_model_ids;

		$order_through = 0;
		foreach ($models_to as $key => $model_to)
		{
			if ( ! $model_to instanceof $this->model_to)
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
			if ( ! in_array($current_model_id, $original_model_ids))
			{
				// Insert the relation
				\DB::insert($this->table_through)
					->set($through_pks)
					->execute(call_user_func(array($model_from, 'connection')))
				;
				// Prevents inserting it a second time
				$original_model_ids[] = $current_model_id;
			}

			// Otherwise update the relationships if needed
			else
			{
				// unset current model from from array of new relations
				unset($del_rels[array_search($current_model_id, $original_model_ids)]);
			}

			// ensure correct pk assignment
			if ($key != $current_model_id)
			{
				$model_from->unfreeze();
				$rel = $model_from->_relate();
				if ( ! empty($rel[$this->name][$key]) and $rel[$this->name][$key] === $model_to)
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
		if ($cascade and ! empty($models_to))
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
		if ( ! $parent_deleted)
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
		if ($cascade and ! empty($models_to))
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
	 * @param $alias_to
	 * @return mixed|string
	 */
	public function getAliasedField($field, $alias_to)
	{
		if ($field instanceof \Fuel\Core\Database_Expression)
		{
			return $field;
		}

		if (strpos($field, '.') !== false)
		{
			// Replace the table name by the corresponding alias
			$field = str_replace(
				array($this->table_through.'.', call_user_func(array($this->model_to, 'table')).'.'),
				array($alias_to.'_through.', $alias_to.'.'),
				$field
			);
		}
		else
		{
			// Set the alias on the field
			$field = $alias_to.'.'.$field;
		}

		return $field;
	}
}

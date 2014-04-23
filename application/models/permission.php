<?php

class Permission extends DataMapper {

	var $table = 'permissions';

    var $has_one = array('action','group');
	var $auto_populate_has_one = TRUE;

    var $validation = array(
        'allowed' => array(
            'label' => 'Allowed',
            'rules' => array('integer','greater_than[0]','less_than[1]'),
        )
    );

	
	/**
	 * get-function by using its 'natural' keys, thus using names instead of id's.
	 * @param $module_directory
	 * 		The directory-name of the module
	 * @param $action_controller
	 * 		The name of the controller the action belongs to
	 * @param $action_name
	 * 		The name of the action the permission applies to
	 * @param $group_name
	 * 		The name of the group the permission applies to	 
	 * @return 
	 * 		the current permission-object or NULL if permission doesn't exist.
	 */
	public function get_by_unique($module_directory, $controller_name, $action_name, $group_name){
		$action = new Action();
		$action->get_by_unique($module_directory, $controller_name, $action_name);
		$group = new Group();
		$group->get_where(array('name'=>$group_name),1);		
		$this->where_related($action)->where_related($group)->get();
		
		if($this->exists()){
			return $this;
		}
		return NULL;
	}

}

/* End of file permission.php */
/* Location: ./application/models/permission.php */
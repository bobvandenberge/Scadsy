<?php (defined('BASEPATH')) OR exit('No direct script access allowed');

/**
 * The module_manager class. This class manages the initialising and loading of modules.
 */
class Module_manager {
	private $CI;
	private $module;
	
	public function __construct() {
		$this->CI =& get_instance();
		$this->module = (defined("ENTERPRISE") && Database_manager::get_db()->database == ENTERPRISE) ? NULL : new Module();
	}
	
	/** 
	 * Install a module 
	 **/	
	public function install_module($directory) {
		$module_dir = $this->CI->config->item('modules_dir');
		$file = $module_dir.$directory.'/install.php';

		if(is_file($file)) {
			include_once($file);
		}			
			
		$m = new Module();
		$m->install($directory);
	}
	
	/** 
	 * Uninstall a module 
	 **/	
	public function uninstall_module($directory) {
		$module_dir = $this->CI->config->item('modules_dir');
		$file = $module_dir.$directory.'/uninstall.php';
		
		if(is_file($file)) {
			include_once($file);
		}
		
		$m = new Module();
		$m->uninstall($directory);			
	}

	/**
	 * Check if all the modules in the database are still present on the file system
	 */
	public function check_modules() {
		$modules = new Module();
		$modules->get();
		
		foreach($modules as $module) {
			if(!is_dir($this->CI->config->item('modules_dir').$module->directory)) {
				$module->delete();
				break;
			}
		}
	}

	/**
	 * Load all the currently active modules
	 */
	public function load_modules() {
		$module_dir = $this->CI->config->item('modules_dir');
		if(!defined('ENTERPRISE') || isset($_COOKIE['scadsy_db_cookie'])){			
			$modules = $this->module->get_by_status('enabled');
		}
		else{			
			$module_dir = $this->CI->config->item('enterprise_dir');
			$modules = (object) $this->get_enterprise_modules();
		}

		foreach($modules as $module) {
			$module = (object) $module;
			if(!is_dir($module_dir.$module->directory)) {
				$module->delete();
				break;
			}
			
			if(!is_file($module_dir.$module->directory.'/index.php')) {
				break;						
			}
			
			include_once($module_dir.$module->directory.'/index.php');

			// Auto load module models
			if(is_dir($module_dir.$module->directory.'/models/')) {
				$models = preg_grep('/^([^.])/', scandir($module_dir.$module->directory.'/models/'));
				foreach($models as $model) {
					include_once($module_dir.$module->directory.'/models/'.$model);
				}
			}				

			// Auto load the language file for the module if it is present
			$file = $module_dir.$module->directory.'/language/'.$this->CI->config->item('language').'/'.$module->directory.'_lang.php';
			if(is_file($file)) {				
				$this->CI->load->language($module->directory, '', FALSE, TRUE, '', $module->directory);
			}
			
			// Auto load the callback_helper if it is present
			$file = $module_dir.$module->directory.'/helpers/callback_helper.php';
			if(is_file($file)) {
				$this->CI->load->helper('callback', $module->directory);
			}
		}
		
		Hook_manager::execute_hook('modules_loaded', $this->CI->form_manager);	
		
	}
	
	/**
	 * Refresh the metadata that is stored for a certain module
	 * @param $directory	
	 * 				The module directory
	 */
	 public function refresh_module($directory) {
	 	$module_dir = $this->CI->config->item('modules_dir');

		if(is_dir($module_dir.$directory) && is_file($module_dir.$directory . '/index.php')) {
			$module_actions = $this->get_module_actions($module_dir.$directory.'/controllers//');
			$module_permissions = $this->get_module_permissions($module_dir.$directory . '/index.php');
			
			$this->module->get_where(array('directory'=>$directory),1);
			if($this->module->exists() === TRUE){
				$this->module->update_module($directory, $module_actions, $module_permissions);
			}
		}
	 }
	
	/**
	 * Scan for new modules and add them to the database
	 */
	public function add_new_modules() {
		$module_dir = $this->CI->config->item('modules_dir');
		
		// Get files and directories without the . and .. folders
		$directories = preg_grep('/^([^.])/', scandir($module_dir));
		// Scan all files/folders found
		foreach($directories as $dir) {
			// If it is a dir and has the correct file name
			if(is_dir($module_dir.$dir) 
					&& is_file($module_dir.$dir . '/index.php')) {
						
				$module_metadata = $this->get_module_metadata($module_dir.$dir . '/index.php', $dir);
				$module_actions = $this->get_module_actions($module_dir.$dir.'/controllers//');
				$module_permissions = $this->get_module_permissions($module_dir.$dir . '/index.php');
						
				$m = new Module();				
				$m->get_where(array('directory'=>$module_metadata['directory']),1);
				if($m->result_count() == 0){
					
					$m->add_module($module_metadata, $module_actions, $module_permissions);
				}
			}
		}
	}
	
	/**
	 * Scan for all modules in the given locations, retrieving their metadata.
	 * @param $config_item
	 * 		Optional (default = enterprise_dir). Key (string) for the config item in which the locations are stored. 
	 * @return
	 * 		array with the metadata of each module.
	 */
	public function get_all_modules_from_directory($config_item = 'modules_dir'){
		$modules_data = array();
		$location = $this->CI->config->item($config_item);					
		$module_dirs = scandir(getcwd().'/'.$location);			
		foreach($module_dirs AS $module_dir){
			$module_dir_path = getcwd().'/'.$location.$module_dir;			
			if(is_dir($module_dir_path) && $module_dir != '.' && $module_dir != '..'){
				$index_filepath = $module_dir_path.'/index.php';
				if(is_file($index_filepath)){
					$modules_data[] = $this->get_module_metadata($index_filepath,$module_dir);
				}
			}
		}
		return $modules_data;
	}
	
	/**
	 * Scan for all enterprise modules, retrieving their metadata.
	 * @return
	 * 		array with the metadata of each enterprise module.
	 */
	private function get_enterprise_modules(){
		return $this->get_all_modules_from_directory('enterprise_dir');
	}
	
	/**
	 * Get the meta data from a module
	 * @param $filepath
	 * 		The complete filepath to the index.php
	 * @param $directory
	 * 		The directory where the module is located
	 * @return
	 * 		The meta data extracted from the module
	 */
	private function get_module_metadata($filepath, $directory) {
		// Load the class		
		$module_data = file_get_contents($filepath);
		
		// Get the meta data
		preg_match ( '|Module Name:(.*)$|mi', $module_data, $name );
		preg_match ( '|Module URI:(.*)$|mi', $module_data, $uri );
		preg_match ( '|Version:(.*)|i', $module_data, $version );
		preg_match ( '|Description:(.*)$|mi', $module_data, $description );
		preg_match ( '|Author:(.*)$|mi', $module_data, $author_name );
		preg_match ( '|Author URI:(.*)$|mi', $module_data, $author_uri );
		
		return array(
			'directory' => $directory,
			'name' => $name[1], 
			'uri' => $uri[1],
			'version' => $version[1],
			'description' => $description[1],
			'author' => $author_name[1],
			'author_uri' => $author_uri[1]);	
	}
	
	/**
	 * Get the permissions from the module
	 * @param $filepath
	 * 		The complete filepath to the index.php
	 * @return 
	 * 		The permissions for the module
	 */
	private function get_module_permissions($filepath) {
		// Load the class		
		$module_data = file_get_contents($filepath);
		
		// Get the meta data
		preg_match ( '|Module Permissions:(.*)$|mi', $module_data, $permission );
		
		$permissions = explode(',', $permission[1]);
		
		foreach($permissions as &$permission) {
			$permission = trim($permission);
		}
		
		return $permissions;	
	}
	
	/**
	 * Get the actions a certain module can perform
	 * @param $directory
	 * 		The controllers directory
	 * @return 
	 * 		An array with action names
	 */
	private function get_module_actions($directory) {	
		$directories = preg_grep('/^([^.])/', scandir($directory));		
		$module_actions = array();

		foreach($directories as $file) {
			if(strpos($file, ".php") !== FALSE) {
				$classname = str_replace(".php", "", $file);
				include_once($directory.$file);
		
				$reflection_class = new ReflectionClass($classname);
				$results = $reflection_class->getMethods(ReflectionMethod::IS_PUBLIC);
		
				$methods = array();
				foreach($results as $result) {
					if(strtolower($result->class) == strtolower($classname) && $result->name != '__construct') {
						$module_actions[] = array("controller" => strtolower($result->class), "action" => $result->name);
					}
				}
			}
		}
		
		return $module_actions;
	}
}

/* End of file Module_manager.php */
/* Location: ./application/libraries/Module_manager.php */
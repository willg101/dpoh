<?php

use Dpoh\DataStorage;
use Dpoh\PersistentDataStorage;

define( 'USER_CONFIG_FILE', 'user-config.json' );
define( 'SETTINGS_FILE',    'settings-global.json' );
define( 'MODULES_PATH',     'modules_enabled' );

require_once 'DataStorage.class.php';
require_once 'PersistentDataStorage.class.php';

/**
 * @brief
 *	Loads all module information
 *
 * @retval array
 *	@code
 *	[
 *		'hook_implementations' => 'modules/foo/hooks.php'             // or FALSE
 *		'ajax_api_script'      => 'modules/foo/ajax-api.php'          // or FALSE
 *		'page_template'        => 'modules/foo/page-template.tpl.php' // or FALSE
 *		'js'                   => [ 'modules/foo/js/a/b/c.js', 'modules/foo/js/d.js' ],
 *		'css'                  => [ 'modules/foo/css/a/b/c.css', 'modules/foo/css/d.css' ],
 *		'less'                 => [ 'modules/foo/less/a/b.css.less', 'modules/foo/less/d.css.less' ],
 *		'templates'            => [ 'modules/foo/templates/a.tpl.php' ],
 *		'settings'             => [
 *			'external_dependencies' => [
 *				'js'  => [ '//a.com/b/c.js' ],
 *				'css' => [ '//a.com/b/c.css' ],
 *			],
 *		],
 *	]
 *	@endcode
 */
function load_all_modules()
{
	$standard_dirs  = [
		'js'      => 'js',
		'css'     => 'css',
		'less'    => 'less',
		'tpl.php' => 'templates'
	];
	$standard_files        = [
		'hook_implementations' => 'hooks.php',
		'ajax_api_script'      => 'ajax-api.php',
		'page_template'        => 'page-template.tpl.php',
	];
	$default_settings = [
		'external_dependencies' => [
			'js'  => [],
			'css' => [],
		],
	];

	$mod_dir_contents = glob( MODULES_PATH . '/*' );
	$modules          = [];

	foreach ( $mod_dir_contents as $item )
	{
		$mod_name = basename( $item );
		if ( is_dir( $item ) )
		{
			$modules[ $mod_name ] = [];

			foreach ( $standard_dirs as $extension => $dir )
			{
				$modules[ $mod_name ][ $dir ] = [];
				if ( file_exists( "$item/$dir" ) )
				{
					$modules[ $mod_name ][ $dir ] = recursive_file_scan( $extension, "$item/$dir" );
				}
			}

			foreach ( $standard_files as $key => $filename )
			{
				$modules[ $mod_name ][ $key ] = file_exists( "$item/$filename" )
					? "$item/$filename"
					: FALSE;
			}

			$settings = file_exists( "$item/settings.json" )
				? json_decode( file_get_contents( "$item/settings.json" ), TRUE )
				: [];
			$modules[ $mod_name ][ 'settings' ] = array_merge( $default_settings, $settings );
		}
	}

	return $modules;
}

/**
 * @brief
 *	Looks up a module data value or returns the module data model
 *
 * @param string $key         (OPTIONAL)
 * @param string $default_val (OPTIONAL)
 *
 * @retval mixed
 *	If $key is given, this acts as an alias to $modules_model->get(); otherwise this returns
 *	$modules_models
 */
function modules( $key = NULL, $default_val = NULL )
{
	// Lazy load the modules model if necessary
	static $modules_model;
	if ( $modules_model === NULL )
	{
		$modules_model = new DataStorage( 'modules', load_all_modules() );
	}

	return $key !== NULL
		? $modules_model->get( $key, $default_val )
		: $modules_model;
}

/**
 * @brief
 *	Looks up a global settings data value or returns the global settings data model
 *
 * @param string $key         (OPTIONAL)
 * @param string $default_val (OPTIONAL)
 *
 * @retval mixed
 *	If $key is given, this acts as an alias to $settings_model->get(); otherwise this returns
 *	$settings_model
 */
function settings( $key = NULL, $default_val = NULL )
{
	// Lazy load the global setting model if necessary
	static $settings_model;
	if ( $settings_model === NULL )
	{
		$settings = json_decode( file_get_contents( SETTINGS_FILE ), TRUE ) ?: [];
		$default_settings = [
			'allowed_directories' => [],
			'tree_root' => '/dev/null',
			'less_variables' => [
				'defaults' =>  "~'" . DPOH_ROOT . "/less/defaults'",
			],
		];

		$settings = array_merge( $default_settings, $settings );
		$settings[ 'tree_root' ] = realpath( $settings[ 'tree_root' ] );
		foreach ( $settings[ 'allowed_directories' ] as &$dir )
		{
			$dir = realpath( $dir );
		}

		$settings_model = new DataStorage( 'global_settings', $settings );
	}

	return $key !== NULL
		? $settings_model->get( $key, $default_val )
		: $settings_model;
}

/**
 * @brief
 *	Looks up a user config data value or returns the user config data model
 *
 * @param string $key         (OPTIONAL)
 * @param string $default_val (OPTIONAL)
 *
 * @retval mixed
 *	If $key is given, this acts as an alias to $user_config_model->get(); otherwise this returns
 *	$user_config_model
 */
function user_config( $key = NULL, $default_val = NULL )
{
	static $user_config_model;

	if ( $user_config_model === NULL )
	{
		$default_config = [ 'theme_module' => '_core' ];
		$user_config_model = new PersistentDataStorage( 'user_config', $default_config, FALSE,
			USER_CONFIG_FILE );
	}

	return $key !== NULL
		? $user_config_model->get( $key, $default_val )
		: $user_config_model;
}

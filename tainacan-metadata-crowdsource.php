<?php
/**
 * Plugin Name: Tainacan Metadata Crowdsource
 * Plugin URI:  https://github.com/marcossigismundo/tainacan-metadata-crowdsource
 * Description: Permite que visitantes sugiram correções nos metadados dos itens Tainacan. Sugestões passam por moderação da equipe antes de serem aplicadas.
 * Version:     1.4.4
 * Author:      Tainacan Team
 * License:     GPL-3.0+
 * Text Domain: tainacan-metadata-crowdsource
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package TMC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TMC_VERSION', '1.4.4' );
define( 'TMC_PLUGIN_FILE', __FILE__ );
define( 'TMC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TMC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TMC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoloader PSR-4: TMC\Foo\Bar => includes/Foo/Bar.php.
spl_autoload_register(
	function ( $class_name ) {
		$prefix   = 'TMC\\';
		$base_dir = TMC_PLUGIN_DIR . 'includes/';

		$len = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class_name, $len ) ) {
			return;
		}

		$relative = substr( $class_name, $len );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'TMC\\Core\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TMC\\Core\\Deactivator', 'deactivate' ) );

add_action(
	'init',
	function () {
		load_plugin_textdomain(
			'tainacan-metadata-crowdsource',
			false,
			dirname( TMC_PLUGIN_BASENAME ) . '/languages'
		);
	}
);

add_action(
	'plugins_loaded',
	function () {
		\TMC\Core\Plugin::instance()->boot();
	}
);

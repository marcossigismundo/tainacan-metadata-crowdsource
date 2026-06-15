<?php
/**
 * Rotina de desativação do plugin.
 *
 * @package TMC
 */

namespace TMC\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executada ao desativar o plugin. Não remove dados (isso só ocorre no uninstall).
 */
class Deactivator {

	/**
	 * Suspende o plugin sem apagar dados.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// A tabela e as options são preservadas; a remoção acontece em uninstall.php.
		do_action( 'tmc_deactivated' );
	}
}

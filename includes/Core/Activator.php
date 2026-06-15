<?php
/**
 * Rotina de ativação do plugin.
 *
 * @package TMC
 */

namespace TMC\Core;

use TMC\Database\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executada ao ativar o plugin: cria a tabela e define options padrão.
 */
class Activator {

	/**
	 * Cria o schema e registra as opções padrão.
	 *
	 * @return void
	 */
	public static function activate() {
		Tables::create();

		// Defaults sensatos para as opções do plugin.
		add_option( 'tmc_enabled', 1 );
		add_option( 'tmc_notify_email', 1 );
		add_option( 'tmc_notify_to', get_option( 'admin_email' ) );
	}
}

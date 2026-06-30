<?php
/**
 * Bootstrap do plugin: registra hooks e componentes.
 *
 * @package TMC
 */

namespace TMC\Core;

use TMC\SuggestionsManager;
use TMC\REST\API;
use TMC\Frontend\Shortcode;
use TMC\Frontend\AutoInject;
use TMC\Admin\AdminPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton que conecta REST, shortcode, admin e detecção de "stale".
 */
class Plugin {

	/**
	 * Instância única.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retorna a instância única do plugin.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registra todos os hooks do plugin.
	 *
	 * @return void
	 */
	public function boot() {
		// Upgrade do schema quando a versão do plugin muda (adiciona colunas novas).
		if ( is_admin() ) {
			\TMC\Database\Tables::maybe_upgrade();
		}

		// REST API.
		$api = new API();
		add_action( 'rest_api_init', array( $api, 'register_routes' ) );

		// Shortcode público.
		( new Shortcode() )->register();

		// Auto-injeção do formulário nas páginas de item Tainacan (front-end).
		( new AutoInject() )->register();

		// Página de administração integrada ao menu Tainacan via
		// \Tainacan\Pages. Se o Tainacan não estiver ativo, mostra
		// aviso em vez de fatal.
		if ( is_admin() ) {
			if ( class_exists( '\\Tainacan\\Pages' ) ) {
				AdminPage::get_instance();
			} else {
				add_action( 'admin_notices', array( $this, 'render_missing_tainacan_notice' ) );
			}
		}

		// Detecção de sugestões "stale" ao salvar um item Tainacan.
		// Tainacan usa custom post types com prefixo 'tnc_col_'; capturamos qualquer um.
		add_action( 'save_post', array( $this, 'maybe_mark_stale' ), 20, 2 );
	}

	/**
	 * Reavalia sugestões pendentes de um item Tainacan ao salvá-lo,
	 * marcando como "stale" aquelas cujo valor original mudou.
	 *
	 * @param int      $post_id ID do post salvo.
	 * @param \WP_Post $post    Objeto do post salvo.
	 * @return void
	 */
	public function maybe_mark_stale( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( 0 !== strpos( $post->post_type, 'tnc_col_' ) ) {
			return;
		}

		( new SuggestionsManager() )->mark_stale_for_item( $post_id );
	}

	/**
	 * Aviso quando o Tainacan não está ativo.
	 *
	 * @return void
	 */
	public function render_missing_tainacan_notice() {
		echo '<div class="notice notice-error"><p>'
			. esc_html__(
				'Tainacan Colab requer o plugin Tainacan ativo para integrar com o menu administrativo.',
				'tainacan-metadata-crowdsource'
			)
			. '</p></div>';
	}
}

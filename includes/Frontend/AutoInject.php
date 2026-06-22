<?php
/**
 * Auto-injeção do formulário de sugestões nas páginas de item Tainacan.
 *
 * @package TMC
 */

namespace TMC\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anexa o formulário de sugestões à página de item Tainacan, no front-end.
 *
 * Três pontos de injeção, em ordem de preferência, com guard contra dupla
 * injeção por item:
 *
 *  1. Action do tema `tainacan-interface-single-item-after-metadata` — disparada
 *     pelo `single-items.php` do tema Tainacan Interface (e derivados) logo após
 *     a lista de metadados. É o ponto correto (a sugestão é sobre metadados).
 *     Esse tema renderiza o item via get_template_part(), sem the_content nem o
 *     conteúdo padrão do Tainacan — por isso os filtros abaixo não bastam nele.
 *
 *  2. Filtro do core `tainacan_single_item_content` — para temas que usam o
 *     conteúdo padrão do Tainacan (Theme_Helper::get_tainacan_item_single_content).
 *
 *  3. Filtro `the_content` — fallback genérico para temas clássicos que
 *     renderizam o item via the_content.
 *
 * Quando o tema é baseado no Tainacan Interface, apenas (1) é usado; (2)/(3)
 * são suprimidos para não duplicar nem posicionar errado. Pode ser desligada
 * nas configurações (tmc_autoinject).
 *
 * @see https://tainacan.github.io/tainacan-wiki/#/dev/custom-templates
 */
class AutoInject {

	/**
	 * IDs de itens já processados nesta requisição (anti-dupla-injeção).
	 *
	 * @var array<int,bool>
	 */
	private $injected = array();

	/**
	 * Registra as hooks de injeção.
	 *
	 * @return void
	 */
	public function register() {
		// 1) Tema Tainacan Interface (e derivados): após a lista de metadados.
		add_action( 'tainacan-interface-single-item-after-metadata', array( $this, 'echo_after_metadata' ) );

		// 2) Conteúdo padrão do Tainacan (temas sem template próprio).
		add_filter( 'tainacan_single_item_content', array( $this, 'append_to_item_content' ), 20, 2 );

		// 3) Fallback genérico via the_content (outros temas clássicos).
		add_filter( 'the_content', array( $this, 'append_to_post_content' ), 20 );

		// Garante CSS/JS no <head> da página de item (a action do tema dispara
		// depois do wp_head; o enqueue do shortcode sozinho seria tardio demais).
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Enfileira os assets públicos nas páginas singulares de item Tainacan.
	 *
	 * Idempotente com o enqueue feito por Shortcode::render(); os handles
	 * 'tmc-public' são registrados por Shortcode::register_assets().
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets() {
		if ( ! $this->is_enabled() || ! is_singular() ) {
			return;
		}
		$post_type = get_post_type();
		if ( ! is_string( $post_type ) || ! preg_match( '/^tnc_col_\d+_item$/', $post_type ) ) {
			return;
		}
		wp_enqueue_style( 'tmc-public' );
		wp_enqueue_script( 'tmc-public' );
	}

	/**
	 * Action do tema: imprime o formulário logo após os metadados do item.
	 *
	 * @return void
	 */
	public function echo_after_metadata() {
		if ( ! $this->is_enabled() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Saída é o HTML do próprio shortcode do plugin, escapado na origem em Shortcode::render().
		echo $this->form_html( (int) get_the_ID() );
	}

	/**
	 * Filtro do core: anexa o formulário após o conteúdo padrão do item.
	 *
	 * @param string $content Conteúdo do item (documento + metadados + anexos).
	 * @param mixed  $item    Objeto \Tainacan\Entities\Item.
	 * @return string
	 */
	public function append_to_item_content( $content, $item ) {
		if ( ! $this->is_enabled() || $this->theme_renders_via_hook() ) {
			return $content;
		}

		$item_id = ( is_object( $item ) && method_exists( $item, 'get_id' ) ) ? (int) $item->get_id() : (int) get_the_ID();

		return $content . $this->form_html( $item_id );
	}

	/**
	 * Fallback: anexa o formulário ao final do conteúdo do post de item.
	 *
	 * @param string $content Conteúdo original do post.
	 * @return string
	 */
	public function append_to_post_content( $content ) {
		if ( ! $this->is_enabled() || $this->theme_renders_via_hook() ) {
			return $content;
		}
		if ( is_admin() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_type = get_post_type();
		if ( ! is_string( $post_type ) || ! preg_match( '/^tnc_col_\d+_item$/', $post_type ) ) {
			return $content;
		}

		return $content . $this->form_html( (int) get_the_ID() );
	}

	/**
	 * Indica se o tema ativo já injeta via action própria (Tainacan Interface).
	 *
	 * Nesses temas o single-items.php dispara
	 * `tainacan-interface-single-item-after-metadata`, então os filtros de
	 * conteúdo são suprimidos para evitar duplicação. get_template() retorna o
	 * slug do tema-pai (compatível com child themes como "memorial").
	 *
	 * @return bool
	 */
	private function theme_renders_via_hook() {
		$uses_hook = ( 'tainacan-interface' === get_template() );

		/**
		 * Permite que outros temas baseados no Tainacan Interface declarem que
		 * também disparam a action `tainacan-interface-single-item-after-metadata`.
		 *
		 * @param bool $uses_hook Se o tema injeta via action própria.
		 */
		return (bool) apply_filters( 'tmc_theme_renders_via_after_metadata_hook', $uses_hook );
	}

	/**
	 * Renderiza o formulário recolhível para um item, uma única vez por item.
	 *
	 * @param int $item_id ID do item Tainacan.
	 * @return string HTML do formulário (vazio se inválido ou já injetado).
	 */
	private function form_html( $item_id ) {
		if ( $item_id <= 0 || isset( $this->injected[ $item_id ] ) ) {
			return '';
		}
		$this->injected[ $item_id ] = true;

		return do_shortcode( '[tmc_suggest_form item_id="' . $item_id . '" collapsible="1"]' );
	}

	/**
	 * Indica se a auto-injeção está habilitada nas configurações.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		return (int) get_option( 'tmc_enabled', 1 ) && (int) get_option( 'tmc_autoinject', 1 );
	}
}

<?php
/**
 * Shortcode público do formulário de sugestões.
 *
 * @package TMC
 */

namespace TMC\Frontend;

use TMC\SuggestionsManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [tmc_suggest_form item_id="X"] renderiza o formulário público.
 *
 * Anti-spam 100% local (sem CDN/terceiros): CAPTCHA aritmético carregado via
 * REST no init (imune a page cache) + honeypot + time-trap no servidor.
 */
class Shortcode {

	/**
	 * Gerenciador de sugestões.
	 *
	 * @var SuggestionsManager
	 */
	private $manager;

	/**
	 * Construtor.
	 */
	public function __construct() {
		$this->manager = new SuggestionsManager();
	}

	/**
	 * Registra o shortcode e o enqueue de assets.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'tmc_suggest_form', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Registra (sem enfileirar) os assets do formulário.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'tmc-public',
			TMC_PLUGIN_URL . 'assets/css/public.css',
			array(),
			TMC_VERSION
		);
		wp_register_script(
			'tmc-public',
			TMC_PLUGIN_URL . 'assets/js/public.js',
			array(),
			TMC_VERSION,
			true
		);
	}

	/**
	 * Renderiza o formulário de sugestões para um item.
	 *
	 * @param array $atts Atributos do shortcode (item_id, title, collapsible).
	 * @return string HTML do formulário.
	 */
	public function render( $atts ) {
		if ( ! (int) get_option( 'tmc_enabled', 1 ) ) {
			return '<div class="tmc-disabled">' . esc_html__( 'As sugestões estão desabilitadas no momento.', 'tainacan-metadata-crowdsource' ) . '</div>';
		}

		$atts = shortcode_atts(
			array(
				'item_id'     => 0,
				'title'       => __( 'Sugerir correção nos metadados', 'tainacan-metadata-crowdsource' ),
				'collapsible' => 0,
			),
			$atts,
			'tmc_suggest_form'
		);

		$collapsible = filter_var( $atts['collapsible'], FILTER_VALIDATE_BOOLEAN );

		$item_id = (int) $atts['item_id'];
		if ( $item_id <= 0 ) {
			return '<div class="tmc-error">' . esc_html__( 'Item inválido.', 'tainacan-metadata-crowdsource' ) . '</div>';
		}

		$metadata = $this->manager->get_item_metadata_for_form( $item_id );
		if ( empty( $metadata ) ) {
			return '<div class="tmc-empty">' . esc_html__( 'Este item não possui metadados disponíveis para sugestão no momento.', 'tainacan-metadata-crowdsource' ) . '</div>';
		}

		wp_enqueue_style( 'tmc-public' );
		wp_enqueue_script( 'tmc-public' );
		wp_localize_script(
			'tmc-public',
			'tmcConfig',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'tmc/v1/suggestions' ) ),
				'captchaUrl' => esc_url_raw( rest_url( 'tmc/v1/captcha' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'i18n'       => array(
					'fillOne'       => __( 'Preencha pelo menos um campo para sugerir.', 'tainacan-metadata-crowdsource' ),
					'answerCaptcha' => __( 'Responda a verificação anti-spam antes de enviar.', 'tainacan-metadata-crowdsource' ),
					'sending'       => __( 'Enviando…', 'tainacan-metadata-crowdsource' ),
					'success'       => __( 'Sugestão(ões) enviada(s) com sucesso! Obrigado pela contribuição.', 'tainacan-metadata-crowdsource' ),
					'networkError'  => __( 'Erro de rede. Tente novamente.', 'tainacan-metadata-crowdsource' ),
					'captchaError'  => __( 'Não foi possível carregar a verificação. Recarregue a página.', 'tainacan-metadata-crowdsource' ),
				),
			)
		);

		$current_label = __( 'Valor atual:', 'tainacan-metadata-crowdsource' );
		$empty_label   = __( '(vazio)', 'tainacan-metadata-crowdsource' );
		$suggest_ph    = __( 'Sugerir novo valor…', 'tainacan-metadata-crowdsource' );

		ob_start();
		?>
		<?php if ( $collapsible ) : ?>
		<details class="tmc-widget tmc-collapsible" data-item-id="<?php echo esc_attr( $item_id ); ?>">
			<summary class="tmc-summary"><?php echo esc_html( $atts['title'] ); ?></summary>
		<?php else : ?>
		<div class="tmc-widget" data-item-id="<?php echo esc_attr( $item_id ); ?>">
			<h3 class="tmc-title"><?php echo esc_html( $atts['title'] ); ?></h3>
		<?php endif; ?>
			<p class="tmc-intro">
				<?php esc_html_e( 'Identificou uma informação incorreta ou incompleta? Sugira uma correção. Cada campo é uma sugestão separada — preencha apenas os que deseja corrigir. Sua contribuição será revisada pela equipe antes de ser aplicada.', 'tainacan-metadata-crowdsource' ); ?>
			</p>

			<form class="tmc-form" novalidate>
				<div class="tmc-fields">
					<?php foreach ( $metadata as $md ) : ?>
						<?php $current_value = '' !== (string) $md['current'] ? $md['current'] : $empty_label; ?>
						<div class="tmc-field">
							<div class="tmc-field-head">
								<strong class="tmc-field-label"><?php echo esc_html( $md['label'] ); ?></strong>
								<span class="tmc-field-current"><?php echo esc_html( $current_label ); ?> <em><?php echo esc_html( $current_value ); ?></em></span>
							</div>
							<textarea
								class="tmc-field-input"
								data-metadatum-id="<?php echo esc_attr( $md['metadatum_id'] ); ?>"
								placeholder="<?php echo esc_attr( $suggest_ph ); ?>"
								rows="2"></textarea>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="tmc-meta">
					<label>
						<span><?php esc_html_e( 'Seu nome (opcional)', 'tainacan-metadata-crowdsource' ); ?></span>
						<input type="text" class="tmc-input" name="submitter_name" maxlength="255">
					</label>
					<label>
						<span><?php esc_html_e( 'Seu e-mail (opcional)', 'tainacan-metadata-crowdsource' ); ?></span>
						<input type="email" class="tmc-input" name="submitter_email" maxlength="255">
					</label>
					<label>
						<span><?php esc_html_e( 'Motivo da correção (opcional)', 'tainacan-metadata-crowdsource' ); ?></span>
						<textarea class="tmc-input" name="reason" rows="3" maxlength="2000" placeholder="<?php esc_attr_e( 'Ex.: o documento original indica outra data', 'tainacan-metadata-crowdsource' ); ?>"></textarea>
					</label>
				</div>

				<?php // Honeypot: oculto via CSS; preenchido apenas por bots. ?>
				<div class="tmc-hp" aria-hidden="true">
					<label><?php esc_html_e( 'Deixe este campo em branco', 'tainacan-metadata-crowdsource' ); ?>
						<input type="text" name="tmc_hp" tabindex="-1" autocomplete="off">
					</label>
				</div>

				<div class="tmc-captcha">
					<label>
						<span class="tmc-captcha-label">
							<?php
							/* translators: %s: expressão aritmética (ex.: "3 + 5") injetada pelo JS. */
							printf( esc_html__( 'Verificação anti-spam: quanto é %s?', 'tainacan-metadata-crowdsource' ), '<span class="tmc-captcha-question">…</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Format string escaped via esc_html__; the only placeholder is a static <span> placeholder (no user input).
							?>
						</span>
						<input type="text" class="tmc-captcha-answer" inputmode="numeric" autocomplete="off" required>
					</label>
					<input type="hidden" class="tmc-captcha-token" value="">
				</div>

				<div class="tmc-actions">
					<button type="submit" class="tmc-submit"><?php esc_html_e( 'Enviar sugestões', 'tainacan-metadata-crowdsource' ); ?></button>
					<div class="tmc-feedback" role="status" aria-live="polite"></div>
				</div>
			</form>
		<?php if ( $collapsible ) : ?>
		</details>
		<?php else : ?>
		</div>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}
}

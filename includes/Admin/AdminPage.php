<?php
/**
 * Página de administração integrada ao ambiente Tainacan.
 *
 * Implementa o contrato \Tainacan\Pages descrito em
 * https://tainacan.github.io/tainacan-wiki/#/dev/creating-tainacan-admin-pages
 * — submenu sob o item raiz do Tainacan, navegação lateral, classes
 * tainacan-page-container-content e tainacan-fixed-subheader.
 *
 * @package TMC
 */

namespace TMC\Admin;

use TMC\SuggestionsManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aba/listagem/moderação de sugestões dentro do menu Tainacan.
 *
 * Não recebe `new` direto — usa-se {@see AdminPage::get_instance()} via
 * Singleton_Instance trait. A trait dispara parent::__construct() (que
 * chama $this->init() registrando admin_menu).
 */
class AdminPage extends \Tainacan\Pages {

	use \Tainacan\Traits\Singleton_Instance;

	/**
	 * Gerenciador de sugestões.
	 *
	 * @var SuggestionsManager
	 */
	private $manager;

	/**
	 * Page suffix retornado por add_submenu_page — usado em
	 * load-<suffix> e em admin_enqueue_scripts.
	 *
	 * @var string
	 */
	private $page_suffix = '';

	/**
	 * Slug único do submenu (exigido por \Tainacan\Pages).
	 *
	 * @return string
	 */
	protected function get_page_slug(): string {
		return 'tmc_suggestions_page';
	}

	/**
	 * Hook setup. Acrescenta hooks próprios em torno do que \Tainacan\Pages
	 * já registra (admin_menu, admin_head, init).
	 *
	 * @return void
	 */
	public function init() {
		parent::init();
		$this->manager = new SuggestionsManager();
		add_action( 'admin_init', array( &$this, 'register_settings' ) );
	}

	/**
	 * Registra o submenu no item raiz do Tainacan e o hook de carga.
	 *
	 * Posição alta (80) para empurrar para o final do menu lateral, abaixo
	 * de Repository / Other; do contrário, posições baixas como `3`
	 * intercalam o item entre os filhos de Repository visualmente.
	 *
	 * Cap `read` segue o padrão das páginas nativas do Tainacan (Roles,
	 * Settings); validamos `manage_options` dentro da página/REST antes
	 * de qualquer ação destrutiva.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		// SVG do Tainacan (mensagem/comments). Cai em string vazia se o
		// helper não retornar nada — ainda assim o texto e o link funcionam.
		$icon_svg = method_exists( $this, 'get_svg_icon' ) ? $this->get_svg_icon( 'note' ) : '';

		$this->page_suffix = add_submenu_page(
			$this->tainacan_root_menu_slug,
			__( 'Crowdsource', 'tainacan-metadata-crowdsource' ),
			'<span class="icon" aria-hidden="true">' . $icon_svg . '</span>'
				. '<span class="menu-text">' . esc_html__( 'Crowdsource', 'tainacan-metadata-crowdsource' )
				. $this->menu_badge() . '</span>',
			'read',
			$this->get_page_slug(),
			array( &$this, 'render_page' ),
			80
		);

		if ( $this->page_suffix ) {
			add_action( 'load-' . $this->page_suffix, array( &$this, 'load_page' ) );
		}

		// Remove qualquer menu top-level legado deixado por versões antigas
		// (cache de plugin, arquivos zumbis, opcache não invalidado), para
		// não confundir o usuário com dois cliques que levam a páginas
		// diferentes — um para a integração nova e outro para o renderer antigo.
		$this->prune_legacy_menu();
	}

	/**
	 * Remove o item de menu top-level antigo `tainacan-metadata-crowdsource`,
	 * se algum vestígio dele tiver sobrevivido a atualizações sucessivas.
	 *
	 * @return void
	 */
	private function prune_legacy_menu() {
		global $menu, $submenu;

		if ( is_array( $menu ) ) {
			foreach ( $menu as $key => $item ) {
				if ( isset( $item[2] ) && 'tainacan-metadata-crowdsource' === $item[2] ) {
					unset( $menu[ $key ] );
				}
			}
		}
		if ( isset( $submenu['tainacan-metadata-crowdsource'] ) ) {
			unset( $submenu['tainacan-metadata-crowdsource'] );
		}

		// Redireciona URLs antigas (?page=tainacan-metadata-crowdsource) para
		// a nova página integrada, para bookmarks e qualquer link em e-mails
		// já enviados não caírem em "página não encontrada".
		add_action( 'admin_init', array( $this, 'redirect_legacy_url' ) );
	}

	/**
	 * Redireciona a URL antiga para a nova.
	 *
	 * @return void
	 */
	public function redirect_legacy_url() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect; no state mutation.
		if ( isset( $_GET['page'] ) && 'tainacan-metadata-crowdsource' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . $this->get_page_slug() ) );
			exit;
		}
	}

	/**
	 * Badge HTML (sufixo do menu-text) com contagem de sugestões pendentes.
	 *
	 * @return string
	 */
	private function menu_badge() {
		$counts  = $this->manager ? $this->manager->count_by_status() : array();
		$pending = (int) ( $counts['pending'] ?? 0 );
		if ( $pending <= 0 ) {
			return '';
		}
		return sprintf( ' <span class="awaiting-mod count-%1$d"><span>%1$d</span></span>', $pending );
	}

	/**
	 * Registra as configurações do plugin.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'tmc_settings',
			'tmc_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 1,
			)
		);
		register_setting(
			'tmc_settings',
			'tmc_autoinject',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 1,
			)
		);
		register_setting(
			'tmc_settings',
			'tmc_notify_email',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'absint',
				'default'           => 1,
			)
		);
		register_setting(
			'tmc_settings',
			'tmc_notify_to',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
			)
		);
	}

	/**
	 * Enfileira CSS apenas na página do plugin (chamado pelo load_page() do parent).
	 *
	 * @return void
	 */
	public function admin_enqueue_css() {
		wp_enqueue_style(
			'tmc-admin',
			TMC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			TMC_VERSION
		);
	}

	/**
	 * Enfileira JS apenas na página do plugin.
	 *
	 * @return void
	 */
	public function admin_enqueue_js() {
		wp_enqueue_script(
			'tmc-admin',
			TMC_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			TMC_VERSION,
			true
		);
		wp_localize_script(
			'tmc-admin',
			'tmcAdmin',
			array(
				'restUrl' => esc_url_raw( rest_url( 'tmc/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'rejectPrompt'       => __( 'Motivo da rejeição (opcional):', 'tainacan-metadata-crowdsource' ),
					'processing'         => __( 'Processando…', 'tainacan-metadata-crowdsource' ),
					'approve'            => __( 'Aprovar', 'tainacan-metadata-crowdsource' ),
					'reject'             => __( 'Rejeitar', 'tainacan-metadata-crowdsource' ),
					'unknownError'       => __( 'Erro desconhecido', 'tainacan-metadata-crowdsource' ),
					'failPrefix'         => __( 'Falha:', 'tainacan-metadata-crowdsource' ),
					'thankPrompt'        => __( 'Mensagem de agradecimento (deixe em branco para usar a mensagem padrão):', 'tainacan-metadata-crowdsource' ),
					'thanking'           => __( 'Enviando…', 'tainacan-metadata-crowdsource' ),
					'thanked'            => __( '✓ Agradecido', 'tainacan-metadata-crowdsource' ),
					'thankSent'          => __( 'Agradecimento enviado!', 'tainacan-metadata-crowdsource' ),
					'deleteConfirm'      => __( 'Excluir esta sugestão? Esta ação não pode ser desfeita.', 'tainacan-metadata-crowdsource' ),
					'deleteGroupConfirm' => __( 'Excluir TODAS as sugestões desta submissão? Esta ação não pode ser desfeita.', 'tainacan-metadata-crowdsource' ),
				),
			)
		);
	}

	/**
	 * Conteúdo principal da página (chamado pelo render_page do parent
	 * dentro do container e da navegação lateral nativos do Tainacan).
	 *
	 * @return void
	 */
	public function render_page_content() {
		// O menu usa cap 'read' (padrão Tainacan), mas a moderação expõe dados
		// pessoais dos colaboradores (e-mail). Exigimos manage_options para ver.
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Você não tem permissão para gerenciar as sugestões.', 'tainacan-metadata-crowdsource' ) . '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab switch; no state mutation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'suggestions';
		$tab = in_array( $tab, array( 'suggestions', 'settings' ), true ) ? $tab : 'suggestions';

		$counts   = $this->manager->count_by_status();
		$base_url = '?page=' . $this->get_page_slug();
		?>
		<div class="wrap tainacan-page-container-content tmc-wrap">
			<div class="tainacan-fixed-subheader">
				<h1 class="tainacan-page-title">
					<?php esc_html_e( 'Crowdsource de metadados', 'tainacan-metadata-crowdsource' ); ?>
				</h1>
				<p class="tainacan-page-description">
					<?php esc_html_e( 'Sugestões recebidas do público para revisão pela equipe.', 'tainacan-metadata-crowdsource' ); ?>
				</p>
			</div>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $base_url . '&tab=suggestions' ); ?>" class="nav-tab <?php echo 'suggestions' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Sugestões', 'tainacan-metadata-crowdsource' ); ?>
					<?php if ( ! empty( $counts['pending'] ) ) : ?>
						<span class="tmc-badge"><?php echo (int) $counts['pending']; ?></span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url( $base_url . '&tab=settings' ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Configurações', 'tainacan-metadata-crowdsource' ); ?></a>
			</h2>

			<?php
			if ( 'suggestions' === $tab ) {
				$this->render_suggestions_tab( $counts, $base_url );
			} else {
				$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renderiza a aba de listagem/moderação de sugestões.
	 *
	 * @param array  $counts   Contagem por status.
	 * @param string $base_url Base da URL para os filtros.
	 * @return void
	 */
	private function render_suggestions_tab( $counts, $base_url ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin status filter; no state mutation.
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending';
		$status_filter = in_array( $status_filter, array( 'pending', 'approved', 'rejected', 'stale', 'all' ), true ) ? $status_filter : 'pending';

		$list = $this->manager->list(
			array(
				'status' => 'all' === $status_filter ? null : $status_filter,
				'limit'  => 100,
			)
		);

		$filters = array(
			'pending'  => __( 'Pendentes', 'tainacan-metadata-crowdsource' ),
			'stale'    => __( 'Desatualizadas', 'tainacan-metadata-crowdsource' ),
			'approved' => __( 'Aprovadas', 'tainacan-metadata-crowdsource' ),
			'rejected' => __( 'Rejeitadas', 'tainacan-metadata-crowdsource' ),
		);
		?>
		<ul class="subsubsub">
			<?php foreach ( $filters as $key => $label ) : ?>
				<li>
					<a href="<?php echo esc_url( $base_url . '&status=' . $key ); ?>" class="<?php echo $status_filter === $key ? 'current' : ''; ?>">
						<?php echo esc_html( $label ); ?> <span class="count">(<?php echo (int) ( $counts[ $key ] ?? 0 ); ?>)</span>
					</a> |
				</li>
			<?php endforeach; ?>
			<li><a href="<?php echo esc_url( $base_url . '&status=all' ); ?>" class="<?php echo 'all' === $status_filter ? 'current' : ''; ?>"><?php esc_html_e( 'Todas', 'tainacan-metadata-crowdsource' ); ?></a></li>
		</ul>

		<?php
		if ( empty( $list ) ) :
			?>
			<p class="tmc-empty"><?php esc_html_e( 'Nenhuma sugestão neste filtro.', 'tainacan-metadata-crowdsource' ); ?></p>
			<?php
		else :
			// Agrupa por submissão (um envio de formulário). Linhas legadas sem
			// submission_id viram grupos individuais.
			$groups = array();
			foreach ( $list as $s ) {
				$key = ! empty( $s->submission_id ) ? 'sub-' . $s->submission_id : 'row-' . $s->id;
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array();
				}
				$groups[ $key ][] = $s;
			}

			foreach ( $groups as $rows ) :
				$first      = $rows[0];
				$item_title = get_the_title( $first->item_id );
				if ( ! $item_title ) {
					$item_title = '#' . $first->item_id;
				}
				$submitter = $first->submitter_name ? $first->submitter_name : __( 'anônimo', 'tainacan-metadata-crowdsource' );
				$thanked   = ! empty( $first->thanked_at );
				$can_thank = ! empty( $first->submission_id ) && ! empty( $first->submitter_email );
				?>
				<div class="tmc-submission" data-submission-id="<?php echo esc_attr( (string) $first->submission_id ); ?>">
					<div class="tmc-submission-head">
						<div class="tmc-submission-meta">
							<strong class="tmc-submission-item"><?php echo esc_html( $item_title ); ?></strong>
							<a href="<?php echo esc_url( get_permalink( $first->item_id ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ver item', 'tainacan-metadata-crowdsource' ); ?></a>
							<span class="tmc-submission-by">
								<?php
								echo esc_html( $submitter );
								if ( $first->submitter_email ) {
									echo ' &lt;' . esc_html( $first->submitter_email ) . '&gt;';
								}
								echo ' · ' . esc_html( mysql2date( 'd/m/Y H:i', $first->created_at ) );
								?>
							</span>
						</div>
						<div class="tmc-submission-actions">
							<?php if ( $thanked ) : ?>
								<span class="tmc-thanked">✓ <?php esc_html_e( 'Agradecido', 'tainacan-metadata-crowdsource' ); ?></span>
							<?php elseif ( $can_thank ) : ?>
								<button class="button tmc-thank"><?php esc_html_e( 'Enviar agradecimento', 'tainacan-metadata-crowdsource' ); ?></button>
							<?php endif; ?>
							<?php if ( ! empty( $first->submission_id ) ) : ?>
							<button class="button-link tmc-delete-group"><?php esc_html_e( 'Excluir submissão', 'tainacan-metadata-crowdsource' ); ?></button>
							<?php endif; ?>
						</div>
					</div>
					<table class="wp-list-table widefat striped tmc-submission-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Metadado', 'tainacan-metadata-crowdsource' ); ?></th>
								<th><?php esc_html_e( 'Informação atual', 'tainacan-metadata-crowdsource' ); ?></th>
								<th><?php esc_html_e( 'Informação sugerida', 'tainacan-metadata-crowdsource' ); ?></th>
								<th><?php esc_html_e( 'Motivo', 'tainacan-metadata-crowdsource' ); ?></th>
								<th><?php esc_html_e( 'Status', 'tainacan-metadata-crowdsource' ); ?></th>
								<th><?php esc_html_e( 'Ações', 'tainacan-metadata-crowdsource' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $rows as $s ) :
								$metadatum  = $s->metadatum_label ? $s->metadatum_label : '#' . $s->metadatum_id;
								$reason     = $s->reason ? $s->reason : '—';
								$is_pending = in_array( $s->status, array( 'pending', 'stale' ), true );
								// Heurística de multivalorado: presença do separador "||".
								$is_multiple = ( false !== strpos( (string) $s->old_value, '||' ) ) || ( false !== strpos( (string) $s->new_value, '||' ) );
								$new_display = $is_multiple ? str_replace( '||', "\n", (string) $s->new_value ) : (string) $s->new_value;
								$old_display = $is_multiple ? str_replace( '||', "\n", (string) $s->old_value ) : (string) $s->old_value;
								$old_compact = '' !== (string) $s->old_value
									? ( $is_multiple ? implode( ', ', explode( '||', (string) $s->old_value ) ) : (string) $s->old_value )
									: __( '(vazio)', 'tainacan-metadata-crowdsource' );
								$diff_html   = ( $is_pending && function_exists( 'wp_text_diff' ) )
									? wp_text_diff(
										$old_display,
										$new_display,
										array(
											'title_left'  => __( 'Atual', 'tainacan-metadata-crowdsource' ),
											'title_right' => __( 'Sugerida', 'tainacan-metadata-crowdsource' ),
										)
									)
									: '';
								?>
								<tr data-suggestion-id="<?php echo (int) $s->id; ?>" class="tmc-row tmc-row-<?php echo esc_attr( $s->status ); ?>">
									<td><?php echo esc_html( $metadatum ); ?></td>
									<td class="tmc-val tmc-val-old"><?php echo esc_html( $old_compact ); ?></td>
									<td class="tmc-val tmc-val-new">
										<?php if ( $is_pending ) : ?>
											<textarea class="tmc-edit-value" data-multiple="<?php echo $is_multiple ? '1' : '0'; ?>" data-original="<?php echo esc_attr( (string) $s->new_value ); ?>" rows="3"><?php echo esc_textarea( $new_display ); ?></textarea>
											<?php if ( $diff_html ) : ?>
												<a href="#" class="tmc-diff-toggle"><?php esc_html_e( 'ver diferenças', 'tainacan-metadata-crowdsource' ); ?></a>
												<div class="tmc-diff" hidden>
													<?php echo $diff_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_text_diff() retorna HTML de diff gerado e escapado internamente pelo core do WordPress. ?>
												</div>
											<?php endif; ?>
										<?php else : ?>
											<?php
											$applied         = ( null !== $s->final_value && '' !== (string) $s->final_value ) ? (string) $s->final_value : (string) $s->new_value;
											$applied_display = $is_multiple ? implode( ', ', explode( '||', $applied ) ) : $applied;
											echo esc_html( $applied_display );
											if ( null !== $s->final_value && '' !== (string) $s->final_value ) {
												echo ' <small class="tmc-edited">' . esc_html__( '(editado pelo gestor)', 'tainacan-metadata-crowdsource' ) . '</small>';
											}
											?>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $reason ); ?></td>
									<td>
										<span class="tmc-status tmc-status-<?php echo esc_attr( $s->status ); ?>"><?php echo esc_html( $this->status_label( $s->status ) ); ?></span>
										<?php if ( 'stale' === $s->status ) : ?>
											<div><small><?php esc_html_e( 'O valor original mudou desde a sugestão.', 'tainacan-metadata-crowdsource' ); ?></small></div>
										<?php endif; ?>
										<?php $this->render_history( $s ); ?>
									</td>
									<td class="tmc-actions">
										<?php if ( $is_pending ) : ?>
											<button class="button button-primary tmc-approve"><?php esc_html_e( 'Aprovar', 'tainacan-metadata-crowdsource' ); ?></button>
											<button class="button tmc-reject"><?php esc_html_e( 'Rejeitar', 'tainacan-metadata-crowdsource' ); ?></button>
										<?php endif; ?>
										<button class="button-link tmc-delete"><?php esc_html_e( 'Excluir', 'tainacan-metadata-crowdsource' ); ?></button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php
			endforeach;
		endif;
		?>
		<?php
	}

	/**
	 * Imprime o histórico de revisão de uma sugestão já avaliada.
	 *
	 * @param object $s Linha da sugestão.
	 * @return void
	 */
	private function render_history( $s ) {
		if ( in_array( $s->status, array( 'pending', 'stale' ), true ) ) {
			return;
		}

		$reviewer = $s->reviewed_by ? get_userdata( (int) $s->reviewed_by ) : null;
		$by       = $reviewer ? $reviewer->display_name : __( 'equipe', 'tainacan-metadata-crowdsource' );
		$when     = $s->reviewed_at ? mysql2date( 'd/m/Y H:i', $s->reviewed_at ) : '';

		if ( 'approved' === $s->status ) {
			/* translators: 1: nome do revisor, 2: data e hora. */
			$line = sprintf( __( 'Aprovada por %1$s em %2$s', 'tainacan-metadata-crowdsource' ), $by, $when );
		} elseif ( 'rejected' === $s->status ) {
			/* translators: 1: nome do revisor, 2: data e hora. */
			$line = sprintf( __( 'Rejeitada por %1$s em %2$s', 'tainacan-metadata-crowdsource' ), $by, $when );
		} else {
			return;
		}

		echo '<div class="tmc-history"><small>' . esc_html( $line ) . '</small>';

		if ( null !== $s->final_value && '' !== (string) $s->final_value ) {
			$editor      = $s->edited_by ? get_userdata( (int) $s->edited_by ) : null;
			$editor_name = $editor ? $editor->display_name : $by;
			/* translators: %s: nome de quem editou o valor antes de aprovar. */
			echo '<div><small>' . esc_html( sprintf( __( 'Valor editado por %s antes de aprovar.', 'tainacan-metadata-crowdsource' ), $editor_name ) ) . '</small></div>';
		}

		if ( $s->review_notes ) {
			echo '<div><small>' . esc_html__( 'Notas:', 'tainacan-metadata-crowdsource' ) . ' ' . esc_html( $s->review_notes ) . '</small></div>';
		}

		echo '</div>';
	}

	/**
	 * Renderiza a aba de configurações.
	 *
	 * @return void
	 */
	private function render_settings_tab() {
		?>
		<form method="post" action="options.php" class="tmc-settings">
			<?php settings_fields( 'tmc_settings' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="tmc_enabled"><?php esc_html_e( 'Habilitar sugestões', 'tainacan-metadata-crowdsource' ); ?></label></th>
					<td><label><input type="checkbox" id="tmc_enabled" name="tmc_enabled" value="1" <?php checked( 1, (int) get_option( 'tmc_enabled', 1 ) ); ?>> <?php esc_html_e( 'Aceitar novas sugestões do público', 'tainacan-metadata-crowdsource' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="tmc_autoinject"><?php esc_html_e( 'Exibir nas páginas de item', 'tainacan-metadata-crowdsource' ); ?></label></th>
					<td>
						<label><input type="checkbox" id="tmc_autoinject" name="tmc_autoinject" value="1" <?php checked( 1, (int) get_option( 'tmc_autoinject', 1 ) ); ?>> <?php esc_html_e( 'Inserir automaticamente um formulário recolhível ao final de cada página de item Tainacan', 'tainacan-metadata-crowdsource' ); ?></label>
						<p class="description"><?php esc_html_e( 'Desative para usar apenas o shortcode manual.', 'tainacan-metadata-crowdsource' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tmc_notify_email"><?php esc_html_e( 'Notificar por e-mail', 'tainacan-metadata-crowdsource' ); ?></label></th>
					<td><label><input type="checkbox" id="tmc_notify_email" name="tmc_notify_email" value="1" <?php checked( 1, (int) get_option( 'tmc_notify_email', 1 ) ); ?>> <?php esc_html_e( 'Enviar e-mail ao moderador quando uma nova sugestão chegar', 'tainacan-metadata-crowdsource' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="tmc_notify_to"><?php esc_html_e( 'E-mail do moderador', 'tainacan-metadata-crowdsource' ); ?></label></th>
					<td><input type="email" id="tmc_notify_to" name="tmc_notify_to" class="regular-text" value="<?php echo esc_attr( get_option( 'tmc_notify_to', get_option( 'admin_email' ) ) ); ?>"></td>
				</tr>
			</table>

			<p class="description"><?php esc_html_e( 'A verificação anti-spam é local (pergunta aritmética + honeypot), sem dependência de serviços externos.', 'tainacan-metadata-crowdsource' ); ?></p>

			<?php submit_button(); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Como usar', 'tainacan-metadata-crowdsource' ); ?></h2>
		<p><?php esc_html_e( 'Insira o shortcode abaixo em qualquer página ou template para exibir o formulário de sugestões de um item:', 'tainacan-metadata-crowdsource' ); ?></p>
		<pre><code>[tmc_suggest_form item_id="123"]</code></pre>
		<p>
			<?php
			/* translators: %s: exemplo de ID de item. */
			printf( esc_html__( 'Substitua %s pelo ID do item Tainacan.', 'tainacan-metadata-crowdsource' ), '<code>123</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static <code> markup; not user input.
			?>
		</p>
		<?php
	}

	/**
	 * Rótulo traduzido de um status.
	 *
	 * @param string $status Status interno.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = array(
			'pending'  => __( 'Pendente', 'tainacan-metadata-crowdsource' ),
			'approved' => __( 'Aprovada', 'tainacan-metadata-crowdsource' ),
			'rejected' => __( 'Rejeitada', 'tainacan-metadata-crowdsource' ),
			'stale'    => __( 'Desatualizada', 'tainacan-metadata-crowdsource' ),
		);
		return $labels[ $status ] ?? $status;
	}
}

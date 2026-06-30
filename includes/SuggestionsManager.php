<?php
/**
 * Lógica central de CRUD das sugestões de crowdsourcing.
 *
 * @package TMC
 */

namespace TMC;

use TMC\Settings\CollectionConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gerencia sugestões de crowdsourcing para metadados de itens Tainacan.
 *
 * Fluxo: visitante submete sugestão → fica pending → equipe aprova/rejeita.
 * Ao aprovar, o valor sugerido substitui o metadado no Tainacan. Se o valor
 * original mudar antes da revisão, a sugestão é marcada como 'stale'.
 *
 * Persistência: tabela própria do plugin (wp_tmc_suggestions). Não há entidade
 * Tainacan equivalente (sugestões não são post-centric), então usamos $wpdb
 * direto — todas as queries com prepare()/placeholders e phpcs:ignore Pattern A.
 */
class SuggestionsManager {

	/**
	 * Instância de $wpdb.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Nome completo da tabela de sugestões.
	 *
	 * @var string
	 */
	private $table;

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';
	const STATUS_STALE    = 'stale';

	/**
	 * Sentinela de metadatum_id para o campo especial "Descrição da imagem"
	 * (mapeia para o post_content do item, não para um metadado Tainacan).
	 */
	const DESCRIPTION_ID = 0;

	/**
	 * Tamanho máximo (em caracteres) de um valor sugerido.
	 */
	const MAX_VALUE_LENGTH = 5000;

	/**
	 * Construtor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'tmc_suggestions';
	}

	/**
	 * Registra uma nova sugestão.
	 *
	 * @param int          $item_id      ID do item Tainacan.
	 * @param int          $metadatum_id ID do metadado.
	 * @param string|array $new_value    Valor sugerido.
	 * @param array        $context      Contexto opcional (name, email, reason, ip, user_agent).
	 * @return int|\WP_Error ID da sugestão criada ou erro.
	 */
	public function submit( $item_id, $metadatum_id, $new_value, $context = array() ) {
		$item_id      = (int) $item_id;
		$metadatum_id = (int) $metadatum_id;
		$new_value    = is_array( $new_value ) ? implode( '||', $new_value ) : (string) $new_value;

		// metadatum_id 0 é o campo especial de descrição; negativos são inválidos.
		if ( $item_id <= 0 || $metadatum_id < 0 ) {
			return new \WP_Error( 'tmc_invalid_params', __( 'Item e metadado são obrigatórios.', 'tainacan-metadata-crowdsource' ) );
		}
		if ( '' === trim( $new_value ) ) {
			return new \WP_Error( 'tmc_empty_value', __( 'O novo valor não pode ser vazio.', 'tainacan-metadata-crowdsource' ) );
		}
		if ( mb_strlen( $new_value ) > self::MAX_VALUE_LENGTH ) {
			return new \WP_Error( 'tmc_value_too_long', __( 'O valor sugerido é muito longo.', 'tainacan-metadata-crowdsource' ) );
		}
		if ( ! $this->is_valid_target_item( $item_id ) ) {
			return new \WP_Error( 'tmc_item_not_found', __( 'Item não encontrado ou indisponível para sugestões.', 'tainacan-metadata-crowdsource' ) );
		}

		$current = $this->get_current_metadatum_value( $item_id, $metadatum_id );

		// Segurança: bloqueia sugestão para metadado não-público (defesa contra request forjado).
		if ( $metadatum_id > 0 && 'publish' !== ( $current['status'] ?? 'publish' ) ) {
			return new \WP_Error( 'tmc_metadatum_not_public', __( 'Este metadado não está disponível para sugestões.', 'tainacan-metadata-crowdsource' ) );
		}

		// Segurança: respeita a allowlist por coleção. Defende contra um request
		// forjado para um campo que o gestor não habilitou nesta coleção. Coleção
		// não configurada libera tudo (default retrocompatível).
		if ( ! CollectionConfig::is_metadatum_allowed( (int) ( $current['collection_id'] ?? 0 ), $metadatum_id ) ) {
			return new \WP_Error( 'tmc_metadatum_not_allowed', __( 'Este campo não está disponível para sugestões nesta coleção.', 'tainacan-metadata-crowdsource' ) );
		}

		$data = array(
			'submission_id'        => isset( $context['submission_id'] ) ? substr( (string) $context['submission_id'], 0, 64 ) : null,
			'item_id'              => $item_id,
			'collection_id'        => $current['collection_id'] ?? null,
			'metadatum_id'         => $metadatum_id,
			'metadatum_slug'       => $current['slug'] ?? null,
			'metadatum_label'      => $current['label'] ?? null,
			'old_value'            => $current['value_text'] ?? '',
			'old_value_hash'       => $this->hash_value( $current['value_text'] ?? '' ),
			'new_value'            => $new_value,
			'reason'               => isset( $context['reason'] ) ? substr( (string) $context['reason'], 0, 2000 ) : null,
			'submitter_name'       => isset( $context['name'] ) ? substr( (string) $context['name'], 0, 255 ) : null,
			'submitter_email'      => isset( $context['email'] ) ? substr( (string) $context['email'], 0, 255 ) : null,
			'submitter_ip'         => isset( $context['ip'] ) ? substr( (string) $context['ip'], 0, 45 ) : null,
			'submitter_user_agent' => isset( $context['user_agent'] ) ? substr( (string) $context['user_agent'], 0, 500 ) : null,
			'status'               => self::STATUS_PENDING,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path, caching irrelevant; values sanitized above and inserted via $wpdb->insert (auto-prepared).
		$inserted = $this->wpdb->insert( $this->table, $data );
		if ( false === $inserted ) {
			return new \WP_Error( 'tmc_db_error', __( 'Erro ao registrar sugestão.', 'tainacan-metadata-crowdsource' ) );
		}

		$suggestion_id = (int) $this->wpdb->insert_id;

		do_action( 'tmc_suggestion_submitted', $suggestion_id, $data );
		$this->notify_moderators( $suggestion_id, $data );

		return $suggestion_id;
	}

	/**
	 * Busca uma sugestão por ID.
	 *
	 * @param int $suggestion_id ID da sugestão.
	 * @return object|null
	 */
	public function get( $suggestion_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; not available via WP_Query; table name is $wpdb->prefix (trusted); id via %d placeholder.
		return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", (int) $suggestion_id ) );
	}

	/**
	 * Lista sugestões com filtros.
	 *
	 * @param array $args status, item_id, limit, offset, orderby, order.
	 * @return array
	 */
	public function list( $args = array() ) {
		$defaults = array(
			'status'  => null,
			'item_id' => null,
			'limit'   => 50,
			'offset'  => 0,
			'orderby' => 'created_at',
			'order'   => 'DESC',
		);
		$args     = array_merge( $defaults, $args );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['item_id'] ) ) {
			$where[]  = 'item_id = %d';
			$params[] = (int) $args['item_id'];
		}

		// orderby/order validados contra allowlist antes de interpolar.
		$orderby = in_array( $args['orderby'], array( 'created_at', 'status', 'item_id' ), true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$limit   = max( 1, min( 200, (int) $args['limit'] ) );
		$offset  = max( 0, (int) $args['offset'] );

		$sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where )
			. " ORDER BY {$orderby} {$order} LIMIT {$limit} OFFSET {$offset}";

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql composed from %s/%d placeholders; user input bound via prepare(); ORDER BY/LIMIT from validated allowlist/int casts.
			$sql = $this->wpdb->prepare( $sql, $params );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; admin listing; ORDER BY/LIMIT validated against allowlist/int casts above; WHERE values bound via prepare().
		return $this->wpdb->get_results( $sql );
	}

	/**
	 * Conta sugestões agrupadas por status.
	 *
	 * @return array<string,int>
	 */
	public function count_by_status() {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; aggregate COUNT/GROUP BY not expressible via WP_Query; no user input in query.
		$rows = $this->wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$this->table} GROUP BY status" );
		$out  = array(
			self::STATUS_PENDING  => 0,
			self::STATUS_APPROVED => 0,
			self::STATUS_REJECTED => 0,
			self::STATUS_STALE    => 0,
		);
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $r ) {
			$out[ $r->status ] = (int) $r->total;
		}
		return $out;
	}

	/**
	 * Aprova uma sugestão e aplica o valor no item Tainacan.
	 *
	 * @param int         $suggestion_id    ID da sugestão.
	 * @param int|null    $reviewer_user_id ID do revisor.
	 * @param string|null $notes            Notas de revisão.
	 * @param string|null $final_value      Valor curado pelo gestor (se editou antes de aprovar).
	 * @return true|\WP_Error
	 */
	public function approve( $suggestion_id, $reviewer_user_id = null, $notes = null, $final_value = null ) {
		$suggestion = $this->get( $suggestion_id );
		if ( ! $suggestion ) {
			return new \WP_Error( 'tmc_not_found', __( 'Sugestão não encontrada.', 'tainacan-metadata-crowdsource' ) );
		}
		if ( self::STATUS_APPROVED === $suggestion->status ) {
			return new \WP_Error( 'tmc_already_approved', __( 'Sugestão já aprovada.', 'tainacan-metadata-crowdsource' ) );
		}

		// Curadoria do gestor: havendo final_value, é ele que será aplicado e auditado.
		$has_edit = ( null !== $final_value && '' !== trim( (string) $final_value ) );
		if ( $has_edit ) {
			$final_value = (string) $final_value;
			if ( mb_strlen( $final_value ) > self::MAX_VALUE_LENGTH ) {
				return new \WP_Error( 'tmc_value_too_long', __( 'O valor final é muito longo.', 'tainacan-metadata-crowdsource' ) );
			}
			$suggestion->new_value = $final_value;
		}

		$result = $this->apply_to_tainacan( $suggestion );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path via $wpdb->update (auto-prepared); caching irrelevant.
		$this->wpdb->update(
			$this->table,
			array(
				'status'       => self::STATUS_APPROVED,
				'reviewed_by'  => $reviewer_user_id ? (int) $reviewer_user_id : null,
				'reviewed_at'  => current_time( 'mysql' ),
				'review_notes' => $notes ? substr( (string) $notes, 0, 2000 ) : null,
				'final_value'  => $has_edit ? $final_value : null,
				'edited_by'    => ( $has_edit && $reviewer_user_id ) ? (int) $reviewer_user_id : null,
			),
			array( 'id' => (int) $suggestion_id )
		);

		do_action( 'tmc_suggestion_approved', $suggestion_id, $suggestion );
		return true;
	}

	/**
	 * Rejeita uma sugestão.
	 *
	 * @param int         $suggestion_id    ID da sugestão.
	 * @param int|null    $reviewer_user_id ID do revisor.
	 * @param string|null $notes         Notas de revisão.
	 * @return true|\WP_Error
	 */
	public function reject( $suggestion_id, $reviewer_user_id = null, $notes = null ) {
		$suggestion = $this->get( $suggestion_id );
		if ( ! $suggestion ) {
			return new \WP_Error( 'tmc_not_found', __( 'Sugestão não encontrada.', 'tainacan-metadata-crowdsource' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path via $wpdb->update (auto-prepared); caching irrelevant.
		$this->wpdb->update(
			$this->table,
			array(
				'status'       => self::STATUS_REJECTED,
				'reviewed_by'  => $reviewer_user_id ? (int) $reviewer_user_id : null,
				'reviewed_at'  => current_time( 'mysql' ),
				'review_notes' => $notes ? substr( (string) $notes, 0, 2000 ) : null,
			),
			array( 'id' => (int) $suggestion_id )
		);

		do_action( 'tmc_suggestion_rejected', $suggestion_id, $suggestion );
		return true;
	}

	/**
	 * Exclui uma sugestão permanentemente.
	 *
	 * @param int $suggestion_id ID da sugestão.
	 * @return bool
	 */
	public function delete( $suggestion_id ) {
		$suggestion_id = (int) $suggestion_id;
		if ( $suggestion_id <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; delete via $wpdb->delete (auto-prepared); caching irrelevant.
		$deleted = $this->wpdb->delete( $this->table, array( 'id' => $suggestion_id ), array( '%d' ) );
		if ( $deleted ) {
			do_action( 'tmc_suggestion_deleted', $suggestion_id );
		}
		return (bool) $deleted;
	}

	/**
	 * Exclui todas as sugestões de uma submissão.
	 *
	 * @param string $submission_id Identificador da submissão.
	 * @return int Quantidade de linhas excluídas.
	 */
	public function delete_submission( $submission_id ) {
		$submission_id = $this->sanitize_submission_id( $submission_id );
		if ( '' === $submission_id ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; delete via $wpdb->delete (auto-prepared); caching irrelevant.
		return (int) $this->wpdb->delete( $this->table, array( 'submission_id' => $submission_id ), array( '%s' ) );
	}

	/**
	 * Marca como 'stale' sugestões pendentes cujo valor original mudou.
	 *
	 * @param int $item_id ID do item Tainacan.
	 * @return int Quantidade de sugestões marcadas.
	 */
	public function mark_stale_for_item( $item_id ) {
		$item_id = (int) $item_id;
		if ( $item_id <= 0 ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; not available via WP_Query; table name trusted; values via %d/%s placeholders.
		$pending = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, metadatum_id, old_value_hash FROM {$this->table} WHERE item_id = %d AND status = %s",
				$item_id,
				self::STATUS_PENDING
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $pending ) ) {
			return 0;
		}

		$marked = 0;
		foreach ( $pending as $row ) {
			$current      = $this->get_current_metadatum_value( $item_id, (int) $row->metadatum_id );
			$current_hash = $this->hash_value( $current['value_text'] ?? '' );
			if ( $current_hash !== $row->old_value_hash ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; write path via $wpdb->update (auto-prepared); caching irrelevant.
				$this->wpdb->update(
					$this->table,
					array( 'status' => self::STATUS_STALE ),
					array( 'id' => (int) $row->id )
				);
				++$marked;
			}
		}
		return $marked;
	}

	/**
	 * Retorna os metadados editáveis de um item para o formulário público.
	 *
	 * @param int $item_id ID do item Tainacan.
	 * @return array
	 */
	public function get_item_metadata_for_form( $item_id ) {
		$item_id = (int) $item_id;
		if ( ! class_exists( '\Tainacan\Repositories\Items' ) ) {
			return array();
		}

		try {
			$items_repo = \Tainacan\Repositories\Items::get_instance();
			$item       = $items_repo->fetch( $item_id );
			if ( ! $item || is_wp_error( $item ) ) {
				return array();
			}

			// Allowlist por coleção: se o gestor desligou o crowdsourcing nesta
			// coleção, não há campos a oferecer.
			$collection_id = (int) $item->get_collection_id();
			if ( ! CollectionConfig::is_collection_enabled( $collection_id ) ) {
				return array();
			}

			$out = array();

			// O campo especial "Descrição da imagem" (post_content do item) foi
			// removido do formulário: na prática ele duplica o metadado "Âmbito e
			// conteúdo", gerando dois campos com o mesmo texto. O backend de
			// aplicação/leitura (DESCRIPTION_ID) é mantido para concluir a revisão
			// de sugestões de descrição já existentes no banco.

			// $item->get_metadata() já devolve os metadados na ordem configurada na
			// coleção (Metadata::order_result via get_metadata_order) — a mesma ordem
			// exibida na página do item no Tainacan.
			$item_metadata = $item->get_metadata();
			if ( ! empty( $item_metadata ) ) {
				foreach ( $item_metadata as $im ) {
					$metadatum = $im->get_metadatum();
					if ( ! $metadatum ) {
						continue;
					}

						// Segurança: não oferecer metadados não-públicos (privado/rascunho) no
						// formulário público, para não vazar valores restritos.
					if ( method_exists( $metadatum, 'get_status' ) && 'publish' !== $metadatum->get_status() ) {
						continue;
					}

						// Allowlist por coleção: oferece só os metadados habilitados pelo gestor.
					if ( ! CollectionConfig::is_metadatum_allowed( $collection_id, $metadatum->get_id() ) ) {
						continue;
					}

					$value = $im->get_value();
					// Representação canônica: multivalorado juntado por "||"
					// (mesma usada no armazenamento e na aplicação).
					$value_text = is_array( $value )
						? implode( '||', array_map( 'strval', $value ) )
						: (string) $value;

					$out[] = array(
						'metadatum_id' => $metadatum->get_id(),
						'slug'         => $metadatum->get_slug(),
						'label'        => $metadatum->get_name(),
						'current'      => $value_text,
						'is_multiple'  => $metadatum->is_multiple(),
					);
				}
			}
			return $out;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Lê o valor atual de um metadado de um item via repositórios Tainacan.
	 *
	 * @param int $item_id      ID do item.
	 * @param int $metadatum_id ID do metadado.
	 * @return array value_text, label, slug, collection_id.
	 */
	private function get_current_metadatum_value( $item_id, $metadatum_id ) {
		$out = array(
			'value_text'    => '',
			'label'         => null,
			'slug'          => null,
			'collection_id' => null,
		);

		if ( self::DESCRIPTION_ID === (int) $metadatum_id ) {
			return $this->get_description_field_value( $item_id );
		}

		if ( ! class_exists( '\Tainacan\Repositories\Items' ) ) {
			return $out;
		}

		try {
			$items_repo = \Tainacan\Repositories\Items::get_instance();
			$item       = $items_repo->fetch( (int) $item_id );
			if ( ! $item || is_wp_error( $item ) ) {
				return $out;
			}

			$out['collection_id'] = $item->get_collection_id();

			$metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
			$metadatum     = $metadata_repo->fetch( (int) $metadatum_id );
			if ( ! $metadatum || is_wp_error( $metadatum ) ) {
				return $out;
			}

			$out['slug']   = $metadatum->get_slug();
			$out['label']  = $metadatum->get_name();
			$out['status'] = method_exists( $metadatum, 'get_status' ) ? $metadatum->get_status() : null;

			$im_repo = \Tainacan\Repositories\Item_Metadata::get_instance();
			$im      = new \Tainacan\Entities\Item_Metadata_Entity( $item, $metadatum );
			$im      = $im_repo->fetch( $im );

			if ( $im ) {
				$value             = $im->get_value();
				$out['value_text'] = is_array( $value )
					? implode( '||', array_map( 'strval', $value ) )
					: (string) $value;
			}
		} catch ( \Throwable $e ) {
			// Silencia: item/metadado pode ter sido removido; retorna defaults.
			return $out;
		}

		return $out;
	}

	/**
	 * Aplica o valor sugerido ao item Tainacan via Item_Metadata.
	 *
	 * @param object $suggestion Linha da sugestão.
	 * @return true|\WP_Error
	 */
	private function apply_to_tainacan( $suggestion ) {
		if ( self::DESCRIPTION_ID === (int) $suggestion->metadatum_id ) {
			return $this->apply_description( $suggestion );
		}

		if ( ! class_exists( '\Tainacan\Repositories\Items' ) ) {
			return new \WP_Error( 'tmc_tainacan_missing', __( 'Tainacan não está disponível.', 'tainacan-metadata-crowdsource' ) );
		}

		try {
			$items_repo = \Tainacan\Repositories\Items::get_instance();
			$item       = $items_repo->fetch( (int) $suggestion->item_id );
			if ( ! $item || is_wp_error( $item ) ) {
				return new \WP_Error( 'tmc_item_missing', __( 'Item Tainacan não encontrado.', 'tainacan-metadata-crowdsource' ) );
			}

			$metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
			$metadatum     = $metadata_repo->fetch( (int) $suggestion->metadatum_id );
			if ( ! $metadatum || is_wp_error( $metadatum ) ) {
				return new \WP_Error( 'tmc_metadatum_missing', __( 'Metadado não encontrado.', 'tainacan-metadata-crowdsource' ) );
			}

			$im_repo = \Tainacan\Repositories\Item_Metadata::get_instance();
			$im      = new \Tainacan\Entities\Item_Metadata_Entity( $item, $metadatum );

			if ( $metadatum->is_multiple() ) {
				$values = array_map( 'trim', explode( '||', (string) $suggestion->new_value ) );
				$im->set_value( $values );
			} else {
				$im->set_value( (string) $suggestion->new_value );
			}

			if ( ! $im->validate() ) {
				return new \WP_Error(
					'tmc_invalid_value',
					sprintf(
						/* translators: %s: lista de erros de validação retornada pelo Tainacan. */
						__( 'Valor inválido: %s', 'tainacan-metadata-crowdsource' ),
						wp_json_encode( $im->get_errors() )
					)
				);
			}

			$im_repo->insert( $im );
			return true;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'tmc_apply_error', $e->getMessage() );
		}
	}

	/**
	 * Lê a descrição da imagem (post_content) do item.
	 *
	 * @param int $item_id ID do item.
	 * @return array value_text, label, slug, collection_id.
	 */
	private function get_description_field_value( $item_id ) {
		$post          = get_post( (int) $item_id );
		$collection_id = null;
		if ( $post && preg_match( '/^tnc_col_(\d+)_item$/', $post->post_type, $matches ) ) {
			$collection_id = (int) $matches[1];
		}
		return array(
			'value_text'    => $post ? (string) $post->post_content : '',
			'label'         => __( 'Descrição da imagem', 'tainacan-metadata-crowdsource' ),
			'slug'          => 'description',
			'collection_id' => $collection_id,
		);
	}

	/**
	 * Valida que o alvo é um item Tainacan publicado (proteção do endpoint público).
	 *
	 * @param int $item_id ID do item.
	 * @return bool
	 */
	private function is_valid_target_item( $item_id ) {
		$post = get_post( (int) $item_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}
		return (bool) preg_match( '/^tnc_col_\d+_item$/', $post->post_type );
	}

	/**
	 * Aplica a sugestão de descrição da imagem ao post_content do item.
	 *
	 * @param object $suggestion Linha da sugestão.
	 * @return true|\WP_Error
	 */
	private function apply_description( $suggestion ) {
		$item_id = (int) $suggestion->item_id;

		if ( ! $this->is_valid_target_item( $item_id ) ) {
			return new \WP_Error( 'tmc_item_missing', __( 'Item Tainacan não encontrado.', 'tainacan-metadata-crowdsource' ) );
		}

		// A descrição é o post_content do item (campo core do WordPress).
		// wp_update_post dispara save_post, ao qual o Tainacan reage para reindexar.
		$updated = wp_update_post(
			array(
				'ID'           => $item_id,
				'post_content' => wp_kses_post( (string) $suggestion->new_value ),
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		return true;
	}

	/**
	 * Retorna as sugestões de uma submissão (envio único de formulário).
	 *
	 * @param string $submission_id Identificador da submissão.
	 * @return array
	 */
	public function get_by_submission( $submission_id ) {
		$submission_id = $this->sanitize_submission_id( $submission_id );
		if ( '' === $submission_id ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; not available via WP_Query; submission_id via %s placeholder.
		return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE submission_id = %s ORDER BY id ASC", $submission_id ) );
	}

	/**
	 * Marca todas as sugestões de uma submissão como agradecidas.
	 *
	 * @param string $submission_id Identificador da submissão.
	 * @return void
	 */
	public function mark_thanked( $submission_id ) {
		$submission_id = $this->sanitize_submission_id( $submission_id );
		if ( '' === $submission_id ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin's own table; write path; values via %s placeholders.
		$this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->table} SET thanked_at = %s WHERE submission_id = %s", current_time( 'mysql' ), $submission_id ) );
	}

	/**
	 * Envia um agradecimento ao(s) colaborador(es) de uma submissão.
	 *
	 * @param string $submission_id Identificador da submissão.
	 * @param string $message       Mensagem personalizada (opcional).
	 * @return int|\WP_Error Quantidade de e-mails enviados ou erro.
	 */
	public function thank_submission( $submission_id, $message = '' ) {
		$rows = $this->get_by_submission( $submission_id );
		if ( empty( $rows ) ) {
			return new \WP_Error( 'tmc_not_found', __( 'Submissão não encontrada.', 'tainacan-metadata-crowdsource' ) );
		}

		$emails = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row->submitter_email ) && is_email( $row->submitter_email ) ) {
				$emails[ strtolower( $row->submitter_email ) ] = sanitize_email( $row->submitter_email );
			}
		}
		if ( empty( $emails ) ) {
			return new \WP_Error( 'tmc_no_email', __( 'O colaborador não informou e-mail.', 'tainacan-metadata-crowdsource' ) );
		}

		$message = trim( wp_strip_all_tags( (string) $message ) );
		if ( '' === $message ) {
			$message = $this->default_thanks_message();
		}

		$subject = sprintf(
			/* translators: %s: nome do site. */
			__( 'Obrigado pela sua contribuição — %s', 'tainacan-metadata-crowdsource' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$sent = 0;
		foreach ( $emails as $email ) {
			if ( wp_mail( $email, $subject, $message ) ) {
				++$sent;
			}
		}

		if ( $sent > 0 ) {
			$this->mark_thanked( $submission_id );
			return $sent;
		}
		return new \WP_Error( 'tmc_mail_failed', __( 'Não foi possível enviar o e-mail de agradecimento.', 'tainacan-metadata-crowdsource' ) );
	}

	/**
	 * Sanitiza um identificador de submissão (UUID v4 hex/hífen).
	 *
	 * @param string $submission_id Valor recebido.
	 * @return string
	 */
	private function sanitize_submission_id( $submission_id ) {
		$submission_id = preg_replace( '/[^a-fA-F0-9\-]/', '', (string) $submission_id );
		return substr( (string) $submission_id, 0, 64 );
	}

	/**
	 * Mensagem padrão de agradecimento.
	 *
	 * @return string
	 */
	private function default_thanks_message() {
		return __( 'Olá! Agradecemos de coração a sua colaboração com o acervo. Suas sugestões foram avaliadas pela nossa equipe. Contribuições como a sua ajudam a preservar e qualificar a memória deste acervo. Muito obrigado!', 'tainacan-metadata-crowdsource' );
	}

	/**
	 * Gera o hash de um valor (para detecção de "stale").
	 *
	 * @param string $value Valor original.
	 * @return string
	 */
	private function hash_value( $value ) {
		return hash( 'sha256', (string) $value );
	}

	/**
	 * Notifica o moderador por e-mail sobre uma nova sugestão.
	 *
	 * @param int   $suggestion_id ID da sugestão.
	 * @param array $data          Dados da sugestão.
	 * @return void
	 */
	private function notify_moderators( $suggestion_id, $data ) {
		if ( ! (int) get_option( 'tmc_notify_email', 1 ) ) {
			return;
		}

		$recipient = get_option( 'tmc_notify_to', get_option( 'admin_email' ) );
		if ( ! $recipient ) {
			return;
		}

		$item_title = get_the_title( $data['item_id'] );
		if ( ! $item_title ) {
			$item_title = '#' . $data['item_id'];
		}

		$subject = sprintf(
			/* translators: %s: título do item Tainacan. */
			__( '[Tainacan Crowdsource] Nova sugestão — %s', 'tainacan-metadata-crowdsource' ),
			$item_title
		);

		$admin_url = admin_url( 'admin.php?page=tainacan-metadata-crowdsource' );

		$metadatum_label = $data['metadatum_label'] ?? ( '#' . $data['metadatum_id'] );
		$old_value       = '' !== (string) $data['old_value'] ? $data['old_value'] : __( '(vazio)', 'tainacan-metadata-crowdsource' );
		$reason          = ! empty( $data['reason'] ) ? $data['reason'] : __( '(não informado)', 'tainacan-metadata-crowdsource' );
		$submitter       = ! empty( $data['submitter_name'] ) ? $data['submitter_name'] : __( 'anônimo', 'tainacan-metadata-crowdsource' );

		$lines = array(
			__( 'Nova sugestão de metadado recebida.', 'tainacan-metadata-crowdsource' ),
			'',
			/* translators: 1: título do item, 2: ID do item. */
			sprintf( __( 'Item: %1$s (ID %2$d)', 'tainacan-metadata-crowdsource' ), $item_title, (int) $data['item_id'] ),
			/* translators: %s: rótulo ou ID do metadado. */
			sprintf( __( 'Metadado: %s', 'tainacan-metadata-crowdsource' ), $metadatum_label ),
			/* translators: %s: valor atual do metadado. */
			sprintf( __( 'Valor atual: %s', 'tainacan-metadata-crowdsource' ), $old_value ),
			/* translators: %s: novo valor sugerido. */
			sprintf( __( 'Valor sugerido: %s', 'tainacan-metadata-crowdsource' ), $data['new_value'] ),
			/* translators: %s: motivo informado pelo colaborador. */
			sprintf( __( 'Motivo: %s', 'tainacan-metadata-crowdsource' ), $reason ),
			/* translators: %s: nome do colaborador. */
			sprintf( __( 'Colaborador: %s', 'tainacan-metadata-crowdsource' ), $submitter ),
			'',
			/* translators: %s: URL do painel de revisão. */
			sprintf( __( 'Revisar: %s', 'tainacan-metadata-crowdsource' ), $admin_url ),
		);

		wp_mail( $recipient, $subject, implode( "\n", $lines ) );
	}
}

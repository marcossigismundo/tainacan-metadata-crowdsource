<?php
/**
 * Endpoints REST do plugin.
 *
 * @package TMC
 */

namespace TMC\REST;

use TMC\SuggestionsManager;
use TMC\Security\Captcha;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra e atende os endpoints REST.
 *
 * Público:
 *   GET  /wp-json/tmc/v1/captcha      (gera desafio anti-spam local)
 *   POST /wp-json/tmc/v1/suggestions  (submete sugestões em lote)
 *
 * Admin (manage_options):
 *   GET  /wp-json/tmc/v1/suggestions
 *   POST /wp-json/tmc/v1/suggestions/{id}/approve
 *   POST /wp-json/tmc/v1/suggestions/{id}/reject
 */
class API {

	/**
	 * Limite de submissões por IP por hora (anti-flood).
	 */
	const MAX_PER_HOUR = 20;

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
	 * Registra todas as rotas REST.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'tmc/v1',
			'/captcha',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_captcha' ),
				'permission_callback' => array( $this, 'public_endpoint_permission' ),
			)
		);

		register_rest_route(
			'tmc/v1',
			'/suggestions',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit' ),
					'permission_callback' => array( $this, 'public_endpoint_permission' ),
					'args'                => array(
						'item_id'         => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'validate_callback' => array( $this, 'validate_positive_int' ),
						),
						'suggestions'     => array(
							'required' => true,
							'type'     => 'array',
						),
						'captcha_token'   => array( 'type' => 'string' ),
						'captcha_answer'  => array( 'type' => 'string' ),
						'submitter_name'  => array( 'type' => 'string' ),
						'submitter_email' => array( 'type' => 'string' ),
						'reason'          => array( 'type' => 'string' ),
						'hp'              => array( 'type' => 'string' ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_for_admin' ),
					'permission_callback' => array( $this, 'admin_permission' ),
				),
			)
		);

		register_rest_route(
			'tmc/v1',
			'/suggestions/(?P<id>\d+)/approve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'approve' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_positive_int' ),
					),
				),
			)
		);

		register_rest_route(
			'tmc/v1',
			'/suggestions/(?P<id>\d+)/reject',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reject' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_positive_int' ),
					),
				),
			)
		);

		register_rest_route(
			'tmc/v1',
			'/submissions/(?P<submission_id>[a-fA-F0-9\-]{1,64})/thank',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'thank' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'message' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Valida que o parâmetro é um inteiro positivo.
	 *
	 * @param mixed $value Valor recebido.
	 * @return bool
	 */
	public function validate_positive_int( $value ) {
		return is_numeric( $value ) && (int) $value > 0;
	}

	/**
	 * Permissão dos endpoints administrativos.
	 *
	 * @return bool
	 */
	public function admin_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permissão dos endpoints públicos (captcha + submissão).
	 *
	 * Não há capability aplicável a visitante anônimo; a proteção real é CAPTCHA
	 * local de uso único + honeypot + time-trap + rate-limit por IP, verificados
	 * no callback de submissão. Liberado apenas enquanto o recurso está habilitado.
	 *
	 * @return bool
	 */
	public function public_endpoint_permission() {
		return 1 === (int) get_option( 'tmc_enabled', 1 );
	}

	/**
	 * Gera um desafio anti-spam.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_captcha() {
		return new \WP_REST_Response( Captcha::generate(), 200 );
	}

	/**
	 * Recebe e registra sugestões em lote.
	 *
	 * @param \WP_REST_Request $request Requisição.
	 * @return \WP_REST_Response
	 */
	public function submit( \WP_REST_Request $request ) {
		$ip = $this->get_client_ip();

		// Rate limit simples: 1 submissão a cada 15s por IP.
		$rate_key = 'tmc_rate_' . md5( $ip );
		if ( get_transient( $rate_key ) ) {
			return new \WP_REST_Response(
				array( 'error' => __( 'Aguarde alguns segundos antes de enviar outra sugestão.', 'tainacan-metadata-crowdsource' ) ),
				429
			);
		}

		// Limite por hora por IP (anti-flood).
		$hour_key   = 'tmc_hcount_' . md5( $ip );
		$hour_count = (int) get_transient( $hour_key );
		if ( $hour_count >= self::MAX_PER_HOUR ) {
			return new \WP_REST_Response(
				array( 'error' => __( 'Você atingiu o limite de envios por hora. Tente novamente mais tarde.', 'tainacan-metadata-crowdsource' ) ),
				429
			);
		}

		if ( ! Captcha::verify(
			(string) $request->get_param( 'captcha_token' ),
			$request->get_param( 'captcha_answer' ),
			(string) $request->get_param( 'hp' )
		) ) {
			return new \WP_REST_Response(
				array( 'error' => __( 'Falha na verificação anti-spam. Recarregue a página e tente novamente.', 'tainacan-metadata-crowdsource' ) ),
				400
			);
		}

		$item_id     = (int) $request->get_param( 'item_id' );
		$suggestions = $request->get_param( 'suggestions' );
		if ( ! is_array( $suggestions ) || empty( $suggestions ) ) {
			return new \WP_REST_Response(
				array( 'error' => __( 'Nenhuma sugestão foi enviada.', 'tainacan-metadata-crowdsource' ) ),
				400
			);
		}

		$context = array(
			'submission_id' => wp_generate_uuid4(),
			'name'          => sanitize_text_field( (string) $request->get_param( 'submitter_name' ) ),
			'email'         => sanitize_email( (string) $request->get_param( 'submitter_email' ) ),
			'reason'        => sanitize_textarea_field( (string) $request->get_param( 'reason' ) ),
			'ip'            => $ip,
			'user_agent'    => $this->get_user_agent(),
		);

		$created = 0;
		$errors  = array();
		foreach ( $suggestions as $s ) {
			if ( ! is_array( $s ) || ! isset( $s['metadatum_id'], $s['new_value'] ) ) {
				continue;
			}
			$result = $this->manager->submit(
				$item_id,
				(int) $s['metadatum_id'],
				wp_kses_post( (string) $s['new_value'] ),
				$context
			);
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				++$created;
			}
		}

		if ( 0 === $created ) {
			$error_message = empty( $errors )
				? __( 'Não foi possível registrar as sugestões.', 'tainacan-metadata-crowdsource' )
				: implode( ' ', array_unique( $errors ) );
			return new \WP_REST_Response( array( 'error' => $error_message ), 400 );
		}

		set_transient( $rate_key, 1, 15 );
		set_transient( $hour_key, $hour_count + 1, HOUR_IN_SECONDS );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'created' => $created,
				'message' => __( 'Sugestão(ões) recebida(s)! Serão revisadas pela equipe antes de aplicadas.', 'tainacan-metadata-crowdsource' ),
			),
			201
		);
	}

	/**
	 * Lista sugestões para o admin.
	 *
	 * @param \WP_REST_Request $request Requisição.
	 * @return \WP_REST_Response
	 */
	public function list_for_admin( \WP_REST_Request $request ) {
		$status = $request->get_param( 'status' );
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );

		$items = $this->manager->list(
			array(
				'status' => $status ? sanitize_key( (string) $status ) : null,
				'limit'  => $limit > 0 ? $limit : 50,
				'offset' => max( 0, $offset ),
			)
		);
		return new \WP_REST_Response(
			array(
				'items'  => $items,
				'counts' => $this->manager->count_by_status(),
			),
			200
		);
	}

	/**
	 * Aprova uma sugestão.
	 *
	 * @param \WP_REST_Request $request Requisição.
	 * @return \WP_REST_Response
	 */
	public function approve( \WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$notes  = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		$result = $this->manager->approve( $id, get_current_user_id(), $notes );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'error' => $result->get_error_message() ), 400 );
		}
		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Rejeita uma sugestão.
	 *
	 * @param \WP_REST_Request $request Requisição.
	 * @return \WP_REST_Response
	 */
	public function reject( \WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$notes  = sanitize_textarea_field( (string) $request->get_param( 'notes' ) );
		$result = $this->manager->reject( $id, get_current_user_id(), $notes );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'error' => $result->get_error_message() ), 400 );
		}
		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Envia o agradecimento aos colaboradores de uma submissão.
	 *
	 * @param \WP_REST_Request $request Requisição.
	 * @return \WP_REST_Response
	 */
	public function thank( \WP_REST_Request $request ) {
		$submission_id = (string) $request->get_param( 'submission_id' );
		$message       = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$result        = $this->manager->thank_submission( $submission_id, $message );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( array( 'error' => $result->get_error_message() ), 400 );
		}
		return new \WP_REST_Response(
			array(
				'success' => true,
				'sent'    => (int) $result,
			),
			200
		);
	}

	/**
	 * Retorna o user agent saneado.
	 *
	 * @return string
	 */
	private function get_user_agent() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}
		return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 );
	}

	/**
	 * Retorna o IP do cliente (considerando proxies conhecidos).
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$ip  = trim( explode( ',', $raw )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '0.0.0.0';
	}
}

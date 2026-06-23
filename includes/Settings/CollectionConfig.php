<?php
/**
 * Configuração de quais metadados aceitam sugestões, por coleção.
 *
 * @package TMC
 */

namespace TMC\Settings;

use TMC\SuggestionsManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lê e grava a allowlist de metadados habilitados para crowdsourcing por coleção.
 *
 * Modelo de dados (option `tmc_collection_config`, autoload off):
 *
 *   [
 *     (int) collection_id => [
 *       'enabled'     => 0|1,            // crowdsourcing ligado/desligado na coleção
 *       'description' => 0|1,            // aceita sugestão para a "Descrição da imagem"
 *       'metadata'    => [ int, int … ], // allowlist de metadatum_ids habilitados
 *     ],
 *     …
 *   ]
 *
 * Semântica de compatibilidade: uma coleção **ausente** do mapa é tratada como
 * "não configurada" — todos os metadados públicos e a descrição são liberados,
 * exatamente como o plugin se comportava antes desta opção existir. Assim sites
 * já em produção não têm o fluxo alterado até o gestor configurar a coleção.
 *
 * Uma coleção configurada usa allowlist: metadados acrescentados à coleção depois
 * só passam a aceitar sugestões quando o gestor os habilita aqui.
 */
class CollectionConfig {

	/**
	 * Nome da option que guarda o mapa por coleção.
	 */
	const OPTION = 'tmc_collection_config';

	/**
	 * Teto de IDs de metadados guardados por coleção (sanidade contra POST forjado).
	 */
	const MAX_METADATA = 1000;

	/**
	 * Retorna o mapa completo (coleção => config), sempre como array.
	 *
	 * @return array<int,array>
	 */
	public static function get_all() {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Retorna a config de uma coleção, ou null se ela nunca foi configurada.
	 *
	 * @param int $collection_id ID da coleção Tainacan.
	 * @return array|null
	 */
	public static function get( $collection_id ) {
		$all = self::get_all();
		$cid = (int) $collection_id;
		return isset( $all[ $cid ] ) && is_array( $all[ $cid ] ) ? $all[ $cid ] : null;
	}

	/**
	 * Indica se a coleção já foi configurada pelo gestor.
	 *
	 * @param int $collection_id ID da coleção.
	 * @return bool
	 */
	public static function is_configured( $collection_id ) {
		return null !== self::get( $collection_id );
	}

	/**
	 * Indica se o crowdsourcing está ligado para a coleção.
	 *
	 * Coleção não configurada => ligada (default retrocompatível).
	 *
	 * @param int $collection_id ID da coleção.
	 * @return bool
	 */
	public static function is_collection_enabled( $collection_id ) {
		$config = self::get( $collection_id );
		if ( null === $config ) {
			return true;
		}
		return ! empty( $config['enabled'] );
	}

	/**
	 * Indica se a "Descrição da imagem" aceita sugestões na coleção.
	 *
	 * @param int $collection_id ID da coleção.
	 * @return bool
	 */
	public static function is_description_allowed( $collection_id ) {
		$config = self::get( $collection_id );
		if ( null === $config ) {
			return true;
		}
		return ! empty( $config['enabled'] ) && ! empty( $config['description'] );
	}

	/**
	 * Indica se um metadado específico aceita sugestões na coleção.
	 *
	 * @param int $collection_id ID da coleção.
	 * @param int $metadatum_id  ID do metadado (0 = descrição da imagem).
	 * @return bool
	 */
	public static function is_metadatum_allowed( $collection_id, $metadatum_id ) {
		$metadatum_id = (int) $metadatum_id;

		if ( SuggestionsManager::DESCRIPTION_ID === $metadatum_id ) {
			return self::is_description_allowed( $collection_id );
		}

		$config = self::get( $collection_id );
		if ( null === $config ) {
			return true;
		}
		if ( empty( $config['enabled'] ) ) {
			return false;
		}
		$allow = isset( $config['metadata'] ) && is_array( $config['metadata'] ) ? $config['metadata'] : array();
		return in_array( $metadatum_id, array_map( 'intval', $allow ), true );
	}

	/**
	 * Sanitiza o array vindo do formulário antes de gravar (sanitize_callback).
	 *
	 * Recebe apenas as coleções presentes no formulário submetido (marcador
	 * `__collections__`) e funde com a config já gravada — assim salvar uma
	 * coleção não apaga as demais (cada coleção tem seu próprio formulário).
	 *
	 * **Idempotência (importante):** ao criar a option pela primeira vez, o
	 * WordPress aplica `sanitize_option` duas vezes (`update_option` chama
	 * `add_option`, e ambos saneiam). Na segunda passada o valor recebido já é
	 * a saída normalizada da primeira — sem o marcador `__collections__`. Por
	 * isso, quando o marcador está ausente, tratamos a entrada como mapa já
	 * final: apenas revalida e devolve, sem reler/mesclar (o que zeraria a
	 * config recém-montada e fazia a seleção "não salvar").
	 *
	 * @param mixed $raw Valor cru do POST (1ª passada) ou mapa já saneado (2ª).
	 * @return array<int,array>
	 */
	public static function sanitize( $raw ) {
		if ( ! is_array( $raw ) ) {
			return self::get_all();
		}

		// 2ª passada (reentrante): valor já normalizado, sem o marcador.
		if ( ! isset( $raw['__collections__'] ) ) {
			return self::normalize_map( $raw );
		}

		// 1ª passada: vem do formulário (um <form> por coleção). Funde as
		// coleções presentes na página com a config já gravada das demais.
		$out  = self::get_all();
		$page = array_map( 'absint', (array) $raw['__collections__'] );

		foreach ( $page as $cid ) {
			if ( $cid <= 0 ) {
				continue;
			}
			$entry       = ( isset( $raw[ $cid ] ) && is_array( $raw[ $cid ] ) ) ? $raw[ $cid ] : array();
			$out[ $cid ] = self::normalize_entry( $entry );
		}

		return self::normalize_map( $out );
	}

	/**
	 * Normaliza um mapa coleção => config (descarta chaves não-numéricas).
	 *
	 * @param mixed $map Mapa a normalizar.
	 * @return array<int,array>
	 */
	private static function normalize_map( $map ) {
		$out = array();
		if ( ! is_array( $map ) ) {
			return $out;
		}
		foreach ( $map as $cid => $entry ) {
			$cid = (int) $cid;
			if ( $cid <= 0 || ! is_array( $entry ) ) {
				continue;
			}
			$out[ $cid ] = self::normalize_entry( $entry );
		}
		return $out;
	}

	/**
	 * Normaliza uma entrada de coleção em { enabled, description, metadata }.
	 *
	 * @param array $entry Entrada crua.
	 * @return array{enabled:int,description:int,metadata:array<int,int>}
	 */
	private static function normalize_entry( $entry ) {
		$metadata = array();
		if ( ! empty( $entry['metadata'] ) && is_array( $entry['metadata'] ) ) {
			$metadata = array_values(
				array_unique(
					array_filter( array_map( 'absint', $entry['metadata'] ) )
				)
			);
			$metadata = array_slice( $metadata, 0, self::MAX_METADATA );
		}
		return array(
			'enabled'     => empty( $entry['enabled'] ) ? 0 : 1,
			'description' => empty( $entry['description'] ) ? 0 : 1,
			'metadata'    => $metadata,
		);
	}
}

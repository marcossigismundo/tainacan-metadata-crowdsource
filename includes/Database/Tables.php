<?php
/**
 * Schema do plugin.
 *
 * @package TMC
 */

namespace TMC\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cria e remove a tabela única do plugin: wp_tmc_suggestions.
 */
class Tables {

	/**
	 * Cria (ou atualiza, via dbDelta) a tabela e grava a versão do schema.
	 *
	 * @return void
	 */
	public static function create() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = $wpdb->prefix . 'tmc_suggestions';

		$sql = "CREATE TABLE $table (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			submission_id varchar(64) DEFAULT NULL,
			item_id bigint(20) UNSIGNED NOT NULL,
			collection_id bigint(20) UNSIGNED DEFAULT NULL,
			metadatum_id bigint(20) UNSIGNED NOT NULL,
			metadatum_slug varchar(255) DEFAULT NULL,
			metadatum_label varchar(255) DEFAULT NULL,
			old_value longtext DEFAULT NULL,
			old_value_hash varchar(64) DEFAULT NULL,
			new_value longtext NOT NULL,
			reason text DEFAULT NULL,
			submitter_name varchar(255) DEFAULT NULL,
			submitter_email varchar(255) DEFAULT NULL,
			submitter_ip varchar(45) DEFAULT NULL,
			submitter_user_agent varchar(500) DEFAULT NULL,
			status varchar(20) DEFAULT 'pending',
			reviewed_by bigint(20) UNSIGNED DEFAULT NULL,
			reviewed_at datetime DEFAULT NULL,
			review_notes text DEFAULT NULL,
			thanked_at datetime DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY submission_id (submission_id),
			KEY item_id (item_id),
			KEY metadatum_id (metadatum_id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

		dbDelta( $sql );

		update_option( 'tmc_db_version', TMC_VERSION );
	}

	/**
	 * Executa o upgrade do schema quando a versão gravada difere da atual.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'tmc_db_version' ) !== TMC_VERSION ) {
			self::create();
		}
	}

	/**
	 * Remove a tabela e a option de versão do schema.
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;
		$table = $wpdb->prefix . 'tmc_suggestions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Drop of the plugin's own table; table name is $wpdb->prefix (trusted); DROP cannot use prepared placeholders.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( 'tmc_db_version' );
	}
}

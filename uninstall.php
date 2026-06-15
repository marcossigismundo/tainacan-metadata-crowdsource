<?php
/**
 * Limpeza ao desinstalar o plugin: remove a tabela e todas as options.
 *
 * @package TMC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tmc_table = $wpdb->prefix . 'tmc_suggestions';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Uninstall cleanup of the plugin's own table; table name is $wpdb->prefix (trusted); DROP cannot use prepared placeholders.
$wpdb->query( "DROP TABLE IF EXISTS {$tmc_table}" );

$tmc_options = array(
	'tmc_db_version',
	'tmc_enabled',
	'tmc_notify_email',
	'tmc_notify_to',
);

foreach ( $tmc_options as $tmc_option ) {
	delete_option( $tmc_option );
}

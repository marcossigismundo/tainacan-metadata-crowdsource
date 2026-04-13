<?php
namespace TMC\Database;

/**
 * Schema do plugin. Uma única tabela: wp_tmc_suggestions.
 */
class Tables {
    public static function create() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table = $wpdb->prefix . 'tmc_suggestions';

        $sql = "CREATE TABLE $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
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
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY item_id (item_id),
            KEY metadatum_id (metadatum_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql);

        update_option('tmc_db_version', TMC_VERSION);
    }

    public static function drop() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}tmc_suggestions");
        delete_option('tmc_db_version');
    }
}

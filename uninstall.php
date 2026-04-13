<?php
/**
 * Executado quando o plugin é desinstalado via WP Admin.
 * Remove a tabela e todas as options associadas.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}tmc_suggestions");

$options = [
    'tmc_db_version',
    'tmc_enabled',
    'tmc_notify_email',
    'tmc_notify_to',
    'tmc_hcaptcha_site_key',
    'tmc_hcaptcha_secret',
];

foreach ($options as $opt) {
    delete_option($opt);
}

<?php
namespace TMC\Core;

use TMC\Database\Tables;

class Activator {
    public static function activate() {
        Tables::create();

        // Defaults sensatos para as opções do plugin.
        add_option('tmc_enabled', 1);
        add_option('tmc_notify_email', 1);
        add_option('tmc_notify_to', get_option('admin_email'));
        add_option('tmc_hcaptcha_site_key', '');
        add_option('tmc_hcaptcha_secret', '');
    }
}

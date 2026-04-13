<?php
namespace TMC\Core;

use TMC\SuggestionsManager;
use TMC\REST\API;
use TMC\Frontend\Shortcode;
use TMC\Admin\AdminPage;

/**
 * Bootstrap do plugin: conecta todos os hooks e componentes.
 */
class Plugin {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot() {
        // REST API
        $api = new API();
        add_action('rest_api_init', [$api, 'register_routes']);

        // Shortcode público
        (new Shortcode())->register();

        // Admin
        if (is_admin()) {
            (new AdminPage())->register();
        }

        // Detecção de sugestões stale ao salvar um item Tainacan.
        // Tainacan usa custom post types com prefixo 'tnc_col_' — capturamos qualquer um.
        add_action('save_post', [$this, 'maybe_mark_stale'], 20, 2);
    }

    /**
     * Chamado em save_post. Se o post for de um tipo Tainacan (tnc_col_*),
     * reavalia sugestões pendentes do item para marcar stale quando o valor mudou.
     */
    public function maybe_mark_stale($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!$post instanceof \WP_Post) return;
        if (strpos($post->post_type, 'tnc_col_') !== 0) return;

        (new SuggestionsManager())->mark_stale_for_item($post_id);
    }
}

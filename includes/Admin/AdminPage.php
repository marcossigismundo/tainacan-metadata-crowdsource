<?php
namespace TMC\Admin;

use TMC\SuggestionsManager;

/**
 * Página de administração: lista/modera sugestões e exibe configurações.
 */
class AdminPage {
    private $manager;

    public function __construct() {
        $this->manager = new SuggestionsManager();
    }

    public function register() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu() {
        $hook = add_menu_page(
            'Tainacan Crowdsource',
            'Crowdsource',
            'manage_options',
            'tainacan-metadata-crowdsource',
            [$this, 'render_page'],
            'dashicons-feedback',
            56
        );

        // Badge de sugestões pendentes
        add_filter('admin_menu', function () use ($hook) {
            global $menu;
            $counts = $this->manager->count_by_status();
            $pending = (int) ($counts['pending'] ?? 0);
            if ($pending > 0) {
                foreach ($menu as &$item) {
                    if (isset($item[2]) && $item[2] === 'tainacan-metadata-crowdsource') {
                        $item[0] .= sprintf(' <span class="awaiting-mod count-%d"><span>%d</span></span>', $pending, $pending);
                        break;
                    }
                }
            }
        }, 99);
    }

    public function register_settings() {
        register_setting('tmc_settings', 'tmc_enabled', ['type' => 'boolean', 'default' => 1]);
        register_setting('tmc_settings', 'tmc_notify_email', ['type' => 'boolean', 'default' => 1]);
        register_setting('tmc_settings', 'tmc_notify_to', ['type' => 'string', 'sanitize_callback' => 'sanitize_email']);
        register_setting('tmc_settings', 'tmc_hcaptcha_site_key', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('tmc_settings', 'tmc_hcaptcha_secret', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_tainacan-metadata-crowdsource') return;

        wp_enqueue_style('tmc-admin', TMC_PLUGIN_URL . 'assets/css/admin.css', [], TMC_VERSION);
        wp_enqueue_script('tmc-admin', TMC_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], TMC_VERSION, true);
        wp_localize_script('tmc-admin', 'tmcAdmin', [
            'restUrl' => esc_url_raw(rest_url('tmc/v1/')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }

    public function render_page() {
        $tab = $_GET['tab'] ?? 'suggestions';
        $tab = in_array($tab, ['suggestions', 'settings'], true) ? $tab : 'suggestions';

        $counts = $this->manager->count_by_status();
        ?>
        <div class="wrap tmc-wrap">
            <h1>Tainacan Metadata Crowdsource</h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=tainacan-metadata-crowdsource&tab=suggestions" class="nav-tab <?php echo $tab === 'suggestions' ? 'nav-tab-active' : ''; ?>">
                    Sugestões
                    <?php if ($counts['pending']): ?>
                        <span class="tmc-badge"><?php echo (int) $counts['pending']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="?page=tainacan-metadata-crowdsource&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">Configurações</a>
            </h2>

            <?php if ($tab === 'suggestions'): ?>
                <?php $this->render_suggestions_tab($counts); ?>
            <?php else: ?>
                <?php $this->render_settings_tab(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_suggestions_tab($counts) {
        $status_filter = $_GET['status'] ?? 'pending';
        $status_filter = in_array($status_filter, ['pending', 'approved', 'rejected', 'stale', 'all'], true) ? $status_filter : 'pending';

        $list = $this->manager->list([
            'status' => $status_filter === 'all' ? null : $status_filter,
            'limit'  => 100,
        ]);
        ?>
        <ul class="subsubsub">
            <li><a href="?page=tainacan-metadata-crowdsource&status=pending"  class="<?php echo $status_filter === 'pending' ? 'current' : ''; ?>">Pendentes <span class="count">(<?php echo (int) $counts['pending']; ?>)</span></a> |</li>
            <li><a href="?page=tainacan-metadata-crowdsource&status=stale"    class="<?php echo $status_filter === 'stale' ? 'current' : ''; ?>">Desatualizadas <span class="count">(<?php echo (int) $counts['stale']; ?>)</span></a> |</li>
            <li><a href="?page=tainacan-metadata-crowdsource&status=approved" class="<?php echo $status_filter === 'approved' ? 'current' : ''; ?>">Aprovadas <span class="count">(<?php echo (int) $counts['approved']; ?>)</span></a> |</li>
            <li><a href="?page=tainacan-metadata-crowdsource&status=rejected" class="<?php echo $status_filter === 'rejected' ? 'current' : ''; ?>">Rejeitadas <span class="count">(<?php echo (int) $counts['rejected']; ?>)</span></a> |</li>
            <li><a href="?page=tainacan-metadata-crowdsource&status=all"      class="<?php echo $status_filter === 'all' ? 'current' : ''; ?>">Todas</a></li>
        </ul>

        <?php if (empty($list)): ?>
            <p class="tmc-empty">Nenhuma sugestão neste filtro.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Metadado</th>
                        <th>Valor atual</th>
                        <th>Valor sugerido</th>
                        <th>Motivo</th>
                        <th>Colaborador</th>
                        <th>Data</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list as $s): ?>
                        <tr data-suggestion-id="<?php echo (int) $s->id; ?>" class="tmc-row tmc-row-<?php echo esc_attr($s->status); ?>">
                            <td>
                                <strong><?php echo esc_html(get_the_title($s->item_id) ?: ('#' . $s->item_id)); ?></strong>
                                <div><a href="<?php echo esc_url(get_permalink($s->item_id)); ?>" target="_blank">Ver item</a></div>
                            </td>
                            <td><?php echo esc_html($s->metadatum_label ?: ('#' . $s->metadatum_id)); ?></td>
                            <td class="tmc-val tmc-val-old"><?php echo esc_html($s->old_value ?: '(vazio)'); ?></td>
                            <td class="tmc-val tmc-val-new"><?php echo esc_html($s->new_value); ?></td>
                            <td><?php echo esc_html($s->reason ?: '—'); ?></td>
                            <td>
                                <?php echo esc_html($s->submitter_name ?: 'anônimo'); ?>
                                <?php if ($s->submitter_email): ?>
                                    <div><small><?php echo esc_html($s->submitter_email); ?></small></div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html(mysql2date('d/m/Y H:i', $s->created_at)); ?></td>
                            <td>
                                <span class="tmc-status tmc-status-<?php echo esc_attr($s->status); ?>"><?php echo esc_html($this->status_label($s->status)); ?></span>
                                <?php if ($s->status === 'stale'): ?>
                                    <div><small>Valor original mudou desde a sugestão.</small></div>
                                <?php endif; ?>
                            </td>
                            <td class="tmc-actions">
                                <?php if (in_array($s->status, ['pending', 'stale'], true)): ?>
                                    <button class="button button-primary tmc-approve">Aprovar</button>
                                    <button class="button tmc-reject">Rejeitar</button>
                                <?php else: ?>
                                    <em>—</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    private function render_settings_tab() {
        ?>
        <form method="post" action="options.php" class="tmc-settings">
            <?php settings_fields('tmc_settings'); ?>

            <table class="form-table">
                <tr>
                    <th><label for="tmc_enabled">Habilitar sugestões</label></th>
                    <td><input type="checkbox" id="tmc_enabled" name="tmc_enabled" value="1" <?php checked(1, (int) get_option('tmc_enabled', 1)); ?>> Aceitar novas sugestões do público</td>
                </tr>
                <tr>
                    <th><label for="tmc_notify_email">Notificar por e-mail</label></th>
                    <td><input type="checkbox" id="tmc_notify_email" name="tmc_notify_email" value="1" <?php checked(1, (int) get_option('tmc_notify_email', 1)); ?>> Enviar e-mail ao administrador quando uma nova sugestão chegar</td>
                </tr>
                <tr>
                    <th><label for="tmc_notify_to">E-mail do moderador</label></th>
                    <td><input type="email" id="tmc_notify_to" name="tmc_notify_to" class="regular-text" value="<?php echo esc_attr(get_option('tmc_notify_to', get_option('admin_email'))); ?>"></td>
                </tr>
                <tr>
                    <th colspan="2"><h2>hCaptcha</h2><p class="description">Crie uma conta gratuita em <a href="https://www.hcaptcha.com" target="_blank">hcaptcha.com</a> para obter as chaves.</p></th>
                </tr>
                <tr>
                    <th><label for="tmc_hcaptcha_site_key">Site Key</label></th>
                    <td><input type="text" id="tmc_hcaptcha_site_key" name="tmc_hcaptcha_site_key" class="regular-text" value="<?php echo esc_attr(get_option('tmc_hcaptcha_site_key', '')); ?>"></td>
                </tr>
                <tr>
                    <th><label for="tmc_hcaptcha_secret">Secret Key</label></th>
                    <td><input type="password" id="tmc_hcaptcha_secret" name="tmc_hcaptcha_secret" class="regular-text" value="<?php echo esc_attr(get_option('tmc_hcaptcha_secret', '')); ?>"></td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>
        <h2>Como usar</h2>
        <p>Insira o shortcode abaixo em qualquer página ou template para exibir o formulário de sugestões para um item:</p>
        <pre><code>[tmc_suggest_form item_id="123"]</code></pre>
        <p>Substitua <code>123</code> pelo ID do item Tainacan.</p>
        <?php
    }

    private function status_label($status) {
        return [
            'pending'  => 'Pendente',
            'approved' => 'Aprovada',
            'rejected' => 'Rejeitada',
            'stale'    => 'Desatualizada',
        ][$status] ?? $status;
    }
}

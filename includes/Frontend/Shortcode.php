<?php
namespace TMC\Frontend;

use TMC\SuggestionsManager;

/**
 * Shortcode [tmc_suggest_form item_id="X"] renderiza o formulário público.
 */
class Shortcode {
    private $manager;

    public function __construct() {
        $this->manager = new SuggestionsManager();
    }

    public function register() {
        add_shortcode('tmc_suggest_form', [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_assets() {
        wp_register_style(
            'tmc-public',
            TMC_PLUGIN_URL . 'assets/css/public.css',
            [],
            TMC_VERSION
        );
        wp_register_script(
            'hcaptcha',
            'https://js.hcaptcha.com/1/api.js',
            [],
            null,
            true
        );
        wp_register_script(
            'tmc-public',
            TMC_PLUGIN_URL . 'assets/js/public.js',
            ['hcaptcha'],
            TMC_VERSION,
            true
        );
    }

    public function render($atts) {
        if (!(int) get_option('tmc_enabled', 1)) {
            return '<div class="tmc-disabled">Sugestões estão desabilitadas.</div>';
        }

        $atts = shortcode_atts([
            'item_id' => 0,
            'title'   => 'Sugerir correção nos metadados',
        ], $atts, 'tmc_suggest_form');

        $item_id = (int) $atts['item_id'];
        if ($item_id <= 0) {
            return '<div class="tmc-error">Item inválido.</div>';
        }

        $metadata = $this->manager->get_item_metadata_for_form($item_id);
        if (empty($metadata)) {
            return '<div class="tmc-empty">Este item não possui metadados disponíveis para sugestão no momento.</div>';
        }

        $site_key = trim((string) get_option('tmc_hcaptcha_site_key', ''));
        if (empty($site_key)) {
            return '<div class="tmc-error">Formulário indisponível: hCaptcha não configurado.</div>';
        }

        wp_enqueue_style('tmc-public');
        wp_enqueue_script('tmc-public');
        wp_localize_script('tmc-public', 'tmcConfig', [
            'restUrl' => esc_url_raw(rest_url('tmc/v1/suggestions')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);

        ob_start();
        ?>
        <div class="tmc-widget" data-item-id="<?php echo esc_attr($item_id); ?>">
            <h3 class="tmc-title"><?php echo esc_html($atts['title']); ?></h3>
            <p class="tmc-intro">
                Identificou uma informação incorreta ou incompleta? Sugira uma correção.
                Cada campo é uma sugestão separada. Preencha apenas os campos que deseja sugerir.
                Sua contribuição será revisada pela equipe antes de ser aplicada.
            </p>

            <form class="tmc-form" novalidate>
                <div class="tmc-fields">
                    <?php foreach ($metadata as $md): ?>
                        <div class="tmc-field">
                            <div class="tmc-field-head">
                                <strong class="tmc-field-label"><?php echo esc_html($md['label']); ?></strong>
                                <span class="tmc-field-current">Valor atual: <em><?php echo esc_html($md['current'] ?: '(vazio)'); ?></em></span>
                            </div>
                            <textarea
                                class="tmc-field-input"
                                data-metadatum-id="<?php echo esc_attr($md['metadatum_id']); ?>"
                                placeholder="Sugerir novo valor..."
                                rows="2"></textarea>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="tmc-meta">
                    <label>
                        <span>Seu nome (opcional)</span>
                        <input type="text" class="tmc-input" name="submitter_name" maxlength="255">
                    </label>
                    <label>
                        <span>Seu e-mail (opcional)</span>
                        <input type="email" class="tmc-input" name="submitter_email" maxlength="255">
                    </label>
                    <label>
                        <span>Motivo da correção (opcional)</span>
                        <textarea class="tmc-input" name="reason" rows="3" maxlength="2000" placeholder="Ex: documento original indica outra data"></textarea>
                    </label>
                </div>

                <div class="h-captcha" data-sitekey="<?php echo esc_attr($site_key); ?>"></div>

                <div class="tmc-actions">
                    <button type="submit" class="tmc-submit">Enviar sugestões</button>
                    <div class="tmc-feedback" role="status" aria-live="polite"></div>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}

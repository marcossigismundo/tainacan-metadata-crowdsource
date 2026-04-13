<?php
namespace TMC\REST;

use TMC\SuggestionsManager;

/**
 * Endpoints REST do plugin.
 *
 * Público:
 *   POST /wp-json/tmc/v1/suggestions           (submit, com hCaptcha)
 *
 * Admin (manage_options):
 *   GET  /wp-json/tmc/v1/suggestions           (listar)
 *   POST /wp-json/tmc/v1/suggestions/{id}/approve
 *   POST /wp-json/tmc/v1/suggestions/{id}/reject
 */
class API {
    private $manager;

    public function __construct() {
        $this->manager = new SuggestionsManager();
    }

    public function register_routes() {
        register_rest_route('tmc/v1', '/suggestions', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'submit'],
                'permission_callback' => '__return_true',
                'args' => [
                    'item_id'      => ['required' => true, 'type' => 'integer'],
                    'metadatum_id' => ['required' => true, 'type' => 'integer'],
                    'new_value'    => ['required' => true, 'type' => 'string'],
                ],
            ],
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'list_for_admin'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route('tmc/v1', '/suggestions/(?P<id>\d+)/approve', [
            'methods'             => 'POST',
            'callback'            => [$this, 'approve'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route('tmc/v1', '/suggestions/(?P<id>\d+)/reject', [
            'methods'             => 'POST',
            'callback'            => [$this, 'reject'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);
    }

    public function admin_permission() {
        return current_user_can('manage_options');
    }

    public function submit(\WP_REST_Request $request) {
        if (!(int) get_option('tmc_enabled', 1)) {
            return new \WP_REST_Response(['error' => 'Sugestões desabilitadas.'], 403);
        }

        $ip = $this->get_client_ip();

        // Rate limit simples: 1 submissão a cada 15s por IP
        $rate_key = 'tmc_rate_' . md5($ip);
        if (get_transient($rate_key)) {
            return new \WP_REST_Response(['error' => 'Aguarde alguns segundos antes de enviar outra sugestão.'], 429);
        }

        $captcha_token = (string) $request->get_param('hcaptcha_token');
        if (!$this->verify_hcaptcha($captcha_token, $ip)) {
            return new \WP_REST_Response(['error' => 'Falha na verificação de CAPTCHA.'], 400);
        }

        $context = [
            'name'       => sanitize_text_field((string) $request->get_param('submitter_name')),
            'email'      => sanitize_email((string) $request->get_param('submitter_email')),
            'reason'     => sanitize_textarea_field((string) $request->get_param('reason')),
            'ip'         => $ip,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : '',
        ];

        $result = $this->manager->submit(
            (int) $request->get_param('item_id'),
            (int) $request->get_param('metadatum_id'),
            wp_kses_post((string) $request->get_param('new_value')),
            $context
        );

        if (is_wp_error($result)) {
            return new \WP_REST_Response(['error' => $result->get_error_message()], 400);
        }

        set_transient($rate_key, 1, 15);

        return new \WP_REST_Response([
            'success'       => true,
            'suggestion_id' => $result,
            'message'       => 'Sugestão recebida! Ela será revisada pela equipe.',
        ], 201);
    }

    public function list_for_admin(\WP_REST_Request $request) {
        $status = $request->get_param('status');
        $items = $this->manager->list([
            'status' => $status,
            'limit'  => (int) ($request->get_param('limit') ?: 50),
            'offset' => (int) ($request->get_param('offset') ?: 0),
        ]);
        return new \WP_REST_Response([
            'items'  => $items,
            'counts' => $this->manager->count_by_status(),
        ], 200);
    }

    public function approve(\WP_REST_Request $request) {
        $id = (int) $request->get_param('id');
        $notes = sanitize_textarea_field((string) $request->get_param('notes'));
        $result = $this->manager->approve($id, get_current_user_id(), $notes);
        if (is_wp_error($result)) {
            return new \WP_REST_Response(['error' => $result->get_error_message()], 400);
        }
        return new \WP_REST_Response(['success' => true], 200);
    }

    public function reject(\WP_REST_Request $request) {
        $id = (int) $request->get_param('id');
        $notes = sanitize_textarea_field((string) $request->get_param('notes'));
        $result = $this->manager->reject($id, get_current_user_id(), $notes);
        if (is_wp_error($result)) {
            return new \WP_REST_Response(['error' => $result->get_error_message()], 400);
        }
        return new \WP_REST_Response(['success' => true], 200);
    }

    private function verify_hcaptcha($token, $ip) {
        if (empty($token)) return false;

        $secret = trim((string) get_option('tmc_hcaptcha_secret', ''));
        if (empty($secret)) return false;

        $response = wp_remote_post('https://api.hcaptcha.com/siteverify', [
            'timeout' => 10,
            'body' => [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ],
        ]);

        if (is_wp_error($response)) return false;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) && !empty($body['success']);
    }

    private function get_client_ip() {
        $candidates = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }
}

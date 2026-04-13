<?php
namespace TMC;

/**
 * Gerencia sugestões de crowdsourcing para metadados de itens Tainacan.
 *
 * Fluxo: visitante submete sugestão → fica pending → equipe aprova/rejeita.
 * Ao aprovar, o valor sugerido substitui o metadado no Tainacan.
 * Se o valor original mudar antes da revisão, a sugestão é marcada
 * como 'stale' para alertar o revisor.
 */
class SuggestionsManager {
    private $wpdb;
    private $table;

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_STALE    = 'stale';

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tmc_suggestions';
    }

    /**
     * Registra uma nova sugestão. Retorna o ID ou WP_Error.
     */
    public function submit($item_id, $metadatum_id, $new_value, $context = []) {
        $item_id      = (int) $item_id;
        $metadatum_id = (int) $metadatum_id;
        $new_value    = is_array($new_value) ? implode('||', $new_value) : (string) $new_value;

        if ($item_id <= 0 || $metadatum_id <= 0) {
            return new \WP_Error('tmc_invalid_params', 'Item e metadado são obrigatórios.');
        }
        if (trim($new_value) === '') {
            return new \WP_Error('tmc_empty_value', 'O novo valor não pode ser vazio.');
        }
        if (!get_post($item_id)) {
            return new \WP_Error('tmc_item_not_found', 'Item não encontrado.');
        }

        $current = $this->get_current_metadatum_value($item_id, $metadatum_id);

        $data = [
            'item_id'              => $item_id,
            'collection_id'        => $current['collection_id'] ?? null,
            'metadatum_id'         => $metadatum_id,
            'metadatum_slug'       => $current['slug'] ?? null,
            'metadatum_label'      => $current['label'] ?? null,
            'old_value'            => $current['value_text'] ?? '',
            'old_value_hash'       => $this->hash_value($current['value_text'] ?? ''),
            'new_value'            => $new_value,
            'reason'               => isset($context['reason']) ? substr((string) $context['reason'], 0, 2000) : null,
            'submitter_name'       => isset($context['name']) ? substr((string) $context['name'], 0, 255) : null,
            'submitter_email'      => isset($context['email']) ? substr((string) $context['email'], 0, 255) : null,
            'submitter_ip'         => isset($context['ip']) ? substr((string) $context['ip'], 0, 45) : null,
            'submitter_user_agent' => isset($context['user_agent']) ? substr((string) $context['user_agent'], 0, 500) : null,
            'status'               => self::STATUS_PENDING,
        ];

        $inserted = $this->wpdb->insert($this->table, $data);
        if ($inserted === false) {
            return new \WP_Error('tmc_db_error', 'Erro ao registrar sugestão.');
        }

        $suggestion_id = (int) $this->wpdb->insert_id;

        do_action('tmc_suggestion_submitted', $suggestion_id, $data);
        $this->notify_moderators($suggestion_id, $data);

        return $suggestion_id;
    }

    public function get($suggestion_id) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", (int) $suggestion_id)
        );
    }

    public function list($args = []) {
        $defaults = [
            'status'  => null,
            'item_id' => null,
            'limit'   => 50,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        ];
        $args = array_merge($defaults, $args);

        $where = ['1=1'];
        $params = [];

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['item_id'])) {
            $where[] = 'item_id = %d';
            $params[] = (int) $args['item_id'];
        }

        $orderby = in_array($args['orderby'], ['created_at', 'status', 'item_id'], true) ? $args['orderby'] : 'created_at';
        $order   = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $limit   = max(1, min(200, (int) $args['limit']));
        $offset  = max(0, (int) $args['offset']);

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where)
             . " ORDER BY {$orderby} {$order} LIMIT {$limit} OFFSET {$offset}";

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        return $this->wpdb->get_results($sql);
    }

    public function count_by_status() {
        $rows = $this->wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$this->table} GROUP BY status");
        $out = [
            self::STATUS_PENDING  => 0,
            self::STATUS_APPROVED => 0,
            self::STATUS_REJECTED => 0,
            self::STATUS_STALE    => 0,
        ];
        foreach ($rows ?: [] as $r) {
            $out[$r->status] = (int) $r->total;
        }
        return $out;
    }

    public function approve($suggestion_id, $reviewer_user_id = null, $notes = null) {
        $suggestion = $this->get($suggestion_id);
        if (!$suggestion) {
            return new \WP_Error('tmc_not_found', 'Sugestão não encontrada.');
        }
        if ($suggestion->status === self::STATUS_APPROVED) {
            return new \WP_Error('tmc_already_approved', 'Sugestão já aprovada.');
        }

        $result = $this->apply_to_tainacan($suggestion);
        if (is_wp_error($result)) {
            return $result;
        }

        $this->wpdb->update(
            $this->table,
            [
                'status'       => self::STATUS_APPROVED,
                'reviewed_by'  => $reviewer_user_id ? (int) $reviewer_user_id : null,
                'reviewed_at'  => current_time('mysql'),
                'review_notes' => $notes ? substr((string) $notes, 0, 2000) : null,
            ],
            ['id' => (int) $suggestion_id]
        );

        do_action('tmc_suggestion_approved', $suggestion_id, $suggestion);
        return true;
    }

    public function reject($suggestion_id, $reviewer_user_id = null, $notes = null) {
        $suggestion = $this->get($suggestion_id);
        if (!$suggestion) {
            return new \WP_Error('tmc_not_found', 'Sugestão não encontrada.');
        }

        $this->wpdb->update(
            $this->table,
            [
                'status'       => self::STATUS_REJECTED,
                'reviewed_by'  => $reviewer_user_id ? (int) $reviewer_user_id : null,
                'reviewed_at'  => current_time('mysql'),
                'review_notes' => $notes ? substr((string) $notes, 0, 2000) : null,
            ],
            ['id' => (int) $suggestion_id]
        );

        do_action('tmc_suggestion_rejected', $suggestion_id, $suggestion);
        return true;
    }

    /**
     * Marca como 'stale' sugestões pendentes cujo valor original mudou desde a submissão.
     * Chamado via save_post dos tipos de item Tainacan.
     */
    public function mark_stale_for_item($item_id) {
        $item_id = (int) $item_id;
        if ($item_id <= 0) return 0;

        $pending = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, metadatum_id, old_value_hash FROM {$this->table}
             WHERE item_id = %d AND status = %s",
            $item_id,
            self::STATUS_PENDING
        ));

        if (empty($pending)) return 0;

        $marked = 0;
        foreach ($pending as $row) {
            $current = $this->get_current_metadatum_value($item_id, (int) $row->metadatum_id);
            $current_hash = $this->hash_value($current['value_text'] ?? '');
            if ($current_hash !== $row->old_value_hash) {
                $this->wpdb->update(
                    $this->table,
                    ['status' => self::STATUS_STALE],
                    ['id' => (int) $row->id]
                );
                $marked++;
            }
        }
        return $marked;
    }

    /**
     * Retorna metadados editáveis de um item (para o formulário público).
     */
    public function get_item_metadata_for_form($item_id) {
        $item_id = (int) $item_id;
        if (!class_exists('\Tainacan\Repositories\Items')) {
            return [];
        }

        try {
            $items_repo = \Tainacan\Repositories\Items::get_instance();
            $item = $items_repo->fetch($item_id);
            if (!$item || is_wp_error($item)) return [];

            $item_metadata = $item->get_metadata();
            if (empty($item_metadata)) return [];

            $out = [];
            foreach ($item_metadata as $im) {
                $metadatum = $im->get_metadatum();
                if (!$metadatum) continue;

                $value = $im->get_value();
                $value_text = is_array($value)
                    ? implode(', ', array_map('strval', $value))
                    : (string) $value;

                $out[] = [
                    'metadatum_id' => $metadatum->get_id(),
                    'slug'         => $metadatum->get_slug(),
                    'label'        => $metadatum->get_name(),
                    'current'      => $value_text,
                    'is_multiple'  => $metadatum->is_multiple(),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function get_current_metadatum_value($item_id, $metadatum_id) {
        $out = ['value_text' => '', 'label' => null, 'slug' => null, 'collection_id' => null];

        if (!class_exists('\Tainacan\Repositories\Items')) return $out;

        try {
            $items_repo = \Tainacan\Repositories\Items::get_instance();
            $item = $items_repo->fetch((int) $item_id);
            if (!$item || is_wp_error($item)) return $out;

            $out['collection_id'] = $item->get_collection_id();

            $metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
            $metadatum = $metadata_repo->fetch((int) $metadatum_id);
            if (!$metadatum || is_wp_error($metadatum)) return $out;

            $out['slug']  = $metadatum->get_slug();
            $out['label'] = $metadatum->get_name();

            $im_repo = \Tainacan\Repositories\Item_Metadata::get_instance();
            $im = new \Tainacan\Entities\Item_Metadata_Entity($item, $metadatum);
            $im = $im_repo->fetch($im);

            if ($im) {
                $value = $im->get_value();
                $out['value_text'] = is_array($value)
                    ? implode('||', array_map('strval', $value))
                    : (string) $value;
            }
        } catch (\Throwable $e) {
            // silencia
        }

        return $out;
    }

    private function apply_to_tainacan($suggestion) {
        if (!class_exists('\Tainacan\Repositories\Items')) {
            return new \WP_Error('tmc_tainacan_missing', 'Tainacan não está disponível.');
        }

        try {
            $items_repo = \Tainacan\Repositories\Items::get_instance();
            $item = $items_repo->fetch((int) $suggestion->item_id);
            if (!$item || is_wp_error($item)) {
                return new \WP_Error('tmc_item_missing', 'Item Tainacan não encontrado.');
            }

            $metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
            $metadatum = $metadata_repo->fetch((int) $suggestion->metadatum_id);
            if (!$metadatum || is_wp_error($metadatum)) {
                return new \WP_Error('tmc_metadatum_missing', 'Metadado não encontrado.');
            }

            $im_repo = \Tainacan\Repositories\Item_Metadata::get_instance();
            $im = new \Tainacan\Entities\Item_Metadata_Entity($item, $metadatum);

            if ($metadatum->is_multiple()) {
                $values = array_map('trim', explode('||', (string) $suggestion->new_value));
                $im->set_value($values);
            } else {
                $im->set_value((string) $suggestion->new_value);
            }

            if (!$im->validate()) {
                return new \WP_Error('tmc_invalid_value', 'Valor inválido: ' . wp_json_encode($im->get_errors()));
            }

            $im_repo->insert($im);
            return true;
        } catch (\Throwable $e) {
            return new \WP_Error('tmc_apply_error', $e->getMessage());
        }
    }

    private function hash_value($value) {
        return hash('sha256', (string) $value);
    }

    private function notify_moderators($suggestion_id, $data) {
        if (!(int) get_option('tmc_notify_email', 1)) return;

        $recipient = get_option('tmc_notify_to', get_option('admin_email'));
        if (!$recipient) return;

        $item_title = get_the_title($data['item_id']) ?: ('#' . $data['item_id']);
        $subject = sprintf('[Tainacan Crowdsource] Nova sugestão — %s', $item_title);

        $admin_url = admin_url('admin.php?page=tainacan-metadata-crowdsource');

        $body = "Nova sugestão de metadado recebida.\n\n"
              . "Item: {$item_title} (ID {$data['item_id']})\n"
              . "Metadado: " . ($data['metadatum_label'] ?? ('#' . $data['metadatum_id'])) . "\n"
              . "Valor atual: " . ($data['old_value'] ?: '(vazio)') . "\n"
              . "Valor sugerido: " . $data['new_value'] . "\n"
              . "Motivo: " . ($data['reason'] ?: '(não informado)') . "\n"
              . "Colaborador: " . ($data['submitter_name'] ?: 'anônimo') . "\n\n"
              . "Revisar: {$admin_url}\n";

        wp_mail($recipient, $subject, $body);
    }
}

<?php
/**
 * Plugin Name: Obra Pagamentos MVP
 * Description: Gestão de solicitações de pagamento de alimentação e transporte para equipes de obra.
 * Version: 0.4.0
 * Author: Codex
 */

if (!defined('ABSPATH')) {
    exit;
}

class ObraPagamentosMVP {
    private const FINANCE_EMAIL = 'kamyllec@gmail.com';
    private const REQUESTERS = [
        'arthur' => 'arthuryurilima@gmail.com',
        'elias' => 'elias.sinalvida@hotmail.com',
        'rita' => 'planejamento@sinalvida.com.br',
    ];
    private const PORTAL_SLUG = 'financeiro-solicitacoes';
    private const ALLOWANCES = [
        'cafe_manha' => ['label' => 'Café da manhã', 'default' => 10],
        'almoco' => ['label' => 'Almoço', 'default' => 20],
        'jantar' => ['label' => 'Jantar', 'default' => 20],
        'transporte' => ['label' => 'Transporte', 'default' => 0],
        'hospedagem' => ['label' => 'Hospedagem', 'default' => 0],
    ];

    private const EMPLOYEES = [
        'Joelington Gomes de Barros','Maria Eduarda Soares Queiroz','Wanderson Vitor dos Santos Ferreira','Anderson Felipe Da Silva','Arthur Joaquim Ferreira da Silva','Daniel Jose de Araujo','Edgar Lira Brandao','Edson José dos Santos','Inacio Milanes da Silva','Jose Erivaldo Alves da Silva','Jose Harisson Tavares da Silva','Jose Henrique da Silva','Rita de Cassia Moura Silva','Williams Cavalcanti Silva','Edilson Mariano da Silva Junior','Eraldo Jose Viana','Israel Julio Silva de Santana','Jeremias Antonio Alves','Joao Luiz da Silva','Jose Valci da Silva Filho','Tiago de Santana Silva','Elias Pires de Oliveira','Luiz Mario da Silva','André Vieira Barbosa Filho','Claudemir Benedito da Silva','Cristiano Cipriano de Araujo Filho','Edmilson Soares do Nascimento','Eliezer Severino de Andrade','ivanildo Barbosa Duarte','Izaquiel Raimundo Ferreira','Jose Caetano da Silva Filho','Jose Severino da Silva','Josivaldo Domingos de Souza Filho','Leandro Alves da Silva','Luandeço Pereira da Silva','Maurilio Antonio Tenorio','Tercio Soares da Silva','Welker Rodrigues de Carvalho','Atila da Silva Lemos','Diego Jose da Silva Pereira','Jose Viana da Silva Filho','Mychel Felipe Meira Lima','Silas Morais do Nascimento','Sivaldo Arnaldo da Silva','Jose Aldenizio Ferreira','Jose Marconi dos Santos','José Maria Soares Neto','Marcelo Nunes de Oliveira'
    ];

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('admin_menu', [$this, 'register_admin_pages']);
        add_action('admin_init', [$this, 'handle_form_actions']);
        add_action('wp_ajax_op_create_request', [$this, 'ajax_create_request']);
        add_action('wp_ajax_op_approve_request', [$this, 'ajax_approve_request']);
        add_action('init', [$this, 'register_shortcodes']);
        add_action('init', [$this, 'ensure_portal_page']);
    }

    public function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}equipes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,nome VARCHAR(120) NOT NULL,PRIMARY KEY (id),UNIQUE KEY nome (nome)) $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}funcionarios (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,nome VARCHAR(180) NOT NULL,equipe_id BIGINT UNSIGNED NOT NULL,ativo TINYINT(1) NOT NULL DEFAULT 1,PRIMARY KEY (id),KEY equipe_id (equipe_id)) $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}valores_diarias (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,cafe_manha DECIMAL(10,2) NOT NULL DEFAULT 10,almoco DECIMAL(10,2) NOT NULL DEFAULT 20,jantar DECIMAL(10,2) NOT NULL DEFAULT 20,transporte DECIMAL(10,2) NOT NULL DEFAULT 0,hospedagem DECIMAL(10,2) NOT NULL DEFAULT 0,PRIMARY KEY (id)) $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}solicitacoes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,solicitante_id BIGINT UNSIGNED NOT NULL,equipe_id BIGINT UNSIGNED NOT NULL,obra VARCHAR(180) NOT NULL,data_inicio DATE NOT NULL,data_fim DATE NOT NULL,quantidade_dias INT NOT NULL,valor_total DECIMAL(12,2) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'Pendente',itens_json LONGTEXT NULL,data_solicitacao DATETIME DEFAULT CURRENT_TIMESTAMP,data_aprovacao DATETIME NULL,aprovador_id BIGINT UNSIGNED NULL,motivo_rejeicao TEXT NULL,PRIMARY KEY (id),KEY status (status)) $charset;");
        dbDelta("CREATE TABLE {$wpdb->prefix}solicitacao_funcionarios (solicitacao_id BIGINT UNSIGNED NOT NULL,funcionario_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY (solicitacao_id, funcionario_id)) $charset;");

        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}equipes") === 0) {
            $wpdb->insert("{$wpdb->prefix}equipes", ['nome' => 'Equipe Geral']);
        }
        $team_id = (int) $wpdb->get_var("SELECT id FROM {$wpdb->prefix}equipes ORDER BY id ASC LIMIT 1");
        foreach (self::EMPLOYEES as $employee) {
            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}funcionarios WHERE nome=%s", $employee));
            if ($exists === 0) {
                $wpdb->insert("{$wpdb->prefix}funcionarios", ['nome' => $employee, 'equipe_id' => $team_id, 'ativo' => 1]);
            }
        }
        $this->ensure_default_values();
        flush_rewrite_rules();
    }

    public function register_shortcodes(): void {
        add_shortcode('op_portal', [$this, 'render_front_portal']);
    }

    public function ensure_portal_page(): void {
        if (get_page_by_path(self::PORTAL_SLUG) instanceof WP_Post) return;
        wp_insert_post(['post_title'=>'Financeiro Solicitações','post_name'=>self::PORTAL_SLUG,'post_status'=>'publish','post_type'=>'page','post_content'=>'[op_portal]']);
    }

    private function ensure_default_values(): void {
        global $wpdb;
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}valores_diarias") > 0) return;
        $row = [];
        foreach (self::ALLOWANCES as $k => $item) $row[$k] = $item['default'];
        $wpdb->insert("{$wpdb->prefix}valores_diarias", $row);
    }

    private function is_requester(): bool {
        $user = wp_get_current_user();
        if (!$user || !$user->ID) return false;
        if (current_user_can('manage_options')) return true; // master também solicita
        $login = strtolower((string) $user->user_login);
        $email = strtolower((string) $user->user_email);
        return array_key_exists($login, self::REQUESTERS) || in_array($email, array_map('strtolower', self::REQUESTERS), true);
    }

    private function is_master(): bool { return current_user_can('manage_options'); }

    public function register_admin_pages(): void {
        add_menu_page('Obra Pagamentos', 'Obra Pagamentos', 'read', 'op-dashboard', [$this,'render_dashboard']);
        add_submenu_page('op-dashboard','Nova solicitação','Nova solicitação','read','op-nova',[$this,'render_new_request']);
        add_submenu_page('op-dashboard','Minhas solicitações','Minhas solicitações','read','op-minhas',[$this,'render_my_requests']);
        add_submenu_page('op-dashboard','Aprovação','Aprovação','manage_options','op-aprovacao',[$this,'render_approval']);
        add_submenu_page('op-dashboard','Valores','Valores','manage_options','op-valores',[$this,'render_daily_values']);
    }

    public function handle_form_actions(): void {
        if (!is_admin() || !isset($_POST['op_action'])) return;
        check_admin_referer('op_action_nonce');
        $action = sanitize_text_field(wp_unslash($_POST['op_action']));
        if ($action === 'save_values' && $this->is_master()) $this->save_values();
    }

    private function get_daily_values(): object {
        global $wpdb;
        $this->ensure_default_values();
        return $wpdb->get_row("SELECT * FROM {$wpdb->prefix}valores_diarias ORDER BY id ASC LIMIT 1") ?: (object)[];
    }

    private function save_values(): void {
        global $wpdb;
        $row = $wpdb->get_row("SELECT id FROM {$wpdb->prefix}valores_diarias ORDER BY id ASC LIMIT 1");
        $data = [];
        foreach (self::ALLOWANCES as $key => $_) $data[$key] = (float)($_POST[$key] ?? 0);
        if ($row) $wpdb->update("{$wpdb->prefix}valores_diarias", $data, ['id' => $row->id]);
        wp_safe_redirect(admin_url('admin.php?page=op-valores&saved=1')); exit;
    }

    public function ajax_create_request(): void {
        check_ajax_referer('op_ajax_nonce', 'nonce');
        if (!$this->is_requester()) wp_send_json_error('Sem permissão.', 403);
        global $wpdb;
        $employee_ids = array_map('intval', $_POST['funcionarios'] ?? []);
        $start = sanitize_text_field(wp_unslash($_POST['data_inicio'] ?? ''));
        $end = sanitize_text_field(wp_unslash($_POST['data_fim'] ?? ''));
        if (strtotime($end) < strtotime($start)) wp_send_json_error('Data fim menor que início.', 400);
        $days = max(1, (int)($_POST['quantidade_dias'] ?? 1));

        $items = [];
        $dailyTotal = 0;
        foreach (self::ALLOWANCES as $key => $meta) {
            $value = (float)($_POST[$key] ?? 0);
            $items[$key] = ['label' => $meta['label'], 'valor' => $value];
            $dailyTotal += $value;
        }
        $total = $dailyTotal * $days * max(1, count($employee_ids));

        $wpdb->insert("{$wpdb->prefix}solicitacoes", [
            'solicitante_id'=>get_current_user_id(),'equipe_id'=>(int)($_POST['equipe_id'] ?? 0),'obra'=>sanitize_text_field(wp_unslash($_POST['obra'] ?? '')),
            'data_inicio'=>$start,'data_fim'=>$end,'quantidade_dias'=>$days,'valor_total'=>$total,'status'=>'Pendente','itens_json'=>wp_json_encode($items)
        ]);
        $request_id = (int)$wpdb->insert_id;
        foreach ($employee_ids as $eid) {
            $wpdb->replace("{$wpdb->prefix}solicitacao_funcionarios", ['solicitacao_id'=>$request_id, 'funcionario_id'=>$eid]);
        }
        wp_send_json_success(['request_id'=>$request_id,'valor_total'=>$total]);
    }

    public function ajax_approve_request(): void {
        check_ajax_referer('op_ajax_nonce', 'nonce');
        if (!$this->is_master()) wp_send_json_error('Sem permissão.', 403);
        global $wpdb;
        $decision = sanitize_text_field(wp_unslash($_POST['decision'] ?? ''));
        if (!in_array($decision, ['Aprovado', 'Rejeitado'], true)) wp_send_json_error('Decisão inválida.', 400);
        $wpdb->update("{$wpdb->prefix}solicitacoes", ['status'=>$decision,'motivo_rejeicao'=>sanitize_text_field(wp_unslash($_POST['motivo'] ?? '')),'data_aprovacao'=>current_time('mysql'),'aprovador_id'=>get_current_user_id()], ['id'=>(int)($_POST['request_id'] ?? 0)]);
        wp_send_json_success(['status'=>$decision]);
    }

    public function render_front_portal(): string {
        ob_start();
        echo '<div style="max-width:900px;margin:24px auto;padding:16px"><h2>Portal de Solicitações Financeiras</h2>';
        echo '<p>Login permitido via WordPress. Para Google, use plugin social login (ex.: Nextend Social Login).</p>';
        if (!is_user_logged_in()) { wp_login_form(['redirect' => home_url('/' . self::PORTAL_SLUG)]); echo '</div>'; return (string)ob_get_clean(); }
        echo '<ul><li><a href="' . esc_url(admin_url('admin.php?page=op-nova')) . '">Nova solicitação</a></li><li><a href="' . esc_url(admin_url('admin.php?page=op-minhas')) . '">Minhas solicitações</a></li>';
        if ($this->is_master()) echo '<li><a href="' . esc_url(admin_url('admin.php?page=op-aprovacao')) . '">Aprovação</a></li><li><a href="' . esc_url(admin_url('admin.php?page=op-valores')) . '">Valores</a></li>';
        echo '</ul></div>';
        return (string)ob_get_clean();
    }

    public function render_dashboard(): void { echo '<div class="wrap"><h1>Dashboard</h1></div>'; }

    public function render_new_request(): void {
        if (!$this->is_requester()) { echo '<div class="wrap"><p>Sem permissão.</p></div>'; return; }
        global $wpdb;
        $teams = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}equipes ORDER BY nome");
        $employees = $wpdb->get_results("SELECT id,nome FROM {$wpdb->prefix}funcionarios WHERE ativo=1 ORDER BY nome");
        $values = $this->get_daily_values();
        echo '<div class="wrap"><h1>Nova solicitação</h1><form id="op-new-request"><table class="form-table">';
        echo '<tr><th>Equipe</th><td><select name="equipe_id" required>'; foreach($teams as $t){ echo '<option value="'.(int)$t->id.'">'.esc_html($t->nome).'</option>'; } echo '</select></td></tr>';
        echo '<tr><th>Funcionários</th><td><select name="funcionarios[]" multiple size="12" required>'; foreach($employees as $e){ echo '<option value="'.(int)$e->id.'">'.esc_html($e->nome).'</option>'; } echo '</select></td></tr>';
        echo '<tr><th>Obra</th><td><input name="obra" required></td></tr><tr><th>Início/Fim</th><td><input type="date" name="data_inicio" required> até <input type="date" name="data_fim" required></td></tr><tr><th>Dias</th><td><input type="number" min="1" name="quantidade_dias" value="1" required></td></tr>';
        foreach (self::ALLOWANCES as $k => $meta) { echo '<tr><th>' . esc_html($meta['label']) . '</th><td><input type="number" step="0.01" name="' . esc_attr($k) . '" value="' . esc_attr($values->$k ?? $meta['default']) . '"></td></tr>'; }
        echo '</table><button class="button button-primary">Salvar solicitação</button></form></div>';
        echo "<script>document.getElementById('op-new-request').addEventListener('submit',async(e)=>{e.preventDefault();const d=new FormData(e.target);d.append('action','op_create_request');d.append('nonce','".esc_js(wp_create_nonce('op_ajax_nonce'))."');const r=await fetch('".esc_js(admin_url('admin-ajax.php'))."',{method:'POST',body:d});const j=await r.json();alert(j.success?'Solicitação criada!':'Erro: '+(j.data||''));if(j.success)e.target.reset();});</script>";
    }

    public function render_my_requests(): void { global $wpdb; $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}solicitacoes WHERE solicitante_id=%d ORDER BY id DESC", get_current_user_id())); echo '<div class="wrap"><h1>Minhas solicitações</h1><table class="widefat"><tr><th>ID</th><th>Obra</th><th>Status</th><th>Total</th></tr>'; foreach($rows as $r) echo '<tr><td>'.(int)$r->id.'</td><td>'.esc_html($r->obra).'</td><td>'.esc_html($r->status).'</td><td>R$ '.esc_html(number_format((float)$r->valor_total,2,',','.')).'</td></tr>'; echo '</table></div>'; }

    public function render_approval(): void {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT s.*,u.display_name solicitante FROM {$wpdb->prefix}solicitacoes s INNER JOIN {$wpdb->users} u ON u.ID=s.solicitante_id ORDER BY s.id DESC");
        echo '<div class="wrap"><h1>Aprovação (histórico completo)</h1><table class="widefat"><tr><th>ID</th><th>Solicitante</th><th>Obra</th><th>Status</th><th>Total</th><th>Ação</th></tr>';
        foreach($rows as $r){ echo '<tr><td>'.(int)$r->id.'</td><td>'.esc_html($r->solicitante).'</td><td>'.esc_html($r->obra).'</td><td>'.esc_html($r->status).'</td><td>R$ '.esc_html(number_format((float)$r->valor_total,2,',','.')).'</td><td><button class="button button-primary" onclick="opDecision('.(int)$r->id.',\'Aprovado\')">Aprovar</button> <button class="button" onclick="opDecision('.(int)$r->id.',\'Rejeitado\')">Rejeitar</button></td></tr>'; }
        echo '</table></div>';
        echo "<script>async function opDecision(id,d){const fd=new FormData();fd.append('action','op_approve_request');fd.append('nonce','".esc_js(wp_create_nonce('op_ajax_nonce'))."');fd.append('request_id',id);fd.append('decision',d);if(d==='Rejeitado'){fd.append('motivo',prompt('Motivo da rejeição (opcional):')||'');}const r=await fetch('".esc_js(admin_url('admin-ajax.php'))."',{method:'POST',body:fd});const j=await r.json();if(j.success)location.reload();else alert(j.data||'Erro');}</script>";
    }

    public function render_daily_values(): void {
        if (!$this->is_master()) wp_die('Sem permissão');
        $v = $this->get_daily_values();
        echo '<div class="wrap"><h1>Valores diários</h1><form method="post">'; wp_nonce_field('op_action_nonce');
        echo '<input type="hidden" name="op_action" value="save_values"><table class="form-table">';
        foreach (self::ALLOWANCES as $k => $meta) echo '<tr><th>'.esc_html($meta['label']).'</th><td><input type="number" step="0.01" name="'.esc_attr($k).'" value="'.esc_attr($v->$k ?? $meta['default']).'"></td></tr>';
        echo '</table><button class="button button-primary">Salvar</button></form></div>';
    }
}

new ObraPagamentosMVP();

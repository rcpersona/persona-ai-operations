<?php
/**
 * Plugin Name: RC Persona AI Connector
 * Description: Employee-scoped read-only REST API for the local Persona connector.
 */

defined('ABSPATH') || exit;

const RCP_AI_APP_ID = '35baa11b-a925-465f-903b-f05bc8fcac87';
const RCP_AI_NAMESPACE = 'persona-ai/v1';
const RCP_AI_ROUTES = [
    '/me', '/candidates', '/candidates/(?P<id>[1-9][0-9]{0,14})',
    '/candidates/(?P<id>[1-9][0-9]{0,14})/activity',
    '/vacancies', '/vacancies/(?P<id>[1-9][0-9]{0,14})',
    '/statistics/candidates', '/statistics/vacancies',
];

function rcp_ai_error($code, $status) {
    return new WP_Error('rcp_ai_' . $code, $code, ['status' => $status]);
}

function rcp_ai_active($user_id) {
    return $user_id && is_user_member_of_blog($user_id)
        && in_array(get_user_meta($user_id, 'account_status', true), ['', 'enabled'], true);
}

add_action('wp_authenticate_application_password_errors', function ($errors, $user, $item) {
    if (($item['app_id'] ?? '') !== RCP_AI_APP_ID) {
        return;
    }
    if (!is_ssl() || !defined('REST_REQUEST') || !REST_REQUEST
        || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
        || isset($_GET['_method']) || isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])
        || !rcp_ai_allowed_path($GLOBALS['wp']->query_vars['rest_route'] ?? '')
        || !rcp_ai_active($user->ID)) {
        $errors->add('rcp_ai_forbidden', 'forbidden', ['status' => 403]);
        $GLOBALS['rcp_ai_auth_denial'] = $errors;
    }
}, 10, 3);

add_filter('rest_authentication_errors', function ($result) {
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        // Resolve Basic authentication before core evaluates its deferred authentication result.
        get_current_user_id();
    }
    return $GLOBALS['rcp_ai_auth_denial'] ?? $result;
}, 5);

add_action('application_password_did_authenticate', function ($user, $item) {
    if (($item['app_id'] ?? '') === RCP_AI_APP_ID) {
        $GLOBALS['rcp_ai_authenticated_user'] = (int) $user->ID;
    }
}, 10, 2);

function rcp_ai_allowed_path($path) {
    if (!is_string($path)) {
        return false;
    }
    foreach (RCP_AI_ROUTES as $route) {
        if (preg_match('~^/' . RCP_AI_NAMESPACE . $route . '$~D', $path)) {
            return true;
        }
    }
    return false;
}

add_action('after_setup_theme', function () {
    // The current RCPersona theme runs maintenance on init, before REST dispatch.
    $uri = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $prefix = '/' . rest_get_url_prefix() . '/';
    $position = is_string($uri) ? strrpos($uri, $prefix) : false;
    $path = $_GET['rest_route'] ?? ($position === false ? '' : substr($uri, $position + strlen($prefix) - 1));
    if (is_string($path) && strpos($path, '/' . RCP_AI_NAMESPACE . '/') === 0) {
        remove_action('init', 'disable_existing_comments');
        remove_action('init', 'rcp_install_full_quiz_drafts_table', 5);
    }
}, PHP_INT_MAX);

function rcp_ai_allowed_route($request) {
    return $request->get_method() === 'GET' && rcp_ai_allowed_path($request->get_route());
}

add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    // Force authentication before checking the credential marker, including on public REST routes.
    $user_id = get_current_user_id();
    if (isset($GLOBALS['rcp_ai_authenticated_user']) &&
        (!$user_id || !rcp_ai_active($user_id) || !rcp_ai_allowed_route($request))) {
        return rcp_ai_error('forbidden', 403);
    }
    return $result;
}, PHP_INT_MAX, 3);

function rcp_ai_permission($request) {
    $user_id = get_current_user_id();
    if (!is_ssl() || !rcp_ai_allowed_route($request) || !$user_id
        || ($GLOBALS['rcp_ai_authenticated_user'] ?? 0) !== (int) $user_id) {
        return rcp_ai_error('credential_required', 401);
    }
    if (!rcp_ai_active($user_id) || !current_user_can('read')) {
        return rcp_ai_error('forbidden', 403);
    }

    // A single atomic counter per employee, shared by all workers and connector passwords.
    global $wpdb;
    $key = 'rcp_ai_rate_' . $user_id;
    $bucket = intdiv(time(), 60) * 1000000;
    $written = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')
         ON DUPLICATE KEY UPDATE option_value = IF(
            CAST(option_value AS UNSIGNED) < %d, %d,
            IF(CAST(option_value AS UNSIGNED) < %d, CAST(option_value AS UNSIGNED) + 1, option_value)
         )",
        $key, $bucket + 1, $bucket, $bucket + 1, $bucket + 60
    ));
    if ($written === false) {
        return rcp_ai_error('unavailable', 503);
    }
    if ($written === 0) {
        return rcp_ai_error('rate_limited', 429);
    }
    return true;
}

add_action('rest_api_init', function () {
    $date = [
        'type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$',
        'validate_callback' => function ($value) {
            return is_string($value) && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $m)
                && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && $value <= '9999-12-30';
        },
    ];
    $period = ['from' => $date, 'to' => $date];
    $paging = [
        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
        'before_id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 999999999999999],
    ];
    $candidates = $period + [
        'q' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
        'assigned_to_me' => ['type' => 'boolean'],
    ];
    $vacancies = $period + [
        'status' => ['type' => 'string', 'enum' => ['actual', 'closed', 'hide']],
        'assigned_to_me' => ['type' => 'boolean'],
    ];
    $args = [
        [], $candidates + $paging + ['peopleforce' => ['type' => 'string', 'enum' => ['linked', 'unlinked']], 'experience' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 120]],
        [], [], $vacancies + ['limit' => $paging['limit'], 'cursor' => ['type' => 'string', 'maxLength' => 160]], [], $candidates, $vacancies,
    ];
    foreach (RCP_AI_ROUTES as $index => $route) {
        register_rest_route(RCP_AI_NAMESPACE, $route, [
            'methods' => 'GET', 'permission_callback' => 'rcp_ai_permission', 'args' => $args[$index],
            'callback' => function ($request) use ($index, $args) {
                $query = $request->get_query_params();
                unset($query['rest_route']);
                if ($request->get_body() !== '' || array_diff_key($query, $args[$index])) {
                    return rcp_ai_error('invalid_query', 400);
                }
                if ($request['from'] && $request['to'] && $request['from'] > $request['to']) {
                    return rcp_ai_error('invalid_period', 400);
                }
                $handlers = ['rcp_ai_me', 'rcp_ai_candidates', 'rcp_ai_candidate', 'rcp_ai_activity',
                    'rcp_ai_vacancies', 'rcp_ai_vacancy', 'rcp_ai_candidate_stats', 'rcp_ai_vacancy_stats'];
                return $handlers[$index]($request);
            },
        ]);
    }
});

add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if (strpos($request->get_route(), '/' . RCP_AI_NAMESPACE . '/') === 0
        || isset($GLOBALS['rcp_ai_authenticated_user'])) {
        $response->header('Cache-Control', 'private, no-store');
        error_log(sprintf('rcpersona_connector http_status=%d', $response->get_status()));
    }
    return $response;
}, 10, 3);

function rcp_ai_can_read_candidates() {
    // The quick-quiz UI grants this action to read-capable staff. Fail closed for other accounts.
    return current_user_can('read') && (current_user_can('manage_options')
        || current_user_can('recruiter') || current_user_can('head-recruiter') || current_user_can('teamlead'));
}

function rcp_ai_me() {
    return [
        'user_id' => get_current_user_id(),
        'active' => true,
        'connector' => RCP_AI_NAMESPACE,
        'permissions' => ['read_candidates' => rcp_ai_can_read_candidates(), 'read_vacancies' => post_type_exists('vacancies')],
    ];
}

function rcp_ai_candidate_scope(&$params) {
    global $wpdb;
    if (!rcp_ai_can_read_candidates()) {
        return '1=0';
    }
    if (current_user_can('manage_options') && current_user_can('administrator')) {
        return '1=1';
    }
    $user_id = get_current_user_id();
    $managers = [$user_id, -1];
    $team = get_user_meta($user_id, 'team_members', true);
    if (current_user_can('teamlead') && is_array($team)) {
        $managers = array_merge($managers, array_filter(array_map('intval', $team), function ($id) { return $id > 0; }));
    }
    $managers = array_values(array_unique($managers));
    $params = array_merge($params, $managers);
    $assigned = 'q.candidate_manager IN (' . implode(',', array_fill(0, count($managers), '%d')) . ')';

    // These are the two mutually exclusive vacancy ownership branches in the quick-quiz UI.
    $projects = get_user_meta($user_id, 'projects', true);
    $projects = is_array($projects) ? $projects : explode(',', (string) $projects);
    $projects = array_values(array_filter(array_map('intval', $projects), function ($id) { return $id > 0; }));
    $vacancy_match = "p.post_type = 'vacancies' AND FIND_IN_SET(p.ID, REPLACE(q.candidate_vacancy, ' ', '')) > 0";
    if ($projects) {
        $params = array_merge($params, $projects);
        $placeholders = implode(',', array_fill(0, count($projects), '%d'));
        $vacancies = "SELECT 1 FROM {$wpdb->posts} p
            JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
            JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE {$vacancy_match} AND tt.taxonomy = 'vacancies-project' AND tt.term_id IN ({$placeholders})";
    } else {
        $params[] = $user_id;
        $vacancies = "SELECT 1 FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE {$vacancy_match} AND pm.meta_key = 'manager_mail_new' AND pm.meta_value = %d";
    }
    return "({$assigned} OR EXISTS ({$vacancies}))";
}

function rcp_ai_period_sql($request, $column, &$params) {
    $sql = '';
    if ($request['from']) {
        $sql .= " AND {$column} >= %s";
        $params[] = $request['from'];
    }
    if ($request['to']) {
        $sql .= " AND {$column} < %s";
        $params[] = (new DateTimeImmutable($request['to']))->modify('+1 day')->format('Y-m-d');
    }
    return $sql;
}

function rcp_ai_query($sql, $params) {
    global $wpdb;
    $previous = $wpdb->suppress_errors(true);
    try {
        $rows = $wpdb->get_results($params ? $wpdb->prepare($sql, $params) : $sql, ARRAY_A);
        return $wpdb->last_error ? rcp_ai_error('unavailable', 503) : $rows;
    } finally {
        $wpdb->suppress_errors($previous);
    }
}

function rcp_ai_candidate_rows($request, $mode, $limit = 1) {
    global $wpdb;
    $params = [];
    $where = "q.status != '-1' AND " . rcp_ai_candidate_scope($params);
    $where .= rcp_ai_period_sql($request, 'q.date_creation', $params);
    if ($request['id']) {
        $where .= ' AND q.id = %d';
        $params[] = $request['id'];
    }
    if ($request['before_id']) {
        $where .= ' AND q.id < %d';
        $params[] = $request['before_id'];
    }
    if ($request['assigned_to_me']) {
        $where .= ' AND q.candidate_manager = %d';
        $params[] = get_current_user_id();
    }
    if ($request['q']) {
        $where .= ' AND q.candidate_job_title LIKE %s';
        $params[] = '%' . $wpdb->esc_like($request['q']) . '%';
    }
    $table = $wpdb->prefix . 'quiz_quick';
    if ($mode === 'stats') {
        return rcp_ai_query("SELECT q.status, COUNT(*) AS total FROM {$table} q WHERE {$where}
            GROUP BY q.status ORDER BY total DESC LIMIT 101", $params);
    }
    $params[] = $limit;
    $experience = $request['experience'] ? ', LEFT(q.candidate_description, 12000) AS experience_source' : '';
    return rcp_ai_query("SELECT q.id, q.status, LEFT(q.candidate_job_title, 400) AS job_title, q.date_creation{$experience}
        FROM {$table} q WHERE {$where} ORDER BY q.id DESC LIMIT %d", $params);
}

function rcp_ai_link_state($id) {
    if (!function_exists('rcp_peopleforce_sync_option_name')) {
        return 'unavailable';
    }
    $state = get_option(rcp_peopleforce_sync_option_name($id), []);
    return !empty($state['peopleforce_candidate_id']) ? 'linked' : 'unlinked';
}

function rcp_ai_text($text) {
    return wp_check_invalid_utf8(wp_strip_all_tags((string) $text, true), true);
}

function rcp_ai_candidate_payload($row) {
    $id = (int) $row['id'];
    return ['id' => $id, 'status' => rcp_ai_text($row['status']), 'created_at' => $row['date_creation'],
        'job_title' => rcp_ai_text($row['job_title']), 'peopleforce' => rcp_ai_link_state($id)];
}

function rcp_ai_matches_experience($html, $term) {
    if (!$html) {
        return false;
    }
    $document = new DOMDocument();
    if (!@$document->loadHTML('<?xml encoding="UTF-8"><table>' . $html . '</table>', LIBXML_NONET)) {
        return false;
    }
    $labels = [
        'Tell us briefly about your work experience', 'Розкажіть коротко про свій досвід роботи',
        'Розкажіть коротко про ваш досвід роботи', 'Досвід роботи', 'Опыт работы', 'Work experience',
    ];
    foreach ($document->getElementsByTagName('tr') as $row) {
        $cells = $row->getElementsByTagName('td');
        if ($cells->length === 2 && in_array(trim($cells->item(0)->textContent, " \t\n\r:?"), $labels, true)
            && mb_stripos($cells->item(1)->textContent, trim($term), 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

function rcp_ai_candidates($request) {
    if ($request['experience'] &&
        (!class_exists('DOMDocument') || !function_exists('mb_stripos') || strlen(trim($request['experience'])) < 2)) {
        return rcp_ai_error('experience_unavailable', 503);
    }
    if ($request['peopleforce'] && !function_exists('rcp_peopleforce_sync_option_name')) {
        return rcp_ai_error('peopleforce_unavailable', 503);
    }
    $limit = (int) $request['limit'];
    $scan = $request['peopleforce'] || $request['experience'] ? 200 : $limit;
    $rows = rcp_ai_candidate_rows($request, 'list', $scan + 1);
    if (is_wp_error($rows)) {
        return $rows;
    }
    $items = [];
    $consumed = 0;
    foreach (array_slice($rows, 0, $scan) as $row) {
        ++$consumed;
        if ($request['experience'] && !rcp_ai_matches_experience($row['experience_source'], $request['experience'])) {
            continue;
        }
        $payload = rcp_ai_candidate_payload($row);
        if (!$request['peopleforce'] || $payload['peopleforce'] === $request['peopleforce']) {
            $items[] = $payload;
            if (count($items) === $limit) {
                break;
            }
        }
    }
    return ['items' => $items, 'next_before_id' => count($rows) > $consumed ? (int) $rows[$consumed - 1]['id'] : null];
}

function rcp_ai_candidate($request) {
    $rows = rcp_ai_candidate_rows($request, 'detail');
    if (is_wp_error($rows)) {
        return $rows;
    }
    return $rows ? rcp_ai_candidate_payload($rows[0]) : rcp_ai_error('not_found', 404);
}

function rcp_ai_activity($request) {
    $candidate = rcp_ai_candidate($request);
    if (is_wp_error($candidate)) {
        return $candidate;
    }
    return ['id' => $candidate['id'], 'events' => [['type' => 'submitted', 'at' => $candidate['created_at']]],
        'current_status' => $candidate['status'], 'peopleforce' => $candidate['peopleforce'],
        'peopleforce_activity' => 'unavailable'];
}

function rcp_ai_candidate_stats($request) {
    $rows = rcp_ai_candidate_rows($request, 'stats');
    if (is_wp_error($rows)) {
        return $rows;
    }
    if (count($rows) > 100) {
        return rcp_ai_error('statistics_too_large', 422);
    }
    $counts = array_map(function ($row) {
        return ['status' => rcp_ai_text($row['status']), 'count' => (int) $row['total']];
    }, $rows);
    return ['total' => array_sum(array_column($counts, 'count')), 'by_status' => $counts];
}

function rcp_ai_can_read_vacancy($post) {
    if (!$post || $post->post_type !== 'vacancies'
        || !in_array($post->post_status, ['publish', 'draft', 'pending', 'private', 'future'], true)) {
        return false;
    }
    if ($post->post_status === 'publish' && rcp_ai_vacancy_status($post->ID) !== 'hide') {
        return $post->post_password === '' || current_user_can('edit_post', $post->ID);
    }
    return current_user_can('read_post', $post->ID) && current_user_can('edit_post', $post->ID);
}

function rcp_ai_vacancy_status($id) {
    $status = get_post_meta($id, 'vacancy_status', true);
    return in_array($status, ['actual', 'closed', 'hide'], true) ? $status : 'unknown';
}

function rcp_ai_vacancy_payload($post) {
    $mapping = function_exists('rcp_peopleforce_get_vacancy_mapping') ? rcp_peopleforce_get_vacancy_mapping($post->ID) : null;
    return ['id' => (int) $post->ID, 'title' => rcp_ai_text(function_exists('mb_substr') ?
        mb_substr($post->post_title, 0, 300, 'UTF-8') : substr($post->post_title, 0, 300)),
        'status' => rcp_ai_vacancy_status($post->ID), 'publication_status' => $post->post_status,
        'created_at' => $post->post_date,
        'peopleforce' => $mapping === null ? 'unavailable' : (!empty($mapping['id']) ? 'linked' : 'unlinked')];
}

function rcp_ai_vacancy($request) {
    $post = get_post((int) $request['id']);
    return rcp_ai_can_read_vacancy($post) ? rcp_ai_vacancy_payload($post) : rcp_ai_error('not_found', 404);
}

function rcp_ai_vacancy_ids($request, $before, $limit) {
    global $wpdb;
    $params = [];
    $where = "p.post_type = 'vacancies' AND p.post_status IN ('publish','draft','pending','private','future')";
    $where .= rcp_ai_period_sql($request, 'p.post_date', $params);
    if ($before) {
        $where .= ' AND p.ID < %d';
        $params[] = $before;
    }
    if ($request['status']) {
        $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm
            WHERE pm.post_id = p.ID AND pm.meta_key = 'vacancy_status' AND pm.meta_value = %s)";
        $params[] = $request['status'];
    }
    if ($request['assigned_to_me']) {
        $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm
            WHERE pm.post_id = p.ID AND pm.meta_key = 'manager_mail_new' AND pm.meta_value = %d)";
        $params[] = get_current_user_id();
    }
    $params[] = $limit;
    return rcp_ai_query("SELECT p.ID FROM {$wpdb->posts} p WHERE {$where} ORDER BY p.ID DESC LIMIT %d", $params);
}

function rcp_ai_vacancy_cursor($value, $encode) {
    $key = hash('sha256', wp_salt('auth'), true);
    $context = RCP_AI_NAMESPACE . ':vacancies:' . get_current_blog_id() . ':' . get_current_user_id();
    if ($encode) {
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt((string) $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $context);
        return $ciphertext === false ? false : rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');
    }
    $bytes = base64_decode(strtr((string) $value, '-_', '+/'), true);
    if ($bytes === false || strlen($bytes) < 29) {
        return false;
    }
    $id = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        substr($bytes, 0, 12), substr($bytes, 12, 16), $context);
    return $id !== false && preg_match('/^[1-9][0-9]{0,14}$/D', $id) ? (int) $id : false;
}

function rcp_ai_vacancies($request) {
    $before = $request['cursor'] ? rcp_ai_vacancy_cursor($request['cursor'], false) : null;
    if ($before === false) {
        return rcp_ai_error('invalid_cursor', 400);
    }
    $rows = rcp_ai_vacancy_ids($request, $before, 201);
    if (is_wp_error($rows)) {
        return $rows;
    }
    $items = [];
    $consumed = 0;
    foreach (array_slice($rows, 0, 200) as $row) {
        ++$consumed;
        $post = get_post((int) $row['ID']);
        if (rcp_ai_can_read_vacancy($post) && (!$request['status'] || rcp_ai_vacancy_status($post->ID) === $request['status'])) {
            $items[] = rcp_ai_vacancy_payload($post);
            if (count($items) === (int) $request['limit']) {
                break;
            }
        }
    }
    $next = count($rows) > $consumed ? rcp_ai_vacancy_cursor($rows[$consumed - 1]['ID'], true) : null;
    return $next === false ? rcp_ai_error('unavailable', 503) : ['items' => $items, 'next_cursor' => $next];
}

function rcp_ai_vacancy_stats($request) {
    $counts = ['actual' => 0, 'closed' => 0, 'hide' => 0, 'unknown' => 0];
    $before = null;
    for ($batch = 0; $batch < 25; ++$batch) {
        $rows = rcp_ai_vacancy_ids($request, $before, 201);
        if (is_wp_error($rows)) {
            return $rows;
        }
        foreach (array_slice($rows, 0, 200) as $row) {
            $before = (int) $row['ID'];
            $post = get_post($before);
            if (rcp_ai_can_read_vacancy($post)) {
                $status = rcp_ai_vacancy_status($post->ID);
                if (!$request['status'] || $status === $request['status']) {
                    ++$counts[$status];
                }
            }
        }
        if (count($rows) <= 200) {
            return ['total' => array_sum($counts), 'by_status' => $counts];
        }
    }
    return rcp_ai_error('statistics_too_large', 422);
}





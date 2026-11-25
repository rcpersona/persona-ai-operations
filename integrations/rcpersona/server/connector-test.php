<?php

define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');
define('REST_REQUEST', true);

class WP_Error {
    public $entries = [];
    public function __construct($code = '', $message = '', $data = []) {
        if ($code) {
            $this->add($code, $message, $data);
        }
    }
    public function add($code, $message, $data) {
        $this->entries[$code] = $data['status'];
    }
}

function add_action($name, $callback, $priority = 10, $arity = 1) { $GLOBALS['actions'][$name] = $callback; }
function add_filter($name, $callback, $priority = 10, $arity = 1) { $GLOBALS['filters'][$name] = $callback; }
function get_current_user_id() { return $GLOBALS['user']; }
function get_current_blog_id() { return 1; }
function wp_salt($scheme) { return 'synthetic-key'; }
function is_ssl() { return $GLOBALS['tls']; }
function is_user_member_of_blog($id) { return in_array($id, [10, 20, 30], true); }
function current_user_can($cap, ...$args) { return in_array($cap, $GLOBALS['caps'], true); }
function get_user_meta($id, $key, $single) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_strip_all_tags($value, $breaks) { return strip_tags($value); }
function wp_check_invalid_utf8($value, $strip) { return $value; }
function rcp_peopleforce_sync_option_name($id) { return 'pf_' . $id; }
function get_option($key, $default) { return $key === 'pf_1' ? ['peopleforce_candidate_id' => 123] : $default; }

class FixtureRequest extends ArrayObject {
    public function offsetGet(mixed $key): mixed { return $this->offsetExists($key) ? parent::offsetGet($key) : null; }
    public function __construct(array $params = [], private string $route = '/persona-ai/v1/me', private string $method = 'GET') {
        parent::__construct($params);
    }
    public function get_method() { return $this->method; }
    public function get_route() { return $this->route; }
}

class FixtureDB {
    public $posts = 'posts';
    public $postmeta = 'postmeta';
    public $term_relationships = 'term_relationships';
    public $term_taxonomy = 'term_taxonomy';
    public $prefix = '';
    public $last_error = '';
    public $options = 'options';
    public $affected = 1;
    private PDO $db;

    public function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->sqliteCreateFunction('FIND_IN_SET', function ($needle, $list) {
            return in_array((string) $needle, explode(',', $list ?? ''), true) ? 1 : 0;
        }, 2);
        $this->db->exec("CREATE TABLE quiz_quick (id INTEGER, status TEXT, candidate_manager INTEGER,
            candidate_vacancy TEXT, candidate_job_title TEXT, date_creation TEXT, candidate_description TEXT);
            CREATE TABLE posts (ID INTEGER, post_type TEXT);
            CREATE TABLE postmeta (post_id INTEGER, meta_key TEXT, meta_value INTEGER);
            CREATE TABLE term_relationships (object_id INTEGER, term_taxonomy_id INTEGER);
            CREATE TABLE term_taxonomy (term_taxonomy_id INTEGER, taxonomy TEXT, term_id INTEGER);
            INSERT INTO posts VALUES (11, 'vacancies'), (12, 'vacancies');
            INSERT INTO postmeta VALUES (11, 'manager_mail_new', 10);
            INSERT INTO term_relationships VALUES (12, 90);
            INSERT INTO term_taxonomy VALUES (90, 'vacancies-project', 3);
            INSERT INTO quiz_quick VALUES
            (1, 'new', 10, '', 'Engineer', '2026-11-15 12:00:00', '<tr><td>Work experience</td><td>PLC automation</td></tr>'),
            (2, 'new', 20, '', 'Engineer', '2026-11-15 12:00:00', '<tr><td>Private note</td><td>PLC</td></tr>'),
            (3, 'new', 20, '11', 'Engineer', '2026-11-16 12:00:00', ''),
            (4, 'old', -1, '', 'Inspector', '2026-11-16 12:00:00', ''),
            (5, '-1', 10, '', 'Hidden', '2026-11-17 12:00:00', ''),
            (6, 'new', 20, '12', 'Engineer', '2026-11-17 12:00:00', ''),
            (7, 'new', 30, '', 'Unique', '2026-11-17 12:00:00', '');");
        $this->db->sqliteCreateFunction('fixture_left', function ($str, $length) {
            return mb_substr($str, 0, $length);
        }, 2);
    }
    public function prepare($sql, ...$params) {
        $params = is_array($params[0]) ? $params[0] : $params;
        return preg_replace_callback('/%[ds]/', function ($match) use (&$params) {
            $value = array_shift($params);
            return $match[0] === '%d' ? (int) $value : $this->db->quote($value);
        }, $sql);
    }
    public function get_results($sql, $format) {
        return $this->db->query(str_replace('LEFT(q.', 'fixture_left(q.', $sql))->fetchAll(PDO::FETCH_ASSOC);
    }
    public function query($sql) { return $this->affected; }
    public function suppress_errors($new) { return false; }
    public function esc_like($text) { return addcslashes($text, '_%\\'); }
}

function expect($value, $expected, $context) {
    if ($value !== $expected) {
        throw new RuntimeException($context . ' expected ' . json_encode($expected) . ', got ' . json_encode($value));
    }
}

function ids($params = []) {
    return array_column(rcp_ai_candidates(new FixtureRequest($params + ['limit' => 20]))['items'], 'id');
}

require __DIR__ . '/rcpersona-ai-connector.php';

$wpdb = new FixtureDB();
$user = 10;
$caps = ['read', 'recruiter'];
$tls = true;
$meta = [];
$wp = (object) ['query_vars' => ['rest_route' => '/persona-ai/v1/me']];
$_SERVER['REQUEST_METHOD'] = 'GET';
$item = ['app_id' => RCP_AI_APP_ID];
$profile = (object) ['ID' => $user];
$auth = $actions['wp_authenticate_application_password_errors'];
$mark = $actions['application_password_did_authenticate'];
$guard = $filters['rest_pre_dispatch'];

$errors = new WP_Error();
$auth($errors, $profile, $item);
expect($errors->entries, [], 'active profile');
expect(rcp_ai_permission(new FixtureRequest())->entries, ['rcp_ai_credential_required' => 401], 'unmarked credential');
$mark($profile, $item);
expect(rcp_ai_permission(new FixtureRequest()), true, 'connector credential');
$wpdb->affected = 0;
expect(rcp_ai_permission(new FixtureRequest())->entries, ['rcp_ai_rate_limited' => 429], 'rate limit');
$wpdb->affected = 1;

foreach ([['GET', '/wp/v2/users/me'], ['POST', '/persona-ai/v1/candidates'], ['GET', '/batch/v1']] as [$method, $path]) {
    $wp->query_vars['rest_route'] = $path;
    $_SERVER['REQUEST_METHOD'] = $method;
    $errors = new WP_Error();
    $auth($errors, $profile, $item);
    expect($errors->entries, ['rcp_ai_forbidden' => 403], 'early authentication scope');
    expect($guard(null, null, new FixtureRequest([], $path, $method))->entries, ['rcp_ai_forbidden' => 403], 'dispatch scope');
}
$wp->query_vars['rest_route'] = '/persona-ai/v1/me';
$_SERVER['REQUEST_METHOD'] = 'GET';
$meta[10]['account_status'] = 'disabled';
$errors = new WP_Error();
$auth($errors, $profile, $item);
expect($errors->entries, ['rcp_ai_forbidden' => 403], 'disabled account');
expect($guard(null, null, new FixtureRequest())->entries, ['rcp_ai_forbidden' => 403], 'disabled after authentication');
unset($meta[10]['account_status']);

expect(ids(), [4, 3, 1], 'recruiter scope');
expect(ids(['assigned_to_me' => true]), [1], 'assigned only');
expect(ids(['experience' => 'plc']), [1], 'work experience only');
expect(ids(['peopleforce' => 'unlinked']), [4, 3], 'unlinked only');
expect(ids(['q' => 'Unique']), [], 'foreign search');
expect(rcp_ai_candidate(new FixtureRequest(['id' => 7]))->entries, ['rcp_ai_not_found' => 404], 'foreign detail');
expect(rcp_ai_activity(new FixtureRequest(['id' => 7]))->entries, ['rcp_ai_not_found' => 404], 'foreign activity');
expect(rcp_ai_candidate_stats(new FixtureRequest(['q' => 'Unique']))['total'], 0, 'foreign statistic');
expect(rcp_ai_candidate_stats(new FixtureRequest(['from' => '2026-11-15', 'to' => '2026-11-15']))['total'], 1, 'date range');

$meta[10]['projects'] = [3];
expect(ids(), [6, 4, 1], 'projects take precedence over vacancy manager');
$caps = ['read', 'teamlead'];
$meta[10]['team_members'] = [20];
expect(ids(), [6, 4, 3, 2, 1], 'team scope');
$meta[10]['team_members'] = [];
expect(ids(), [6, 4, 1], 'team changes are picked up on the next request');
$caps = ['read', 'administrator', 'manage_options'];
expect(ids(), [7, 6, 4, 3, 2, 1], 'administrator scope');
$caps = ['read'];
expect(ids(), [], 'other role fail closed');

$cursor = rcp_ai_vacancy_cursor(123, true);
expect(rcp_ai_vacancy_cursor($cursor, false), 123, 'vacancy cursor round trip');
$user = 20;
expect(rcp_ai_vacancy_cursor($cursor, false), false, 'vacancy cursor is bound to user');

echo "Synthetic fixtures passed.\n";

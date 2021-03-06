<?php

define('DEBUGGER_N_MOST_RECENT_FILES', 10);

use Vortex\Cli\SocketServerStartCommand;
use Vortex\Cli\SocketServerRunCommand;
use Vortex\Cli\DbgpApp;
use Vortex\App;
use Vortex\Exceptions\DatabaseException;

function debugger_provide_windows()
{
    return [
        [
            'title'     => 'Console',
            'id'        => 'console',
            'secondary' => false,
            'icon'      => 'terminal',
            'content'   => render('console_window'),
        ],
        [
            'title'     => 'Code',
            'id'        => 'code',
            'secondary' => '<span id="filename"></span> <i data-modal-role="open" style="display: none" class="nested-codebase-indicator fa fa-code-branch"></i>',
            'icon'      => 'code',
            'content'   => render('code_window'),
        ],
        [
            'title'     => 'Scope',
            'id'        => 'context',
            'secondary' => '<span data-active-session-visibility="show" class="status-indicator"><i class="fa fa-memory"></i> <span id="mem_usage"></span></span>',
            'icon'      => 'sitemap',
            'content'   => render('context_window'),
        ],
        [
            'title'     => 'Watch',
            'id'        => 'watch',
            'secondary' => '',
            'icon'      => 'binoculars',
            'content'   => render('watch_window'),
        ],
        [
            'title'     => 'Stack',
            'id'        => 'stack',
            'secondary' => '<span data-active-session-visibility="show" class="status-indicator"><i class="fa fa-sort-amount-down"></i> <span id="stack_depth"></span></span>',
            'icon'      => 'sort-amount-down',
            'content'   => render('stack_window'),
        ],
    ];
}

function debugger_render_preprocess(&$data)
{
    if ($data[ 'template' ] == 'toolbar_right') {
        $data[ 'implementations' ][ 'debugger' ][ 'weight' ] = 10;
    }
}

/**
 * @brief
 *	Implements hook_boot(). Adds request handlers for the files and config APIs
 */
function debugger_boot($vars)
{
    $vars['request_handlers']->register('/file/', 'debugger_file_api');
    $vars['request_handlers']->register('/recent_files/', 'debugger_recent_files_api');
    $vars['request_handlers']->register('/ws_maintenance/', 'debugger_ws_maintenance_api');
}

/**
 * @brief
 *	Create a maintenance token (a short-lived, one-time login token intended for use exclusively
 *	by the server)
 *
 * @return string
 */
function make_maintenance_token()
{
    db_query('CREATE TABLE IF NOT EXISTS maintenance_tokens (
		id      INTEGER      PRIMARY KEY AUTOINCREMENT,
		token   VARCHAR(60)  NOT NULL,
		expires DATETIME     DEFAULT CURRENT_TIMESTAMP
	);');

    $token = get_random_token(10) ;
    db_query('
		INSERT INTO maintenance_tokens ( token, expires)
		VALUES                         (:token, DATETIME( CURRENT_TIMESTAMP, "+2 minute"))', [
        ':token' => password_hash($token, PASSWORD_DEFAULT),
    ]);
    return $token;
}

/**
 * @brief
 *	Verify a maintenance token created by make_maintenance_token()
 *
 * NOTE: if the token is validated, ALL tokens are deleted immediately.
 *
 * @param string $token
 * @return bool
 */
function validate_maintenance_token($token)
{
    try {
        $rows = db_query('SELECT token FROM maintenance_tokens WHERE expires > CURRENT_TIMESTAMP');
        foreach ($rows as $row) {
            if (password_verify($token, $row[ 'token' ])) {
                return true;
            }
        }
    } catch (DatabaseException $e) {
        // Ignore & return FALSE below
    }
    return false;
}

/**
 * @brief
 *	Handle requests to the ws maintenance API.
 *
 * @param Vortex\App $app
 *
 * This API is used to work with the socket server, even for clients that do not currently have a
 * websocket connection.
 */
function debugger_ws_maintenance_api(App $app)
{
    $action = $app->request->request->get('action', '');

    if ($action == 'commandeer') {
        $token = make_maintenance_token();
        $params = http_build_query([
            'security_token' => $token,
            'action'         => 'commandeer',
        ]);
        $ss_host = $app->settings->get('socket_server.host');
        $ss_port = $app->settings->get('socket_server.ws_port');
        Ratchet\Client\connect("ws://$ss_host:$ss_port/?$params")->then(function ($conn) {
            $conn->on('message', function ($msg) use ($conn, $app) {
                $conn->close();
                if ($parsed = json_decode($msg, true)) {
                    db_query('DELETE FROM maintenance_tokens;');
                    header('Content-Type: application/json');
                    echo $msg;
                    exit; // TODO: Why doesn't this work as expected with Vortex\Response?
                } else {
                    $app->response->setContent(['error' => $msg])->sendAndTerminate();
                }
            });
        });
    }
}

/**
 * @brief
 *
 * @param string
 * @return array
 */
function debugger_find_codebase_root($file)
{
    $parent_dir = $file;
    $root       = [];
    $visited    = [];
    do {
        $parent_dir  = dirname($parent_dir);
        if ( !empty( $visited[ $parent_dir ] ) )
        {
            break; // Don't visit the same directory twice (avoids infinite loops in certain edge cases)
        }
        else
        {
            $visited[ $parent_dir ] = true;
        }

        $git_dir     = "$parent_dir/.git";
        $config_file = "$git_dir/config";
        if (is_dir($git_dir) && is_readable($config_file)) {
            $config_parsed = parse_ini_file("$config_file", true);
            $root = [
                'root'      => $parent_dir,
                'id'        => $config_parsed[ 'remote origin' ][ 'url' ],
                'is_nested' => !!$root,
            ];
        }

        $vortex_file = "$parent_dir/.vortex-codebase";
        if (is_readable($vortex_file)) { // The first .vortex-codebase file that we find
            return [
                'root'      => $parent_dir,
                'id'        => trim(file_get_contents($vortex_file)),
                'is_nested' => false,
            ];
        }
    } while ($parent_dir != '/');

    return $root;
}

/**
 * @return string
 *	The code for `debugger_get_source_code_for_codebase_root_finder()` as an anonymous function.
 *	This allows us to send this to the client via js, which, in turn, can eval the function on
 *	remote hosts to find codebase roots
 *
 * See https://stackoverflow.com/a/7027198
 */
function debugger_get_source_code_for_codebase_root_finder()
{
    $func       = new ReflectionFunction('debugger_find_codebase_root');
    $filename   = $func->getFileName();
    $start_line = $func->getStartLine() - 1;
    $end_line   = $func->getEndLine();
    $length     = $end_line - $start_line;

    $source = file($filename);
    $body   = implode("", array_slice($source, $start_line, $length));
    return str_replace('debugger_find_codebase_root', '', $body); // Convert to anon function
}

/**
 * @brief
 *	Implements hook_alter_js_options
 */
function debugger_alter_js_options(&$data)
{
    $data[ 'options' ][ 'debugger' ][ 'find_codebase_root' ] =
        debugger_get_source_code_for_codebase_root_finder();
}

/**
 * @brief
 *	Request handler for the files API; sends the contents of a file to the client
 *
 * @param Vortex\App $app
 */
function debugger_file_api(App $app)
{
    require_method('GET');

    // Strip off the leading 'file/' from the path and check if the corresponding file exists
    $path = trim(preg_replace('#/+#', '/', $app->request->getPathInfo()), '/');
    $file = '/' . (array_get(explode('/', $path, 2), 1, ''));
    if (!is_readable($file)) {
        $app->response->setContent("$file does not exist")->setStatusCode(404);
    } elseif (is_file($file)) { // Send the file's contents to the client
        $info = debugger_find_codebase_root($file);
        $info[ 'contents' ] = file_get_contents($file);
        $app->response->setContent($info);
    } else { // List the directory's contents for the client
        $response_data = [];
        $contents = glob("$file/*");

        foreach ($contents as $item) {
            $is_file = is_file($item);
            if (!$is_file || client_can_view_file($item)) {
                if ($app->request->query->get('view') == 'jstree') {
                    $response_data[] = [
                        'text' => basename($item),
                        'icon' => $is_file ? 'fa fa-file-code-o code' : 'fa fa-folder folder',
                        'children' => !$is_file,
                        'li_attr' => [
                            'data-full-path' => $item,
                            'data-is-file' => $is_file,
                        ],
                    ];
                } else {
                    $response_data[] = [
                        'name'     => basename($item),
                        'fullpath' => $item,
                        'is_dir'   => !$is_file,
                    ];
                }
            }
        }

        $app->response->setContent($response_data);
    }
}

/**
 * @brief
 *	Request handler for the files API; sends the client a list of the most recently edited files
 *	within the "watched" directories
 *
 * @param Vortex\App $app
 */
function debugger_recent_files_api(App $app)
{
    require_method('GET');

    $extensions = implode('\|', $app->settings->get('allowed_extensions'));
    $dirs = implode(' ', array_map('escapeshellarg', $app->settings->get('recent_dirs')));
    $n_files = DEBUGGER_N_MOST_RECENT_FILES;
    $files = [];
    exec("find $dirs -type f -regextype sed -regex '.*\.\($extensions\)' -printf '%T@ %p\n' | sort -n | tail -n $n_files | cut -f2- -d\" \"", $files);
    $files = array_filter(array_map('trim', $files));

    $response_data = [];
    foreach ($files as $item) {
        if (client_can_view_file($item)) {
            array_unshift($response_data, [
                'name'     => basename($item),
                'fullpath' => $item,
                'is_dir'   => false,
            ]);
        }
    }

    $app->response->setContent($response_data);
}

function debugger_provide_console_commands($data)
{
    $data[ 'application' ]->add(new SocketServerStartCommand());
    $data[ 'application' ]->add(new SocketServerRunCommand());
    $data[ 'application' ]->setDefaultCommand('socket-server:start');
}

function debugger_ws_message_received(&$data)
{
    $cid_prefix = DbgpApp::CONNECTION_ID_PREFIX;
    if (preg_match('/^X-glob /', $data[ 'message' ])) {
        $args = debugger_parse_glob_command($data[ 'message' ]);
        if ($args[ 'id' ] && $args[ 'pattern' ]) {
            $xml_out = '';
            foreach (glob($args[ 'pattern' ] . '*') as $item) {
                $type = is_dir($item) ? 'dir' : 'file';
                $xml_out .= "<item type=\"$type\">$item</item>";
            }
            $xml_out = "<globber transaction_id=\"$args[id]\" pattern=\"$args[pattern]\">$xml_out</globber>";
            logger()->debug("Handling X-glob command: $data[message]", $args);
            $data[ 'bridge' ]->sendToWs($xml_out);
        } else {
            logger()->warning("Ignoring improperly formatted X-glob command: $data[message]", $args);
        }
    } elseif (preg_match('/^X-ctrl:stop /', $data[ 'message' ])) {
        App::fireHook('stop_socket_server');
        logger()->info("Received stop command; killing server");
        exit('stop');
    } elseif (preg_match('/^X-ctrl:restart /', $data[ 'message' ])) {
        App::fireHook('restart_socket_server');
        logger()->info("Received restart command; restarting server");
        exit('restart');
    } elseif (preg_match('/^X-ctrl:peek_queue /', $data[ 'message' ])) {
        $data[ 'bridge' ]->sendToWs($data[ 'bridge' ]->getQueueAsXml());
    } elseif (preg_match('/^X-ctrl:detach_queued_session -s (?<id>' . $cid_prefix . '\d+) /', $data[ 'message' ], $match)) {
        $data[ 'bridge' ]->detachQueuedSession($match[ 'id' ]);
        $data[ 'bridge' ]->sendToWs('<wsserver session-status-change=neutral status="alert" type="detach_queued_session" session_id="' . $match[ 'id' ] . '">');
    } elseif (preg_match('/^X-ctrl:switch_session -s (?<id>' . $cid_prefix . '\d+) /', $data[ 'message' ], $match)) {
        $data[ 'bridge' ]->switchSession($match[ 'id' ]);
    } elseif (preg_match('/^X-ctrl:new_sessions -s ([\'"])(?<state>enable|disable)\1/', $data[ 'message' ], $match)) {
        logger()->debug("Client requested that we $match[state] new sessions");
        $data[ 'bridge' ]->setNewSessionsAllowedFlag($match[ 'state' ] == 'enable');
    } elseif (preg_match('/\s+-Xs (?<id>' . $cid_prefix . '\d+)/', $data[ 'message' ], $match)) {
        $dbg_conn = $data[ 'bridge' ]->getDbgConnection();
        if ($dbg_conn && Vortex\Cli\DbgpApp::getConnectionId($dbg_conn) != $match[ 'id' ]) {
            $data[ 'abort' ] = true;
        }
    } elseif (strpos($data[ 'message' ], 'property_set ') === 0) {
        logger()->debug('Detected `property_set` command; checking syntax...');

        $command_split_point = strrpos($data[ 'message' ], '--') + 2;
        $expression = base64_decode(trim(substr($data[ 'message' ], $command_split_point)));

        if (!debugger_validate_php_syntax($expression)) {
            logger()->debug("Invalid syntax: <<<$expression>>>; treating as string...");

            $expression        = base64_encode(quote_string($expression));
            $command           = trim(substr($data[ 'message' ], 0, $command_split_point - 2));
            $data[ 'message' ] = "$command -- $expression\0";
        }
    }
}

function debugger_before_debugger_detach($data)
{
    $data[ 'connection' ]->send(
        "eval -i 0 -- aW5pX3NldCggInhkZWJ1Zy5yZW1vdGVfcG9ydCIsIDAgKTs\0"
    ); // ini_set( "xdebug.remote_port", 0 );
}

/**
 * @brief
 *	Determines if the given snippet of code is valid PHP
 *
 * @param string $snippet
 *
 * @return bool
 */
function debugger_validate_php_syntax($snippet)
{
    $snippet = escapeshellarg("<?php $snippet");
    exec("echo $snippet | php -l > /dev/null 2>&1", $_, $status);
    return $status === 0;
}

/**
 * @brief
 *	Convert the given string into a string whose *content* is a valid single-quoted PHP string.
 *
 * Examples:
 *	"Hi, I have a widow's peak" -> "'Hi, I have a widow\'s peak'"
 *	"Hi, My name is Bob"        -> "'Hi, my name is Bob'"
 *
 * @param string $str
 *
 * @return string
 */
function quote_string($str)
{
    return preg_replace('/(?<!\\\\|^)\'(?!$)/', '', escapeshellarg($str));
}

function debugger_parse_glob_command($command)
{
    static $regex= '/(
		-p \s+  " (?P<pattern_quoted_d> [^"]+ )  " |
		-p \s+ \' (?P<pattern_quoted_s> [^"]+ ) \' |
		-p \s+    (?P<pattern> \w+ )               |
		-i \s+    (?P<id> \w+ )
	)/x';
    static $parsed_items = [
        'id'      => [ 'id' ],
        'pattern' => [ 'pattern_quoted_d', 'pattern_quoted_s', 'pattern' ],
    ];
    $out = [
        'id'      => false,
        'pattern' => false,
    ];

    $matches = [];
    if (preg_match_all($regex, $command, $matches)) {
        foreach ($parsed_items as $key => $locations) {
            foreach ($locations as $location) {
                while (($val = array_shift($matches[ $location ])) !== null) {
                    if ($val) {
                        $out[ $key ] = $val;
                        break 2;
                    }
                }
            }
        }
    }

    return $out;
}

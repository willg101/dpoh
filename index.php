<?php /* dpoh: ignore */

use Symfony\Component\HttpFoundation\Request;
use Whoops\Handler\Handler;
use Vortex\App;
use Vortex\SendAndTerminateException;

define('DPOH_ROOT', __DIR__);
define('IS_AJAX_REQUEST', !empty($_SERVER['HTTP_X_REQUESTED_WITH']));

require_once 'vendor/autoload.php';
$whoops = new Whoops\Run;
$whoops->pushHandler(IS_AJAX_REQUEST
    ? new Whoops\Handler\JsonResponseHandler
    : new Whoops\Handler\PrettyPageHandler);
$whoops->pushHandler(function($exception) {
    if ($exception instanceof SendAndTerminateException) {
        $exception->response->send();
        return Handler::QUIT;
    } else {
        return Handler::DONE;
    }
});
$whoops->register();

require_once 'includes/arrays.php';
require_once 'includes/bootstrap.php';
require_once 'includes/database.php';
require_once 'includes/exceptions.php';
require_once 'includes/files.php';
require_once 'includes/html.php';
require_once 'includes/http.php';
require_once 'includes/javascript.php';
require_once 'includes/security.php';
require_once 'includes/stylesheets.php';
require_once 'includes/templates.php';

$app = new App(__DIR__ . '/modules_enabled', __DIR__ . '/settings-global.ini');
App::setInstance($app);
bootstrap($app);
$app->response->prepare($app->request);
$app->response->send();

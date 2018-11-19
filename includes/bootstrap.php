<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use \Monolog\Handler\ErrorLogHandler;
use Symfony\Component\HttpFoundation\Request;
use Vortex\Response;
use Vortex\App;

/**
 * @brief
 *	The core of the PHP side of DPOH; boots up all modules, loads config and settings information,
 *	and then optionally renders a response
 *
 * @note During the lifecycle of a request, the following standard hooks are fired (see app::fireHook
 *	for more information):
 *	- preboot: Alter modules, user config, and settings data (MUCS). Because modules can be disabled
 *		at this step, and MUCS can (and should, if necessary) be altered at this stage, it's
 *		recommended to keep your logic at this step to a minimum (i.e., don't make decisions based
 *		on MUCS at this stage)
 *	- boot: Perform initialization tasks. So that other modules can safely make decisions based on
 *		MUCS, avoid altering MUCS at or after this stage.
 *
 * @throws FatalConfigError
 *
 * @return string|NULL
 */
function bootstrap(Request $request, Response $response)
{
    date_default_timezone_set(settings('timezone'));
    $boot_vars = [ 'request' => $request, 'response' => $response ];
    fire_hook('preboot', $boot_vars);
    fire_hook('boot', $boot_vars, true);

    if (!request_handlers()->handle($request, $response)) {
        throw new HttpException("Page not found: " . $request->getPathInfo(), [ 'HTTP/1.1 404 Not found' ]);
    }
}

/**
 * @brief
 *	Lazy-initializes the logger for this request/execution, allowing complete override of the
 *	default logging mechanism via hook_init_logger(), and then returns a monolog
 *	(or monolog-compatible) logger instance
 *
 * @return Monolog\Logger
 */
function logger()
{
    static $logger;

    if (!$logger) {
        $handler = null;
        $label   = '';
        $log_level = settings('log_level');
        $log_level = constant(Logger::class . "::$log_level");
        if (php_sapi_name() == 'cli') {
            $label   = 'cli';
            $handler = new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $log_level);
        } else {
            $label   = 'http';
            $handler = new StreamHandler(__DIR__ . "/../logs/http.log", $log_level);
        }

        $data = [
            'logger'  => null,
            'label'   => $label,
            'handler' => $handler,
        ];
        App::fireHook('init_logger', $data);

        if (!$data[ 'logger' ]) {
            $logger = new Logger("Vortex Logger ($data[label])");
            $logger->pushHandler($handler);
        } else {
            $logger = $data[ 'logger' ];
        }
    }

    return $logger;
}

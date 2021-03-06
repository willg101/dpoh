<?php

namespace Vortex\Cli;

use Ratchet\ConnectionInterface;

class ConnectionBridge
{
    /**
     * Connection to websocket client
     *
     * @var Ratchet\ConnectionInterface
     */
    protected $ws_connection;

    /**
     * @var bool
     */
    protected $ws_client_allows_new_sessions;

    /**
     * Connection to debugger engine
     *
     * @var Ratchet\ConnectionInterface
     */
    protected $dbg_connection;

    /**
     * @var Vortex\Cli\DbgpApp
     */
    protected $dbg_app;

    /**
     * @return bool
     */
    public function hasWsConnection()
    {
        return !!$this->ws_connection;
    }

    /**
     * @return bool
     */
    public function hasDbgConnection()
    {
        return !!$this->dbg_connection;
    }

    /**
     * @return ConnectionInterface
     */
    public function getWsConnection()
    {
        return $this->ws_connection;
    }

    /**
     * @return ConnectionInterface
     */
    public function getDbgConnection()
    {
        return $this->dbg_connection;
    }

    public function setWsConnection(ConnectionInterface $conn)
    {
        $this->ws_client_allows_new_sessions = true;
        $this->ws_connection = $conn;
    }

    public function setDbgConnection(ConnectionInterface $conn)
    {
        $this->dbg_connection = $conn;
    }

    /**
     * @param  Ratchet\ConnectionInterface $conn OPTIONAL. When given, ONLY clears the websocket
     *                                           connection if this param is the same as the current
     *                                           connection.
     */
    public function clearWsConnection(ConnectionInterface $conn = null)
    {
        if (!$conn || $conn == $this->ws_connection) {
            $this->ws_connection = null;
        }
    }

    /**
     * @param  Ratchet\ConnectionInterface $conn OPTIONAL. When given, ONLY clears the debugger
     *                                           engine connection if this param is the same as the
     *                                           current connection.
     */
    public function clearDbgConnection(ConnectionInterface $conn = null)
    {
        if (!$conn || $conn == $this->dbg_connection) {
            $this->dbg_connection = null;
        }
    }

    /**
     * @brief
     *	Send a message to our web socket client, if available
     *
     * @param string $msg
     * @param bool   $raw OPTIONAL. Default is FALSE. When TRUE, wraps the message similar to how
     *                    the debugger engine wraps its messages:
     *                    <int: msg length> NULL <string: msg> NULL
     */
    public function sendToWs($msg, $raw = false)
    {
        if ($this->hasWsConnection()) {
            if (!$raw) {
                $msg = WsApp::prepareMessage($msg);
            }
            $this->ws_connection->send($msg);
        }
    }

    /**
     * @brief
     *	Send a message to our debugger engine, if available
     *
     * @param string $msg
     */
    public function sendToDbg($msg)
    {
        $msg = DbgpApp::prepareMessage($msg);

        if ($this->hasDbgConnection()) {
            if (preg_match('/^detach /', $msg)) {
                $this->dbg_app->beforeDetach($this->dbg_connection);
            }

            $this->dbg_connection->send($msg);

            // Close & clear the debugger engine connection if this is a `stop` or `clear` command
            if (preg_match('/^(stop|detach) /', $msg)) {
                logger()->debug('Caught a stop or detach command; closing connection');
                $this->dbg_connection->close();
            }
        }
    }

    /**
     * @brief
     *	Store a reference to the current DbgpApp instance to facilitate session switching
     *
     * @param DbgpApp $app
     */
    public function registerDbgApp(DbgpApp $app)
    {
        $this->dbg_app = $app;
    }

    /**
     * @brief
     *	Proxy calls to $this->dbg_app->getQueueAsXml(), returning an empty string if $this->dbg_app
     *	is not defined
     *
     * @return string
     */
    public function getQueueAsXml()
    {
        return $this->dbg_app
            ? $this->dbg_app->getQueueAsXml()
            : '';
    }

    /**
     * @brief
     *	Proxy calls to $this->dbg_app->detachQueuedSession()
     */
    public function detachQueuedSession($cid)
    {
        $this->dbg_app && $this->dbg_app->detachQueuedSession($cid);
    }

    /**
     * @brief
     *	Proxy calls to $this->dbg_app->switchSession()
     */
    public function switchSession($cid)
    {
        $this->dbg_app && $this->dbg_app->switchSession($cid, $this->dbg_connection);
    }

    public function setNewSessionsAllowedFlag($new_value)
    {
        $this->ws_client_allows_new_sessions = !!$new_value;
    }

    public function isQueueable()
    {
        return $this->hasWsConnection() && $this->ws_client_allows_new_sessions;
    }
}

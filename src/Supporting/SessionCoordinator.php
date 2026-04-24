<?php

namespace INTERMediator\FileMakerServer\RESTAPI\Supporting;

use Exception;
use INTERMediator\FileMakerServer\RESTAPI\PersistentSession\PersistentSessionStore;

/**
 * SessionCoordinator coordinates session handling for the communication provider.
 *
 * It manages the authenticated communication scope used by startCommunication(),
 * endCommunication(), and withSession(). In the normal mode, it keeps one authenticated
 * session during the current communication scope. When persistent sessions are enabled,
 * it can reuse a cached session token and refresh it when needed.
 *
 * @package INTER-Mediator\FileMakerServer\RESTAPI
 * @link https://github.com/msyk/FMDataAPI GitHub Repository
 * @version 36
 */
class SessionCoordinator
{
    /**
     * @var CommunicationProvider|null The instance of the communication class.
     * @ignore
     */
    private CommunicationProvider|null $restAPI;

    /**
     * @var null|PersistentSessionStore Store for the cached persistent session token.
     * @ignore
     */
    private PersistentSessionStore|null $sessionStore;

    /**
     * SessionCoordinator constructor.
     * @param CommunicationProvider|null $restAPI
     * @param PersistentSessionStore|null $sessionStore
     * @ignore
     */
    public function __construct(CommunicationProvider|null  $restAPI,
                                PersistentSessionStore|null $sessionStore) {
        $this->restAPI = $restAPI;
        $this->sessionStore = $sessionStore;
    }

    /**
     * Start a communication scope with a shared authenticated session.
     *
     * Usually most methods login and logout before and after each database operation.
     * By calling startCommunication() and endCommunication(), methods between them don't
     * log in and out every time, and it can expect faster operations.
     *
     * When persistent sessions are not enabled, one authenticated session is kept during
     * the current communication scope.
     *
     * When persistent sessions are enabled, the cached session token is reused if available.
     * If there is no cached token, a new session is created and stored.
     * @throws Exception
     */
    public function startCommunication(): void
    {
        if ($this->sessionStore !== null) {
            try {
                $cachedToken = $this->sessionStore->get();
                if ($cachedToken !== false) {
                    $this->restAPI->accessToken = $cachedToken;
                } else {
                    if ($this->restAPI->login() && $this->restAPI->accessToken !== null) {
                        $this->sessionStore->set($this->restAPI->accessToken);
                    }
                }
                $this->restAPI->keepPersistentSession = true;
            } catch (Exception $e) {
                $this->sessionStore->clear();
                $this->restAPI->accessToken = null;
                $this->restAPI->keepPersistentSession = false;
                throw $e;
            }
        } else {
            try {
                if ($this->restAPI->login()) {
                    $this->restAPI->keepAuth = true;
                }
            } catch (Exception $e) {
                $this->restAPI->keepAuth = false;
                throw $e;
            }
        }
    }

    /**
     * Finish a communication scope and logout.
     *
     * When persistent sessions are not enabled, the authenticated session for the current
     * communication scope is ended.
     *
     * When persistent sessions are enabled, the cached session token is cleared and the
     * current session is logged out.
     *
     * @throws Exception
     */
    public function endCommunication(): void
    {
        if ($this->sessionStore !== null) {
            $this->restAPI->keepPersistentSession = false;
            $this->restAPI->accessToken = null;
            return;
        }

        $this->restAPI->keepAuth = false;
        $this->restAPI->logout();
    }

    /**
     * Execute a callback within a communication scope.
     *
     * This method starts a communication scope before invoking the callback and always ends
     * the communication scope afterward.
     *
     * When persistent sessions are enabled, the callback may use a cached session token.
     *
     * @template TParam
     * @template TReturn
     * @param callable(TParam): TReturn $fn
     * @param TParam $input
     * @return TReturn
     * @throws Exception Any exception thrown by the callback or the underlying provider.
     */
    public function withSession(callable $fn, mixed $input)
    {
        $this->startCommunication();
        try {
            return $fn($input);
        } finally {
            $this->endCommunication();
        }
    }

    /**
     * Execute a callback with the supplied input by using the current session handling behavior.
     *
     * When persistent sessions are not enabled, the callback is invoked immediately.
     *
     * When persistent sessions are enabled, this method uses the cached session when available.
     * If FileMaker returns error code 952, the session is refreshed and the callback is retried once.
     *
     * Note that the callback can be executed up to two times when retrying after an expired session.
     *
     * @template TParam
     * @template TReturn
     * @param callable(TParam): TReturn $fn
     * @param TParam $input
     * @return TReturn
     * @throws Exception Any exception thrown by the callback or the underlying provider.
     */
    public function executeWithSessionRetry(callable $fn, mixed $input)
    {
        if (!$this->restAPI->keepPersistentSession || $this->sessionStore === null) {
            return $fn($input);
        }

        if ($this->restAPI->throwExceptionInError) {
            try {
                return $fn($input);
            } catch (Exception $e) {
                if ($this->restAPI->errorCode == 952) {
                    if (!$this->refresh()) {
                        throw new Exception("Unable to refresh persistent session.");
                    }
                    return $fn($input);
                }
                throw $e;
            }
        }

        $result = $fn($input);
        if ($this->restAPI->errorCode == 952) {
            if (!$this->refresh()) {
                return null;
            }
            return $fn($input);
        }
        return $result;
    }

    /**
     * Clear the current persistent session state, log in again, and cache the refreshed session token.
     * @return bool Returns true if the session was refreshed successfully, or false if re-authentication failed.
     * @throws Exception
     */
    private function refresh(): bool
    {
        $this->sessionStore->clear();
        $this->restAPI->accessToken = null;
        if (!$this->restAPI->login() || $this->restAPI->accessToken === null) {
            return false;
        }
        $this->sessionStore->set($this->restAPI->accessToken);
        return true;
    }
}

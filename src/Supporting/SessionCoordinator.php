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
            $this->startPersistentCommunication();
            return;
        }

        $this->startNonPersistentCommunication();
    }

    /**
     * Finish a communication scope.
     *
     * When persistent sessions are not enabled, the authenticated session for the current
     * communication scope is ended and the server session is logged out.
     *
     * When persistent sessions are enabled, only the local in-memory state is reset.
     * The cached token in the persistent store and the FileMaker server session are
     * left intact so the next PHP request can reuse them.
     *
     * @throws Exception Only when the non-persistent logout call fails
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
            return $this->executeWithSessionRetry($fn, $input);
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
    private function executeWithSessionRetry(callable $fn, mixed $input)
    {
        if (!$this->restAPI->keepPersistentSession || $this->sessionStore === null) {
            return $fn($input);
        }

        if ($this->restAPI->throwExceptionInError) {
            try {
                return $fn($input);
            } catch (Exception $e) {
                if ($this->restAPI->errorCode == 952) {
                    if (!$this->refreshPersistentSession()) {
                        throw new Exception("Unable to refresh persistent session.");
                    }
                    return $fn($input);
                }
                throw $e;
            }
        }

        $result = $fn($input);
        if ($this->restAPI->errorCode == 952) {
            if (!$this->refreshPersistentSession()) {
                return $result;
            }
            return $fn($input);
        }
        return $result;
    }

    /**
     * Start a non-persistent authenticated session for the current communication scope.
     *
     * @throws Exception
     */
    private function startNonPersistentCommunication(): void
    {
        try {
            if ($this->restAPI->login()) {
                $this->restAPI->keepAuth = true;
                return;
            }

            $this->restAPI->keepAuth = false;
        } catch (Exception $e) {
            $this->restAPI->keepAuth = false;
            throw $e;
        }
    }

    /**
     * Start a persistent communication scope by reusing a cached token when available,
     * or by creating and storing a new persistent session.
     *
     * @throws Exception
     */
    private function startPersistentCommunication(): void
    {
        $cachedToken = $this->sessionStore->getAndKeepAlive();
        if ($cachedToken !== null) {
            $this->activatePersistentSession($cachedToken);
            return;
        }

        $this->establishPersistentSession();
    }

    /**
     * Log in, cache the session token, and activate persistent-session mode.
     *
     * On failure, persistent-session state is reset. If login throws, the state is
     * reset and the exception is rethrown.
     *
     * @return bool Returns true if the persistent session was established successfully, or false if login failed.
     * @throws Exception
     */
    private function establishPersistentSession(): bool
    {
        try {
            if (!$this->restAPI->login() || $this->restAPI->accessToken === null) {
                $this->resetPersistentSessionState();
                return false;
            }

            $token = $this->restAPI->accessToken;
            $this->sessionStore->set($token);
            $this->activatePersistentSession($token);
            return true;
        } catch (Exception $e) {
            $this->resetPersistentSessionState();
            throw $e;
        }
    }

    /**
     * Set the active token and enable persistent-session mode.
     * @param string $token The session token to activate.
     */
    private function activatePersistentSession(string $token): void
    {
        $this->restAPI->accessToken = $token;
        $this->restAPI->keepPersistentSession = true;
    }

    /**
     * Clear cached and in-memory persistent-session state.
     */
    private function resetPersistentSessionState(): void
    {
        $this->sessionStore->clear();
        $this->restAPI->accessToken = null;
        $this->restAPI->keepPersistentSession = false;
    }

    /**
     * Reset the current persistent session state and attempt to establish a new one.
     *
     * @return bool Returns true if the persistent session was refreshed successfully, or false if re-authentication failed.
     * @throws Exception
     */
    private function refreshPersistentSession(): bool
    {
        $this->resetPersistentSessionState();
        return $this->establishPersistentSession();
    }
}

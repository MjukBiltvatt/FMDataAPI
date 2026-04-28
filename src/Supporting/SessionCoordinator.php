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
 * Concurrency note: under high concurrency with a cold cache, multiple workers may
 * each perform a login before one cached token wins. Orphaned tokens are cleaned up
 * by their owning workers at the end of their communication scope, so no sessions
 * leak on the FileMaker server, but cold-cache logins are not deduplicated.
 *
 * @package INTER-Mediator\FileMakerServer\RESTAPI\Supporting
 * @link https://github.com/msyk/FMDataAPI GitHub Repository
 * @version 36
 */
class SessionCoordinator
{
    /**
     * @var CommunicationProvider The instance of the communication class.
     * @ignore
     */
    private CommunicationProvider $restAPI;
    /**
     * @var PersistentSessionStore|null Store for the cached persistent session token, or null if persistent sessions are disabled.
     * @ignore
     */
    private PersistentSessionStore|null $sessionStore;
    /**
     * @var bool Indicates whether we are currently in a communication scope.
     * @ignore
     */
    private bool $inCommunicationScope = false;

    /**
     * SessionCoordinator constructor.
     * @param CommunicationProvider $restAPI The communication provider.
     * @param PersistentSessionStore|null $sessionStore Store for the cached persistent
     *                                                  session token, or null to disable
     *                                                  persistent sessions.
     * @ignore
     */
    public function __construct(
        CommunicationProvider       $restAPI,
        PersistentSessionStore|null $sessionStore = null)
    {
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
        } else {
            $this->startNonPersistentCommunication();
        }

        $this->inCommunicationScope = true;
    }

    /**
     * Finish a communication scope.
     *
     * When persistent sessions are not enabled, the authenticated session for the current
     * communication scope is ended and the server session is logged out.
     *
     * When persistent sessions are enabled, the cached token is renewed if it still matches
     * the token held by this instance. If another worker has replaced the cached token in
     * the meantime, only this instance's (now-stale) token is logged out, leaving the
     * newer cached token intact.
     *
     * @throws Exception Only when the logout call fails.
     */
    public function endCommunication(): void
    {
        $this->inCommunicationScope = false;

        if ($this->sessionStore !== null) {
            $this->endPersistentCommunication();
            return;
        }

        $this->restAPI->keepAuth = false;
        $this->restAPI->logout();
    }

    /**
     * Execute a callback within a communication scope.
     *
     * If a communication scope is already active (via startCommunication()), the callback
     * is executed within that scope without opening or closing it. Otherwise, a new
     * communication scope is opened, the callback is executed, and the scope is always
     * closed afterward even if the callback throws.
     *
     * When persistent sessions are enabled, the callback may use a cached session token.
     * If FileMaker returns error code 952, the session is refreshed and the callback is
     * retried once automatically.
     *
     * @template TParam
     * @template TReturn
     * @param callable(TParam): TReturn $fn The operation to execute within the session scope.
     * @param TParam $input The value passed as the argument to the callback.
     * @return TReturn
     * @throws Exception Any exception thrown by the callback or the underlying provider.
     */
    public function withSession(callable $fn, mixed $input): mixed
    {
        $ownScope = !$this->inCommunicationScope;

        if ($ownScope) {
            $this->startCommunication();
        }

        try {
            return $this->executeInScope(fn() => $fn($input));
        } finally {
            if ($ownScope) {
                $this->endCommunication();
            }
        }
    }

    /**
     * Start a non-persistent authenticated session for the current communication scope.
     * @throws Exception
     */
    private function startNonPersistentCommunication(): void
    {
        try {
            $this->restAPI->keepAuth = $this->restAPI->login();
        } catch (Exception $e) {
            $this->restAPI->keepAuth = false;
            throw $e;
        }
    }

    /**
     * Start a persistent communication scope by reusing a cached token when available
     * or by creating and storing a new persistent session.
     * @throws Exception
     */
    private function startPersistentCommunication(): void
    {
        $cachedToken = $this->sessionStore->get();
        if ($cachedToken !== null) {
            $this->activatePersistentSession($cachedToken);
            return;
        }

        $this->establishPersistentSession();
    }

    /**
     * End a persistent communication scope.
     *
     * If the token held by this instance still matches the cached token, the cache
     * entry is renewed for another TTL period. If the tokens differ — meaning another
     * worker has refreshed the session since this instance started — only this
     * instance's token is logged out and the cache is left untouched.
     *
     * In-memory session state is always cleared regardless of outcome.
     * @throws Exception
     */
    private function endPersistentCommunication(): void
    {
        $ourToken = $this->restAPI->accessToken;
        $cachedToken = $this->sessionStore->get();

        // Always clear in-memory state first
        $this->restAPI->keepPersistentSession = false;
        $this->restAPI->accessToken = null;

        if ($ourToken === null) {
            return;
        }

        if ($ourToken === $cachedToken) {
            // Happy path: our token is still the live one, renew it
            $this->sessionStore->set($ourToken);
            // if the cache write fails, the token will expire naturally
        } else {
            // Another worker refreshed the cache while we were running.
            // Log out only our own (now-stale) token — don't touch theirs.
            $this->restAPI->accessToken = $ourToken;
            $this->restAPI->logout();
            $this->restAPI->accessToken = null;
        }
    }

    /**
     * Execute a callback with the current session handling behavior.
     *
     * When persistent sessions are not enabled, the callback is invoked immediately.
     * When persistent sessions are enabled, the callback is executed using the cached
     * session token, retrying once if FileMaker returns error code 952.
     *
     * @param callable(): mixed $fn The operation to execute.
     * @return mixed
     * @throws Exception Any exception thrown by the callback or the underlying provider.
     */
    private function executeInScope(callable $fn): mixed
    {
        if ($this->sessionStore === null) {
            return $fn();
        }

        return $this->execute(
            $fn,
            fn() => $this->refreshPersistentSession()
        );
    }

    /**
     * Execute a callback, retrying once if FileMaker returns error code 952.
     *
     * When the provider is configured to throw exceptions on error, a 952 exception
     * triggers $onRefresh and the callback is retried. If the refresh fails, the
     * original exception is rethrown wrapped in a new Exception.
     *
     * When the provider is not configured to throw exceptions, the error code is
     * checked after the call returns. If 952 is detected, $onRefresh is called
     * and the callback is retried. If the refresh fails, the original result is returned.
     *
     * Note that the callback may be invoked up to two times when a retry occurs.
     *
     * @template TReturn
     * @param callable(): TReturn $fn The operation to execute.
     * @param callable(): bool $onRefresh Called when error 952 is detected.
     *                                    Should re-establish the session and return
     *                                    true on success or false if re-authentication failed.
     * @return TReturn
     * @throws Exception Any exception thrown by the callback, or if the session
     *                   could not be refreshed when throwExceptionInError is enabled.
     */
    private function execute(callable $fn, callable $onRefresh): mixed
    {
        if ($this->restAPI->throwExceptionInError) {
            try {
                return $fn();
            } catch (Exception $e) {
                if ($this->restAPI->errorCode === 952) {
                    if (!$onRefresh()) {
                        throw new Exception("Unable to refresh persistent session.", 0, $e);
                    }
                    return $fn();
                }
                throw $e;
            }
        }

        $result = $fn();
        if ($this->restAPI->errorCode === 952) {
            if (!$onRefresh()) {
                return $result;
            }
            return $fn();
        }
        return $result;
    }

    /**
     * Log in, cache the session token, and activate persistent-session mode.
     *
     * If the cache write fails, persistent-session state is reset and the coordinator
     * falls back to non-persistent keepAuth behavior for the current request.
     *
     * On login failure, persistent-session state is reset. If login throws, the
     * state is reset and the exception is rethrown.
     *
     * @return bool Returns true if the persistent session was established successfully,
     *              or false if login failed or the cache write could not be completed.
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

            if ($this->sessionStore->set($token) === false) {
                // Cache write failed — reset and degrade gracefully to
                // non-persistent keepAuth behavior for this request
                $this->resetPersistentSessionState();
                $this->restAPI->keepAuth = true;
                return false;
            }

            $this->activatePersistentSession($token);
            return true;
        } catch (Exception $e) {
            $this->resetPersistentSessionState();
            throw $e;
        }
    }

    /**
     * Reset the current persistent session state and attempt to establish a new one.
     * @return bool Returns true if the persistent session was refreshed successfully,
     *              or false if re-authentication failed.
     * @throws Exception
     */
    private function refreshPersistentSession(): bool
    {
        $this->resetPersistentSessionState();
        return $this->establishPersistentSession();
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
}

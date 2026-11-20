# 2.7.0

### Changes

- `Client` takes React's own loop (`React\EventLoop\Loop::get()`) when no loop is passed, instead of `voryx/event-loop`'s `getLoop()`. It is the same loop, but `getLoop()` also registers a shutdown function that runs the loop when nobody did, which a long-running server or a command does not want. Code that passes a loop is unaffected.

# 2.6.0

### Bug Fixes

- A cancel request can no longer hit a later, unrelated statement of the same connection. The cancel is sent on its own socket and closing that socket says nothing about the backend having handled it (a pooler in front answers it itself), so a statement that ended just before the signal arrived left it to cancel whatever ran next, aborting a transaction or failing an innocent query. The connection is now reused only when the statement the cancel was sent for ended with `query_canceled` (57014), which proves the signal was consumed. When it ended any other way the backend may still be signalled: outside a transaction the connection starts a new session (queued commands are carried over, a `reconnect` event is emitted); inside one it is closed. A cancel that is never answered (`cancel_timeout`, seconds, default 5) or cannot connect does the same instead of holding the connection for ever.
- `Connection::getBacklogLength()` no longer counts commands whose subscriber went away, so a transaction waiting for a connection is not left waiting when only skipped commands are queued.

# 2.5.0

### Bug Fixes

- A transaction subscriber that goes away while `BEGIN`, `COMMIT` or `ROLLBACK` is on its way no longer leaks the reserved connection (and a `BEGIN` that still runs is rolled back before the connection is reused); a subscriber that goes away before it is handed the connection gives it back.
- Closing the socket fails the command that is in flight (it was never failed), and a call on a closed connection fails at once instead of waiting for ever. `Connection::kill()` and `Transaction::discard()` drop a connection whose backend no longer answers.
- Disposing a running query or statement asks the server to cancel it immediately instead of after the next bytes arrive (a long statement was only cancelled once it had finished).
- A cancelled command no longer notifies its disposed subscriber, and failing a command that only prepares a statement no longer throws.
- Transactions are served before queries are pipelined onto busy connections, so a stream of queries cannot starve a transaction. `acquire_timeout` (seconds, default 30, 0 waits for ever) fails a wait for a connection instead of hanging.
- An empty query (`''`, a comment, `;`) completes with no rows instead of hanging; `MERGE n` reports its row count.
- `tls` is validated from the parameters (it was checked against the default), and `verify-ca` verifies the certificate chain.
- `ErrorException::getExtraInfo()` returns `[]` instead of null, and `getSqlState()` / `ErrorResponse::getSqlState()` expose the SQLSTATE.
- The `error` listener of a statement that started its connection is removed when the statement is cancelled while running.

### Features

- `Transaction::whenReady()` and `Transaction::getBackendTransactionStatus()` (`E` after a failed statement), `Client::getReservedConnectionCount()`.

# 1.0.3

### Bug Fixes

- Fixed recursion issue

# 1.0.2

### Bug Fixes

- Fixed #19. Passwords should be null terminated strings.

# 1.0.1

### Bug Fixes

- Fixed disconnect endless loop when CONNECTION_BAD #17 #18

# 1.0.0

### Bug Fixes

- Fixed `auto_disconnect` not actually disconnecting #14 #15

### Features

- Added 0.5.* as supported version for `react/socket-client` #13

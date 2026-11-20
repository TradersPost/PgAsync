<?php

use PgAsync\Connection;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

class ConnectionTlsTest extends TestCase
{
    public function testAnUnknownTlsModeIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);

        new Connection(['user' => 'u', 'database' => 'd', 'tls' => 'sometimes'], Loop::get());
    }

    public function testEveryDocumentedTlsModeIsAccepted()
    {
        foreach (Connection::TLS_MODE_LIST_FULL as $mode) {
            $connection = new Connection(['user' => 'u', 'database' => 'd', 'tls' => $mode], Loop::get());

            $this->assertInstanceOf(Connection::class, $connection);
        }
    }
}

<?php

use PgAsync\ErrorException;
use PgAsync\Message\ErrorResponse;
use PHPUnit\Framework\TestCase;

class ErrorExceptionTest extends TestCase
{
    private function response(): ErrorResponse
    {
        $response = new ErrorResponse();
        $fields   = "SERROR\0C23505\0Mduplicate key value violates unique constraint \"t_pkey\"\0\0";
        $response->parseMessage('E' . pack('N', strlen($fields) + 4) . $fields);

        return $response;
    }

    public function testExtraInfoIsAnArrayEvenWhenNoneWasGiven()
    {
        $exception = new ErrorException($this->response());

        $this->assertSame([], $exception->getExtraInfo());
    }

    public function testExtraInfoIsKept()
    {
        $exception = new ErrorException($this->response(), ['query_string' => 'INSERT']);

        $this->assertSame(['query_string' => 'INSERT'], $exception->getExtraInfo());
    }

    public function testTheSqlStateOfTheErrorIsAvailable()
    {
        $exception = new ErrorException($this->response());

        $this->assertSame('23505', $exception->getSqlState());
    }
}

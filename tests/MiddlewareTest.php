<?php

use RateLimiter\Middleware;

class MiddlewareTest extends \PHPUnit_Framework_TestCase
{
    var $redis_settings = [
        'host' => '127.0.0.1',
        'port' => '6379'
    ];

    public function testConstructorWithArguments()
    {
        $middlware = new Middleware([], $this->redis_settings);

        $this->assertInstanceOf('\RateLimiter\Middleware', $middlware);
    }

    public function testDefaultSettings()
    {
        $middlware = new Middleware([], $this->redis_settings);

        $this->assertEquals(6000, $middlware->settings['hourly_request_limit']);
        $this->assertEquals(200, $middlware->settings['minute_request_limit']);
        $this->assertEquals(20, $middlware->settings['second_request_limit']);
    }

    public function testCustomSettings()
    {
        $middlware = new Middleware(['hourly_request_limit' => 5000, 'minute_request_limit' => 100, 'second_request_limit' => 10], $this->redis_settings);

        $this->assertEquals(5000, $middlware->settings['hourly_request_limit']);
        $this->assertEquals(100, $middlware->settings['minute_request_limit']);
        $this->assertEquals(10, $middlware->settings['second_request_limit']);
    }


    public function testLimitNotReached() {
        $middlware = new Middleware([], $this->redis_settings);

        $middlware->withName('requests:not_reached')->withTimeInternal(1)->limit(10);
        $middlware->withName('requests:not_reached')->withTimeInternal(1)->limit(10);
    }

    public function testOverLimitRate() {

        $this->setExpectedException('RateLimiter\Exceptions\RateLimitException');

        $middlware = new Middleware([], $this->redis_settings);

        $middlware->withName('requests:over_limit')->withTimeInternal(1)->limit(1);
        $middlware->withName('requests:over_limit')->withTimeInternal(1)->limit(1);
    }

    public function testRecordLimit() {

        $this->setExpectedException('RateLimiter\Exceptions\RateLimitException');

        $middlware = new Middleware([], $this->redis_settings);

        $middlware->withName('requests:record')->withTimeInternal(20)->record(20);
        $middlware->withName('requests:record')->withTimeInternal(20)->limit(20);
    }

    public function testRequestTagGeneration() {

        $middlware = new Middleware([], $this->redis_settings);

        $middlware->withUserId('100')->withName('request_name');
        $this->assertEquals('100:request_name:', $middlware->getRequestTag());
    }


    public function testFlush() {

        $middlware = new Middleware([], $this->redis_settings);

        $middlware->withName('requests:record')->withTimeInternal(20)->record(20);
        $middlware->flushAll();
        $middlware->withName('requests:record')->withTimeInternal(20)->limit(20);
    }
}

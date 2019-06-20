<?php
namespace Unifreak\QLog;

use Predis\Client as Redis;

// @todo:
// test beautiful dump, both in browser and in cli
// try out travis build? see <https://blog.martinhujer.cz/17-tips-for-using-composer-efficiently/>
// follow <composer global require hirak/prestissimo> suggestions
// mail to $config['receiver'] when level up to $config['mail_level']
//
// @think:
// what should be done if QLog error out (invalid redis and so on...)? WhatFailerGroupHandler?
class QLogTest extends \PHPUnit_Framework_TestCase
{
    const Q_NAME = 'qlog_test';

    private $redis;
    private $logger;

    public function setUp()
    {
        $this->redis = new Redis(['host' => getenv('redis_host'), 'post' => getenv('redis_port')]);
    }

    public function tearDown()
    {
        $this->redis->del(self::Q_NAME);
    }

    private function logger(array $config = [])
    {
        return new QLogger($this->redis, $this->mockConfig($config));
    }

    private function mockConfig(array $toMerge)
    {
        return array_merge([
            'queue_name' => self::Q_NAME,
            'log_to' => QLogger::LOG_BOTH
        ], $toMerge);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testInvalidConfigException()
    {
        new QLogger($this->redis, []);
    }

    public function disableAndLogToProvider()
    {
        return [
            // if disabled, all will be 0
            [true, QLogger::LOG_BOTH, 0, 0],
            [true, QLogger::LOG_STASH, 0, 0],
            [true, QLogger::LOG_REDIS, 0, 0],
            // else:
            [false, QLogger::LOG_BOTH, 1, 1],
            [false, QLogger::LOG_REDIS, 0, 1],
            [false, QLogger::LOG_STASH, 1, 0],
        ];
    }

    /**
     * @dataProvider disableAndLogToProvider
     */
    public function testDisabledConfig($disable, $logTo, $stashCount, $redisCount)
    {
        $logger = $this->logger(['disable' => $disable, 'log_to' => $logTo]);
        $logger->info('to stash');
        $this->assertCount($stashCount, $logger->stashed());
        $this->assertCount($redisCount, $this->redis->lrange(self::Q_NAME, 0, -1));
    }

    public function testLogStructure()
    {
        $logger = $this->logger();
        $logger->warning('warning log', ['with' => ['some' => 'context']]);

        $log = $logger->pop();
        $this->assertEquals('warning log', $log['message']);
        $this->assertEquals(['with' => ['some' => 'context']], $log['context']);
        $this->assertEquals(QLogger::WARNING, $log['level']);

        $requiredKeys = [
            'channel', 'session', 'level', 'datetime',
            'time', 'time_total', 'mem'
        ];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $log);
        }
    }

    public function testChannel()
    {
        $logger = $this->logger(['default_channel' => 'default']);
        $this->assertEquals($logger->getName(), 'default');

        $logger = $this->logger();
        $this->assertEquals($logger->getName(), QLogger::CHANNEL_APP);

        $logger->in('custom channel')->warn('nothing');
        $this->assertEquals('custom channel', $logger->pop()['channel']);

        $logger->inSql()->info('to channel sql');
        $this->assertEquals(QLogger::CHANNEL_SQL, $logger->pop()['channel']);
    }

    public function testTimer()
    {
        $logger = $this->logger();
        $logger->info('start');
        usleep(10000);
        $logger->info('10 ms passed');
        usleep(5000);
        $logger->info('5 ms passed');

        $log = $logger->shift();
        $this->assertEquals('0 ms', $log['time']);
        $this->assertEquals('0 ms', $log['time_total']);

        // due to extra time cost of script, cannot test to millisecond precision, so use greateThan
        // fix? try overload microtime() function like glopgar/monolog-timer-processor does
        $log = $logger->shift();
        $this->assertGreaterThan('10 ms', $log['time']);

        $log = $logger->shift();
        $this->assertGreaterThan('15 ms', $log['time_total']);
    }

    public function testSize()
    {
        $logger1 = $this->logger(['size' => 3]);
        $logger1->info('log 1');
        $logger1->info('log 2');

        $logger2 = $this->logger(['size' => 2]);
        $logger2->info('log 3');
        $logger2->info('log 4');
        $logger2->info('log 5');

        // the last size definition (2) wins
        $this->assertEquals(2, $this->redis->llen(self::Q_NAME));

        $first = json_decode($this->redis->lpop(self::Q_NAME), true);
        $last = json_decode($this->redis->rpop(self::Q_NAME), true);
        $this->assertEquals('log 4', $first['message']);
        $this->assertEquals('log 5', $last['message']);
    }

    public function testNewSession()
    {
        $logger = $this->logger();
        $session = $logger->getSession();

        $logger->info('test new session 1');
        $logger->info('test new session 2');
        foreach ($logger->stashed() as $log) {
            $this->assertNotEmpty($log['session']);
            $this->assertEquals($session, $log['session']);
        }
    }

    public function testAutoSession()
    {
        $_COOKIE[QLogger::SESSION_KEY] = 'test_qlog_session';
        $logger = $this->logger();
        $logger->info('test cookie session 1');
        $logger->info('test cookie session 2');
        foreach ($logger->stashed() as $log) {
            $this->assertEquals($_COOKIE[QLogger::SESSION_KEY], $log['session']);
        }
    }

    /**
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage id must end with
     */
    public function testInvalidIdException()
    {
        $logger = $this->logger();
        $logger->idBy('car', 123);
    }

    public function testId()
    {
        $logger = $this->logger();
        $logger->idBy('car_id', '123')->info('car info');
        $logger->idBy('car_id', '234')->info('car info');
        $logger->idBy('user_id', '345')->info('user info');

        $log = $logger->shift();
        $this->assertEquals('123', $log['car_id']);

        $log = $logger->shift();
        $this->assertEquals('234', $log['car_id']);

        $log = $logger->shift();
        $this->assertEquals('234', $log['car_id']);
        $this->assertEquals('345', $log['user_id']);
    }

    public function testMatchStashed()
    {
        $logger = $this->logger();
        $logger->idBy('some_id', 123)->debug('debugging');
        $logger->idBy('someother_id', 234)->info('infoing');
        $logger->info('some info message');
        $logger->info('some info message and more');

        $withSomeIdLogs = $logger->stashed(function($log) {
            return isset($log['some_id']);
        });
        $this->assertCount(4, $withSomeIdLogs);

        $withSomeOtherIdLogs = $logger->stashed(function($log) {
            return isset($log['someother_id']);
        });
        $this->assertCount(3, $withSomeOtherIdLogs);

        $withMessageLogs = $logger->stashed(function($log) {
            return $log['message'] == 'some info message';

        });
        $this->assertCount(1, $withMessageLogs);

        $this->assertCount(4, $logger->stashed());
    }
}

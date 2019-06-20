<?php
namespace Unifreak\QLog;

use Monolog\Logger;
use Monolog\Handler\GroupHandler;
use Monolog\Handler\RedisHandler;
use Unifreak\QLog\Handler\StashHandler;
use Unifreak\QLog\Processor\IdProcessor;
use Unifreak\QLog\Formatter\QLogFormatter;
use Unifreak\QLog\Processor\TimerProcessor;
use Unifreak\QLog\Processor\SessionProcessor;
use Monolog\Handler\NativeMailerHandler;
use Monolog\Processor\MemoryUsageProcessor;

class QLogger extends Logger
{
    const SESSION_KEY = 'QLOG_SESSION';

    const CHANNEL_APP = 'app';
    const CHANNEL_SQL = 'sql';
    const CHANNEL_API = 'api';
    const CHANNEL_REQ = 'req';
    const CHANNEL_RESP = 'resp';

    const LOG_STASH = 0;
    const LOG_REDIS = 1;
    const LOG_BOTH = 2;

    private static $logTo = [self::LOG_STASH, self::LOG_REDIS, self::LOG_BOTH];
    private static $channels = [
        self::CHANNEL_APP,
        self::CHANNEL_SQL,
        self::CHANNEL_API,
        self::CHANNEL_REQ,
        self::CHANNEL_RESP,
    ];

    private $disabled = false;
    private $config = [];
    private $redis;
    private $stash;
    private $idProcessor;

    public function __construct($redis, array $config = [])
    {
        $config = $this->createConfig($config);
        if ($config['disable']) {
            $this->disabled = true;
        }

        parent::__construct($config['default_channel']);

        $this->initHandlers($redis, $config);
        $this->initProcessors();
    }

    public function addRecord($level, $message, array $context = array())
    {
        if ($this->disabled) {
            return false;
        }
        return parent::addRecord($level, $message, $context);
    }

    private function createConfig(array $config)
    {
        $required = ['queue_name'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                throw new \InvalidArgumentException("config item `{$field}` can not be empty");
            }
        }

        $defaults = [
            'disable' => false,
            'default_channel' => self::CHANNEL_APP,
            'size' => 3000,
            'log_to' => self::LOG_REDIS,
        ];
        foreach ($defaults as $item => $default) {
            $config[$item] = isset($config[$item]) ? $config[$item] : $default;
        }

        return $config;
    }

    private function initHandlers($redis, array $config)
    {
        $this->stash = $this->stashHandler();
        $this->redis = $this->redisHandler($redis, $config);

        $handlers = [];
        switch ($config['log_to']) {
            case self::LOG_STASH:
                array_push($handlers, $this->stash);
                break;
            case self::LOG_REDIS:
                array_push($handlers, $this->redis);
                break;
            default:
                array_push($handlers, $this->stash);
                array_push($handlers, $this->redis);
        }

        $group = new GroupHandler($handlers);
        $group->setFormatter(new QLogFormatter($this->getSession()));
        $this->pushHandler($group);
    }

    private function redisHandler($redis, $config)
    {
        return new RedisHandler($redis, $config['queue_name'], self::DEBUG, true, $config['size']);
    }

    private function stashHandler()
    {
        return new StashHandler();
    }

    private function initProcessors()
    {
        $this->idProcessor = new IdProcessor();

        $this->pushProcessor($this->idProcessor);
        $this->pushProcessor(new SessionProcessor($this->getSession()));
        $this->pushProcessor(new TimerProcessor());
        $this->pushProcessor(new MemoryUsageProcessor());
    }

    public function in($channel)
    {
        $this->name = $channel;
        return $this;
    }

    public function idBy($id, $value)
    {
        $this->idProcessor->setId($id, $value);
        return $this;
    }

    public function getSession()
    {
        if (empty($this->session)) {
            $this->session = isset($_COOKIE['QLOG_SESSION'])
                ? $_COOKIE['QLOG_SESSION']
                : md5(uniqid());
        }
        return $this->session;
    }

    public function __call($method, $args)
    {
        if (method_exists($this->stash, $method)) {
            return call_user_func_array(array($this->stash, $method), $args);
        }

        if (substr($method, 0, 2) == 'in') {
            $channel = strtolower(substr($method, 2));
            if (in_array($channel, self::$channels)) {
                return $this->in($channel);
            }
        }

        throw new \BadMethodCallException(
            'Call to undefined method ' . get_class($this) . '::' . $method . '()'
        );
    }
}

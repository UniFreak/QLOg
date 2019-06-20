<?php
namespace Unifreak\QLog\Processor;

class TimerProcessor
{
    private $last;
    private $start;

    public function __construct()
    {
        $start = microtime(true);
        $this->start = $start;
        $this->last = $start;
    }

    public function __invoke(array $record)
    {
        $now = microtime(true);
        $record['time'] = number_format(($now - $this->last), 3) * 1000 . ' ms';
        $record['time_total'] = number_format(($now - $this->start), 3) * 1000 . ' ms';
        $this->last = $now;

        return $record;
    }
}
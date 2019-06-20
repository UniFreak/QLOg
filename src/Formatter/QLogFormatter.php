<?php
namespace Unifreak\QLog\Formatter;

use Monolog\Formatter\FormatterInterface;

class QLogFormatter implements FormatterInterface
{
    public function format(array $record)
    {
        $extra = $record['extra'];
        unset($record['extra']);

        $record['datetime'] = $record['datetime']->format('Y:m:d H:i:s.u');
        return json_encode(array_merge($record, ['mem' => $extra['memory_usage']]));
    }

    public function formatBatch(array $records) {}
}

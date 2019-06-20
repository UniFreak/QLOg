中文用户请见 <https://github.com/UniFreak/QLog/blob/master/README.cn.md>

`QLog` wraps around `Monolog` package, and added some customized Handlers/Processors/Formatters, to provide these features:

- Log records into stash zone (for latter output and debugging) or into redis queue, according to configs
- Provide some pre-defined log channels and corresponding methods to log into specified channels
- All records have additional fields, provide info about time consuming and memory usage. See [Some Conceptions](#log_structure)
- Auto generate a session key for each record. See [Some Conceptions](#log_structure)
- Tag records with `_id` identifiers
- Search stashed records

For now, two main use case of `QLog` are:

- Print stashed records in api response, for realtime debugging
- Process redis queued records asynchronously, aggregate them into one place (like ES) for search or visualization

# Installation

Simply run `composer require unifreak/qlog`

# Usage

## Some Concepts <a name="log_structure"></a>

`QLog` logs records as an array with a pre-defined structure, like this:

```php
[
    "message" => "api call to api.example.com",
    "context" => [
        "method" => "GET",
        "params" => [
            "id" => 12345,
            "name" => "John",
        ],
    ],
    "level" => 200,
    "level_name" => "INFO",
    "channel" => "app",
    "datetime" => "2019:03:28 18:53:19.587777",
    "time" => "15 ms",
    "time_total" => "15 ms",
    "session" => "15a1c8220475f41990cc389ad5f6b495",
    "mem" => "3 MB",
]
```

- `message`: Log message
- `context`: Log context
- `level` & `level_name`

    Log level, supported levels are `DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERROR`, `CRITICAL`, `ALERT`, `EMERGENCY`. You can specify level by calling corresponding log methods. See [Log](#log_methods)

- `channel`

    Log channel. Each log is in a specified channel, to indicate what application event this log maybe related to.

    QLog has several pre-defined channels:

    ```php
    QLogger::CHANNEL_APP = 'app'; // Applicatoin
    QLogger::CHANNEL_SQL = 'sql'; // SQL
    QLogger::CHANNEL_API = 'api'; // Api call
    QLogger::CHANNEL_REQ = 'req'; // Request
    QLogger::CHANNEL_RESP = 'resp'; // Response
    ```

    You can log records into pre-defined channels or your own customized channels, by calling differenct `in` methods. See [Specify Channels](#channel_methods)

- `datetime`: Log date time
- `time` / `time_total` / `mem`: Time consumed since last log / time consumed since first log / memory consumed
- `session`:

    Log record's session key. This session key can be used to chain together logs in different requests which may cross several apps. Say a api call to applicatoin A `A:api/a`:

    `A:api/a --> B:api/b --> C:api/c`

    If all logs in A, B and C share the same session key, then we can search for the entire cross-app log chains.

    QLogger's session key is a randomized string. QLogger will read from `QLOG_SESSION` cookie to init the session key, if there is no `QLOG_SESSION` cookie, then it will generate a new one. After this, all log records will have the same session key.

    But **NOTE THIS**: you have to maitain the `QLOG_SESSION` cookie manually, to chain up records.

- `_id`: id

    If we only have session key, this will be a problem: since session key is randomized, we don't know it before we searching the records chain, so how can we know this session key?

    Here comes `id` field.

    The default log records structure doesn't have `_id` fields, but you can specify multiple `_id` fields by calling `idBy()` methods. See [Specify ID](#id_methods).

    `id`'s main purpose, is to be searched firstly to locate one interested record, then use this record's session key to search the whole log records chain.

## Initialisation

QLog's constructor requires two parameter: a `\Predis\Client`/`\Reids` instance, and a config array. Like this:

```php
use Unifreak\QLog\QLogger;

$redis = new \Predis\Client(['host' => '127.0.0.1', 'port' => 6379]);
$config = [
    'default_channel' => QLogger::CHANNEL_APP,
    'queue_name' => 'qlog:example.com',
    'size' => 3000,
    'log_to' => QLogger::LOG_REDIS,
];
$log = new QLogger($redis, $config);
```

## Config options: `$config` <a name="log_structure"></a>

- `queue_name`:

    Required, specify which redis queue for QLog to log into

- `default_channel`:

    Specify default channel. Default to `app`

- `size`: Maximum redis queue size. Default to 3000
- `log_to`: Where the records are logged into. Support three values:
    + `0`: Only log into stash zone
    + `1`: The default value. Only log into redis queue
    + `2`: Log into both stash and redis queue

## Log <a name="log_methods"></a>

You can log different level record by calling different log methods, passing in log message (required) and log context (optional):

```php
$message = 'log message';
$context = ['some' => 'context'];

$log->info($message, $context);
$log->notice($message, $context);
$log->warn($message, $context);
$log->error($message, $context);
$log->critical($message, $context);
$log->alert($message, $context);
$log->emergency($message, $context);
```

## Specify Channels <a name="channel_methods"></a>

You can specify pre-defined channels by calling these methods:

```php
$log->inApp()->info($message, $context); // Specify channel: app
$log->inSql()->info($message, $context); // Specify channel: sql
$log->inApi()->info($message, $context); // Specify channel: api
$log->inReq()->info($message, $context); // Specify channel: req
$log->inResp()->info($message, $context); // Specify channel: resp
```

Also, you can specify a customized channel by calling `in()` method:

```php
$log->in('custom_channel')->info($message, $context); // Specify channel: custom_channel
```

## Specify ID <a name="id_methods"></a>

You can specify multiple id name/value pairs by calling `idBy()` method. **NOTE**: id name must end with `_id`.

```php
$log->idBy('car_id', 123)->idBy('user_id', 321)->info($message, $context);
```

Then the record will have additional two id fields:

```php
[
    'car_id' => 123,
    'user_id' => 321
]
```

**NOTE**:

- If you call `idBy()` multiple times with the same name, the latter value will override the former
- Id is _sticky_, this means that all logs afterwards will auto hold the specified id name/value.

## Filter Stashed Logs

You can filter stashed logs by calling these methods:

```php
$log->shift(); // get the first record
$log->pop(); // get the last record
$log->stashed(); // get all records
$log->stashed(function($record) { // filter for specific records
    // like: filter for records that have car_id and level greater than warning
    return !empty($record['car_id']) && $record['level'] > QLogger::WARN;
});
$log->clean(); // clear all records
```

**NOTE**: if `QLog` is configured not to log into stash zone (the `log_to` config option), then stash zone will be empty, hence all above methods will return a empty array

# Laravel & Lumen

QLog provides a facade class and service provider class for `laravel`/`lumen`:
- Facade: `Unifreak\QLog\QLogFacade`
- Service provider: `Unifreak\QLog\QLogServiceProvider`

After registering facade and service provider, you can use `QLog` to access `QLogger`, like:

```php
use QLog;

QLog::in('my_channel')->idBy('car_id', 123)->warning('somthing went wrong');
dump(QLog::stashed());
```

The `QLogServiceProvider` also enables auto logging sql queries and `GuzzleHttp` requests. You can use the following query parameters to control `QLog`'s log bahaviors:

1. `qlog_debug`:

    A none-zero value is equivalent to pass in `qlog_autolog=2` and `qlog_log_to=0`, enable auto logging both `GuzzleHttp` request and sql queries, and only log into stash zone. see below

2. `qlog_autolog`: control auto log behaviors

    - `1`: Default. Auto log `GuzzleHttp` requests
    - `2`: Auto log both `GuzzleHttp` requests and sql queries

3. `qlog_log_to`: control log zone

    - `0`: Only log into stash zone
    - `1`: Only log into redis queue
    - `2`: Log both into stash zone and redis queue

## Register Facade and Service Provider

1. Add a new config file `config/qlog.php`:

```php
return [
    // Disable QLog:
    // - If disabled, all log methods call will simply be ignored
    // - But if there is `qlog_debug` query parameter present, QLog will be auto-enabled
    'disable' => false,
    // redis connection config
    'redis' => [
        'host'     => $redisHost,
        'port'     => $redisPort
    ],
    // qlog config
    'queue_name' => 'qlog:example.com',
    'size' => 2000,
];
```

2. Add the following codes into `bootstrap/app.php`:

```php
if (!class_exists('QLog')) {
    class_alias(Unifreak\QLog\QLogFacade::class, 'QLog');
}

$app->configure('qlog');
$app->register(Unifreak\QLog\QLogServiceProvider::class);

```

**NOTE**: Make sure that service provider is registered after `$app->withEloquent()` , otherwise the sql queries auto logging feature will not function properly

See `laravel`/`lumen` official documentation for more infomation

# TODO

- Auto log exception in `QLogServiceProvider`
- Auto send mails when record meet configured level

QLog 通过封装 Monolog 包并加入一些自定的 Handler/Processor/Formatter, 实现了以下功能:

- 根据配置, 把 log 打到暂存区 (以便于调试输出) 或 redis 指定队列
- 提供几个预定义的 log channel 以及相应的操作方法
- 定义了一个固定的 log 格式, 提供性能等相关信息, 见 [一些概念](#log_structure)
- 为每个 log 自动生成一个 session, 见 [一些概念](#log_structure)
- 为 log 打 id 标识
- 搜索暂存区 log

目前 QLog 的应用场景主要有两个:

- 在接口中实时打印 log 以便于调试
- 通过一个定时任务处理这些 log, 集中到一个监控平台. 可以在此监控平台中搜索这些 log

# 安装

运行 `composer require unifreak/qlog`

# 使用

## 一些概念 <a name="log_structure"></a>

QLog 打的日志是一个固定结构的数组, 如下:

```php
[
    "message" => "api call to api.fin",
    "context" => [
        "method" => "GET",
        "params" => [
            "id" => 12345,
            "name" => "ZhangSan",
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

- `message`: log 消息
- `context`: log 上下文
- `level` & `level_name`

    log 级别, 支持的级别包括 `DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERROR`, `CRITICAL`, `ALERT`, `EMERGENCY`, 可以通过调用不同的 log 方法指定对应的 log 级别. 见 [打 log](#log_methods)

- `channel`

    log 频道. 每条 log 可能分属于不同频道, 以标识该条 log 属于应用运行中的哪些事件.

    QLog 包括几个预定义的频道

    ```php
    QLogger::CHANNEL_APP = 'app'; // 应用
    QLogger::CHANNEL_SQL = 'sql'; // sql
    QLogger::CHANNEL_API = 'api'; // api 调用
    QLogger::CHANNEL_REQ = 'req'; // 请求
    QLogger::CHANNEL_RESP = 'resp'; // 响应
    ```

    可以通过调用不同的 `in` 方法指定预定义或自定义的频道. 见 [指定 channel](#channel_methods)

- `datetime`: 时间
- `time` / `time_total` / `mem`: 单项耗时 / 总耗时 / 占用内存
- `session`:

    log 所属的会话 id. 这个会话 id 可用于串联跨应用的多个请求. 比如某个请求, 可能要经由三个项目完成:

    `A:api/a --> B:api/b --> C:api/c`

    则所有这三个项目中的接口中打的 log 都可属于同一个 session. 这样可以方便对整个调用链 log 进行搜索.

    QLogger 的 session 是一个随机字符串, 它先尝试从 `QLOG_SESSION` 的 cookie 中获取, 如果没有则自动生成一个, 然后所有之后的 log 自动延用此 session. 简言之, 各项目可通过 `QLOG_SESSION` 这个 cookie 项来串联请求.

    你可能会想到一个问题: 因为 session 是一个随机串, 自己怎么知道这个串, 更别说搜索了. 这就要用到下面的 `id` 字段了

- `_id`: id 标识

    默认的 log 结构中并没有 `_id` 字段, 但是可以通过 `idBy()` 方法动态为其指定, 见 [指定 id](#id_methods)

    提供这个动态指定 id 字段的主要用意, 是方便根据 id 标识搜索某条 log, 获取其 session, 然后利用 session 搜索整个 log 链

## 实例化

QLog 的构造函数需要两个参数: 一个 `\Predis\Client` 或者 `\Redis` 实例, 一个可选的配置数组, 如下

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

## 配置项: `$config` <a name="log_structure"></a>

- `queue_name`:

    必配项, 要 log 到的 redis 队列名称

- `default_channel`:

    默认 log 到的 channel

- `size`: redis 队列最大条数
- `log_to`: log 到哪里. 目前支持的三个值:
    + `0`: 只 log 到暂存区
    + `1`: 默认, 只 log 到 redis 队列
    + `2`: 同时 log 到暂存区和 redis 队列

## 打 log <a name="log_methods"></a>

通过调用不同方法, 传入 log 消息 (必传) 和上下文 (选传), 打指定级别的 log:

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

## 指定 channel <a name="channel_methods"></a>

可以使用以下方法指定预定义的 channel:

```php
$log->inApp()->info($message, $context); // 指定 channel: app
$log->inSql()->info($message, $context); // 指定 channel: sql
$log->inApi()->info($message, $context); // 指定 channel: api
$log->inReq()->info($message, $context); // 指定 channel: req
$log->inResp()->info($message, $context); // 指定 channel: resp
```

也可以使用 `in()` 方法指定自定义的 channel:

```php
$log->in('custom_channel')->info($message, $context);
```

## 指定 id <a name="id_methods"></a>

可以使用 `idBy()` 方法, 传入 id 名称和值来指定多个 id 标识. **注意**: id 名必须以 `_id` 结尾

```php
$log->idBy('car_id', 123)->idBy('user_id', 321)->info($message, $context);
```

这样打出来的 log 结构中会多两项:

```php
[
    'car_id' => 123,
    'user_id' => 321
]
```

**注意**:

- 指定多次相同名称的 id 的话, 后面的会覆盖掉前面的值
- id 是**粘滞**的, 即后续的 log 都会自动保有前面打 log 时指定的各个 id 及其值

## 操作, 筛选暂存区 log

可以通过以下方法操作或筛选已有的 log (**注意**: 如果配置为不往暂存区打 log, 则所有筛选都返回空数组)

```php
$log->shift(); // 弹出第一条 log
$log->pop(); // 弹出最后一条 log
$log->stashed(); // 获取所有 log
$log->stashed(function($record) { // 筛选指定 log
    // 所有 car_id 且级别大于 warning 的都满足条件
    return !empty($record['car_id']) && $record['level'] > QLogger::WARN;
});
$log->clean(); // 清除暂存区所有 log
```

# Laravel & Lumen

QLog 专门为 `laravel`/`lumen` 提供了外观类和服务注册器, 分别位于
- 外观类: `Unifreak\QLog\QLogFacade`
- 服务注册器: `Unifreak\QLog\QLogServiceProvider`

注册以上两个类之后, 则可以直接通过 `QLog` 形式访问 `QLogger` 功能, 如:

```php
use QLog;

QLog::in('my_channel')->idBy('car_id', 123)->warning('somthing went wrong');
dump(QLog::stashed());
```

而且服务注册器提供了自动打 sql 和 guzzleHttp 请求 log 的功能, 可以使用以下参数控制 QLog 的行为:

1. `qlog_debug` 参数:

    当该参数为非 0 时, 相当于传入了 `qlog_autolog=2` 和 `qlog_log_to=0`, 即会自动 log 请求和 sql, 并且只打入暂存区. 见下.

2. `qlog_autolog` 参数: 是否自动打 log

    - `1`: 默认, 自动 log guzzleHttp 请求
    - `2`: 自动 log guzzleHttp 请求和 sql 查询

3. `qlog_log_to` 参数: 是否打入暂存区或 redis

    - `0`: 只打入暂存区
    - `1`: 只打入 redis
    - `2`: 打入暂存区和 redis

## 注册步骤

1. 新增 `config/qlog.php` 配置文件如下:

```php
return [
    // 默认是否禁用:
    // - 禁用后所有打 log 动作都会无效
    // - 入参如果有 qlog_debug 的话, 则又会自动启用
    'disable' => false,
    // redis 连接配置
    'redis' => [
        'host'     => $redisHost,
        'port'     => $redisPort
    ],
    // qlog 配置
    'queue_name' => 'qlog:example.com',
    'size' => 2000,
];
```

2. 在 `bootstrap/app.php` 相应位置新增一下内容:

```php
if (!class_exists('QLog')) {
    class_alias(Unifreak\QLog\QLogFacade::class, 'QLog');
}

$app->configure('qlog');
$app->register(Unifreak\QLog\QLogServiceProvider::class);

```

**注意**: 确保服务注册在 `$app->withEloquent()` 之后, 否则不能自动 log sql

具体请参见 `laravel`/`lumen` 官网文档

# TODO

- 服务注册器中提供自动 log exception
- 指定级别自动发邮件

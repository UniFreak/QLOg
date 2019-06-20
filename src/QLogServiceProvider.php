<?php
namespace Unifreak\QLog;

use DB;
use QLog;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Handler\CurlHandler;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Support\ServiceProvider;

class QLogServiceProvider extends ServiceProvider
{
    private $request;

    public function register()
    {

    }

    public function boot()
    {
        $this->request = app(\Illuminate\Http\Request::class);
        $debugging = $this->request->get('qlog_debug');

        $this->app->singleton('qlog', function ($app) use ($debugging) {
            $config = config('qlog');
            if (empty($config)) {
                throw new \Exception('no qlog config found');
            }

            if ($debugging) {
                unset($config['disable']);
            }

            // Set log_to:
            // 0: stash, 1: redis, 2: both, null: take QLogger default (redis)
            $config['log_to'] = $this->request->get('qlog_log_to', ($debugging ? 0 : null));

            // init QLogger
            if (class_exists('\Predis\Client')) {
                $redis = new \Predis\Client($config['redis']);
            } elseif (class_exists('\Redis')) {
                $redis = new \Redis();
                $redis->pconnect($config['redis']['host'], $config['redis']['port'], 2);
            } else {
                throw new \Exception('qlog require predis/predis package or phpredis exntension');
            }

            $qlogger = new QLogger($redis, $config);
            return $qlogger;
        });

        // Set auto logging according to autolog:
        // 0: none, 1: auto log api call, 2: auto log sql query
        $autoLog = $this->request->get('qlog_autolog', ($debugging ? 2 : 1));

        // Auto log api calls
        if ($autoLog >= 1) {
            $this->autoLogApi();
        }

        // Auto log SQL query
        if ($autoLog >= 2) {
            $this->autoLogSql(); // this make app really slow...
        }
    }

    private function autoLogSql()
    {
        DB::listen(function ($query, $bindings=null, $spend=null, $db=null) {
            if (! is_string($query)) { // lumen5.4 only passed in a QueryExcuted Object
                $bindings = $query->bindings;
                $spend = $query->time;
                $db = $query->connectionName;
                $query = $query->sql;
            }
            $i = 0;
            $message = sprintf(
                "query %s => %s ( %s ms )",
                $db,
                preg_replace_callback('/\?/', function ($matches) use ($bindings, &$i) {
                    return '\''.$bindings[$i++].'\'';
                }, $query),
                $spend
            );
            QLog::inSql()->info($message);
        });
    }

    private function autoLogApi()
    {
        $this->app->singleton(Client::class, function($app) {
            $handler = new CurlHandler();
            $stack = HandlerStack::create($handler);
            $stack->setHandler($handler);
            $stack->push($this->autoLogApiHandler());
            $client = new Client(['handler' => $stack]);
            return $client;
        });
    }

    private function autoLogApiHandler()
    {
        return function (callable $handler) {
            return function (
                RequestInterface $request,
                array $options
            ) use ($handler) {
                // TODO: make QLog cookie work even when not auto-logging
                $qlogCookie = new SetCookie();
                $qlogCookie->setName(QLogger::SESSION_KEY);
                $qlogCookie->setValue(QLog::getSession());
                $qlogCookie->setDomain($request->getUri()->getHost());
                $qlogCookie->setDiscard(true);
                if (empty($options['cookies'])
                    || ! $options['cookies'] instanceof CookieJar
                ) {
                    $options['cookies'] = new CookieJar(false, [$qlogCookie]);
                } else {
                    $options['cookies']->setCookie($qlogCookie);
                }

                $promise = $handler($request, $options);
                return $promise->then(
                    function (ResponseInterface $response) use ($request, $options) {
                        $this->logApiResponse($response, $request, $options);

                        // set stream seek point to the beginning
                        $response->getBody()->rewind();
                        return $response;
                    },
                    function (\Exception $e) use ($request, $options) {
                        $this->logApiException($e, $request, $options);
                        throw $e;
                    }
                );
            };
        };
    }

    private function logApiResponse(
        ResponseInterface $response,
        RequestInterface $request,
        array $options
    ) {
        $logContext = array_merge(
            $this->apiLogContext($request, $options), [
                'status' => $response->getStatusCode(),
                'response' => json_decode($response->getBody()->getContents(), true),
            ]
        );
        QLog::inApi()->info(
            $this->apiLogMessage($request),
            $logContext
        );
    }

    private function logApiException(
        \Exception $e,
        RequestInterface $request,
        array $options
    ) {
        $logContext = array_merge(
            $this->apiLogContext($request, $options),
            [
                'status' => 500,
                'response' => $e->getMessage(),
            ]
        );
        QLog::inApi()->error(
            $this->apiLogMessage($request),
            $logContext
        );
    }

    private function apiLogMessage(RequestInterface $request)
    {
        $entry = $request->getUri()->getHost().$request->getUri()->getPath();
        return 'api call to ' . $entry;
    }

    private function apiLogContext(RequestInterface $request, array $options)
    {
        // params
        if ($request->getMethod() == 'GET') {
            parse_str($request->getUri()->getQuery(), $params);
        } else {
            parse_str((string) $request->getBody(), $params);
        }

        // headers
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(',', $values);
        }

        return [
            'method' => $request->getMethod(),
            'params' => $params,
            'cookies' => $options['cookies']->toArray(),
            'headers' => $headers,
        ];
    }
}
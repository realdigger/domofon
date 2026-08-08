<?php

namespace App\Command;

use App\Service\AsyncRegistry;
use App\Service\Domru;
use App\Service\HomeAssistant;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory;
use React\Http\Message\Response;
use React\Http\Server;
use React\Promise\PromiseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Throwable;

use function React\Promise\all;
use function React\Promise\resolve;

class RunCommand extends Command
{
    use LoggerAwareTrait;

    protected static $defaultName = 'app:run';

    protected Domru $domru;
    protected HomeAssistant $homeAssistant;
    protected AsyncRegistry $registry;

    private Request $request;

    public function __construct(Domru $domru, HomeAssistant $homeAssistant, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->domru = $domru;
        $this->homeAssistant = $homeAssistant;
        $this->registry = AsyncRegistry::getInstance();
        $this->registry->loop = Factory::create();
        $this->domru->setupRegistry($this->registry);
        $this->homeAssistant->setupRegistry($this->registry);

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $server = new Server(
            $this->registry->loop,
            function (ServerRequestInterface $serverRequest) {
                try {
                    $response = $this->handleRequest($serverRequest);

                    if ($response instanceof PromiseInterface) {
                        return $response->then(
                            function ($response) {
                                if ($response instanceof ResponseInterface) {
                                    return $response;
                                }

                                return $this->error($response, 500);
                            },
                            function ($error) {
                                return $this->error($error, 500);
                            }
                        );
                    }

                    if ($response instanceof ResponseInterface) {
                        return $response;
                    }

                    return $this->error($response, 500);
                } catch (ResourceNotFoundException $e) {
                    return $this->error('404 not found', 404);
                } catch (Throwable $e) {
                    return $this->error($e, 500);
                }
            }
        );

        $server->on(
            'error',
            function (Throwable $e) {
                dump($e);
                $this->logger->critical(
                    $e->getMessage(),
                    [
                        'code' => $e->getCode(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString(),
                    ]
                );
            }
        );

        if (isset($_SERVER['SUPERVISOR_TOKEN'])) {
            $this->homeAssistant->async($_SERVER['SUPERVISOR_TOKEN']);
        }

        $this->domru->async();

        $socket = new \React\Socket\Server(8080, $this->registry->loop);
        $server->listen($socket);
        $this->registry->loop->run();

        return Command::SUCCESS;
    }

    private function handleRequest(ServerRequestInterface $serverRequest)
    {
        $this->request = Request::create(
            $serverRequest->getUri(),
            $serverRequest->getMethod(),
            $serverRequest->getQueryParams(),
            $serverRequest->getCookieParams(),
            $serverRequest->getUploadedFiles(),
            $serverRequest->getServerParams(),
            $serverRequest->getBody()
        );

        $routesArray = [
            'fetchFullInfo' => [
                'path' => '/api',
                'data' => [
                    '_controller' => self::class,
                    '_method' => 'fetchFullInfo',
                ],
            ],
            'fetchInfo' => [
                'path' => '/api/{account}',
                'data' => [
                    '_controller' => self::class,
                    '_method' => 'fetchInfo',
                    'account' => null,
                ],
            ],
            'openDoor' => [
                'path' => '/api/open/{account}/{cameraId}',
                'data' => [
                    '_controller' => self::class,
                    '_method' => 'openDoor',
                    'account' => null,
                    'cameraId' => null,
                ],
            ],
            'cameraSnapshot' => [
                'path' => '/api/camera/snapshot/{account}/{cameraId}',
                'data' => [
                    '_controller' => self::class,
                    '_method' => 'cameraSnapshot',
                    'account' => null,
                    'cameraId' => null,
                ],
            ],
            'cameraStream' => [
                'path' => '/api/camera/stream/{account}/{cameraId}/{timestamp}',
                'data' => [
                    '_controller' => self::class,
                    '_method' => 'cameraStream',
                    'account' => null,
                    'cameraId' => null,
                    'timestamp' => null,
                ],
            ],
            'events' => [
                'path' => '/api/events/{account}/{placeId}',
                'data' => [
                    '_controller' => self::class,
                    '_method' => 'events',
                    'account' => null,
                    'placeId' => null,
                ],
            ],
        ];

        $routes = new RouteCollection();

        foreach ($routesArray as $routeName => $routeData) {
            $routes->add($routeName, new Route($routeData['path'], $routeData['data']));
        }

        $context = (new RequestContext())->fromRequest($this->request);
        $matcher = new UrlMatcher($routes, $context);
        $parameters = $matcher->match($this->request->getPathInfo());

        $args = [];

        foreach ($parameters as $k => $v) {
            $pos = mb_strpos($k, '_');

            if ($pos === false || $pos > 0) {
                $args[$k] = $v;
            }
        }

        $this->logger->debug('HTTP request run', ['parameters' => $parameters, 'args' => $args]);

        $result = call_user_func_array([$parameters['_controller'], $parameters['_method']], $args);

        if ($result instanceof Response) {
            return $result;
        } elseif ($result instanceof PromiseInterface) {
            return $result->then(
                function ($response) {
                    if ($response instanceof ResponseInterface) {
                        return $response;
                    }

                    return $this->error($response);
                },
                function ($errorResponse) {
                    return $this->error($errorResponse);
                }
            );
        }

        return $this->error('Internal Error');
    }

    private function fetchFullInfo(): PromiseInterface
    {
        $memory = memory_get_usage(true);
        $registry = $this->registry->all();
        $promises = [];
        $accounts = $registry['accounts'] ?? [];

        if ($this->request->query->get('events') && is_array($accounts)) {
            foreach ($accounts as $accountId => &$accountData) {
                $promises[$accountId.'_events'] = $this->domru->events($accountId)
                    ->then(
                        function ($events) use (&$accountData) {
                            if (!is_array($events)) {
                                $events = [];
                            }

                            $subscriberPlaces = $accountData['subscriberPlaces'] ?? [];

                            if (!is_array($subscriberPlaces)) {
                                $subscriberPlaces = [];
                            }

                            foreach ($events as &$event) {
                                if (($event['source']['type'] ?? null) !== 'accessControl') {
                                    continue;
                                }

                                foreach ($subscriberPlaces as $subscriberPlace) {
                                    $place = $subscriberPlace['place'] ?? [];

                                    if (($place['id'] ?? null) !== ($event['placeId'] ?? null)) {
                                        continue;
                                    }

                                    $accessControls = $place['accessControls'] ?? [];

                                    if (!is_array($accessControls)) {
                                        continue;
                                    }

                                    foreach ($accessControls as $accessControl) {
                                        if (($accessControl['id'] ?? null) === ($event['source']['id'] ?? null)) {
                                            if (isset($accessControl['cameraId'])) {
                                                $event['cameraId'] = $accessControl['cameraId'];
                                            }
                                        }
                                    }
                                }
                            }

                            $accountData['events'] = $events;
                        },
                        function () use (&$accountData) {
                            $accountData['events'] = [];
                        }
                    );
            }
        }

        return all($promises)->then(
            fn() => $this->json(
                array_merge(
                    $registry,
                    [
                        'memoryHuman' => $this->memoryConvert($memory),
                        'memory' => $memory,
                    ]
                )
            )
        );
    }

    private function fetchInfo(string $account): PromiseInterface
    {
        $memory = memory_get_usage(true);
        $registry = $this->registry->all();
        $accounts = $registry['accounts'] ?? [];

        if (!isset($accounts[$account])) {
            return resolve($this->error('Unknown account'));
        }

        $promises = [];

        if ($this->request->query->get('events') && is_array($accounts)) {
            foreach ($accounts as $accountId => &$accountData) {
                if ($account != $accountId) {
                    continue;
                }

                $promises[$accountId.'_events'] = $this->domru->events($accountId)
                    ->then(
                        function ($events) use (&$accountData) {
                            if (!is_array($events)) {
                                $events = [];
                            }

                            $accountData['events'] = $events;
                        }
                    );
            }
        }

        return all($promises)->then(
            fn() => $this->json(
                array_merge(
                    $accounts[$account],
                    [
                        'memoryHuman' => $this->memoryConvert($memory),
                        'memory' => $memory,
                    ]
                )
            )
        );
    }

    private function openDoor(string $account, int $cameraId = null): PromiseInterface
    {
        return $this->domru->openDoor($account, $cameraId)->then(
            function ($data) {
                return $this->json($data);
            },
            function ($error) {
                return $this->error($error);
            }
        );
    }

    private function cameraSnapshot(string $account, int $cameraId = null): PromiseInterface
    {
        return $this->domru->cameraSnapshot($account, $cameraId)->then(
            function ($data) {
                return $this->image($data['content'], $data['mime']);
            },
            function ($error) {
                return $this->error($error);
            }
        );
    }

    private function cameraStream(string $account, int $cameraId = null, int $timestamp = null): PromiseInterface
    {
        return $this->domru->cameraStream($account, $cameraId, $timestamp)->then(
            function ($data) {
                return new Response(302, ['Location' => $data]);
            },
            function ($error) {
                return $this->error($error);
            }
        );
    }

    private function events(string $account, int $placeId = null): PromiseInterface
    {
        return $this->domru->events($account, $placeId, $this->request->query->get('limit'))->then(
            function ($data) {
                return $this->json($data);
            },
            function ($error) {
                return $this->error($error);
            }
        );
    }

    private function json(array $data = [], int $statusCode = 200): Response
    {
        return new Response(
            $statusCode,
            ['Content-Type' => 'application/json; charset=UTF-8'],
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private function error($message, int $statusCode = 400): Response
    {
        if ($message instanceof Throwable) {
            $message = $message->getMessage();
        } elseif ($message instanceof ResponseInterface) {
            $message = $message->getBody()->getContents();
        } elseif (is_array($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } elseif (!is_string($message)) {
            $message = print_r($message, true);
        }

        if (!$message) {
            $message = 'Internal Error';
        }

        return $this->json(['status' => 'error', 'errorMessage' => $message], $statusCode);
    }

    private function image(string $imageData, string $mime = 'image/jpeg'): Response
    {
        return new Response(
            200,
            ['Content-Type' => $mime],
            $imageData
        );
    }

    private function memoryConvert($size)
    {
        $unit = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];

        return round($size / pow(1024, ($i = floor(log($size, 1024)))), 2).' '.$unit[$i];
    }
}

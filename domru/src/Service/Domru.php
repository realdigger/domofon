<?php

namespace App\Service;

use App\Traits\HttpClientAwareTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\reject;
use function React\Promise\resolve;

class Domru
{
    use HttpClientAwareTrait;
    use LoggerAwareTrait;

    private Cache $cache;
    private AccountService $accountService;
    private Browser $client;
    private ?AsyncRegistry $registry = null;

    /**
     * Modern Android myHome/Dom.ru User-Agent format.
     * Format arguments: operatorId, uuid, placeId.
     */
    private ?string $asyncUserAgent = 'Google sdkgphone64x8664 | Android 14 | erth | 8.26.0 (82600010) | | %d | %s | %d';

    public const API_HOST = 'myhome.proptech.ru';

    public const LOGIN_BY_PHONE = 'phone';
    public const LOGIN_BY_ACCOUNT = 'account';

    public const API_AUTH_LOGIN = 'https://myhome.proptech.ru/auth/v2/login/%s';
    public const API_AUTH_CONFIRMATION = 'https://myhome.proptech.ru/auth/v2/confirmation/%s';
    public const API_AUTH_CONFIRMATION_SMS = 'https://myhome.proptech.ru/auth/v2/auth/%s/confirmation';
    public const API_USER_AGENT = 'Google sdkgphone64x8664 | Android 14 | erth | 8.26.0 (82600010) | | 0 | 00000000-0000-0000-0000-000000000000 | 1';

    public const API_REFRESH_SESSION = 'https://myhome.proptech.ru/auth/v2/session/refresh';

    public const API_PROFILES = 'https://myhome.proptech.ru/rest/v1/subscribers/profiles';
    public const API_FINANCES = 'https://myhome.proptech.ru/rest/v1/subscribers/profiles/finances';
    public const API_CAMERAS = 'https://myhome.proptech.ru/rest/v1/forpost/cameras';
    public const API_SUBSCRIBER_PLACES = 'https://myhome.proptech.ru/rest/v1/subscriberplaces';

    public const API_OPEN_DOOR = 'https://myhome.proptech.ru/rest/v1/places/%d/accesscontrols/%d/actions';
    public const API_CAMERA_GET_STREAM = 'https://myhome.proptech.ru/rest/v1/forpost/cameras/%d/video?';
    public const API_CAMERA_GET_SNAPSHOT = 'https://myhome.proptech.ru/rest/v1/forpost/cameras/%d/snapshots?';
    public const API_EVENTS = 'https://myhome.proptech.ru/rest/v1/places/%d/events?allowExtentedActions=true';

    public const REFRESH_ACCESS_TOKEN_INTERVAL = 60;
    public const REFRESH_FINANCES_INTERVAL = 3600;
    public const REFRESH_PROFILES_INTERVAL = 3600;
    public const REFRESH_SUBSCRIBER_PLACES_INTERVAL = 3600;
    public const REFRESH_CAMERAS_INTERVAL = 3600;
    public const REFRESH_EVENTS_INTERVAL = 300;

    public function __construct(LoggerInterface $logger, Cache $cache, AccountService $accountService)
    {
        $this->logger = $logger;
        $this->logger->debug('Initiate Domru');
        $this->cache = $cache;
        $this->accountService = $accountService;
    }

    private function apiUserAgent($operatorId = 0, $uuid = null, $placeId = 1): string
    {
        if (!$uuid) {
            $uuid = '00000000-0000-0000-0000-000000000000';
        }

        if (!$placeId) {
            $placeId = 1;
        }

        return sprintf($this->asyncUserAgent, (int)$operatorId, $uuid, (int)$placeId);
    }

    private function commonHeaders($operatorId = 0, $uuid = null, $placeId = 1, array $extra = []): array
    {
        return array_merge(
            [
                'Host' => self::API_HOST,
                'Content-Type' => 'application/json; charset=UTF-8',
                'Connection' => 'keep-alive',
                'Accept' => '*/*',
                'User-Agent' => $this->apiUserAgent($operatorId, $uuid, $placeId),
                'Accept-Language' => 'en-us',
                'Accept-Encoding' => 'gzip, deflate, br',
            ],
            $extra
        );
    }

    private function apiError(string $account, \Exception $e)
    {
        $content = '';

        if ($e instanceof ResponseException) {
            try {
                $content = $e->getResponse()->getBody()->getContents();
            } catch (\Throwable $ignored) {
                $content = '';
            }
        }

        $error = '['.$account.'] Api error: ['.$e->getMessage().']';
        if ($content !== '') {
            $error .= ' Contents: '.$content;
        }

        $this->logger->error($error);

        return reject($error);
    }

    public function getAccounts(string $phone, string $loginType): ?array
    {
        $data = $this->cache->get('accounts');

        if (!$data) {
            $response = $this->getHttp()->request(
                'GET',
                sprintf(self::API_AUTH_LOGIN, $phone),
                [
                    'headers' => $this->commonHeaders(0, null, 1, ['Authorization' => '']),
                ]
            );

            $content = $response->getBody()->getContents();
            $this->logger->debug(__METHOD__.' | Headers', $response->getHeaders());
            $this->logger->debug(__METHOD__.' | Content', [$content]);

            if ($loginType === self::LOGIN_BY_PHONE) {
                $accounts = json_decode($content, true);
            } else {
                /** @TODO Auth by account id + pass */
                $accounts = null;
            }

            if ($accounts) {
                $data = [
                    'phone' => $phone,
                    'accounts' => $accounts,
                ];

                $this->cache->set('accounts', $data, 600);
            }
        }

        return $data;
    }

    public function requestSmsConfirmation(string $phone, int $index): bool
    {
        $accounts = $this->getAccounts($phone, Domru::LOGIN_BY_PHONE);
        $address = $accounts['accounts'][$index];

        $headers = $this->commonHeaders(
            $address['operatorId'] ?? 0,
            null,
            $address['placeId'] ?? 1,
            ['Authorization' => '']
        );

        $data = [
            'accountId' => $address['accountId'],
            'address' => $address['address'],
            'operatorId' => (int)$address['operatorId'],
            'placeId' => (int)$address['placeId'],
            'subscriberId' => $address['subscriberId'],
            'profileId' => $address['profileId'] ?? '',
        ];

        $this->logger->debug(__METHOD__.' | Send', ['headers' => $headers, 'data' => $data]);

        $response = $this->getHttp()->request(
            'POST',
            sprintf(self::API_AUTH_CONFIRMATION, $phone),
            [
                'headers' => $headers,
                'body' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ]
        );

        $content = $response->getBody()->getContents();
        $this->logger->debug(__METHOD__.' | Headers', $response->getHeaders());
        $this->logger->debug(__METHOD__.' | Content', [$content]);

        return true;
    }

    public function requestSmsVerification(string $phone, int $index, int $code): ?array
    {
        $accounts = $this->getAccounts($phone, Domru::LOGIN_BY_PHONE);
        $address = $accounts['accounts'][$index];

        $headers = $this->commonHeaders(
            $address['operatorId'] ?? 0,
            null,
            $address['placeId'] ?? 1,
            ['Authorization' => '']
        );

        $data = [
            'accountId' => $address['accountId'],
            'confirm1' => (string)$code,
            'login' => $phone,
            'operatorId' => (int)$address['operatorId'],
            'subscriberId' => $address['subscriberId'],
            'profileId' => $address['profileId'] ?? '',
        ];

        $this->logger->debug(__METHOD__.' | Send', ['headers' => $headers, 'data' => $data]);

        $response = $this->getHttp()->request(
            'POST',
            sprintf(self::API_AUTH_CONFIRMATION_SMS, $phone),
            [
                'headers' => $headers,
                'body' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ]
        );

        $content = $response->getBody()->getContents();
        $this->logger->debug(__METHOD__.' | Headers', $response->getHeaders());
        $this->logger->debug(__METHOD__.' | Content', [$content]);

        $this->cache->clear();

        return json_decode($content, true);
    }

    public function async()
    {
        $this->client = new Browser($this->registry->loop);

        /** Accounts cache */
        $this->registry->loop->addPeriodicTimer(
            5,
            function () {
                $savedAccounts = array_keys($this->registry->accounts);
                $this->registry->accounts = $this->accountService->getAccounts();
                $newAccounts = array_diff(array_keys($this->registry->accounts), $savedAccounts);
                $deletedAccounts = array_diff($savedAccounts, array_keys($this->registry->accounts));

                $this->registry->accountsUpdate($deletedAccounts);

                if ($newAccounts) {
                    $this->refreshTokens()
                        ->then(
                            function () use ($newAccounts) {
                                $promises = [];
                                foreach ($newAccounts as $account) {
                                    $promises[] = $this->fetchData(self::API_SUBSCRIBER_PLACES, 'subscriberPlaces', $account);
                                    $promises[] = $this->fetchData(self::API_FINANCES, 'finances', $account);
                                    $promises[] = $this->fetchData(self::API_PROFILES, 'profiles', $account);
                                    $promises[] = $this->fetchData(self::API_CAMERAS, 'cameras', $account);
                                }

                                return all($promises);
                            }
                        );
                }
            }
        );

        $this->refreshTokens()
            ->then(
                function () {
                    $this->registry->state = AsyncRegistry::STATE_READY;
                    $this->registry->loop->addPeriodicTimer(
                        self::REFRESH_ACCESS_TOKEN_INTERVAL,
                        fn() => $this->refreshTokens()
                    );
                }
            )
            ->then(fn() => $this->watchdog());
    }

    public function setupRegistry(AsyncRegistry $registry)
    {
        $this->registry = $registry;
        $this->registry->accounts = $this->accountService->getAccounts();
    }

    private function watchdog()
    {
        $promises = [
            $this->fetchData(self::API_FINANCES, 'finances')->then(
                function () {
                    $this->logger->debug('Watchdog for finances complete');
                    $this->registry->loop->addPeriodicTimer(
                        self::REFRESH_FINANCES_INTERVAL,
                        fn() => $this->fetchData(self::API_FINANCES, 'finances')
                    );
                }
            ),
            $this->fetchData(self::API_PROFILES, 'profiles')->then(
                function () {
                    $this->logger->debug('Watchdog for profiles complete');
                    $this->registry->loop->addPeriodicTimer(
                        self::REFRESH_PROFILES_INTERVAL,
                        fn() => $this->fetchData(self::API_PROFILES, 'profiles')
                    );
                }
            ),
            $this->fetchData(self::API_CAMERAS, 'cameras')->then(
                function () {
                    $this->logger->debug('Watchdog for cameras complete');
                    $this->registry->loop->addPeriodicTimer(
                        self::REFRESH_CAMERAS_INTERVAL,
                        fn() => $this->fetchData(self::API_CAMERAS, 'cameras')
                    );
                }
            ),
            $this->fetchData(self::API_SUBSCRIBER_PLACES, 'subscriberPlaces')->then(
                function () {
                    $this->logger->debug('Watchdog for subscriberPlaces complete');
                    $this->registry->loop->addPeriodicTimer(
                        self::REFRESH_SUBSCRIBER_PLACES_INTERVAL,
                        fn() => $this->fetchData(self::API_SUBSCRIBER_PLACES, 'subscriberPlaces')
                    );
                }
            ),
        ];

        return all($promises)->then(fn() => $this->registry->state = AsyncRegistry::STATE_LOOP);
    }

    private function refreshTokens(): PromiseInterface
    {
        $promises = [];

        foreach ($this->registry->accounts as $account => $accountData) {
            $operatorId = $accountData['data']['operatorId'] ?? ($accountData['address']['operatorId'] ?? 0);
            $uuid = $accountData['uuid'] ?? null;
            $placeId = $accountData['address']['placeId'] ?? 1;
            $refreshToken = $accountData['data']['refreshToken'] ?? null;

            $promises[$account] = $this->client->get(
                self::API_REFRESH_SESSION,
                $this->commonHeaders(
                    $operatorId,
                    $uuid,
                    $placeId,
                    [
                        'Operator' => (string)$operatorId,
                        'Bearer' => $refreshToken,
                    ]
                )
            )
                ->then(
                    function (ResponseInterface $response) use ($account) {
                        $data = json_decode($response->getBody()->getContents(), true);

                        if (!is_array($data) || empty($data['accessToken'])) {
                            return reject('Api error ['.$account.']: [HTTP OK] Response json failed');
                        }

                        $this->logger->debug('['.$account.'] Access token refresh success');

                        return resolve($data['accessToken']);
                    },
                    function (ResponseException $e) use ($account) {
                        $this->apiError($account, $e);

                        return resolve(false);
                    }
                );
        }

        return all($promises)->then(
            function (array $refreshedAccountsTokens) {
                foreach ($refreshedAccountsTokens as $account => $token) {
                    if ($token) {
                        $this->registry->setToken($account, $token);
                    }
                }
            }
        );
    }

    private function fetchData(string $apiUrl, string $storageKey, string $forcedAccount = null): PromiseInterface
    {
        $promises = [];
        $tokensForFetch = $this->registry->getTokens();

        if ($forcedAccount && isset($tokensForFetch[$forcedAccount])) {
            $tokensForFetch = [
                $forcedAccount => $tokensForFetch[$forcedAccount],
            ];
        }

        $this->logger->debug('Fetching '.$storageKey.' for accounts', array_keys($tokensForFetch));

        foreach ($tokensForFetch as $account => $token) {
            $this->logger->debug('['.$account.'] Trying to fetch: '.$storageKey);

            $operatorId = $this->registry->accounts[$account]['data']['operatorId']
                ?? ($this->registry->accounts[$account]['address']['operatorId'] ?? 0);
            $uuid = $this->registry->accounts[$account]['uuid'] ?? null;
            $placeId = $this->registry->accounts[$account]['address']['placeId'] ?? 1;

            $promises[$account] = $this->client->get(
                $apiUrl,
                $this->commonHeaders(
                    $operatorId,
                    $uuid,
                    $placeId,
                    [
                        'Operator' => (string)$operatorId,
                        'Authorization' => 'Bearer '.$token,
                    ]
                )
            )->then(
                function (ResponseInterface $response) use ($account, $storageKey) {
                    $data = json_decode($response->getBody()->getContents(), true);
                    $this->logger->debug('['.$account.'] Fetching success: '.$storageKey);

                    return resolve($data);
                },
                function (ResponseException $e) use ($account) {
                    $this->apiError($account, $e);

                    return resolve(false);
                }
            );
        }

        return all($promises)->then(
            function (array $refreshedAccountsTokens) use ($storageKey) {
                foreach ($refreshedAccountsTokens as $account => $data) {
                    if ($data) {
                        if (isset($data['data'])) {
                            $this->registry->update($storageKey, $account, $data['data']);
                        } else {
                            $this->registry->update($storageKey, $account, $data);
                        }
                    }
                }
            }
        );
    }

    private function getPlaceIdAccessControlId(string $account, int $cameraId): PromiseInterface
    {
        $all = $this->registry->all();
        $accountData = $all['accounts'][$account];
        $subscriberPlaces = $accountData['subscriberPlaces'] ?? null;

        if (!is_array($subscriberPlaces)) {
            return reject('Subscriber places is empty');
        }

        $useAccessControl = $placeId = $accessControlId = null;

        foreach ($subscriberPlaces as $subscriberPlace) {
            foreach ($subscriberPlace['place']['accessControls'] as $accessControl) {
                if (isset($accessControl['cameraId']) && $accessControl['cameraId'] === $cameraId) {
                    $placeId = $subscriberPlace['place']['id'];
                    $accessControlId = $accessControl['id'];
                    $useAccessControl = $subscriberPlace['place'];
                    break 2;
                }
            }
        }

        if (!$placeId || !$accessControlId || !$useAccessControl) {
            return reject('Wrong parameters');
        }

        return resolve(
            [
                'placeId' => $placeId,
                'accessControlId' => $accessControlId,
                'accessControl' => $useAccessControl,
            ]
        );
    }

    private function getPlaceId(string $account, int $placeId = null): PromiseInterface
    {
        $subscriberPlaces = $this->registry->fetch('subscriberPlaces', $account);

        if (!is_array($subscriberPlaces)) {
            return reject('Subscriber places is empty');
        }

        $place = null;

        foreach ($subscriberPlaces as $subscriberPlace) {
            if ($placeId === null) {
                $placeId = $subscriberPlace['place']['id'];
                $place = $subscriberPlace['place'];
                break;
            }

            if ($placeId === $subscriberPlace['place']['id']) {
                $place = $subscriberPlace['place'];
                break;
            }
        }

        if (!$placeId || !$place) {
            return reject('Wrong parameters');
        }

        return resolve(
            [
                'placeId' => $placeId,
                'place' => $place,
            ]
        );
    }

    public function openDoor(string $account, int $cameraId): PromiseInterface
    {
        if ($this->registry->state !== AsyncRegistry::STATE_LOOP) {
            return reject('Api not ready');
        }

        return $this->getPlaceIdAccessControlId($account, $cameraId)
            ->then(
                function ($use) use ($account) {
                    if ($use['accessControl']['allowOpen'] === false) {
                        return reject('Access control allowOpen disabled');
                    }

                    $this->logger->debug(
                        'Trying to open door for place',
                        ['placeId' => $use['placeId'], 'accessControlId' => $use['accessControlId']]
                    );

                    $operatorId = $this->registry->accounts[$account]['data']['operatorId']
                        ?? ($this->registry->accounts[$account]['address']['operatorId'] ?? 0);
                    $uuid = $this->registry->accounts[$account]['uuid'] ?? null;
                    $placeId = $this->registry->accounts[$account]['address']['placeId'] ?? 1;

                    return $this->client->post(
                        sprintf(self::API_OPEN_DOOR, $use['placeId'], $use['accessControlId']),
                        $this->commonHeaders(
                            $operatorId,
                            $uuid,
                            $placeId,
                            [
                                'Operator' => (string)$operatorId,
                                'Authorization' => 'Bearer '.$this->registry->getToken($account),
                            ]
                        ),
                        json_encode(['name' => 'accessControlOpen'])
                    )->then(
                        function (ResponseInterface $response) use ($account) {
                            $data = json_decode($response->getBody()->getContents(), true);

                            if (!is_array($data) || !isset($data['data']['status'])) {
                                return reject('['.$account.'] Api error: [HTTP OK] Response json failed');
                            }

                            $this->logger->debug('Door opened');

                            return resolve($data['data']);
                        },
                        function (ResponseException $e) use ($account) {
                            $this->apiError($account, $e);

                            return resolve(
                                [
                                    'status' => false,
                                    'errorCode' => $e->getCode(),
                                    'errorMessage' => $e->getMessage(),
                                ]
                            );
                        }
                    );
                },
                function ($error) {
                    return reject($error);
                }
            );
    }

    public function cameraSnapshot(string $account, int $cameraId = null): PromiseInterface
    {
        if ($this->registry->state !== AsyncRegistry::STATE_LOOP) {
            return reject('Api not ready');
        }

        $cameras = $this->registry->fetch('cameras', $account);

        if (!count($cameras) || !isset($cameras[0]['ID'])) {
            return reject('There is no available camera for streaming');
        }

        foreach ($cameras as $camera) {
            if ($cameraId && (int)$camera['ID'] === $cameraId) {
                break;
            }

            if ($cameraId === null) {
                $cameraId = (int)$camera['ID'];
                break;
            }
        }

        $operatorId = $this->registry->accounts[$account]['data']['operatorId']
            ?? ($this->registry->accounts[$account]['address']['operatorId'] ?? 0);
        $uuid = $this->registry->accounts[$account]['uuid'] ?? null;
        $placeId = $this->registry->accounts[$account]['address']['placeId'] ?? 1;

        return $this->client->get(
            sprintf(self::API_CAMERA_GET_SNAPSHOT, $cameraId),
            $this->commonHeaders(
                $operatorId,
                $uuid,
                $placeId,
                [
                    'Operator' => (string)$operatorId,
                    'Authorization' => 'Bearer '.$this->registry->getToken($account),
                ]
            )
        )->then(
            function (ResponseInterface $response) use ($account) {
                if ($response->getHeader('Content-Type')[0] !== 'image/jpeg') {
                    $this->logger->warning('Bad response headers from Domru');
                }

                $this->logger->debug('Snapshot success');

                return resolve(
                    [
                        'mime' => 'image/jpeg',
                        'content' => $response->getBody()->getContents(),
                    ]
                );
            },
            function (ResponseException $e) use ($account) {
                $this->apiError($account, $e);

                return resolve(
                    [
                        'status' => false,
                        'errorCode' => $e->getCode(),
                        'errorMessage' => $e->getMessage(),
                    ]
                );
            }
        );
    }

    public function cameraStream(string $account, int $cameraId = null, int $timestamp = null): PromiseInterface
    {
        if ($this->registry->state !== AsyncRegistry::STATE_LOOP) {
            return reject('Api not ready');
        }

        $cameras = $this->registry->fetch('cameras', $account);

        if (!count($cameras) || !isset($cameras[0]['ID'])) {
            return reject('There is no available camera for streaming');
        }

        $cameraToUse = null;

        foreach ($cameras as $camera) {
            if ($cameraId && (int)$camera['ID'] === $cameraId) {
                $cameraToUse = $camera;
                break;
            }

            if ($cameraId === null) {
                $cameraId = (int)$camera['ID'];
                $cameraToUse = $camera;
                break;
            }
        }

        $url = sprintf(self::API_CAMERA_GET_STREAM, $cameraId);
        $httpQuery = [
            'LightStream' => 0,
        ];

        if ($timestamp) {
            $httpQuery['TS'] = $timestamp;
            $httpQuery['TZ'] = $cameraToUse['TimeZone'];
        }

        $operatorId = $this->registry->accounts[$account]['data']['operatorId']
            ?? ($this->registry->accounts[$account]['address']['operatorId'] ?? 0);
        $uuid = $this->registry->accounts[$account]['uuid'] ?? null;
        $placeId = $this->registry->accounts[$account]['address']['placeId'] ?? 1;

        return $this->client->get(
            $url.http_build_query($httpQuery),
            $this->commonHeaders(
                $operatorId,
                $uuid,
                $placeId,
                [
                    'Operator' => (string)$operatorId,
                    'Authorization' => 'Bearer '.$this->registry->getToken($account),
                ]
            )
        )->then(
            function (ResponseInterface $response) {
                $data = json_decode($response->getBody()->getContents(), true);

                if (!is_array($data) || !is_array($data['data']) || empty($data['data']['URL'])) {
                    return reject('Api error: [HTTP OK] Response json failed');
                }

                return resolve($data['data']['URL']);
            },
            function (ResponseException $e) use ($account) {
                $this->apiError($account, $e);

                return resolve(
                    [
                        'status' => false,
                        'errorCode' => $e->getCode(),
                        'errorMessage' => $e->getMessage(),
                    ]
                );
            }
        );
    }

    public function events(string $account, int $placeId = null, int $limit = null): PromiseInterface
    {
        if ($this->registry->state !== AsyncRegistry::STATE_LOOP) {
            return reject('Api not ready');
        }

        return $this->getPlaceId($account, $placeId)
            ->then(
                function ($use) use ($account, $limit) {
                    $this->logger->debug('Trying to fetch events for place', ['placeId' => $use['placeId']]);

                    $operatorId = $this->registry->accounts[$account]['data']['operatorId']
                        ?? ($this->registry->accounts[$account]['address']['operatorId'] ?? 0);
                    $uuid = $this->registry->accounts[$account]['uuid'] ?? null;
                    $accountPlaceId = $this->registry->accounts[$account]['address']['placeId'] ?? 1;

                    return $this->client->get(
                        sprintf(self::API_EVENTS, $use['placeId']),
                        $this->commonHeaders(
                            $operatorId,
                            $uuid,
                            $accountPlaceId,
                            [
                                'Operator' => (string)$operatorId,
                                'Authorization' => 'Bearer '.$this->registry->getToken($account),
                            ]
                        )
                    )->then(
                        function (ResponseInterface $response) use ($account, $limit) {
                            $data = json_decode($response->getBody()->getContents(), true);

                            if (!is_array($data) || !isset($data['data'])) {
                                return reject('['.$account.'] Api error: [HTTP OK] Response json failed');
                            }

                            if ($limit) {
                                $returnData = [];
                                foreach ($data['data'] as $i => $row) {
                                    if ($i >= $limit) {
                                        break;
                                    }
                                    $returnData[] = $row;
                                }

                                return resolve($returnData);
                            }

                            return resolve($data['data']);
                        },
                        function (ResponseException $e) use ($account) {
                            $this->apiError($account, $e);

                            return resolve(
                                [
                                    'status' => false,
                                    'errorCode' => $e->getCode(),
                                    'errorMessage' => $e->getMessage(),
                                ]
                            );
                        }
                    );
                },
                function ($error) {
                    return reject($error);
                }
            );
    }
}

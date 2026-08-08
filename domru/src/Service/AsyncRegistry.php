<?php

namespace App\Service;

use React\EventLoop\LoopInterface;

class AsyncRegistry
{
    public const STATE_START = 0;
    public const STATE_READY = 1;
    public const STATE_LOOP = 2;

    private static ?self $instance = null;

    public LoopInterface $loop;
    public int $state = self::STATE_START;
    private array $data = [];
    private array $fetchData = [];
    private array $tokens = [];

    public static function getInstance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct()
    {
        $this->data = [];
        $this->fetchData = [];
        $this->tokens = [];
        $this->state = self::STATE_START;
    }

    public function all(): array
    {
        $data = $this->data;

        if (!isset($data['accounts']) || !is_array($data['accounts'])) {
            $data['accounts'] = [];
        }

        foreach ($data['accounts'] as $account => &$accountData) {
            $cameras = [];

            foreach ($this->fetch('cameras', (string)$account) as $camera) {
                if (isset($camera['ID'])) {
                    $cameras[$camera['ID']] = $camera;
                }
            }

            $subscriberPlaces = $this->fetch('subscriberPlaces', (string)$account);
            $accessControls = $this->fetch('accessControls', (string)$account);

            if ($subscriberPlaces) {
                foreach ($accessControls as $accessControl) {
                    $cameraId = $accessControl['externalCameraId'] ?? null;
                    if ($cameraId && isset($cameras[$cameraId])) {
                        $cameras[$cameraId]['isSubscriber'] = $accessControl['id'] ?? true;
                    }
                }

                foreach ($subscriberPlaces as &$subscriberPlace) {
                    $subscriberPlace['additionalCameras'] = false;
                    foreach ($cameras as $camera) {
                        if (!isset($camera['isSubscriber']) && isset($camera['ID'])) {
                            $subscriberPlace['additionalCameras'][] = $camera['ID'];
                        }
                    }
                    if (is_array($subscriberPlace['additionalCameras'])) {
                        $subscriberPlace['additionalCameras'] = array_unique($subscriberPlace['additionalCameras']);
                    }
                }
            }

            $accountData['finances'] = $this->fetch('finances', (string)$account);
            $accountData['profiles'] = $this->fetch('profiles', (string)$account);
            $accountData['cameras'] = $cameras;
            $accountData['accessControls'] = $accessControls;
            $accountData['subscriberPlaces'] = $subscriberPlaces;
            $accountData['apiErrors'] = $this->fetch('apiErrors', (string)$account);
        }
        unset($accountData);

        unset($data['loop']);

        return array_merge(
            $data,
            [
                'state' => $this->state,
                'tokens' => $this->tokens,
            ]
        );
    }

    public function __set($key, $val)
    {
        $this->data[$key] = $val;
    }

    public function __get($key)
    {
        return $this->data[$key] ?? null;
    }

    public function accountsUpdate(array $deletedAccounts)
    {
        if ($deletedAccounts) {
            foreach ($deletedAccounts as $account) {
                if (isset($this->fetchData[$account])) {
                    unset($this->fetchData[$account]);
                }
                if (isset($this->tokens[$account])) {
                    unset($this->tokens[$account]);
                }
                if (isset($this->data['lastUpdate'][$account])) {
                    unset($this->data['lastUpdate'][$account]);
                }
            }
        }
    }

    public function update(string $key, string $account, array $data)
    {
        $this->data['lastUpdate'][$account][$key] = time();

        if ($key === 'apiErrors' && isset($this->fetchData[$account][$key]) && is_array($this->fetchData[$account][$key])) {
            $this->fetchData[$account][$key] = array_merge($this->fetchData[$account][$key], $data);
            return;
        }

        $this->fetchData[$account][$key] = $data;
    }

    public function fetch(string $key, string $account): array
    {
        return $this->fetchData[$account][$key] ?? [];
    }

    public function setToken(string $account, string $token)
    {
        $this->tokens[$account] = $token;
    }

    public function getToken(string $account): ?string
    {
        return $this->tokens[$account] ?? null;
    }

    public function getTokens(): array
    {
        return $this->tokens;
    }
}

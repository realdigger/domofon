<?php

namespace App\Controller;

use App\Service\AccountService;
use App\Service\Domru;
use App\Traits\HttpClientAwareTrait;
use Exception;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Throwable;

/**
 * @Route("/login", name="login_");
 */
class LoginController extends AbstractController
{
    use HttpClientAwareTrait;

    /**
     * @Route("", name="login", methods={"POST", "GET"})
     *
     * @param Request $request
     * @param Domru $domru
     *
     * @return Response
     */
    public function login(Request $request, Domru $domru): Response
    {
        $error = null;

        if ($request->getMethod() === 'POST') {
            try {
                $accounts = $domru->getAccounts($request->get('phone'), Domru::LOGIN_BY_PHONE);

                if ($accounts) {
                    return $this->render(
                        'login-accounts.html.twig',
                        [
                            'hassioIngress' => $this->hassioIngress,
                            'phone' => $accounts['phone'],
                            'accounts' => $accounts['accounts'],
                        ]
                    );
                }

                $error = 'По указанным данным ничего не найдено';
            } catch (Throwable $e) {
                $error = $this->exceptionMessage($e);
            }
        }

        return $this->render(
            'login.html.twig',
            [
                'hassioIngress' => $this->hassioIngress,
                'error' => $error,
            ]
        );
    }

    /**
     * @Route("/address/{phone}/{index}", name="address", methods={"GET"})
     *
     * @param Request $request
     * @param Domru $domru
     *
     * @return Response
     */
    public function selectAddress(Request $request, Domru $domru): Response
    {
        try {
            $smsRequest = $domru->requestSmsConfirmation(
                $request->attributes->get('phone'),
                (int)$request->attributes->get('index')
            );

            if (!$smsRequest) {
                throw new Exception('SMS request failed');
            }

            return $this->render(
                'login-sms.html.twig',
                [
                    'hassioIngress' => $this->hassioIngress,
                    'phone' => $request->attributes->get('phone'),
                    'index' => (int)$request->attributes->get('index'),
                ]
            );
        } catch (Throwable $e) {
            return $this->render(
                'login.html.twig',
                [
                    'hassioIngress' => $this->hassioIngress,
                    'error' => $this->exceptionMessage($e),
                ]
            );
        }
    }

    /**
     * @Route("/sms/{phone}/{index}", name="sms", methods={"POST"})
     *
     * @param Request $request
     * @param Domru $domru
     * @param AccountService $accountService
     *
     * @return Response
     */
    public function sms(Request $request, Domru $domru, AccountService $accountService): Response
    {
        try {
            $phone = $request->attributes->get('phone');
            $index = (int)$request->attributes->get('index');
            $smsCode = (int)$request->get('sms');

            $accounts = $domru->getAccounts($phone, Domru::LOGIN_BY_PHONE);
            $smsRequest = $domru->requestSmsVerification($phone, $index, $smsCode);

            $accountId = $accounts['accounts'][$index]['accountId'];

            $accountService->addAccount(
                [
                    'id' => $accountId,
                    'uuid' => mb_strtoupper(Uuid::uuid4()),
                    'phone' => $accounts['phone'],
                    'address' => $accounts['accounts'][$index],
                    'data' => $smsRequest,
                ]
            );

            $checks = 0;
            $lastError = null;

            while (true) {
                $response = $this->getHttp()->request('GET', 'http://127.0.0.1/api');
                $content = json_decode($response->getBody()->getContents(), true);

                if (
                    isset($content['accounts']) &&
                    is_array($content['accounts']) &&
                    isset($content['accounts'][$accountId])
                ) {
                    $redirect = $this->redirectToRoute('index');

                    if ($this->hassioIngress) {
                        $redirect->setTargetUrl($this->hassioIngress.$redirect->getTargetUrl());
                    }

                    return $redirect;
                }

                if (is_array($content) && isset($content['errorMessage'])) {
                    $lastError = $content['errorMessage'];
                }

                if ($checks > 10) {
                    throw new Exception($lastError ?: 'Авторизация выполнена, но данные аккаунта не загрузились. Проверьте журнал аддона.');
                }

                $checks++;
                sleep(2);
            }
        } catch (Throwable $e) {
            return $this->render(
                'login-sms.html.twig',
                [
                    'hassioIngress' => $this->hassioIngress,
                    'phone' => $request->attributes->get('phone'),
                    'index' => (int)$request->attributes->get('index'),
                    'error' => $this->exceptionMessage($e),
                ]
            );
        }
    }

    private function exceptionMessage(Throwable $e): string
    {
        if (method_exists($e, 'getResponse') && $e->getResponse()) {
            try {
                $body = $e->getResponse()->getBody()->getContents();

                if ($body) {
                    return $body;
                }
            } catch (Throwable $ignored) {
            }
        }

        $message = $e->getMessage();

        if ($message) {
            return $message;
        }

        return get_class($e);
    }
}

<?php

namespace Overtrue\Socialite\Providers;

use GuzzleHttp\Psr7\Stream;
use JetBrains\PhpStorm\ArrayShape;
use Overtrue\Socialite\Contracts\UserInterface;
use Overtrue\Socialite\Exceptions\AuthorizeFailedException;
use Overtrue\Socialite\Exceptions\BadRequestException;
use Overtrue\Socialite\User;

/**
 * @see https://www.tapd.cn/help/show#1120003271001000708
 */
class Tapd extends Base
{
    public const NAME = 'tapd';
    protected string $baseUrl = 'https://api.tapd.cn';

    protected function getAuthUrl(): string
    {
        return $this->buildAuthUrlFromBase($this->baseUrl . '/quickstart/testauth');
    }

    protected function getTokenUrl(): string
    {
        return $this->baseUrl . '/tokens/request_token';
    }

    protected function getRefreshTokenUrl(): string
    {
        return $this->baseUrl . '/tokens/refresh_token';
    }

    /**
     * @throws \Overtrue\Socialite\Exceptions\AuthorizeFailedException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function tokenFromCode(string $code): array
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . \base64_encode(\sprintf('%s:%s', $this->getClientId(), $this->getClientSecret()))
            ],
            'form_params' => $this->getTokenFields($code),
        ]);

        return $this->normalizeAccessTokenResponse($response->getBody()->getContents());
    }

    #[ArrayShape(['grant_type' => "string", 'redirect_uri' => "mixed", 'code' => "string"])]
    protected function getTokenFields(string $code): array
    {
        return [
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUrl,
            'code' => $code,
        ];
    }

    #[ArrayShape(['grant_type' => "string", 'redirect_uri' => "mixed", 'refresh_token' => ""])]
    protected function getRefreshTokenFields(string $refreshToken): array
    {
        return [
            'grant_type' => 'refresh_token',
            'redirect_uri' => $this->redirectUrl,
            'refresh_token' => $refreshToken,
        ];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Overtrue\Socialite\Exceptions\AuthorizeFailedException
     */
    public function tokenFromRefreshToken(string $refreshToken): array
    {
        $response = $this->getHttpClient()->post($this->getRefreshTokenUrl(), [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . \base64_encode(\sprintf('%s:%s', $this->getClientId(), $this->getClientSecret()))
            ],
            'form_params' => $this->getRefreshTokenFields($refreshToken),
        ]);

        return $this->normalizeAccessTokenResponse($response->getBody()->getContents());
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function getUserByToken(string $token): array
    {
        $response = $this->getHttpClient()->get($this->baseUrl . '/users/info', [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        return \json_decode($response->getBody(), true) ?? [];
    }

    /**
     * @throws \Overtrue\Socialite\Exceptions\BadRequestException
     */
    protected function mapUserToObject(array $user): UserInterface
    {
        if (!isset($user['status']) && $user['status'] != 1) {
            throw new BadRequestException("用户信息获取失败");
        }

        return new User([
            'id' => $user['data']['id'] ?? null,
            'nickname' => $user['data']['nick'] ?? null,
            'name' => $user['data']['name'] ?? null,
            'email' => $user['data']['email'] ?? null,
            'avatar' => $user['data']['avatar'] ?? null,
        ]);
    }

    /**
     * @throws \Overtrue\Socialite\Exceptions\AuthorizeFailedException
     */
    protected function normalizeAccessTokenResponse($response): array
    {
        if ($response instanceof Stream) {
            $response->rewind();
            $response = $response->getContents();
        }

        if (\is_string($response)) {
            $response = json_decode($response, true) ?? [];
        }

        if (!\is_array($response)) {
            throw new AuthorizeFailedException('Invalid token response', [$response]);
        }

        if (empty($response['data'][$this->accessTokenKey])) {
            throw new AuthorizeFailedException('Authorize Failed: ' . json_encode($response, JSON_UNESCAPED_UNICODE), $response);
        }

        return $response + [
                'access_token' => $response['data'][$this->accessTokenKey],
                'refresh_token' => $response['data'][$this->refreshTokenKey] ?? null,
                'expires_in' => \intval($response['data'][$this->expiresInKey] ?? 0),
            ];
    }
}

<?php
declare(strict_types=1);

namespace LockmeIntegration\Libs;

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Lockme\OAuth2\Client\Provider\Lockme;

class WrappedProvider extends Lockme
{
    public function executeRequest($method, $url, $token, $body = null)
    {
        global $wpdb;

        try {
            return parent::executeRequest($method, $url, $token, $body);
        } catch (IdentityProviderException $exception) {
            $response = $exception->getResponseBody();
            if (is_string($response)) {
                $resp = json_decode($response, true);
                if ($resp) {
                    $response = json_encode($resp, JSON_PRETTY_PRINT);
                }
            }
            $data = [
                'method' => $method,
                'uri' => $url,
                'params' => json_encode($body, JSON_PRETTY_PRINT),
                'response' => is_string($response) ? $response : json_encode($response, JSON_PRETTY_PRINT)
            ];
            $wpdb->insert(
                $wpdb->prefix.'lockme_log',
                $data
            );

            throw $exception;
        }
    }
}

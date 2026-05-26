<?php

namespace Espo\Modules\QuickBooks\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\ORM\EntityManager;

use DateTime;

/**
 * Handles the QuickBooks OAuth2 authorization callback.
 * QB redirects here with ?code=...&realmId=...&state=...
 *
 * Register as redirect URI: {siteUrl}?entryPoint=QuickBooksOauthCallback
 */
class QuickBooksOauthCallback implements EntryPoint
{
    use NoAuth;

    private const TOKEN_ENDPOINT = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log
    ) {}

    public function run(Request $request, Response $response): void
    {
        $error = $request->getQueryParam('error');

        if ($error) {
            $this->log->warning("QuickBooks OAuth callback error: $error");
            $this->renderResult($response, false, "QuickBooks authorization was denied: $error");

            return;
        }

        $code = $request->getQueryParam('code');
        $realmId = $request->getQueryParam('realmId');
        $state = $request->getQueryParam('state');

        if (!$code || !$realmId) {
            $this->renderResult($response, false, "Missing code or realmId in callback.");

            return;
        }

        try {
            /** @var ?Integration $integration */
            $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'QuickBooks');

            if (!$integration) {
                $this->renderResult($response, false, "QuickBooks integration not found.");

                return;
            }

            $storedState = $integration->get('oauthState');

            if (!$storedState || $state !== $storedState) {
                $this->log->warning("QB OAuth: state mismatch. stored=[$storedState] received=[$state]");
                $this->renderResult($response, false, "Invalid OAuth state parameter.");

                return;
            }

            $tokens = $this->exchangeCodeForTokens($integration, $code, $realmId);

            $expiresAt = (new DateTime())
                ->modify('+' . ($tokens['expires_in'] ?? 3600) . ' seconds')
                ->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

            $integration->set('accessToken', $tokens['access_token'] ?? null);
            $integration->set('refreshToken', $tokens['refresh_token'] ?? null);
            $integration->set('accessTokenExpiresAt', $expiresAt);
            $integration->set('realmId', $realmId);
            $integration->set('connectedAt', (new DateTime())->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT));
            $integration->set('oauthState', null);

            $this->entityManager->saveEntity($integration);

            $this->renderResult($response, true, "QuickBooks connected successfully.");
        } catch (\Throwable $e) {
            $this->log->error("QuickBooks OAuth callback exception: " . $e->getMessage());
            $this->renderResult($response, false, "Failed to connect: " . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     * @throws Error
     */
    protected function exchangeCodeForTokens(Integration $integration, string $code, string $realmId): array
    {
        $clientId = $integration->get('clientId');
        $clientSecret = $integration->get('clientSecret');
        $siteUrl = rtrim($this->config->get('siteUrl') ?? '', '/');
        $redirectUri = $siteUrl . '/?entryPoint=QuickBooksOauthCallback';

        $ch = curl_init(self::TOKEN_ENDPOINT);

        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode("$clientId:$clientSecret"),
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $httpCode !== 200) {
            throw new Error("QuickBooks token exchange failed (HTTP $httpCode).");
        }

        return Json::decode($raw, true);
    }

    private function renderResult(Response $response, bool $success, string $message): void
    {
        $status = $success ? 'success' : 'error';
        $color = $success ? '#2b7de9' : '#c0392b';
        $origin = rtrim($this->config->get('siteUrl') ?? '', '/');
        $originJs = json_encode($origin);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head><title>QuickBooks Connection</title></head>
<body style="font-family:sans-serif;text-align:center;padding:40px;">
  <p style="color:{$color};font-size:16px;">{$message}</p>
  <script>
    if (window.opener) {
      window.opener.postMessage({name:'quickBooksOAuth',status:'{$status}'}, '*');
      setTimeout(function(){ window.close(); }, 1500);
    }
  </script>
</body>
</html>
HTML;

        $response->writeBody($html);
    }
}

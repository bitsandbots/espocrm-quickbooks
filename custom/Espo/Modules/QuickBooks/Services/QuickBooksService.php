<?php

namespace Espo\Modules\QuickBooks\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\DateTime as DateTimeUtil;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Log;
use Espo\Entities\Integration;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;

use DateTime;
use Exception;
use stdClass;

class QuickBooksService
{
    private const API_BASE = 'https://quickbooks.api.intuit.com/v3/company';
    private const TOKEN_ENDPOINT = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log
    ) {}

    // -------------------------------------------------------------------------
    // Integration entity access
    // -------------------------------------------------------------------------

    private function getIntegration(): Integration
    {
        /** @var ?Integration $integration */
        $integration = $this->entityManager->getEntityById(Integration::ENTITY_TYPE, 'QuickBooks');

        if (!$integration || !$integration->isEnabled()) {
            throw new Error("QuickBooks integration is not enabled.");
        }

        return $integration;
    }

    private function saveIntegration(Integration $integration): void
    {
        $this->entityManager->saveEntity($integration);
    }

    // -------------------------------------------------------------------------
    // Token management
    // -------------------------------------------------------------------------

    private function getAccessToken(Integration $integration): string
    {
        $expiresAt = $integration->get('accessTokenExpiresAt');

        if ($expiresAt) {
            try {
                $dt = new DateTime($expiresAt);
                $dt->modify('-30 seconds');

                if ($dt->format('U') < (new DateTime())->format('U')) {
                    $this->refreshAccessToken($integration);
                }
            } catch (Exception) {
                $this->refreshAccessToken($integration);
            }
        }

        $token = $integration->get('accessToken');

        if (!$token) {
            throw new Error("QuickBooks: No access token. Please connect via Admin → Integrations → QuickBooks.");
        }

        return $token;
    }

    private function refreshAccessToken(Integration $integration): void
    {
        $clientId = $integration->get('clientId');
        $clientSecret = $integration->get('clientSecret');
        $refreshToken = $integration->get('refreshToken');

        if (!$clientId || !$clientSecret || !$refreshToken) {
            throw new Error("QuickBooks: Missing credentials for token refresh.");
        }

        $ch = curl_init(self::TOKEN_ENDPOINT);

        $body = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
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

        if ($httpCode !== 200 || !is_string($raw)) {
            throw new Error("QuickBooks: Token refresh failed (HTTP $httpCode).");
        }

        $result = Json::decode($raw, true);

        $expiresAt = (new DateTime())
            ->modify('+' . ($result['expires_in'] ?? 3600) . ' seconds')
            ->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        $integration->set('accessToken', $result['access_token'] ?? null);
        $integration->set('accessTokenExpiresAt', $expiresAt);

        if (!empty($result['refresh_token'])) {
            $integration->set('refreshToken', $result['refresh_token']);
        }

        $this->saveIntegration($integration);
    }

    // -------------------------------------------------------------------------
    // Raw HTTP
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @throws Error
     */
    protected function request(
        string $method,
        string $url,
        ?array $body = null
    ): array {
        $integration = $this->getIntegration();
        $accessToken = $this->getAccessToken($integration);

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, Json::encode($body));
        }

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw)) {
            throw new Error("QuickBooks: cURL request failed for $method $url.");
        }

        $result = Json::decode($raw, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $result['Fault']['Error'][0]['Message'] ?? "HTTP $httpCode";
            throw new Error("QuickBooks API error ($method $url): $msg");
        }

        return $result;
    }

    private function apiUrl(string $path): string
    {
        $integration = $this->getIntegration();
        $realmId = $integration->get('realmId');

        if (!$realmId) {
            throw new Error("QuickBooks: realmId not set. Please reconnect the integration.");
        }

        return self::API_BASE . '/' . $realmId . '/' . ltrim($path, '/');
    }

    // -------------------------------------------------------------------------
    // Customer (Account / Contact → QB Customer)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildCustomerPayload(string $entityType, Entity $entity): array
    {
        $payload = [];

        if ($entityType === 'Account') {
            $payload['CompanyName'] = $entity->get('name');
            $payload['DisplayName'] = $entity->get('name');
        } else {
            $first = $entity->get('firstName') ?? '';
            $last = $entity->get('lastName') ?? '';
            $payload['GivenName'] = $first;
            $payload['FamilyName'] = $last;
            $payload['DisplayName'] = trim("$first $last") ?: ($entity->get('name') ?? '');
        }

        $email = $entity->get('emailAddress');

        if ($email) {
            $payload['PrimaryEmailAddr'] = ['Address' => $email];
        }

        $phone = $entity->get('phoneNumber');

        if ($phone) {
            $payload['PrimaryPhone'] = ['FreeFormNumber' => $phone];
        }

        $website = $entity->get('website');

        if ($website) {
            $payload['WebAddr'] = ['URI' => $website];
        }

        $billStreet = $entity->get('billingAddressStreet');

        if ($billStreet) {
            $payload['BillAddr'] = [
                'Line1' => $billStreet,
                'City' => $entity->get('billingAddressCity') ?? '',
                'CountrySubDivisionCode' => $entity->get('billingAddressState') ?? '',
                'PostalCode' => $entity->get('billingAddressPostalCode') ?? '',
                'Country' => $entity->get('billingAddressCountry') ?? '',
            ];
        }

        return $payload;
    }

    /**
     * Upsert an Account or Contact as a QB Customer.
     *
     * @throws Error
     */
    public function upsertCustomer(string $entityType, Entity $entity): void
    {
        $payload = $this->buildCustomerPayload($entityType, $entity);

        $qbId = $entity->get('qbCustomerId');
        $syncToken = $entity->get('qbCustomerSyncToken');

        if ($qbId && $syncToken !== null) {
            $payload['Id'] = $qbId;
            $payload['SyncToken'] = $syncToken;
            $payload['sparse'] = true;
        }

        $url = $this->apiUrl('customer');
        $result = $this->request('POST', $url, ['Customer' => $payload]);

        $customer = $result['Customer'] ?? null;

        if (!$customer) {
            throw new Error("QuickBooks: Unexpected response from customer upsert.");
        }

        $now = (new DateTime())->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        $entity->set('qbCustomerId', (string) $customer['Id']);
        $entity->set('qbCustomerSyncToken', (string) $customer['SyncToken']);
        $entity->set('qbSyncedAt', $now);

        $this->entityManager->saveEntity($entity, ['skipQuickBooksSync' => true, 'silent' => true]);
    }

    // -------------------------------------------------------------------------
    // Invoice (EspoCRM Invoice → QB Invoice)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     * @throws Error
     */
    private function buildInvoicePayload(Entity $invoice): array
    {
        $accountId = $invoice->get('accountId');
        $account = $accountId
            ? $this->entityManager->getEntityById('Account', $accountId)
            : null;

        $qbCustomerId = $account?->get('qbCustomerId');

        if (!$qbCustomerId) {
            throw new Error(
                "QuickBooks: Cannot sync invoice — linked Account has no QB Customer ID. Sync the Account first."
            );
        }

        $defaultItemId = $this->getIntegration()->get('defaultItemId') ?: null;

        $payload = [
            'CustomerRef' => ['value' => $qbCustomerId],
            'DueDate' => $invoice->get('dueDate'),
            'Line' => $this->buildLineItems($invoice, $defaultItemId),
        ];

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLineItems(Entity $invoice, ?string $defaultItemId = null): array
    {
        $lineItems = $invoice->get('lineItems');

        if (empty($lineItems) || !is_array($lineItems)) {
            $amount = (float) ($invoice->get('amount') ?? 0);

            $detail = [
                'UnitPrice' => $amount,
                'Qty' => 1.0,
            ];

            if ($defaultItemId !== null) {
                $detail['ItemRef'] = ['value' => $defaultItemId];
            }

            return [[
                'Amount' => $amount,
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => $detail,
            ]];
        }

        $lines = [];

        foreach ($lineItems as $item) {
            $qty = (float) ($item['quantity'] ?? 1);
            $price = (float) ($item['unitPrice'] ?? 0);
            $line = [
                'Amount' => $qty * $price,
                'DetailType' => 'SalesItemLineDetail',
                'SalesItemLineDetail' => [
                    'UnitPrice' => $price,
                    'Qty' => $qty,
                ],
                'Description' => $item['description'] ?? '',
            ];

            $itemId = !empty($item['qbItemId']) ? $item['qbItemId'] : $defaultItemId;

            if ($itemId !== null) {
                $line['SalesItemLineDetail']['ItemRef'] = ['value' => $itemId];
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @throws Error
     */
    public function upsertInvoice(Entity $invoice): void
    {
        $payload = $this->buildInvoicePayload($invoice);

        $qbId = $invoice->get('qbInvoiceId');
        $syncToken = $invoice->get('qbInvoiceSyncToken');

        if ($qbId && $syncToken !== null) {
            $payload['Id'] = $qbId;
            $payload['SyncToken'] = $syncToken;
            $payload['sparse'] = true;
        }

        $url = $this->apiUrl('invoice');
        $result = $this->request('POST', $url, ['Invoice' => $payload]);

        $qbInvoice = $result['Invoice'] ?? null;

        if (!$qbInvoice) {
            throw new Error("QuickBooks: Unexpected response from invoice upsert.");
        }

        $now = (new DateTime())->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT);

        $invoice->set('qbInvoiceId', (string) $qbInvoice['Id']);
        $invoice->set('qbInvoiceSyncToken', (string) $qbInvoice['SyncToken']);
        $invoice->set('qbSyncedAt', $now);

        $this->entityManager->saveEntity($invoice, ['skipQuickBooksSync' => true, 'silent' => true]);
    }

    /**
     * Voids an Invoice in QuickBooks. No-ops if the invoice was never synced.
     *
     * @throws Error
     */
    public function voidInvoice(Entity $invoice): void
    {
        $qbId = $invoice->get('qbInvoiceId');
        $syncToken = $invoice->get('qbInvoiceSyncToken');

        if (!$qbId || $syncToken === null) {
            return;
        }

        $url = $this->apiUrl('invoice') . '?operation=void&minorversion=65';

        $this->request('POST', $url, [
            'Invoice' => [
                'Id' => $qbId,
                'SyncToken' => $syncToken,
                'sparse' => true,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Pull from QuickBooks
    // -------------------------------------------------------------------------

    /**
     * Pull QB Payments updated since $sinceDate and update EspoCRM Invoice statuses.
     *
     * @throws Error
     */
    public function pullPaymentsSince(string $sinceDate): void
    {
        $query = urlencode("SELECT * FROM Payment WHERE TxnDate >= '$sinceDate'");
        $url = $this->apiUrl("query?query=$query&minorversion=65");

        $result = $this->request('GET', $url);

        $payments = $result['QueryResponse']['Payment'] ?? [];

        foreach ($payments as $payment) {
            $this->applyPaymentToInvoices($payment);
        }
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function applyPaymentToInvoices(array $payment): void
    {
        $linkedTxns = $payment['Line'][0]['LinkedTxn'] ?? [];

        foreach ($linkedTxns as $txn) {
            if (($txn['TxnType'] ?? '') !== 'Invoice') {
                continue;
            }

            $qbInvoiceId = $txn['TxnId'] ?? null;

            if (!$qbInvoiceId) {
                continue;
            }

            $invoiceCollection = $this->entityManager
                ->getRDBRepository('Invoice')
                ->where(['qbInvoiceId' => $qbInvoiceId])
                ->limit(0, 1)
                ->find();

            foreach ($invoiceCollection as $invoice) {
                $invoice->set('status', 'Paid');
                $invoice->set('qbPaymentId', $payment['Id'] ?? null);
                $invoice->set('qbPaymentDate', $payment['TxnDate'] ?? null);

                $this->entityManager->saveEntity($invoice, ['skipQuickBooksSync' => true, 'silent' => true]);
            }
        }
    }

    /**
     * Pull QB Customers updated since $sinceDate and sync to EspoCRM Accounts.
     *
     * @throws Error
     */
    public function pullCustomersSince(string $sinceDate): void
    {
        $query = urlencode("SELECT * FROM Customer WHERE MetaData.LastUpdatedTime >= '$sinceDate'");
        $url = $this->apiUrl("query?query=$query&minorversion=65");

        $result = $this->request('GET', $url);

        $customers = $result['QueryResponse']['Customer'] ?? [];

        foreach ($customers as $customer) {
            $this->applyCustomerToAccount($customer);
        }
    }

    /**
     * @param array<string, mixed> $customer
     */
    private function applyCustomerToAccount(array $customer): void
    {
        $qbId = (string) $customer['Id'];

        $collection = $this->entityManager
            ->getRDBRepository('Account')
            ->where(['qbCustomerId' => $qbId])
            ->limit(0, 1)
            ->find();

        foreach ($collection as $account) {
            $qbUpdatedRaw = $customer['MetaData']['LastUpdatedTime'] ?? null;
            $espoSyncedAt = $account->get('qbSyncedAt');

            if ($qbUpdatedRaw && $espoSyncedAt) {
                $qbUpdated = strtotime($qbUpdatedRaw);
                $espoSynced = strtotime($espoSyncedAt);

                if ($qbUpdated <= $espoSynced) {
                    continue;
                }
            }

            $account->set('name', $customer['CompanyName'] ?? $customer['DisplayName'] ?? $account->get('name'));

            $email = $customer['PrimaryEmailAddr']['Address'] ?? null;

            if ($email) {
                $account->set('emailAddress', $email);
            }

            $phone = $customer['PrimaryPhone']['FreeFormNumber'] ?? null;

            if ($phone) {
                $account->set('phoneNumber', $phone);
            }

            $account->set('qbCustomerSyncToken', (string) ($customer['SyncToken'] ?? ''));
            $account->set('qbSyncedAt', (new DateTime())->format(DateTimeUtil::SYSTEM_DATE_TIME_FORMAT));

            $this->entityManager->saveEntity($account, ['skipQuickBooksSync' => true, 'silent' => true]);
        }
    }
}

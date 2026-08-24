<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Hands a built label to depot, which owns the printer.
 *
 * Pushed, not queued. Depot's existing print path is a pull — `depot:poll-print-jobs`
 * asks ssai every few seconds whether anything is waiting — which is right when a
 * hub feeds many stations, but here the operator is standing at the printer holding
 * the item. Seconds of poll latency is the difference between "it printed" and
 * "is it broken?". Depot answers with the outcome, so a jam surfaces at the moment
 * of the click rather than in a log.
 */
final class DepotPrintClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly PriceLabelService $labels,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(DEPOT_BASE_URL)%')]
        private readonly string $depotBaseUrl,
        #[Autowire('%env(DEPOT_TENANT_ID)%')]
        private readonly string $tenantId,
        #[Autowire('%env(DEPOT_PRINTER_PROFILE)%')]
        private readonly string $printerProfile,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->depotBaseUrl !== '';
    }

    /**
     * @return array{ok: bool, message: string, zpl: string}
     */
    public function printLabel(Item $item, ?string $qrValue = null, int $copies = 1): array
    {
        $zpl = $this->labels->buildZpl($item, $qrValue);

        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'DEPOT_BASE_URL is not set — nothing to print to. The label was built; see the preview.',
                'zpl' => $zpl,
            ];
        }

        try {
            $response = $this->http->request('POST', rtrim($this->depotBaseUrl, '/').'/api/print-jobs', [
                'json' => [
                    'tenantId' => $this->tenantId !== '' ? $this->tenantId : 'default',
                    'kind' => 'price-label',
                    'entityRef' => 'priceit:item:'.$item->getId(),
                    'contentType' => 'application/zpl',
                    'content' => $zpl,
                    'printerProfile' => $this->printerProfile !== '' ? $this->printerProfile : null,
                    'copies' => max(1, $copies),
                ],
                'timeout' => 15,
            ]);

            $status = $response->getStatusCode();
            $body = $response->toArray(false);

            if ($status === 200 && ($body['ok'] ?? false) === true) {
                return ['ok' => true, 'message' => sprintf('Printed (depot job #%s).', $body['id'] ?? '?'), 'zpl' => $zpl];
            }

            $message = (string) ($body['message'] ?? $body['error'] ?? 'HTTP '.$status);
            $this->logger->warning('priceit: depot refused a print job', ['item' => $item->getId(), 'status' => $status, 'body' => $body]);

            return ['ok' => false, 'message' => 'Depot could not print it: '.$message, 'zpl' => $zpl];
        } catch (\Throwable $e) {
            $this->logger->error('priceit: depot unreachable', ['item' => $item->getId(), 'error' => $e->getMessage()]);

            return [
                'ok' => false,
                'message' => sprintf('Depot at %s is unreachable: %s', $this->depotBaseUrl, $e->getMessage()),
                'zpl' => $zpl,
            ];
        }
    }
}

<?php
namespace Drupal\regiotoken_wallet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

class CronController extends ControllerBase {
    
    public function syncTransactions() {
        // Nur per Cron oder autorisiert aufrufbar
        $cronToken = \Drupal::config('regiotoken_wallet.settings')->get('cron_token');
        $requestToken = \Drupal::request()->query->get('token');
        
        if ($cronToken && $requestToken !== $cronToken) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthorized'], 403);
        }
        
        try {
            $transactionService = \Drupal::service('regiotoken_wallet.transaction_service');
            $results = $transactionService->syncPendingTransactions();
            
            $synced = count(array_filter($results, function($r) {
                return $r['status'] === 'confirmed';
            }));
            
            return new JsonResponse([
                'success' => true,
                'message' => "Synchronisiert: {$synced} Transaktionen",
                'results' => $results,
                'timestamp' => time(),
            ]);
            
        } catch (\Exception $e) {
            \Drupal::logger('regiotoken_wallet')->error('Cron sync error: @error', [
                '@error' => $e->getMessage(),
            ]);
            
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
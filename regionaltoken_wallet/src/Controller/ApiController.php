<?php
namespace Drupal\regiotoken_wallet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ApiController extends ControllerBase {
    
    public function getBalance($address) {
        try {
            $blockchainService = \Drupal::service('regiotoken_wallet.blockchain_service');
            
            if (!$blockchainService->validateAddress($address)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid address format',
                ], 400);
            }
            
            $balance = $blockchainService->getBalance($address);
            
            return new JsonResponse([
                'success' => true,
                'address' => $address,
                'balance' => (string) $balance,
                'symbol' => \Drupal::config('regiotoken_wallet.settings')->get('token_symbol'),
                'decimals' => (int) \Drupal::config('regiotoken_wallet.settings')->get('token_decimals'),
                'timestamp' => time(),
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    public function getGasPrice() {
        try {
            $blockchainService = \Drupal::service('regiotoken_wallet.blockchain_service');
            $gasPrice = $blockchainService->getGasPrice();
            
            return new JsonResponse([
                'success' => true,
                'gas_price' => $gasPrice,
                'network' => [
                    'chain_id' => \Drupal::config('regiotoken_wallet.settings')->get('chain_id'),
                    'name' => $this->getNetworkName(
                        \Drupal::config('regiotoken_wallet.settings')->get('chain_id')
                    ),
                ],
                'timestamp' => time(),
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    public function getTransaction($txHash) {
        try {
            $blockchainService = \Drupal::service('regiotoken_wallet.blockchain_service');
            $status = $blockchainService->getTransactionStatus($txHash);
            
            return new JsonResponse([
                'success' => true,
                'tx_hash' => $txHash,
                'status' => $status,
                'explorer_url' => \Drupal::config('regiotoken_wallet.settings')->get('explorer_url') . '/tx/' . $txHash,
                'timestamp' => time(),
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    private function getNetworkName($chainId) {
        $networks = [
            1 => 'Ethereum Mainnet',
            5 => 'Goerli Testnet',
            100 => 'Gnosis Chain',
            10200 => 'Gnosis Chiado Testnet',
            137 => 'Polygon Mainnet',
            80001 => 'Polygon Mumbai',
        ];
        
        return $networks[$chainId] ?? 'Unknown Network';
    }
}
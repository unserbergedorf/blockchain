<?php
namespace Drupal\regiotoken_wallet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\user\Entity\User;
use Drupal\Core\Access\AccessResult;

class FlutterApiController extends ControllerBase {
    
    /**
     * Authentifizierung für Flutter App
     */
    public function login(Request $request) {
        $username = $request->request->get('username');
        $password = $request->request->get('password');
        
        // Drupal User Authentication
        $uid = \Drupal::service('user.auth')->authenticate($username, $password);
        
        if ($uid) {
            $user = User::load($uid);
            $token = $this->generateApiToken($user);
            
            return new JsonResponse([
                'success' => true,
                'token' => $token,
                'user' => [
                    'id' => $user->id(),
                    'name' => $user->getAccountName(),
                    'email' => $user->getEmail(),
                ],
                'message' => 'Login erfolgreich',
            ]);
        }
        
        return new JsonResponse([
            'success' => false,
            'error' => 'Ungültige Anmeldedaten',
        ], 401);
    }
    
    /**
     * Guthaben für Flutter App
     */
    public function getBalance(Request $request) {
        $token = $request->headers->get('Authorization');
        $user = $this->validateToken($token);
        
        if (!$user) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
        }
        
        $walletService = \Drupal::service('regiotoken_wallet.wallet_service');
        $wallet = $walletService->getUserWallet($user->id());
        
        $blockchainService = \Drupal::service('regiotoken_wallet.blockchain_service');
        $gasPrice = $blockchainService->getGasPrice();
        
        return new JsonResponse([
            'success' => true,
            'balance' => (float) $wallet['balance'],
            'address' => $wallet['wallet_address'],
            'symbol' => 'REGIO',
            'gas_price' => $gasPrice,
            'timestamp' => time(),
        ]);
    }
    
    /**
     * Token senden von Flutter App
     */
    public function sendTokens(Request $request) {
        $token = $request->headers->get('Authorization');
        $user = $this->validateToken($token);
        
        if (!$user) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
        }
        
        $toAddress = $request->request->get('to_address');
        $amount = $request->request->get('amount');
        $memo = $request->request->get('memo', '');
        
        // Validierung
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $toAddress)) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid address'], 400);
        }
        
        if (!is_numeric($amount) || $amount <= 0) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid amount'], 400);
        }
        
        try {
            $transactionService = \Drupal::service('regiotoken_wallet.transaction_service');
            $walletService = \Drupal::service('regiotoken_wallet.wallet_service');
            
            $wallet = $walletService->getUserWallet($user->id());
            
            $result = $transactionService->createTransaction(
                $user->id(),
                $wallet['wallet_address'],
                $toAddress,
                $amount,
                $memo
            );
            
            if (!$result['success']) {
                throw new \Exception($result['error']);
            }
            
            return new JsonResponse([
                'success' => true,
                'tx_hash' => $result['tx_hash'],
                'explorer_url' => $result['explorer_url'],
                'message' => 'Transaction sent successfully',
                'timestamp' => time(),
            ]);
            
        } catch (\Exception $e) {
            \Drupal::logger('regiotoken_wallet')->error('Flutter send error: @error', [
                '@error' => $e->getMessage(),
            ]);
            
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Transaktionsverlauf für Flutter
     */
    public function getTransactions(Request $request) {
        $token = $request->headers->get('Authorization');
        $user = $this->validateToken($token);
        
        if (!$user) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
        }
        
        $limit = $request->query->get('limit', 20);
        $offset = $request->query->get('offset', 0);
        
        $connection = \Drupal::database();
        
        $query = $connection->select('regiotoken_transactions', 't')
            ->fields('t')
            ->condition('user_id', $user->id())
            ->orderBy('created', 'DESC')
            ->range($offset, $limit);
        
        $transactions = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
        
        // Formatieren für Flutter
        $formatted = [];
        foreach ($transactions as $tx) {
            $formatted[] = [
                'id' => $tx['id'],
                'tx_hash' => $tx['tx_hash'],
                'from' => $tx['from_address'],
                'to' => $tx['to_address'],
                'amount' => (float) $tx['amount'],
                'memo' => $tx['memo'],
                'status' => $tx['status'],
                'timestamp' => (int) $tx['created'],
                'confirmed' => $tx['confirmed'] ? (int) $tx['confirmed'] : null,
                'explorer_url' => \Drupal::config('regiotoken_wallet.settings')
                    ->get('explorer_url') . '/tx/' . $tx['tx_hash'],
            ];
        }
        
        return new JsonResponse([
            'success' => true,
            'transactions' => $formatted,
            'count' => count($formatted),
            'timestamp' => time(),
        ]);
    }
    
    /**
     * QR Code generieren für Adresse
     */
    public function generateQR($address) {
        // QR Code als PNG generieren
        $qrData = [
            'address' => $address,
            'network' => \Drupal::config('regiotoken_wallet.settings')->get('chain_id'),
            'symbol' => 'REGIO',
        ];
        
        $qrContent = json_encode($qrData);
        
        // QR Code mit PHP QR Code Library generieren
        // Hier müsstest du eine QR Code Library einbinden
        
        return new JsonResponse([
            'success' => true,
            'qr_data' => $qrContent,
            'address' => $address,
            'timestamp' => time(),
        ]);
    }
    
    /**
     * API Token generieren
     */
    private function generateApiToken(User $user) {
        $data = [
            'user_id' => $user->id(),
            'username' => $user->getAccountName(),
            'created' => time(),
            'expires' => time() + (30 * 24 * 60 * 60), // 30 Tage
        ];
        
        $token = base64_encode(json_encode($data));
        $signature = hash_hmac('sha256', $token, \Drupal::config('system.site')->get('salt'));
        
        return $token . '.' . $signature;
    }
    
    /**
     * API Token validieren
     */
    private function validateToken($authHeader) {
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }
        
        $token = substr($authHeader, 7);
        $parts = explode('.', $token);
        
        if (count($parts) !== 2) {
            return false;
        }
        
        $tokenData = $parts[0];
        $signature = $parts[1];
        
        $expectedSignature = hash_hmac('sha256', $tokenData, \Drupal::config('system.site')->get('salt'));
        
        if (!hash_equals($signature, $expectedSignature)) {
            return false;
        }
        
        $data = json_decode(base64_decode($tokenData), true);
        
        if (!$data || !isset($data['user_id']) || $data['expires'] < time()) {
            return false;
        }
        
        return User::load($data['user_id']);
    }
}
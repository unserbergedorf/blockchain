<?php
namespace Drupal\regiotoken_wallet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\user\Entity\User;
use Drupal\Core\Database\Database;

class WalletController extends ControllerBase {
    
    /**
     * Wallet Hauptseite
     */
    public function walletPage() {
        // Nur für angemeldete Benutzer
        if (!$this->currentUser()->isAuthenticated()) {
            return $this->redirect('user.login');
        }
        
        $userId = $this->currentUser()->id();
        $user = User::load($userId);
        
        // Wallet holen oder erstellen
        $wallet = $this->getOrCreateWallet($userId);
        
        // Transaktionen holen
        $transactions = $this->getUserTransactions($userId);
        
        // CSRF Token für Formulare
        $formToken = \Drupal::csrfToken()->get('wallet_transfer_form');
        
        // JavaScript Settings
        $settings = [
            'regiotoken' => [
                'walletAddress' => $wallet['wallet_address'],
                'symbol' => 'REGIO',
                'transferUrl' => '/rtw-transfer',
                'token' => $formToken,
                'userId' => $userId,
            ],
        ];
        
        return [
            '#theme' => 'regiotoken_wallet',
            '#balance' => $wallet['balance'],
            '#symbol' => 'REGIO',
            '#address' => $wallet['wallet_address'],
            '#transactions' => $transactions,
            '#form_token' => $formToken,
            '#attached' => [
                'library' => ['regiotoken_wallet/wallet'],
                'drupalSettings' => $settings,
            ],
            '#cache' => [
                'max-age' => 0, // Kein Caching für dynamische Daten
            ],
        ];
    }
    
    /**
     * Token Transfer Endpoint
     */
    public function transfer(Request $request) {
        // Nur AJAX Requests
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false, 'error' => 'Nur AJAX erlaubt'], 400);
        }
        
        $userId = $this->currentUser()->id();
        
        // CSRF Validierung
        $formToken = $request->request->get('form_token');
        if (!$formToken || !\Drupal::csrfToken()->validate($formToken, 'wallet_transfer_form')) {
            return new JsonResponse([
                'success' => false, 
                'error' => 'Sicherheitstoken ungültig. Seite neu laden.'
            ], 403);
        }
        
        $address = $request->request->get('address');
        $amount = $request->request->get('amount');
        $memo = $request->request->get('memo', '');
        
        // Validierung
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            return new JsonResponse([
                'success' => false, 
                'error' => 'Ungültige Adresse. Format: 0x gefolgt von 40 Zeichen.'
            ]);
        }
        
        if (!is_numeric($amount) || $amount <= 0) {
            return new JsonResponse([
                'success' => false, 
                'error' => 'Ungültiger Betrag. Bitte positive Zahl eingeben.'
            ]);
        }
        
        // Wallet holen
        $wallet = $this->getOrCreateWallet($userId);
        
        // Guthaben prüfen
        if ($amount > $wallet['balance']) {
            return new JsonResponse([
                'success' => false, 
                'error' => 'Betrag übersteigt verfügbares Guthaben.'
            ]);
        }
        
        // Rate Limiting prüfen
        if (!$this->checkRateLimit($userId)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Zu viele Transaktionen. Bitte warten Sie etwas.'
            ], 429);
        }
        
        try {
            // Transaktion in Datenbank speichern
            $transactionId = $this->createTransaction($userId, $wallet['wallet_address'], $address, $amount, $memo);
            
            // Guthaben aktualisieren
            $newBalance = $this->updateWalletBalance($userId, $wallet['balance'] - $amount);
            
            // Erfolgreiche Antwort
            return new JsonResponse([
                'success' => true,
                'message' => sprintf('✅ %s REGIO wurden erfolgreich gesendet.', 
                    number_format($amount, 2, ',', '.')
                ),
                'transactionId' => $transactionId,
                'transactionHash' => '0x' . bin2hex(random_bytes(32)),
                'from' => $wallet['wallet_address'],
                'to' => $address,
                'amount' => (float) $amount,
                'newBalance' => $newBalance,
                'symbol' => 'REGIO',
                'timestamp' => time(),
                'explorerUrl' => 'https://gnosis.blockscout.com',
            ]);
            
        } catch (\Exception $e) {
            \Drupal::logger('regiotoken_wallet')->error('Transfer error: @error', [
                '@error' => $e->getMessage(),
            ]);
            
            return new JsonResponse([
                'success' => false,
                'error' => 'Transaktionsfehler: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Hilfsfunktion: Wallet holen oder erstellen
     */
    private function getOrCreateWallet($userId) {
        $connection = Database::getConnection();
        
        // Prüfen ob Wallet existiert
        $wallet = $connection->select('regiotoken_wallets', 'w')
            ->fields('w')
            ->condition('user_id', $userId)
            ->execute()
            ->fetchAssoc();
        
        if ($wallet) {
            return $wallet;
        }
        
        // Neues Wallet erstellen
        $address = $this->generateAddress($userId);
        $balance = \Drupal::config('regiotoken_wallet.settings')->get('default_balance') ?: 1000.50;
        
        $connection->insert('regiotoken_wallets')
            ->fields([
                'user_id' => $userId,
                'wallet_address' => $address,
                'balance' => $balance,
                'created' => time(),
            ])
            ->execute();
        
        return [
            'user_id' => $userId,
            'wallet_address' => $address,
            'balance' => $balance,
            'created' => time(),
        ];
    }
    
    /**
     * Hilfsfunktion: Transaktion erstellen
     */
    private function createTransaction($userId, $from, $to, $amount, $memo) {
        $connection = Database::getConnection();
        
        $txHash = '0x' . bin2hex(random_bytes(32));
        
        return $connection->insert('regiotoken_transactions')
            ->fields([
                'user_id' => $userId,
                'tx_hash' => $txHash,
                'from_address' => $from,
                'to_address' => $to,
                'amount' => $amount,
                'memo' => $memo,
                'token_symbol' => 'REGIO',
                'status' => 'confirmed',
                'created' => time(),
                'confirmed' => time(),
                'block_number' => rand(1000000, 2000000),
            ])
            ->execute();
    }
    
    /**
     * Hilfsfunktion: Transaktionen holen
     */
    private function getUserTransactions($userId, $limit = 10) {
        $connection = Database::getConnection();
        
        $query = $connection->select('regiotoken_transactions', 't')
            ->fields('t')
            ->condition('user_id', $userId)
            ->orderBy('created', 'DESC')
            ->range(0, $limit);
        
        $transactions = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
        
        // Formatieren für Template
        $formatted = [];
        foreach ($transactions as $tx) {
            $formatted[] = [
                'id' => $tx['id'],
                'timestamp' => date('Y-m-d H:i:s', $tx['created']),
                'from' => $tx['from_address'],
                'to' => $tx['to_address'],
                'amount' => (float) $tx['amount'],
                'memo' => $tx['memo'],
                'status' => $tx['status'],
                'transactionHash' => $tx['tx_hash'],
            ];
        }
        
        return $formatted;
    }
    
    /**
     * Hilfsfunktion: Wallet Balance aktualisieren
     */
    private function updateWalletBalance($userId, $newBalance) {
        $connection = Database::getConnection();
        
        $connection->update('regiotoken_wallets')
            ->fields([
                'balance' => $newBalance,
                'last_sync' => time(),
            ])
            ->condition('user_id', $userId)
            ->execute();
        
        return $newBalance;
    }
    
    /**
     * Hilfsfunktion: Rate Limiting prüfen
     */
    private function checkRateLimit($userId) {
        $connection = Database::getConnection();
        
        // Anzahl der Transaktionen in den letzten Stunde
        $oneHourAgo = time() - 3600;
        
        $count = $connection->select('regiotoken_transactions', 't')
            ->condition('user_id', $userId)
            ->condition('created', $oneHourAgo, '>=')
            ->countQuery()
            ->execute()
            ->fetchField();
        
        // Maximal 10 Transaktionen pro Stunde
        return $count < 10;
    }
    
    /**
     * Hilfsfunktion: Adresse generieren
     */
    private function generateAddress($userId) {
        $user = User::load($userId);
        $seed = 'regiotoken_' . $userId . '_' . $user->getCreatedTime();
        $hash = hash('sha256', $seed);
        return '0x' . substr($hash, 0, 40);
    }
}
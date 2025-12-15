<?php
namespace Drupal\regiotoken_wallet\Service;

use Drupal\Core\Database\Database;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\Entity\User;

class WalletService {
    
    private $database;
    private $blockchainService;
    private $currentUser;
    
    public function __construct(
        $database,
        BlockchainService $blockchain_service,
        AccountProxyInterface $current_user
    ) {
        $this->database = $database;
        $this->blockchainService = $blockchain_service;
        $this->currentUser = $current_user;
    }
    
    /**
     * Holt oder erstellt Wallet für Benutzer
     */
    public function getUserWallet($userId = null, $createIfNotExists = true) {
        if (!$userId) {
            $userId = $this->currentUser->id();
        }
        
        $connection = $this->database->getConnection();
        
        $wallet = $connection->select('regiotoken_wallets', 'w')
            ->fields('w')
            ->condition('user_id', $userId)
            ->execute()
            ->fetchAssoc();
        
        if ($wallet) {
            // Echte Balance von Blockchain abrufen
            $realBalance = $this->blockchainService->getBalance($wallet['wallet_address']);
            
            // Nur aktualisieren wenn unterschiedlich
            if ($realBalance != $wallet['balance']) {
                $connection->update('regiotoken_wallets')
                    ->fields([
                        'balance' => $realBalance,
                        'last_sync' => time(),
                    ])
                    ->condition('user_id', $userId)
                    ->execute();
                
                $wallet['balance'] = $realBalance;
            }
            
            return $wallet;
        }
        
        if ($createIfNotExists) {
            return $this->createUserWallet($userId);
        }
        
        return null;
    }
    
    /**
     * Erstellt neue Wallet für Benutzer
     */
    public function createUserWallet($userId) {
        $connection = $this->database->getConnection();
        
        try {
            // Neue Wallet Adresse generieren
            $address = $this->generateAddress($userId);
            
            // In Datenbank speichern
            $connection->insert('regiotoken_wallets')
                ->fields([
                    'user_id' => $userId,
                    'wallet_address' => $address,
                    'balance' => 1000.50, // Startguthaben
                    'created' => time(),
                ])
                ->execute();
            
            return [
                'user_id' => $userId,
                'wallet_address' => $address,
                'balance' => 1000.50,
                'created' => time(),
            ];
            
        } catch (\Exception $e) {
            \Drupal::logger('regiotoken_wallet')->error('Wallet creation failed: @error', [
                '@error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Generiert Ethereum-ähnliche Adresse
     */
    private function generateAddress($userId) {
        $user = User::load($userId);
        if (!$user) {
            throw new \Exception('User not found');
        }
        
        // Sichere Hash-basierte Adresse generieren
        $seed = 'regiotoken_' . $userId . '_' . $user->getCreatedTime();
        $hash = hash('sha256', $seed);
        
        // Format: 0x + 40 Zeichen
        return '0x' . substr($hash, 0, 40);
    }
    
    /**
     * Aktualisiert Wallet Balance
     */
    public function updateBalance($userId, $newBalance) {
        $connection = $this->database->getConnection();
        
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
     * Gibt alle Wallets zurück (Admin-Funktion)
     */
    public function getAllWallets($limit = 100) {
        $connection = $this->database->getConnection();
        
        $query = $connection->select('regiotoken_wallets', 'w')
            ->fields('w')
            ->orderBy('balance', 'DESC')
            ->range(0, $limit);
        
        return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Findet Wallet by Adresse
     */
    public function findWalletByAddress($address) {
        $connection = $this->database->getConnection();
        
        return $connection->select('regiotoken_wallets', 'w')
            ->fields('w')
            ->condition('wallet_address', $address)
            ->execute()
            ->fetchAssoc();
    }
    
    /**
     * Findet Wallet by User ID
     */
    public function findWalletByUserId($userId) {
        $connection = $this->database->getConnection();
        
        return $connection->select('regiotoken_wallets', 'w')
            ->fields('w')
            ->condition('user_id', $userId)
            ->execute()
            ->fetchAssoc();
    }
}
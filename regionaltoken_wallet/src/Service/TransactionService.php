<?php
namespace Drupal\regiotoken_wallet\Service;

use Drupal\Core\Database\Database;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

class TransactionService {
    
    private $database;
    private $blockchainService;
    private $logger;
    
    public function __construct(
        $database,
        BlockchainService $blockchain_service,
        LoggerChannelFactoryInterface $logger_factory
    ) {
        $this->database = $database;
        $this->blockchainService = $blockchain_service;
        $this->logger = $logger_factory->get('regiotoken_wallet');
    }
    
    /**
     * Erstellt eine neue Transaktion
     */
    public function createTransaction($userId, $fromAddress, $toAddress, $amount, $memo = '') {
        $connection = $this->database->getConnection();
        
        try {
            // Demo: Simulierte Blockchain-Transaktion
            $result = $this->blockchainService->sendTokens($fromAddress, $toAddress, $amount);
            
            if (!$result['success']) {
                throw new \Exception($result['error']);
            }
            
            // In Datenbank speichern
            $txId = $connection->insert('regiotoken_transactions')
                ->fields([
                    'user_id' => $userId,
                    'tx_hash' => $result['tx_hash'],
                    'from_address' => $fromAddress,
                    'to_address' => $toAddress,
                    'amount' => $amount,
                    'memo' => $memo,
                    'token_symbol' => 'REGIO',
                    'status' => 'confirmed', // Demo: direkt confirmed
                    'created' => time(),
                    'confirmed' => time(),
                    'block_number' => rand(1000000, 2000000),
                ])
                ->execute();
            
            // Guthaben aktualisieren
            $this->updateUserBalance($userId, -$amount);
            
            $this->logger->info('Transaction created: @txId for user @userId', [
                '@txId' => $txId,
                '@userId' => $userId,
            ]);
            
            return [
                'success' => true,
                'transaction_id' => $txId,
                'tx_hash' => $result['tx_hash'],
                'explorer_url' => $result['explorer_url'],
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Transaction creation failed: @error', ['@error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Aktualisiert Benutzer-Guthaben
     */
    private function updateUserBalance($userId, $amountChange) {
        $connection = $this->database->getConnection();
        
        // Aktuelles Guthaben holen
        $currentBalance = $connection->select('regiotoken_wallets', 'w')
            ->fields('w', ['balance'])
            ->condition('user_id', $userId)
            ->execute()
            ->fetchField();
        
        $newBalance = $currentBalance + $amountChange;
        
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
     * Holt Transaktionen für Benutzer
     */
    public function getUserTransactions($userId, $limit = 10) {
        $connection = $this->database->getConnection();
        
        $query = $connection->select('regiotoken_transactions', 't')
            ->fields('t')
            ->condition('user_id', $userId)
            ->orderBy('created', 'DESC')
            ->range(0, $limit);
        
        return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
}
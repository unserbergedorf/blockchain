<?php
namespace Drupal\regiotoken_wallet\Service;

use Drupal\Core\Database\Database;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

class FlutterIntegrationService {
    
    private $database;
    private $logger;
    private $blockchainService;
    
    public function __construct(
        $database,
        BlockchainService $blockchain_service,
        LoggerChannelFactoryInterface $logger_factory
    ) {
        $this->database = $database;
        $this->blockchainService = $blockchain_service;
        $this->logger = $logger_factory->get('regiotoken_wallet_flutter');
    }
    
    /**
     * Push-Benachrichtigungen für Flutter
     */
    public function sendPushNotification($userId, $title, $message, $data = []) {
        $connection = $this->database->getConnection();
        
        // Device Tokens für Benutzer abrufen
        $deviceTokens = $connection->select('regiotoken_flutter_devices', 'd')
            ->fields('d', ['device_token', 'platform'])
            ->condition('user_id', $userId)
            ->execute()
            ->fetchAll();
        
        $results = [];
        foreach ($deviceTokens as $device) {
            $result = $this->sendToDevice($device->device_token, $device->platform, $title, $message, $data);
            $results[] = $result;
        }
        
        return $results;
    }
    
    /**
     * Speichert Flutter Device Token
     */
    public function registerDevice($userId, $deviceToken, $platform) {
        $connection = $this->database->getConnection();
        
        // Prüfen ob bereits registriert
        $exists = $connection->select('regiotoken_flutter_devices', 'd')
            ->condition('user_id', $userId)
            ->condition('device_token', $deviceToken)
            ->countQuery()
            ->execute()
            ->fetchField();
        
        if (!$exists) {
            $connection->insert('regiotoken_flutter_devices')
                ->fields([
                    'user_id' => $userId,
                    'device_token' => $deviceToken,
                    'platform' => $platform,
                    'registered' => time(),
                    'last_active' => time(),
                ])
                ->execute();
            
            $this->logger->info('Device registered: @token for user @userId', [
                '@token' => substr($deviceToken, 0, 20) . '...',
                '@userId' => $userId,
            ]);
            
            return true;
        }
        
        // Update last active
        $connection->update('regiotoken_flutter_devices')
            ->fields(['last_active' => time()])
            ->condition('user_id', $userId)
            ->condition('device_token', $deviceToken)
            ->execute();
        
        return true;
    }
    
    /**
     * Sendet Transaktions-Update an Flutter App
     */
    public function notifyTransactionUpdate($userId, $transaction) {
        $title = 'Transaktions-Update';
        
        switch ($transaction['status']) {
            case 'confirmed':
                $message = sprintf('✅ %s REGIO empfangen', $transaction['amount']);
                break;
            case 'pending':
                $message = sprintf('⏳ Transaktion wird verarbeitet: %s REGIO', $transaction['amount']);
                break;
            case 'failed':
                $message = sprintf('❌ Transaktion fehlgeschlagen: %s REGIO', $transaction['amount']);
                break;
            default:
                return;
        }
        
        $data = [
            'type' => 'transaction_update',
            'transaction_id' => $transaction['id'],
            'tx_hash' => $transaction['tx_hash'],
            'status' => $transaction['status'],
            'amount' => $transaction['amount'],
            'explorer_url' => $transaction['explorer_url'],
        ];
        
        return $this->sendPushNotification($userId, $title, $message, $data);
    }
    
    /**
     * Sendet Benachrichtigung an spezifisches Gerät
     */
    private function sendToDevice($deviceToken, $platform, $title, $message, $data) {
        $config = \Drupal::config('regiotoken_wallet.settings');
        
        if ($platform === 'ios') {
            return $this->sendApplePush($deviceToken, $title, $message, $data);
        } elseif ($platform === 'android') {
            return $this->sendFirebasePush($deviceToken, $title, $message, $data);
        }
        
        return false;
    }
    
    private function sendFirebasePush($deviceToken, $title, $message, $data) {
        $apiKey = \Drupal::config('regiotoken_wallet.settings')->get('firebase_api_key');
        
        if (!$apiKey) {
            $this->logger->warning('Firebase API Key nicht konfiguriert');
            return false;
        }
        
        $payload = [
            'to' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body' => $message,
                'sound' => 'default',
            ],
            'data' => $data,
            'priority' => 'high',
        ];
        
        $ch = curl_init('https://fcm.googleapis.com/fcm/send');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: key=' . $apiKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        curl_close($ch);
        
        $success = ($httpCode === 200);
        
        if (!$success) {
            $this->logger->error('FCM push failed: @response', ['@response' => $response]);
        }
        
        return $success;
    }
    
    private function sendApplePush($deviceToken, $title, $message, $data) {
        // APNs Implementation würde hier hin
        $this->logger->info('APNs push not implemented yet');
        return false;
    }
}
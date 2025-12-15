<?php
namespace Drupal\regiotoken_wallet\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Database\Database;
use Drupal\user\Entity\User;

class AdminController extends ControllerBase {
    
    /**
     * Admin Dashboard Hauptseite
     */
    public function dashboard() {
        // Zugriffskontrolle - nur Admins
        if (!$this->currentUser()->hasPermission('administer site configuration')) {
            $this->messenger()->addError($this->t('Zugriff verweigert. Admin-Rechte erforderlich.'));
            return $this->redirect('regiotoken_wallet.wallet');
        }
        
        $connection = Database::getConnection();
        
        // Statistiken berechnen
        $stats = $this->getDashboardStats();
        
        // Letzte Transaktionen
        $transactions = $this->getRecentTransactions(20);
        
        // Top User nach Guthaben
        $topUsers = $this->getTopUsersByBalance(10);
        
        // System-Informationen
        $systemInfo = $this->getSystemInfo();
        
        return [
            '#theme' => 'admin_dashboard',
            '#stats' => $stats,
            '#transactions' => $transactions,
            '#top_users' => $topUsers,
            '#system_info' => $systemInfo,
            '#attached' => [
                'library' => ['regiotoken_wallet/admin'],
            ],
            '#cache' => [
                'max-age' => 60, // 1 Minute Cache
            ],
        ];
    }
    
    /**
     * Transaktions-Details Seite
     */
    public function transactionDetail($id) {
        if (!$this->currentUser()->hasPermission('administer site configuration')) {
            $this->messenger()->addError($this->t('Zugriff verweigert.'));
            return $this->redirect('regiotoken_wallet.admin');
        }
        
        $connection = Database::getConnection();
        
        $transaction = $connection->select('regiotoken_transactions', 't')
            ->fields('t')
            ->condition('id', $id)
            ->execute()
            ->fetchAssoc();
        
        if (!$transaction) {
            $this->messenger()->addError($this->t('Transaktion nicht gefunden.'));
            return $this->redirect('regiotoken_wallet.admin');
        }
        
        // Benutzer-Informationen
        $user = User::load($transaction['user_id']);
        $transaction['username'] = $user ? $user->getAccountName() : 'Unbekannt';
        $transaction['user_email'] = $user ? $user->getEmail() : '';
        
        return [
            '#theme' => 'transaction_detail',
            '#transaction' => $transaction,
            '#attached' => [
                'library' => ['regiotoken_wallet/admin'],
            ],
        ];
    }
    
    /**
     * API: Transaktions-Status aktualisieren
     */
    public function updateTransactionStatus(Request $request, $id) {
        if (!$this->currentUser()->hasPermission('administer site configuration')) {
            return new JsonResponse(['success' => false, 'error' => 'Zugriff verweigert'], 403);
        }
        
        $status = $request->request->get('status');
        $validStatuses = ['pending', 'confirmed', 'failed'];
        
        if (!in_array($status, $validStatuses)) {
            return new JsonResponse(['success' => false, 'error' => 'Ungültiger Status'], 400);
        }
        
        $connection = Database::getConnection();
        
        $fields = ['status' => $status];
        if ($status === 'confirmed') {
            $fields['confirmed'] = time();
            $fields['block_number'] = $request->request->get('block_number', 0);
        }
        
        $connection->update('regiotoken_transactions')
            ->fields($fields)
            ->condition('id', $id)
            ->execute();
        
        // Log-Eintrag
        \Drupal::logger('regiotoken_wallet_admin')->info('Transaction @id status updated to @status by @user', [
            '@id' => $id,
            '@status' => $status,
            '@user' => $this->currentUser()->getAccountName(),
        ]);
        
        return new JsonResponse([
            'success' => true, 
            'message' => 'Status erfolgreich aktualisiert',
            'transaction_id' => $id,
            'new_status' => $status,
        ]);
    }
    
    /**
     * API: Guthaben manuell anpassen
     */
    public function adjustUserBalance(Request $request) {
        if (!$this->currentUser()->hasPermission('administer site configuration')) {
            return new JsonResponse(['success' => false, 'error' => 'Zugriff verweigert'], 403);
        }
        
        $userId = $request->request->get('user_id');
        $amount = $request->request->get('amount');
        $reason = $request->request->get('reason', '');
        
        if (!is_numeric($userId) || !is_numeric($amount)) {
            return new JsonResponse(['success' => false, 'error' => 'Ungültige Parameter'], 400);
        }
        
        $connection = Database::getConnection();
        
        // Aktuelles Guthaben holen
        $currentBalance = $connection->select('regiotoken_wallets', 'w')
            ->fields('w', ['balance', 'wallet_address'])
            ->condition('user_id', $userId)
            ->execute()
            ->fetchAssoc();
        
        if (!$currentBalance) {
            return new JsonResponse(['success' => false, 'error' => 'Wallet nicht gefunden'], 404);
        }
        
        // Neues Guthaben berechnen
        $newBalance = $currentBalance['balance'] + $amount;
        
        if ($newBalance < 0) {
            return new JsonResponse(['success' => false, 'error' => 'Guthaben kann nicht negativ sein'], 400);
        }
        
        // Guthaben aktualisieren
        $connection->update('regiotoken_wallets')
            ->fields(['balance' => $newBalance, 'last_sync' => time()])
            ->condition('user_id', $userId)
            ->execute();
        
        // Admin-Transaktion erstellen
        $txHash = '0xADMIN' . bin2hex(random_bytes(29));
        $connection->insert('regiotoken_transactions')
            ->fields([
                'user_id' => $userId,
                'tx_hash' => $txHash,
                'from_address' => $amount >= 0 ? '0xADMIN_MINT' : $currentBalance['wallet_address'],
                'to_address' => $amount >= 0 ? $currentBalance['wallet_address'] : '0xADMIN_BURN',
                'amount' => abs($amount),
                'memo' => $reason ?: ($amount >= 0 ? 'Admin: Guthaben hinzugefügt' : 'Admin: Guthaben abgezogen'),
                'token_symbol' => 'REGIO',
                'status' => 'confirmed',
                'created' => time(),
                'confirmed' => time(),
                'block_number' => 0,
            ])
            ->execute();
        
        \Drupal::logger('regiotoken_wallet_admin')->info('Balance adjusted for user @userId: @amount REGIO', [
            '@userId' => $userId,
            '@amount' => $amount,
        ]);
        
        return new JsonResponse([
            'success' => true,
            'message' => sprintf('Guthaben aktualisiert: %+.2f REGIO', $amount),
            'user_id' => $userId,
            'old_balance' => $currentBalance['balance'],
            'new_balance' => $newBalance,
            'change' => $amount,
            'transaction_hash' => $txHash,
        ]);
    }
    
    /**
     * API: Export Transaktionen als CSV
     */
    public function exportTransactions(Request $request) {
        if (!$this->currentUser()->hasPermission('administer site configuration')) {
            return new JsonResponse(['success' => false, 'error' => 'Zugriff verweigert'], 403);
        }
        
        $startDate = $request->query->get('start', strtotime('-30 days'));
        $endDate = $request->query->get('end', time());
        $format = $request->query->get('format', 'csv');
        
        $connection = Database::getConnection();
        
        $query = $connection->select('regiotoken_transactions', 't');
        $query->join('users_field_data', 'u', 't.user_id = u.uid');
        $query->fields('t');
        $query->addField('u', 'name', 'username');
        $query->addField('u', 'mail', 'user_email');
        $query->condition('t.created', $startDate, '>=');
        $query->condition('t.created', $endDate, '<=');
        $query->orderBy('t.created', 'DESC');
        
        $transactions = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
        
        if ($format === 'csv') {
            return $this->exportAsCSV($transactions);
        }
        
        return new JsonResponse([
            'success' => true,
            'transactions' => $transactions,
            'count' => count($transactions),
            'period' => [
                'start' => date('Y-m-d H:i:s', $startDate),
                'end' => date('Y-m-d H:i:s', $endDate),
            ],
        ]);
    }
    
    /**
     * API: System-Informationen
     */
    public function getSystemInfoApi() {
        if (!$this->currentUser()->hasPermission('administer site configuration')) {
            return new JsonResponse(['success' => false, 'error' => 'Zugriff verweigert'], 403);
        }
        
        $info = $this->getSystemInfo();
        
        return new JsonResponse([
            'success' => true,
            'system_info' => $info,
            'timestamp' => time(),
        ]);
    }
    
    // ==================== HELPER FUNCTIONS ====================
    
    private function getDashboardStats() {
        $connection = Database::getConnection();
        
        // Total Users mit Wallet
        $totalUsers = $connection->select('regiotoken_wallets', 'w')
            ->countQuery()
            ->execute()
            ->fetchField();
        
        // Total Transaktionen
        $totalTransactions = $connection->select('regiotoken_transactions', 't')
            ->countQuery()
            ->execute()
            ->fetchField();
        
        // Total Volume (nur confirmed)
        $totalVolume = $connection->select('regiotoken_transactions', 't')
            ->condition('status', 'confirmed')
            ->fields('t', ['amount'])
            ->execute()
            ->fetchCol();
        $totalVolume = array_sum($totalVolume);
        
        // Pending Transaktionen
        $pendingTransactions = $connection->select('regiotoken_transactions', 't')
            ->condition('status', 'pending')
            ->countQuery()
            ->execute()
            ->fetchField();
        
        // Total Balance aller User
        $totalBalance = $connection->select('regiotoken_wallets', 'w')
            ->fields('w', ['balance'])
            ->execute()
            ->fetchCol();
        $totalBalance = array_sum($totalBalance);
        
        // Heutige Transaktionen
        $todayStart = strtotime('today');
        $todayTransactions = $connection->select('regiotoken_transactions', 't')
            ->condition('created', $todayStart, '>=')
            ->countQuery()
            ->execute()
            ->fetchField();
        
        // Heutiges Volume
        $todayVolume = $connection->select('regiotoken_transactions', 't')
            ->condition('created', $todayStart, '>=')
            ->condition('status', 'confirmed')
            ->fields('t', ['amount'])
            ->execute()
            ->fetchCol();
        $todayVolume = array_sum($todayVolume);
        
        return [
            'total_users' => (int) $totalUsers,
            'total_transactions' => (int) $totalTransactions,
            'total_volume' => (float) $totalVolume,
            'pending_transactions' => (int) $pendingTransactions,
            'total_balance' => (float) $totalBalance,
            'today_transactions' => (int) $todayTransactions,
            'today_volume' => (float) $todayVolume,
            'avg_transaction' => $totalTransactions > 0 ? $totalVolume / $totalTransactions : 0,
        ];
    }
    
    private function getRecentTransactions($limit = 20) {
        $connection = Database::getConnection();
        
        $query = $connection->select('regiotoken_transactions', 't');
        $query->join('users_field_data', 'u', 't.user_id = u.uid');
        $query->fields('t');
        $query->addField('u', 'name', 'username');
        $query->orderBy('t.created', 'DESC');
        $query->range(0, $limit);
        
        $transactions = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
        
        // Formatieren für Template
        foreach ($transactions as &$tx) {
            $tx['created_formatted'] = date('d.m.Y H:i', $tx['created']);
            $tx['confirmed_formatted'] = $tx['confirmed'] ? date('d.m.Y H:i', $tx['confirmed']) : '-';
            $tx['amount_formatted'] = number_format($tx['amount'], 2, ',', '.');
        }
        
        return $transactions;
    }
    
    private function getTopUsersByBalance($limit = 10) {
        $connection = Database::getConnection();
        
        $query = $connection->select('regiotoken_wallets', 'w');
        $query->join('users_field_data', 'u', 'w.user_id = u.uid');
        $query->fields('w', ['balance', 'wallet_address']);
        $query->addField('u', 'name', 'username');
        $query->addField('u', 'mail', 'email');
        $query->orderBy('w.balance', 'DESC');
        $query->range(0, $limit);
        
        $users = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
        
        // Formatieren für Template
        foreach ($users as &$user) {
            $user['balance_formatted'] = number_format($user['balance'], 2, ',', '.');
            $user['address_short'] = substr($user['wallet_address'], 0, 8) . '...' . substr($user['wallet_address'], -6);
        }
        
        return $users;
    }
    
    private function getSystemInfo() {
        $connection = Database::getConnection();
        
        // Datenbank Größe
        $dbSize = $connection->query("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE()
            AND table_name LIKE 'regiotoken_%'
        ")->fetchField();
        
        // Letzte Transaktion
        $lastTransaction = $connection->select('regiotoken_transactions', 't')
            ->fields('t', ['created'])
            ->orderBy('created', 'DESC')
            ->range(0, 1)
            ->execute()
            ->fetchField();
        
        // Module Version
        $moduleInfo = \Drupal::service('extension.list.module')->getExtensionInfo('regiotoken_wallet');
        
        return [
            'database_size' => $dbSize ?: '0.00',
            'last_transaction' => $lastTransaction ? date('d.m.Y H:i', $lastTransaction) : 'Keine',
            'module_version' => $moduleInfo['version'] ?? '1.0.0',
            'php_version' => PHP_VERSION,
            'drupal_version' => \Drupal::VERSION,
            'server_time' => date('d.m.Y H:i:s'),
            'uptime' => $this->getSystemUptime(),
        ];
    }
    
    private function getSystemUptime() {
        // Einfache Uptime-Berechnung basierend auf erster Transaktion
        $connection = Database::getConnection();
        
        $firstTransaction = $connection->select('regiotoken_transactions', 't')
            ->fields('t', ['created'])
            ->orderBy('created', 'ASC')
            ->range(0, 1)
            ->execute()
            ->fetchField();
        
        if (!$firstTransaction) {
            return 'Neu installiert';
        }
        
        $uptimeSeconds = time() - $firstTransaction;
        
        $days = floor($uptimeSeconds / 86400);
        $hours = floor(($uptimeSeconds % 86400) / 3600);
        $minutes = floor(($uptimeSeconds % 3600) / 60);
        
        return sprintf('%d Tage, %d Stunden, %d Minuten', $days, $hours, $minutes);
    }
    
    private function exportAsCSV($data) {
        if (empty($data)) {
            return new JsonResponse(['success' => false, 'error' => 'Keine Daten zum Export'], 400);
        }
        
        // CSV Header
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="regiotoken-transactions-' . date('Y-m-d-H-i') . '.csv"',
        ];
        
        // Output Buffer für CSV
        $output = fopen('php://output', 'w');
        
        // Header Row
        $callback = function() use ($data, $output) {
            // CSV Header
            fputcsv($output, [
                'ID', 'User ID', 'Username', 'Email', 'Transaktion Hash',
                'Von', 'An', 'Betrag', 'Symbol', 'Nachricht',
                'Status', 'Erstellt', 'Bestätigt', 'Block'
            ], ';');
            
            // Daten
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['id'],
                    $row['user_id'],
                    $row['username'],
                    $row['user_email'],
                    $row['tx_hash'],
                    $row['from_address'],
                    $row['to_address'],
                    number_format($row['amount'], 6, '.', ''),
                    $row['token_symbol'],
                    $row['memo'],
                    $row['status'],
                    date('Y-m-d H:i:s', $row['created']),
                    $row['confirmed'] ? date('Y-m-d H:i:s', $row['confirmed']) : '',
                    $row['block_number'] ?: ''
                ], ';');
            }
            
            fclose($output);
        };
        
        return new \Symfony\Component\HttpFoundation\StreamedResponse($callback, 200, $headers);
    }
}
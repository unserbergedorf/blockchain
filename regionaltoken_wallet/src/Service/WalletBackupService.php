<?php
namespace Drupal\regiotoken_wallet\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Database\Database;
use Exception;

class BlockchainService {
    
    private $config;
    private $logger;
    private $rpcUrl;
    private $contractAddress;
    private $chainId;
    private $explorerUrl;
    private $privateKey; // NIE im Code speichern! Use Vault/Environment
    
    public function __construct(
        ConfigFactoryInterface $config_factory,
        LoggerChannelFactoryInterface $logger_factory
    ) {
        $this->config = $config_factory->get('regiotoken_wallet.settings');
        $this->logger = $logger_factory->get('regiotoken_wallet');
        
        // Konfiguration laden
        $this->rpcUrl = $this->config->get('rpc_url') ?: 'https://rpc.gnosischain.com';
        $this->contractAddress = $this->config->get('contract_address');
        $this->chainId = (int) $this->config->get('chain_id') ?: 100;
        $this->explorerUrl = $this->config->get('explorer_url') ?: 'https://gnosis.blockscout.com';
        
        // Private Key aus Umgebungsvariable oder Vault
        $this->privateKey = getenv('REGIO_TOKEN_PRIVATE_KEY') ?: '';
    }
    
    /**
     * Sendet Tokens an eine Adresse
     */
    public function sendTokens($fromAddress, $toAddress, $amount, $privateKey = null) {
        try {
            // Validierung
            if (!$this->validateAddress($fromAddress) || !$this->validateAddress($toAddress)) {
                throw new Exception('Ungültige Ethereum-Adresse');
            }
            
            if ($amount <= 0) {
                throw new Exception('Ungültiger Betrag');
            }
            
            // RPC-Call: eth_sendTransaction oder Contract Call
            $txData = $this->buildTransactionData($fromAddress, $toAddress, $amount);
            
            // Signiere Transaktion (wenn private key vorhanden)
            $signedTx = $this->signTransaction($txData, $privateKey ?: $this->privateKey);
            
            // Transaktion an Blockchain senden
            $txHash = $this->sendRawTransaction($signedTx);
            
            $this->logger->info('Transaction sent: @txHash', ['@txHash' => $txHash]);
            
            return [
                'success' => true,
                'tx_hash' => $txHash,
                'explorer_url' => $this->explorerUrl . '/tx/' . $txHash,
                'message' => 'Transaktion wurde an die Blockchain gesendet',
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Blockchain transfer error: @error', ['@error' => $e->getMessage()]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Ruft Guthaben einer Adresse ab
     */
    public function getBalance($address) {
        try {
            // ERC-20 balanceOf Funktion
            $data = '0x70a08231' . str_pad(substr($address, 2), 64, '0', STR_PAD_LEFT);
            
            $params = [
                'to' => $this->contractAddress,
                'data' => $data,
            ];
            
            $response = $this->rpcCall('eth_call', [$params, 'latest']);
            
            if (isset($response['result'])) {
                // Hex zu Decimal konvertieren (18 decimals für die meisten Tokens)
                $balanceHex = $response['result'];
                $balance = $this->hexToDecimal($balanceHex, 18);
                
                return $balance;
            }
            
            throw new Exception('Balance konnte nicht abgerufen werden');
            
        } catch (Exception $e) {
            $this->logger->error('Balance check error: @error', ['@error' => $e->getMessage()]);
            return 0;
        }
    }
    
    /**
     * Prüft Transaktionsstatus
     */
    public function getTransactionStatus($txHash) {
        try {
            $response = $this->rpcCall('eth_getTransactionReceipt', [$txHash]);
            
            if (isset($response['result']) && $response['result']) {
                $receipt = $response['result'];
                
                return [
                    'status' => hexdec($receipt['status']) == 1 ? 'confirmed' : 'failed',
                    'blockNumber' => hexdec($receipt['blockNumber']),
                    'gasUsed' => hexdec($receipt['gasUsed']),
                    'confirmations' => $this->getConfirmations($receipt['blockNumber']),
                ];
            }
            
            return ['status' => 'pending'];
            
        } catch (Exception $e) {
            $this->logger->error('Transaction status error: @error', ['@error' => $e->getMessage()]);
            return ['status' => 'unknown'];
        }
    }
    
    /**
     * Gibt Gas-Preis zurück
     */
    public function getGasPrice() {
        try {
            $response = $this->rpcCall('eth_gasPrice', []);
            
            if (isset($response['result'])) {
                // Gnosis Chain Gas ist günstig, in Gwei umrechnen
                $gasPriceWei = hexdec($response['result']);
                $gasPriceGwei = $gasPriceWei / 1000000000;
                
                return [
                    'wei' => $gasPriceWei,
                    'gwei' => $gasPriceGwei,
                    'human' => number_format($gasPriceGwei, 2, ',', '.') . ' Gwei',
                ];
            }
            
            return ['gwei' => 1, 'human' => '1 Gwei'];
            
        } catch (Exception $e) {
            $this->logger->error('Gas price error: @error', ['@error' => $e->getMessage()]);
            return ['gwei' => 1, 'human' => '1 Gwei'];
        }
    }
    
    /**
     * Erstellt eine neue Wallet
     */
    public function createWallet() {
        try {
            // Erstelle neuen Private Key (nur für Demo - in Produktion anders)
            $privateKey = bin2hex(random_bytes(32));
            $address = $this->privateKeyToAddress($privateKey);
            
            // Speichere verschlüsselt
            $encryptedKey = $this->encryptPrivateKey($privateKey);
            
            return [
                'address' => $address,
                'private_key_encrypted' => $encryptedKey,
                'private_key' => $privateKey, // NUR für Demo, nicht in Produktion!
                'mnemonic' => $this->generateMnemonic(), // Optional: BIP39 Mnemonic
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Wallet creation error: @error', ['@error' => $e->getMessage()]);
            throw $e;
        }
    }
    
    /**
     * Validiert Ethereum-Adresse
     */
    public function validateAddress($address) {
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            return false;
        }
        
        // Checksum Validation (EIP-55)
        return $this->isChecksumAddress($address);
    }
    
    // ==================== PRIVATE METHODS ====================
    
    private function rpcCall($method, $params = []) {
        $data = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => time(),
        ];
        
        $ch = curl_init($this->rpcUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            throw new Exception('RPC Connection error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception('RPC returned HTTP ' . $httpCode);
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['error'])) {
            throw new Exception('RPC Error: ' . $result['error']['message']);
        }
        
        return $result;
    }
    
    private function buildTransactionData($from, $to, $amount) {
        // ERC-20 transfer Funktion
        // function transfer(address to, uint256 amount)
        $functionSignature = '0xa9059cbb';
        
        // to address (padded to 32 bytes)
        $toPadded = str_pad(substr($to, 2), 64, '0', STR_PAD_LEFT);
        
        // amount in wei (18 decimals)
        $amountWei = bcmul($amount, bcpow('10', '18'), 0);
        $amountHex = $this->decimalToHex($amountWei);
        $amountPadded = str_pad(substr($amountHex, 2), 64, '0', STR_PAD_LEFT);
        
        $data = $functionSignature . $toPadded . $amountPadded;
        
        return [
            'from' => $from,
            'to' => $this->contractAddress,
            'value' => '0x0',
            'data' => $data,
            'chainId' => '0x' . dechex($this->chainId),
        ];
    }
    
    private function signTransaction($txData, $privateKey) {
        // In einer echten Implementierung: web3.php oder ethers.php verwenden
        // Hier vereinfachte Demo-Version
        
        // Für Produktion: ethereum-php oder web3.php Library verwenden
        // return $this->signWithLibrary($txData, $privateKey);
        
        // Demo: Simulierte Signatur
        return '0x' . bin2hex(random_bytes(32));
    }
    
    private function sendRawTransaction($signedTx) {
        $response = $this->rpcCall('eth_sendRawTransaction', [$signedTx]);
        
        if (isset($response['result'])) {
            return $response['result'];
        }
        
        throw new Exception('Transaction konnte nicht gesendet werden');
    }
    
    private function hexToDecimal($hex, $decimals = 18) {
        if ($hex === '0x') {
            return '0';
        }
        
        // Entferne 0x
        $hex = str_replace('0x', '', $hex);
        
        // Hex zu decimal (große Zahlen)
        $decimal = '0';
        for ($i = 0; $i < strlen($hex); $i++) {
            $decimal = bcadd(bcmul($decimal, '16'), strval(hexdec($hex[$i])));
        }
        
        // Durch 10^decimals teilen
        $divisor = bcpow('10', strval($decimals));
        $result = bcdiv($decimal, $divisor, $decimals);
        
        return $result;
    }
    
    private function decimalToHex($decimal) {
        $hex = '';
        while ($decimal > 0) {
            $remainder = bcmod($decimal, '16');
            $hex = dechex($remainder) . $hex;
            $decimal = bcdiv($decimal, '16', 0);
        }
        
        return '0x' . ($hex ?: '0');
    }
    
    private function getConfirmations($blockNumber) {
        try {
            $response = $this->rpcCall('eth_blockNumber', []);
            
            if (isset($response['result'])) {
                $currentBlock = hexdec($response['result']);
                $txBlock = hexdec($blockNumber);
                
                return max(0, $currentBlock - $txBlock);
            }
            
            return 0;
            
        } catch (Exception $e) {
            return 0;
        }
    }
    
    private function privateKeyToAddress($privateKey) {
        // In Produktion: proper ECDSA mit secp256k1
        // Hier Demo: generiere pseudo-Adresse
        $hash = hash('sha256', $privateKey);
        return '0x' . substr($hash, 0, 40);
    }
    
    private function encryptPrivateKey($privateKey) {
        // Verschlüssele mit Drupal's Encryption Service
        $encryption = \Drupal::service('encryption');
        return $encryption->encrypt($privateKey);
    }
    
    private function generateMnemonic() {
        $words = file(__DIR__ . '/../Data/bip39_english.txt', FILE_IGNORE_NEW_LINES);
        shuffle($words);
        return array_slice($words, 0, 12);
    }
    
    private function isChecksumAddress($address) {
        // Für Produktion: EIP-55 Checksum implementieren
        // Hier vereinfacht: return true wenn Format stimmt
        return true;
    }
}
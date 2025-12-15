<?php
namespace Drupal\regiotoken_wallet\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class BlockchainSettingsForm extends ConfigFormBase {
    
    public function getFormId() {
        return 'regiotoken_wallet_blockchain_settings';
    }
    
    protected function getEditableConfigNames() {
        return ['regiotoken_wallet.settings'];
    }
    
    public function buildForm(array $form, FormStateInterface $form_state) {
        $config = $this->config('regiotoken_wallet.settings');
        
        $form['network'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Blockchain Network'),
            '#collapsible' => TRUE,
            '#collapsed' => FALSE,
        ];
        
        $form['network']['chain_id'] = [
            '#type' => 'select',
            '#title' => $this->t('Blockchain Network'),
            '#options' => [
                100 => 'Gnosis Chain (Mainnet)',
                10200 => 'Gnosis Chiado (Testnet)',
                1 => 'Ethereum Mainnet',
                5 => 'Goerli Testnet',
                137 => 'Polygon Mainnet',
                80001 => 'Polygon Mumbai',
            ],
            '#default_value' => $config->get('chain_id') ?: 10200,
            '#description' => $this->t('Wählen Sie das Blockchain-Netzwerk.'),
        ];
        
        $form['network']['rpc_url'] = [
            '#type' => 'textfield',
            '#title' => $this->t('RPC URL'),
            '#default_value' => $config->get('rpc_url') ?: 'https://rpc.chiadochain.net',
            '#required' => TRUE,
            '#description' => $this->t('RPC Endpoint für die Blockchain.'),
        ];
        
        $form['network']['explorer_url'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Block Explorer URL'),
            '#default_value' => $config->get('explorer_url') ?: 'https://gnosis-chiado.blockscout.com',
            '#required' => TRUE,
            '#description' => $this->t('URL des Block Explorers.'),
        ];
        
        $form['contract'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Smart Contract'),
            '#collapsible' => TRUE,
            '#collapsed' => FALSE,
        ];
        
        $form['contract']['contract_address'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Token Contract Address'),
            '#default_value' => $config->get('contract_address') ?: '',
            '#required' => TRUE,
            '#description' => $this->t('Adresse des RegioToken Smart Contracts.'),
            '#attributes' => [
                'placeholder' => '0x...',
                'pattern' => '^0x[a-fA-F0-9]{40}$',
            ],
        ];
        
        $form['contract']['token_decimals'] = [
            '#type' => 'number',
            '#title' => $this->t('Token Decimals'),
            '#default_value' => $config->get('token_decimals') ?: 18,
            '#min' => 0,
            '#max' => 36,
            '#description' => $this->t('Anzahl der Dezimalstellen des Tokens (typisch: 18).'),
        ];
        
        $form['contract']['token_symbol'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Token Symbol'),
            '#default_value' => $config->get('token_symbol') ?: 'REGIO',
            '#required' => TRUE,
            '#description' => $this->t('Symbol des Tokens (z.B. REGIO).'),
        ];
        
        $form['security'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Security Settings'),
            '#collapsible' => TRUE,
            '#collapsed' => TRUE,
        ];
        
        $form['security']['admin_private_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Admin Private Key (Encrypted)'),
            '#default_value' => $config->get('admin_private_key_encrypted') ?: '',
            '#description' => $this->t('Verschlüsselter Private Key für Admin-Transaktionen.'),
            '#attributes' => [
                'readonly' => TRUE,
            ],
        ];
        
        $form['security']['max_transactions_per_hour'] = [
            '#type' => 'number',
            '#title' => $this->t('Max Transactions per Hour'),
            '#default_value' => $config->get('max_transactions_per_hour') ?: 10,
            '#min' => 1,
            '#max' => 100,
            '#description' => $this->t('Maximale Anzahl von Transaktionen pro Stunde pro Benutzer.'),
        ];
        
        $form['gas'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Gas Settings'),
            '#collapsible' => TRUE,
            '#collapsed' => TRUE,
        ];
        
        $form['gas']['gas_limit'] = [
            '#type' => 'number',
            '#title' => $this->t('Gas Limit'),
            '#default_value' => $config->get('gas_limit') ?: 21000,
            '#min' => 21000,
            '#max' => 1000000,
            '#description' => $this->t('Gas Limit für Transaktionen.'),
        ];
        
        $form['gas']['gas_price_multiplier'] = [
            '#type' => 'number',
            '#title' => $this->t('Gas Price Multiplier'),
            '#default_value' => $config->get('gas_price_multiplier') ?: 1.2,
            '#step' => 0.1,
            '#min' => 1,
            '#max' => 5,
            '#description' => $this->t('Multiplikator für aktuellen Gas-Preis (schnellere Transaktionen).'),
        ];
        
        return parent::buildForm($form, $form_state);
    }
    
    public function validateForm(array &$form, FormStateInterface $form_state) {
        $contract_address = $form_state->getValue('contract_address');
        
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $contract_address)) {
            $form_state->setErrorByName('contract_address', 
                $this->t('Ungültige Contract-Adresse. Format: 0x gefolgt von 40 hexadezimalen Zeichen.'));
        }
        
        $rpc_url = $form_state->getValue('rpc_url');
        if (!filter_var($rpc_url, FILTER_VALIDATE_URL)) {
            $form_state->setErrorByName('rpc_url', $this->t('Ungültige URL.'));
        }
    }
    
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $config = $this->config('regiotoken_wallet.settings');
        
        $values = [
            'chain_id' => $form_state->getValue('chain_id'),
            'rpc_url' => $form_state->getValue('rpc_url'),
            'explorer_url' => $form_state->getValue('explorer_url'),
            'contract_address' => $form_state->getValue('contract_address'),
            'token_decimals' => $form_state->getValue('token_decimals'),
            'token_symbol' => $form_state->getValue('token_symbol'),
            'max_transactions_per_hour' => $form_state->getValue('max_transactions_per_hour'),
            'gas_limit' => $form_state->getValue('gas_limit'),
            'gas_price_multiplier' => $form_state->getValue('gas_price_multiplier'),
        ];
        
        foreach ($values as $key => $value) {
            $config->set($key, $value);
        }
        
        $config->save();
        
        parent::submitForm($form, $form_state);
        
        \Drupal::messenger()->addStatus($this->t('Blockchain-Einstellungen gespeichert.'));
    }
}
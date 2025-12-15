<?php
namespace Drupal\regiotoken_wallet\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class FlutterAppSettingsForm extends ConfigFormBase {
    
    public function getFormId() {
        return 'regiotoken_wallet_flutter_settings';
    }
    
    protected function getEditableConfigNames() {
        return ['regiotoken_wallet.settings'];
    }
    
    public function buildForm(array $form, FormStateInterface $form_state) {
        $config = $this->config('regiotoken_wallet.settings');
        
        $form['firebase'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Firebase Cloud Messaging'),
            '#collapsible' => TRUE,
            '#collapsed' => FALSE,
        ];
        
        $form['firebase']['firebase_api_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Firebase API Key'),
            '#default_value' => $config->get('firebase_api_key') ?: '',
            '#description' => $this->t('API Key für Firebase Cloud Messaging (Android).'),
        ];
        
        $form['firebase']['firebase_project_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Firebase Project ID'),
            '#default_value' => $config->get('firebase_project_id') ?: '',
        ];
        
        $form['apple'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Apple Push Notifications'),
            '#collapsible' => TRUE,
            '#collapsed' => TRUE,
        ];
        
        $form['apple']['apple_key_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Apple Key ID'),
            '#default_value' => $config->get('apple_key_id') ?: '',
        ];
        
        $form['apple']['apple_team_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Apple Team ID'),
            '#default_value' => $config->get('apple_team_id') ?: '',
        ];
        
        $form['app'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('App Configuration'),
            '#collapsible' => TRUE,
            '#collapsed' => FALSE,
        ];
        
        $form['app']['app_name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('App Name'),
            '#default_value' => $config->get('app_name') ?: 'RegioToken Wallet',
        ];
        
        $form['app']['app_version'] = [
            '#type' => 'textfield',
            '#title' => $this->t('App Version'),
            '#default_value' => $config->get('app_version') ?: '1.0.0',
        ];
        
        $form['app']['minimum_app_version'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Minimum App Version'),
            '#default_value' => $config->get('minimum_app_version') ?: '1.0.0',
            '#description' => $this->t('Ältere Versionen werden abgelehnt.'),
        ];
        
        $form['security'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('API Security'),
            '#collapsible' => TRUE,
            '#collapsed' => TRUE,
        ];
        
        $form['security']['api_rate_limit'] = [
            '#type' => 'number',
            '#title' => $this->t('API Requests per Minute'),
            '#default_value' => $config->get('api_rate_limit') ?: 60,
            '#min' => 10,
            '#max' => 1000,
        ];
        
        $form['security']['enable_api_logging'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable API Logging'),
            '#default_value' => $config->get('enable_api_logging') ?: TRUE,
        ];
        
        return parent::buildForm($form, $form_state);
    }
    
    public function submitForm(array &$form, FormStateInterface $form_state) {
        $config = $this->config('regiotoken_wallet.settings');
        
        $values = [
            'firebase_api_key',
            'firebase_project_id',
            'apple_key_id',
            'apple_team_id',
            'app_name',
            'app_version',
            'minimum_app_version',
            'api_rate_limit',
            'enable_api_logging',
        ];
        
        foreach ($values as $key) {
            $config->set($key, $form_state->getValue($key));
        }
        
        $config->save();
        
        parent::submitForm($form, $form_state);
        
        \Drupal::messenger()->addStatus($this->t('Flutter App Einstellungen gespeichert.'));
    }
}
<?php
namespace Drupal\regiotoken_wallet\EventSubscriber;

use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\regiotoken_wallet\Event\TransactionEvent;

class TransactionSubscriber implements EventSubscriberInterface {
    
    protected $mailManager;
    
    public function __construct(MailManagerInterface $mail_manager) {
        $this->mailManager = $mail_manager;
    }
    
    public static function getSubscribedEvents() {
        return [
            TransactionEvent::SUCCESS => 'onTransactionSuccess',
            TransactionEvent::FAILED => 'onTransactionFailed',
        ];
    }
    
    public function onTransactionSuccess(TransactionEvent $event) {
        $transaction = $event->getTransaction();
        $user = $event->getUser();
        
        $params = [
            'subject' => '✅ RegioToken Transfer erfolgreich',
            'body' => [
                'transaction' => $transaction,
                'user' => $user,
            ],
        ];
        
        $this->mailManager->mail(
            'regiotoken_wallet',
            'transaction_success',
            $user->getEmail(),
            $user->getPreferredLangcode(),
            $params
        );
    }
}
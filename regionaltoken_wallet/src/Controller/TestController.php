<?php
namespace Drupal\regiotoken_wallet\Controller;
use Drupal\Core\Controller\ControllerBase;
class TestController extends ControllerBase {
    public function test() {
        return ['#markup' => '<h1>✅ RegioToken Test erfolgreich!</h1><p>Das Modul funktioniert.</p>'];
    }
}
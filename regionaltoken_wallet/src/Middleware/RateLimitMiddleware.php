<?php
namespace Drupal\regiotoken_wallet\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Cache\CacheBackendInterface;

class RateLimitMiddleware {
    
    private $cache;
    
    public function __construct(CacheBackendInterface $cache) {
        $this->cache = $cache;
    }
    
    public function handle(Request $request) {
        $userId = \Drupal::currentUser()->id();
        $cacheKey = 'regiotoken_rate_limit:' . $userId;
        
        // Prüfe Rate Limit (max 10 Transaktionen pro Stunde)
        $transactions = $this->cache->get($cacheKey);
        
        if ($transactions && $transactions->data >= 10) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Rate limit exceeded. Please try again later.'
            ], 429);
        }
        
        // Rate Limit erhöhen
        $count = $transactions ? $transactions->data + 1 : 1;
        $this->cache->set($cacheKey, $count, time() + 3600);
        
        return null; // Weiter zur nächsten Middleware
    }
}
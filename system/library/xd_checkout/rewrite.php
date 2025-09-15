<?php
/*
@author XDomus
@link   http://xdomus.ru
*/

namespace Xd_checkout;

class Rewrite
{
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function rewrite($url)
    {
        $get_route = isset($_GET['route']) ? $_GET['route'] : (isset($_GET['_route_']) ? $_GET['_route_'] : '');
        $debug = isset($_GET['debug']) ? true : false;

        if (!$debug && !empty($this->config)) {
            // Only replace if not already rewritten
            // if (strpos($url, 'checkout/xd_checkout/cart') === false) {
            //     $url = str_replace('checkout/cart', 'checkout/xd_checkout/checkout', $url);
            // }

            if (strpos($url, 'checkout/xd_checkout/checkout') === false) {
                $url = str_replace('checkout/checkout', 'checkout/xd_checkout/checkout', $url);
            }
        }

        return $url;
    }

    public function redirect()
    {
        $route = isset($_GET['route']) ? $_GET['route'] : '';
        // if ($route == 'checkout/cart') {
        //     header('Location: ' . $this->rewrite('index.php?route=checkout/checkout'));
        //     exit;
        // }
        if ($route == 'checkout/checkout') {
            header('Location: ' . $this->rewrite('index.php?route=checkout/checkout'));
            exit;
        }
    }
}

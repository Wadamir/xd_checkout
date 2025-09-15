<?php
// require_once(DIR_SYSTEM . 'library/equotix/xd_checkout/equotix.php');
class ControllerXdCheckoutCheckout extends Controller
{
    protected $code = 'xd_checkout';

    // Property to store config for all methods
    private $xd_checkout_settings = [];

    public function __construct($registry)
    {
        parent::__construct($registry);

        // Initialize property from config
        $this->xd_checkout_settings = $this->config->get('xd_checkout');
    }

    public function index()
    {
        $this->document->addScript('catalog/view/javascript/jquery/xd_xd_checkout.js');

        $xd_checkout_settings = $this->xd_checkout_settings;

        if ($xd_checkout_settings['load_screen']) {
            $this->document->addScript('catalog/view/javascript/jquery/xd_xd_checkout.block.js');
        }

        if ($xd_checkout_settings['countdown']) {
            $this->document->addScript('catalog/view/javascript/jquery/xd_xd_checkout.countdown.js');
        }

        $this->document->addStyle('catalog/view/theme/default/stylesheet/xd_checkout.css');

        $data['column_layout'] = isset($xd_checkout_settings['layout']) ? $xd_checkout_settings['layout'] : '';

        if ($data['column_layout'] == '1') {
            $stylesheet = 'one';
        } elseif ($data['column_layout'] == '2') {
            $stylesheet = 'two';
        } else {
            $stylesheet = 'three';
        }

        $this->document->addStyle('catalog/view/theme/default/stylesheet/xd_checkout_' . $stylesheet . '.css');

        if (!$xd_checkout_settings['debug'] || !isset($this->request->get['debug'])) {
            if (!$xd_checkout_settings['status']) {
                $this->response->redirect($this->url->link('checkout/checkout', '', true));
            }
        }

        $this->document->addScript('catalog/view/javascript/jquery/datetimepicker/moment/moment.min.js');
        $this->document->addScript('catalog/view/javascript/jquery/xd_checkout/bootstrap-datetimepicker.min.js');
        $this->document->addStyle('catalog/view/javascript/jquery/xd_checkout/bootstrap-datetimepicker.min.css');

        // Required by klarna
        if ($this->config->get('klarna_account') || $this->config->get('klarna_invoice')) {
            $this->document->addScript('http://cdn.klarna.com/public/kitt/toc/v1.0/js/klarna.terms.min.js');
        }

        // Validate cart has products and has stock.
        if ((!$this->cart->hasProducts() && empty($this->session->data['vouchers'])) || (!$this->cart->hasStock() && !$this->config->get('config_stock_checkout'))) {
            $this->response->redirect($this->url->link('checkout/cart'));
        }

        // Validate minimum quantity requirements.			
        $products = $this->cart->getProducts();

        foreach ($products as $product) {
            $product_total = 0;

            foreach ($products as $product_2) {
                if ($product_2['product_id'] == $product['product_id']) {
                    $product_total += $product_2['quantity'];
                }
            }

            if ($product['minimum'] > $product_total) {
                $this->response->redirect($this->url->link('checkout/cart'));
            }
        }

        $data = array_merge($data, $this->load->language('checkout/checkout'));
        $data = array_merge($data, $this->load->language('xd_checkout/checkout'));

        // Validate minimum order total
        if ($this->cart->getTotal() < (float)$xd_checkout_settings['minimum_order']) {
            $this->session->data['error'] = sprintf($this->language->get('error_minimum_order'), $this->currency->format($xd_checkout_settings['minimum_order'], $this->session->data['currency']));

            $this->response->redirect($this->url->link('checkout/cart'));
        }

        $this->document->setTitle($this->language->get('heading_title'));

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text'      => $this->language->get('text_home'),
            'href'      => $this->url->link('common/home')
        );

        $data['breadcrumbs'][] = array(
            'text'      => $this->language->get('text_cart'),
            'href'      => $this->url->link('checkout/cart')
        );

        $data['breadcrumbs'][] = array(
            'text'      => $this->language->get('heading_title'),
            'href'      => $this->url->link('xd_checkout/checkout', '', true)
        );

        if ($xd_checkout_settings['html_header']) {
            $html_header = $xd_checkout_settings['html_header'];

            if (!empty($html_header[$this->config->get('config_language_id')])) {
                $data['html_header'] = html_entity_decode($html_header[$this->config->get('config_language_id')]);
            } else {
                $data['html_header'] = '';
            }
        } else {
            $data['html_header'] = '';
        }

        if ($xd_checkout_settings['html_footer']) {
            $html_footer = $xd_checkout_settings['html_footer'];

            if (!empty($html_footer[$this->config->get('config_language_id')])) {
                $data['html_footer'] = html_entity_decode($html_footer[$this->config->get('config_language_id')]);
            } else {
                $data['html_footer'] = '';
            }
        } else {
            $data['html_footer'] = '';
        }

        $data['countdown_end'] = false;

        if ($xd_checkout_settings['countdown']) {
            $data['timezone'] = substr(date('O'), 0, 3);

            $text = $xd_checkout_settings['countdown_text'];

            if (!empty($text[$this->config->get('config_language_id')])) {
                if ($xd_checkout_settings['countdown_start']) {
                    $time = date('d M Y') . ' ' . $xd_checkout_settings['countdown_time'] . ':00';

                    if (time() > strtotime($time)) {
                        $data['countdown_end'] = date('d M Y H:i:s', (strtotime($time) + 86400));
                    } else {
                        $data['countdown_end'] = $time;
                    }
                } else {
                    if (time() >= strtotime($xd_checkout_settings['countdown_date_start']) && time() <= strtotime($xd_checkout_settings['countdown_date_end'])) {
                        $data['countdown_end'] = $xd_checkout_settings['countdown_date_end'];
                    }
                }

                $text = explode('{timer}', html_entity_decode($text[$this->config->get('config_language_id')]));

                $data['countdown_before'] = $text[0];

                if (isset($text[1])) {
                    $data['countdown_after'] = $text[1];
                } else {
                    $data['countdown_after'] = '';
                }

                $data['countdown_timer'] = '{dn} {dl} {hnn} {hl} {mnn} {ml} {snn} {sl}';
            }
        }

        // All variables
        $data['logged'] = $this->customer->isLogged();
        $data['shipping_required'] = $this->cart->hasShipping();
        $data['load_screen'] = $xd_checkout_settings['load_screen'] ? true : false;
        $data['loading_display'] = $xd_checkout_settings['loading_display'] ?? 0;
        $data['edit_cart'] = $xd_checkout_settings['edit_cart'];
        $data['highlight_error'] = $xd_checkout_settings['highlight_error'];
        $data['text_error'] = $xd_checkout_settings['text_error'];
        // $data['xd_checkout_layout'] = $xd_checkout_settings['layout'];
        $data['slide_effect'] = $xd_checkout_settings['slide_effect'];
        $data['debug'] = $xd_checkout_settings['debug'];
        $data['auto_submit'] = $xd_checkout_settings['auto_submit'];
        $data['coupon_module'] = $xd_checkout_settings['coupon_module'];
        $data['voucher_module'] = $xd_checkout_settings['voucher_module'];
        $data['reward_module'] = $xd_checkout_settings['reward_module'];
        $data['cart_module'] = $xd_checkout_settings['cart_module'];
        $data['payment_module'] = $xd_checkout_settings['payment_module'];
        $data['shipping_module'] = $xd_checkout_settings['shipping_module'];
        $data['login_module'] = $xd_checkout_settings['login_module'];
        $data['countdown'] = $xd_checkout_settings['countdown'];
        $data['save_data'] = $xd_checkout_settings['save_data'];
        $data['custom_css'] = html_entity_decode($xd_checkout_settings['custom_css'], ENT_QUOTES, 'UTF-8');
        $data['confirmation_page'] = $xd_checkout_settings['confirmation_page'];

        if (!$this->customer->isLogged()) {
            $data['guest'] = $this->load->controller('xd_checkout/guest');
            // $data['guest_shipping'] = $this->load->controller('xd_checkout/guest_shipping');

            if ($xd_checkout_settings['login_module']) {
                $data['login'] = $this->load->controller('xd_checkout/login');
            }
        }

        $data['payment_address'] = $this->load->controller('xd_checkout/payment_address');
        // $data['shipping_address'] = $this->load->controller('xd_checkout/shipping_address');


        $data['cart'] = $this->load->controller('xd_checkout/cart');
        $data['voucher'] = $this->load->controller('xd_checkout/voucher');
        $data['terms'] = $this->load->controller('xd_checkout/terms');

        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');

        $this->response->setOutput($this->load->view('xd_checkout/checkout', $data));
    }

    public function country()
    {
        $json = array();

        $this->load->model('localisation/country');

        $country_info = $this->model_localisation_country->getCountry($this->request->get['country_id']);

        if ($country_info) {
            $this->load->model('localisation/zone');

            $xd_checkout_settings = $this->config->get('xd_checkout');
            $xd_checkout_zone = $xd_checkout_settings['field_zone'] ? $xd_checkout_settings['field_zone'] : '';
            $zone_default = $xd_checkout_zone['default'] ? $xd_checkout_zone['default'] : '';

            if (isset($this->session->data['payment_address']['zone_id'])) {
                $zone_default = $this->session->data['payment_address']['zone_id'];
            }

            $json = array(
                'country_id'        => $country_info['country_id'],
                'name'              => $country_info['name'],
                'iso_code_2'        => $country_info['iso_code_2'],
                'iso_code_3'        => $country_info['iso_code_3'],
                'address_format'    => $country_info['address_format'],
                'postcode_required' => $country_info['postcode_required'],
                'zone'              => $this->model_localisation_zone->getZonesByCountryId($this->request->get['country_id']),
                'zone_default'      => $zone_default,
                'status'            => $country_info['status']
            );
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function customfield()
    {
        $json = array();

        $this->load->model('account/custom_field');

        // Customer Group
        if (isset($this->request->get['customer_group_id']) && is_array($this->config->get('config_customer_group_display')) && in_array($this->request->get['customer_group_id'], $this->config->get('config_customer_group_display'))) {
            $customer_group_id = $this->request->get['customer_group_id'];
        } else {
            $customer_group_id = $this->config->get('config_customer_group_id');
        }

        $custom_fields = $this->model_account_custom_field->getCustomFields($customer_group_id);

        foreach ($custom_fields as $custom_field) {
            $json[] = array(
                'custom_field_id' => $custom_field['custom_field_id'],
                'required'        => $custom_field['required']
            );
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function save()
    {
        $json = array();

        if (!$this->customer->isLogged() && isset($this->request->get['type'])) {
            if ($this->request->get['type'] == 'payment') {
                $this->session->data['guest']['firstname'] = $this->request->post['firstname'];
                $this->session->data['guest']['lastname'] = $this->request->post['lastname'];
                $this->session->data['guest']['email'] = $this->request->post['email'];
                $this->session->data['guest']['telephone'] = $this->request->post['telephone'];
                $this->session->data['guest']['shipping_address'] = isset($this->request->post['shipping_address']) ? true : false;
                $this->session->data['guest']['create_account'] = isset($this->request->post['create_account']) ? true : false;

                $this->session->data['payment_address']['firstname'] = $this->request->post['firstname'];
                $this->session->data['payment_address']['lastname'] = $this->request->post['lastname'];
                $this->session->data['payment_address']['company'] = $this->request->post['company'];
                $this->session->data['payment_address']['address_1'] = $this->request->post['address_1'];
                $this->session->data['payment_address']['address_2'] = $this->request->post['address_2'];
                $this->session->data['payment_address']['postcode'] = $this->request->post['postcode'];
                $this->session->data['payment_address']['city'] = $this->request->post['city'];
                $this->session->data['payment_address']['country_id'] = $this->request->post['country_id'];
                $this->session->data['payment_address']['zone_id'] = $this->request->post['zone_id'];

                if (isset($this->request->post['custom_field']['address'])) {
                    $this->session->data['payment_address']['custom_field'] = $this->request->post['custom_field']['address'];
                } else {
                    $this->session->data['payment_address']['custom_field'] = array();
                }
            } else {
                $this->session->data['shipping_address']['firstname'] = $this->request->post['firstname'];
                $this->session->data['shipping_address']['lastname'] = $this->request->post['lastname'];
                $this->session->data['shipping_address']['company'] = $this->request->post['company'];
                $this->session->data['shipping_address']['address_1'] = $this->request->post['address_1'];
                $this->session->data['shipping_address']['address_2'] = $this->request->post['address_2'];
                $this->session->data['shipping_address']['postcode'] = $this->request->post['postcode'];
                $this->session->data['shipping_address']['city'] = $this->request->post['city'];
                $this->session->data['shipping_address']['country_id'] = $this->request->post['country_id'];
                $this->session->data['shipping_address']['zone_id'] = $this->request->post['zone_id'];

                if (isset($this->request->post['custom_field'])) {
                    $this->session->data['shipping_address']['custom_field'] = $this->request->post['custom_field']['address'];
                } else {
                    $this->session->data['shipping_address']['custom_field'] = array();
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function eventPreControllerCheckoutCheckout($route, $data)
    {
        if ($this->config->get('xd_checkout_status')) {
            $this->response->redirect($this->url->link('xd_checkout/checkout', '', 'SSL'));
        }
    }

    public function eventPreControllerCheckoutSuccess($route, $data)
    {
        unset($this->session->data['order_comment']);
        unset($this->session->data['delivery_date']);
        unset($this->session->data['delivery_time']);
        unset($this->session->data['survey']);
        unset($this->session->data['shipping_address']);
        unset($this->session->data['payment_address']);
    }
}

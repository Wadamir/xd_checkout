<?php
class ControllerExtensionXdCheckoutPaymentMethod extends Controller
{
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
        $data = $this->load->language('checkout/checkout');
        $data = array_merge($data, $this->load->language('extension/xd_checkout/checkout'));

        $xd_checkout_settings = $this->xd_checkout_settings;

        $this->load->model('account/address');
        $this->load->model('localisation/country');
        $this->load->model('localisation/zone');

        $payment_address = array();

        if ($this->customer->isLogged() && isset($this->request->get['address_id'])) {
            // Selected stored address
            $payment_address = $this->model_account_address->getAddress($this->request->get['address_id']);

            if (isset($this->session->data['guest'])) {
                unset($this->session->data['guest']);
            }
        } elseif (isset($this->request->post['country_id'])) {
            // Selected new address OR is a guest
            if (isset($this->request->post['country_id'])) {
                $country_info = $this->model_localisation_country->getCountry($this->request->post['country_id']);
            } else {
                $country_info = '';
            }

            if (isset($this->request->post['zone_id'])) {
                $zone_info = $this->model_localisation_zone->getZone($this->request->post['zone_id']);
            } else {
                $zone_info = '';
            }

            if ($country_info) {
                $payment_address['country'] = $country_info['name'];
                $payment_address['iso_code_2'] = $country_info['iso_code_2'];
                $payment_address['iso_code_3'] = $country_info['iso_code_3'];
                $payment_address['address_format'] = $country_info['address_format'];
            } else {
                $payment_address['country'] = '';
                $payment_address['iso_code_2'] = '';
                $payment_address['iso_code_3'] = '';
                $payment_address['address_format'] = '';
            }

            if ($zone_info) {
                $payment_address['zone'] = $zone_info['name'];
                $payment_address['zone_code'] = $zone_info['code'];
            } else {
                $payment_address['zone'] = '';
                $payment_address['zone_code'] = '';
            }

            $payment_address['firstname'] = $this->request->post['firstname'] ?? '';
            $payment_address['lastname'] = $this->request->post['lastname'] ?? '';
            $payment_address['company'] = $this->request->post['company'] ?? '';
            $payment_address['address_1'] = $this->request->post['address_1'] ?? '';
            $payment_address['address_2'] = $this->request->post['address_2'] ?? '';
            $payment_address['postcode'] = $this->request->post['postcode'] ?? '';
            $payment_address['city'] = $this->request->post['city'] ?? '';
            $payment_address['country_id'] = $this->request->post['country_id'] ?? '';
            $payment_address['zone_id'] = $this->request->post['zone_id'] ?? '';
        }

        if (!empty($payment_address)) {
            // Totals
            $total_data = array();
            $total = 0;
            $taxes = $this->cart->getTaxes();

            $total_data = array(
                'totals' => &$totals,
                'taxes'  => &$taxes,
                'total'  => &$total
            );

            $this->load->model('setting/extension');

            $sort_order = array();

            $results = $this->model_setting_extension->getExtensions('total');

            foreach ($results as $key => $value) {
                $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
            }

            array_multisort($sort_order, SORT_ASC, $results);

            foreach ($results as $result) {
                if ($this->config->get('total_' . $result['code'] . '_status')) {
                    $this->load->model('extension/total/' . $result['code']);

                    $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
                }
            }

            // Payment Methods
            $method_data = array();

            $this->load->model('setting/extension');

            $results = $this->model_setting_extension->getExtensions('payment');

            $recurring = $this->cart->hasRecurringProducts();

            foreach ($results as $result) {
                if ($this->config->get('payment_' . $result['code'] . '_status')) {
                    $this->load->model('extension/payment/' . $result['code']);

                    $method = $this->{'model_extension_payment_' . $result['code']}->getMethod($payment_address, $total);

                    if ($method) {
                        if ($recurring) {
                            if (property_exists($this->{'model_extension_payment_' . $result['code']}, 'recurringPayments') && $this->{'model_extension_payment_' . $result['code']}->recurringPayments()) {
                                $method_data[$result['code']] = $method;
                            }
                        } else {
                            $method_data[$result['code']] = $method;
                        }
                    }
                }
            }

            $sort_order = array();

            foreach ($method_data as $key => $value) {
                $sort_order[$key] = $value['sort_order'];
            }

            array_multisort($sort_order, SORT_ASC, $method_data);

            $this->session->data['payment_methods'] = $method_data;
        }

        if ($xd_checkout_settings['survey_text']) {
            $text_survey = $xd_checkout_settings['survey_text'];

            if (!empty($text_survey[$this->config->get('config_language_id')])) {
                $data['text_survey'] = $text_survey[$this->config->get('config_language_id')];
            } else {
                $data['text_survey'] = '';
            }
        } else {
            $data['text_survey'] = '';
        }

        if (empty($this->session->data['payment_methods'])) {
            $data['error_warning'] = sprintf($this->language->get('error_no_payment'), $this->url->link('information/contact'));
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->session->data['payment_methods'])) {
            $data['payment_methods'] = $this->session->data['payment_methods'];
        } else {
            $data['payment_methods'] = array();
        }

        if (isset($this->request->post['payment_method'])) {
            $data['code'] = $this->request->post['payment_method'];
        } elseif (isset($this->session->data['payment_method']['code'])) {
            $data['code'] = $this->session->data['payment_method']['code'];
        } else {
            $data['code'] = $xd_checkout_settings['payment_default'];
        }

        $exists = false;
        $stored_code = false;

        foreach ($data['payment_methods'] as $payment_method) {
            if (!$stored_code) {
                $stored_code = $payment_method['code'];
            }

            if ($payment_method['code'] == $data['code']) {
                $exists = true;

                break;
            }
        }

        if (!$exists) {
            $data['code'] = $stored_code;
        }

        if (isset($this->request->post['comment'])) {
            $data['comment'] = $this->request->post['comment'];
        } elseif (isset($this->session->data['order_comment'])) {
            $data['comment'] = $this->session->data['order_comment'];
        } else {
            $data['comment'] = '';
        }

        if (isset($this->request->post['survey'])) {
            $data['survey'] = $this->request->post['survey'];
        } elseif (isset($this->session->data['survey'])) {
            $data['survey'] = $this->session->data['survey'];
        } else {
            $data['survey'] = '';
        }

        // All variables
        $data['field_comment'] = $xd_checkout_settings['field_comment'];
        $data['field_comment']['default'] = !empty($data['field_comment']['default'][$this->config->get('config_language_id')]) ? $data['field_comment']['default'][$this->config->get('config_language_id')] : '';
        $data['field_comment']['placeholder'] = !empty($data['field_comment']['placeholder'][$this->config->get('config_language_id')]) ? $data['field_comment']['placeholder'][$this->config->get('config_language_id')] : '';

        $data['logged'] = $this->customer->isLogged();
        $data['debug'] = $xd_checkout_settings['debug'];
        $data['payment'] = $xd_checkout_settings['payment'];
        $data['payment_logo'] = $xd_checkout_settings['payment_logo'];
        $data['survey_survey'] = $xd_checkout_settings['survey'];
        $data['survey_required'] = $xd_checkout_settings['survey_required'];
        $data['survey_type'] = $xd_checkout_settings['survey_type'];
        $data['survey_answers'] = $xd_checkout_settings['survey_answers'] ?? [];
        $data['cart_module'] = $xd_checkout_settings['cart_module'];
        $data['payment_reload'] = $xd_checkout_settings['payment_reload'];
        // $data['language_id'] = $xd_checkout_settings['language_id'];
        $data['language_id'] = (int)$this->config->get('config_language_id');

        $this->response->setOutput($this->load->view('xd_checkout/payment_method', $data));
    }

    public function set()
    {
        $this->load->model('account/address');
        $this->load->model('localisation/country');
        $this->load->model('localisation/zone');

        if ($this->customer->isLogged() && isset($this->request->get['address_id'])) {
            // Selected stored address
            $this->session->data['payment_address_id'] = $this->request->get['address_id'];

            $this->session->data['payment_address'] = $this->model_account_address->getAddress($this->request->get['address_id']);

            if (isset($this->session->data['guest'])) {
                unset($this->session->data['guest']);
            }
        } elseif (isset($this->request->post['country_id'])) {
            // Selected new address OR is a guest
            if (isset($this->request->post['country_id'])) {
                $country_info = $this->model_localisation_country->getCountry($this->request->post['country_id']);
            } else {
                $country_info = '';
            }

            if (isset($this->request->post['zone_id'])) {
                $zone_info = $this->model_localisation_zone->getZone($this->request->post['zone_id']);
            } else {
                $zone_info = '';
            }

            if ($country_info) {
                $payment_address['country'] = $country_info['name'];
                $payment_address['iso_code_2'] = $country_info['iso_code_2'];
                $payment_address['iso_code_3'] = $country_info['iso_code_3'];
                $payment_address['address_format'] = $country_info['address_format'];
            } else {
                $payment_address['country'] = '';
                $payment_address['iso_code_2'] = '';
                $payment_address['iso_code_3'] = '';
                $payment_address['address_format'] = '';
            }

            if ($zone_info) {
                $payment_address['zone'] = $zone_info['name'];
                $payment_address['zone_code'] = $zone_info['code'];
            } else {
                $payment_address['zone'] = '';
                $payment_address['zone_code'] = '';
            }

            $payment_address['firstname'] = $this->request->post['firstname'];
            $payment_address['lastname'] = $this->request->post['lastname'];
            $payment_address['company'] = $this->request->post['company'];
            $payment_address['address_1'] = $this->request->post['address_1'];
            $payment_address['address_2'] = $this->request->post['address_2'];
            $payment_address['postcode'] = $this->request->post['postcode'];
            $payment_address['city'] = $this->request->post['city'];
            $payment_address['country_id'] = $this->request->post['country_id'];
            $payment_address['zone_id'] = $this->request->post['zone_id'];

            $this->session->data['payment_address'] = $payment_address;
            $this->session->data['guest'] = $payment_address;
        }

        if (isset($this->request->post['survey'])) {
            $this->session->data['survey'] = strip_tags($this->request->post['survey']);
        }

        if (isset($this->request->post['comment'])) {
            $this->session->data['order_comment'] = strip_tags($this->request->post['comment']);
        }

        if (isset($this->request->post['payment_method']) && isset($this->session->data['payment_methods'][$this->request->post['payment_method']])) {
            $this->session->data['payment_method'] = $this->session->data['payment_methods'][$this->request->post['payment_method']];
        }
    }

    public function validate()
    {
        $this->load->language('checkout/checkout');
        $this->load->language('extension/xd_checkout/checkout');

        $xd_checkout_settings = $this->xd_checkout_settings;

        $this->load->model('account/address');
        $this->load->model('localisation/country');
        $this->load->model('localisation/zone');

        $json = array();

        // Set the address
        $payment_address = array();

        if (isset($this->session->data['payment_address'])) {
            $payment_address = $this->session->data['payment_address'];
        }

        // Validate if payment address has been set.
        if (empty($payment_address)) {
            $json['redirect'] = $this->url->link('xd_checkout/checkout', '', true);
        }

        if (!empty($payment_address)) {
            // Totals
            $total_data = array();
            $total = 0;
            $taxes = $this->cart->getTaxes();

            $total_data = array(
                'totals' => &$totals,
                'taxes'  => &$taxes,
                'total'  => &$total
            );

            $this->load->model('setting/extension');

            $sort_order = array();

            $results = $this->model_setting_extension->getExtensions('total');

            foreach ($results as $key => $value) {
                $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
            }

            array_multisort($sort_order, SORT_ASC, $results);

            foreach ($results as $result) {
                if ($this->config->get('total_' . $result['code'] . '_status')) {
                    $this->load->model('extension/total/' . $result['code']);

                    $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
                }
            }

            // Payment Methods
            $method_data = array();

            $this->load->model('setting/extension');

            $results = $this->model_setting_extension->getExtensions('payment');

            $recurring = $this->cart->hasRecurringProducts();

            foreach ($results as $result) {
                if ($this->config->get('payment_' . $result['code'] . '_status')) {
                    $this->load->model('extension/payment/' . $result['code']);

                    $method = $this->{'model_extension_payment_' . $result['code']}->getMethod($payment_address, $total);

                    if ($method) {
                        if ($recurring) {
                            if (property_exists($this->{'model_extension_payment_' . $result['code']}, 'recurringPayments') && $this->{'model_extension_payment_' . $result['code']}->recurringPayments()) {
                                $method_data[$result['code']] = $method;
                            }
                        } else {
                            $method_data[$result['code']] = $method;
                        }
                    }
                }
            }

            $sort_order = array();

            foreach ($method_data as $key => $value) {
                $sort_order[$key] = $value['sort_order'];
            }

            array_multisort($sort_order, SORT_ASC, $method_data);

            $this->session->data['payment_methods'] = $method_data;
        }

        if ($xd_checkout_settings['survey_required']) {
            if (empty($this->request->post['survey'])) {
                $json['error']['warning'] = $this->language->get('error_survey');
            }
        }

        $field_comment = $xd_checkout_settings['field_comment'];

        if (!empty($field_comment['required'])) {
            if (empty($this->request->post['comment'])) {
                $json['error']['warning'] = $this->language->get('error_comment');
            }
        }

        if (!isset($this->request->post['payment_method'])) {
            $json['error']['warning'] = $this->language->get('error_payment');
        } elseif (!isset($this->session->data['payment_methods'][$this->request->post['payment_method']])) {
            $json['error']['warning'] = $this->language->get('error_payment');
        }

        if (!$json) {
            $this->session->data['payment_method'] = $this->session->data['payment_methods'][$this->request->post['payment_method']];

            $this->session->data['order_comment'] = strip_tags($this->request->post['comment']);

            $this->session->data['survey'] = strip_tags($this->request->post['survey']);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}

<?php
class ControllerExtensionXdCheckoutShippingAddress extends Controller
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

        $this->syncShippingAddressWithPayment();

        $xd_checkout_settings = $this->xd_checkout_settings;

        if (isset($this->session->data['shipping_address']['address_id'])) {
            $data['address_id'] = $this->session->data['shipping_address']['address_id'];
        } else {
            $data['address_id'] = $this->customer->getAddressId();
        }

        $this->load->model('account/address');

        $data['addresses'] = $this->model_account_address->getAddresses();

        if (isset($this->session->data['shipping_postcode'])) {
            $data['postcode'] = $this->session->data['shipping_postcode'];
        } elseif (isset($this->session->data['shipping_address']['postcode'])) {
            $data['postcode'] = $this->session->data['shipping_address']['postcode'];
        } else {
            $data['postcode'] = '';
        }

        if (isset($this->session->data['shipping_country_id'])) {
            $data['country_id'] = $this->session->data['shipping_country_id'];
        } elseif (isset($this->session->data['shipping_address']['country_id'])) {
            $data['country_id'] = $this->session->data['shipping_address']['country_id'];
        } else {
            $country = $xd_checkout_settings['field_country'];

            $data['country_id'] = $country['default'];
        }

        if (isset($this->session->data['shipping_zone_id'])) {
            $data['zone_id'] = $this->session->data['shipping_zone_id'];
        } elseif (isset($this->session->data['shipping_address']['zone_id'])) {
            $data['zone_id'] = $this->session->data['shipping_address']['zone_id'];
        } else {
            $zone = $xd_checkout_settings['field_zone'];

            $data['zone_id'] = isset($zone['default']) ? $zone['default'] : 0;
        }

        $this->load->model('localisation/country');

        $data['countries'] = $this->model_localisation_country->getCountries();

        // Custom Fields
        $this->load->model('account/custom_field');

        $data['custom_fields'] = $this->model_account_custom_field->getCustomFields($this->config->get('config_customer_group_id'));

        if (isset($this->session->data['shipping_address']['custom_field'])) {
            $data['shipping_address_custom_field'] = $this->session->data['shipping_address']['custom_field'];
        } else {
            $data['shipping_address_custom_field'] = array();
        }

        // Fields
        $fields = array(
            'firstname',
            'lastname',
            'company',
            'address_1',
            'address_2',
            'city',
            'postcode',
            'country',
            'zone'
        );

        // All variables
        $data['debug'] = $xd_checkout_settings['debug'];

        $sort_order = array();

        foreach ($fields as $key => $field) {
            $field_data = $xd_checkout_settings['field_' . $field];

            $field_data['default'] = !empty($field_data['default'][$this->config->get('config_language_id')]) ? $field_data['default'][$this->config->get('config_language_id')] : '';
            $field_data['placeholder'] = !empty($field_data['placeholder'][$this->config->get('config_language_id')]) ? $field_data['placeholder'][$this->config->get('config_language_id')] : '';

            $data['field_' . $field] = $field_data;

            $sort_order[$key] = $field_data['sort_order'];
        }

        array_multisort($sort_order, SORT_ASC, $fields);

        $data['fields'] = $fields;

        return $this->load->view('extension/xd_checkout/shipping_address', $data);
    }

    public function validate()
    {
        $this->load->language('checkout/checkout');
        $this->load->language('extension/xd_checkout/checkout');
        $json = array();

        if (empty($this->session->data['payment_address'])) {
            $json['error']['warning'] = $this->language->get('error_address');
        } else {
            $this->syncShippingAddressWithPayment();
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    protected function syncShippingAddressWithPayment()
    {
        if (empty($this->session->data['payment_address']) || !is_array($this->session->data['payment_address'])) {
            return;
        }

        $this->session->data['shipping_address'] = $this->session->data['payment_address'];
    }
}

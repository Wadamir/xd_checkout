<?php
class ControllerExtensionXdCheckoutRegister extends Controller
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

        $data['entry_newsletter'] = sprintf($this->language->get('entry_newsletter'), $this->config->get('config_name'));

        if ($this->config->get('config_account_id')) {
            $this->load->model('catalog/information');

            $information_info = $this->model_catalog_information->getInformation($this->config->get('config_account_id'));

            if ($information_info) {
                $data['text_agree'] = sprintf($this->language->get('text_agree'), $this->url->link('information/information/agree', 'information_id=' . $this->config->get('config_account_id'), true), $information_info['title'], $information_info['title']);
            } else {
                $data['text_agree'] = '';
            }
        } else {
            $data['text_agree'] = '';
        }

        // All variables
        $data['field_newsletter'] = $xd_checkout_settings['field_newsletter'];

        return $this->load->view('xd_checkout/register', $data);
    }

    public function validate()
    {
        $this->load->language('checkout/checkout');
        $this->load->language('extension/xd_checkout/checkout');

        $this->load->model('account/customer');

        $json = array();

        // Validate if customer is already logged out.
        if ($this->customer->isLogged()) {
            $json['redirect'] = $this->url->link('xd_checkout/checkout', '', true);
        }

        if (!$json) {
            // Customer Group
            if (isset($this->request->post['customer_group_id']) && is_array($this->config->get('config_customer_group_display')) && in_array($this->request->post['customer_group_id'], $this->config->get('config_customer_group_display'))) {
                $customer_group_id = $this->request->post['customer_group_id'];
            } else {
                $customer_group_id = $this->config->get('config_customer_group_id');
            }

            if ($this->model_account_customer->getTotalCustomersByEmail($this->request->post['email'])) {
                $json['error']['warning'] = $this->language->get('error_exists');
            }

            if ((utf8_strlen($this->request->post['password']) < 4) || (utf8_strlen($this->request->post['password']) > 20)) {
                $json['error']['password'] = $this->language->get('error_password');
            }

            if ($this->request->post['confirm'] != $this->request->post['password']) {
                $json['error']['confirm'] = $this->language->get('error_confirm');
            }

            if ($this->config->get('config_account_id')) {
                $this->load->model('catalog/information');

                $information_info = $this->model_catalog_information->getInformation($this->config->get('config_account_id'));

                if ($information_info && !isset($this->request->post['agree'])) {
                    $json['error']['warning'] = sprintf($this->language->get('error_agree'), $information_info['title']);
                }
            }
        }

        if (!$json) {
            $customer_id = $this->model_account_customer->addCustomer($this->request->post);

            // Default Payment Address
            $this->load->model('account/address');

            $address_id = $this->model_account_address->addAddress($customer_id, $this->request->post);

            // Set the address as default
            $this->model_account_customer->editAddressId($customer_id, $address_id);

            // Clear any previous login attempts for unregistered accounts.
            $this->model_account_customer->deleteLoginAttempts($this->request->post['email']);

            $this->session->data['account'] = 'register';

            $this->load->model('account/customer_group');

            $customer_group_info = $this->model_account_customer_group->getCustomerGroup($customer_group_id);

            if ($customer_group_info && !$customer_group_info['approval']) {
                $this->customer->login($this->request->post['email'], $this->request->post['password']);

                // Default Payment Address
                $this->load->model('account/address');

                $this->request->post['default'] = true;

                $this->session->data['payment_address'] = $this->model_account_address->getAddress($this->customer->getAddressId());

                if (!empty($this->request->post['shipping_address'])) {
                    $this->session->data['shipping_address'] = $this->model_account_address->getAddress($this->customer->getAddressId());
                }
            } else {
                $json['redirect'] = $this->url->link('account/success');
            }

            unset($this->session->data['guest']);

            // Add to activity log
            if ($this->config->get('config_customer_activity')) {
                $this->load->model('account/activity');

                $activity_data = array(
                    'customer_id' => $customer_id,
                    'name'        => $this->request->post['firstname'] . ' ' . $this->request->post['lastname']
                );

                $this->model_account_activity->addActivity('register', $activity_data);
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}

<?php
class ControllerCheckoutXdCheckoutLogin extends Controller
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
        $data = array_merge($data, $this->load->language('checkout/xd_checkout/checkout'));

        $data['forgotten'] = $this->url->link('account/forgotten', '', true);

        return $this->load->view('checkout/xd_checkout/login', $data);
    }

    public function validate()
    {
        $this->load->language('checkout/checkout');
        $this->load->language('checkout/xd_checkout/checkout');

        $json = array();

        if ($this->customer->isLogged()) {
            $json['redirect'] = $this->url->link('checkout/xd_checkout/checkout', '', true);
        }

        if (!$json) {
            $this->load->model('account/customer');

            // Check how many login attempts have been made.
            $login_info = $this->model_account_customer->getLoginAttempts($this->request->post['email']);

            if ($login_info && ($login_info['total'] > $this->config->get('config_login_attempts')) && strtotime('-1 hour') < strtotime($login_info['date_modified'])) {
                $json['error']['warning'] = $this->language->get('error_attempts');
            }

            // Check if customer has been approved.
            $customer_info = $this->model_account_customer->getCustomerByEmail($this->request->post['email']);

            if ($customer_info && !$customer_info['status']) {
                $json['error']['warning'] = $this->language->get('error_approved');
            }

            if (!$json) {
                if (!$this->customer->login($this->request->post['email'], $this->request->post['password'])) {
                    $json['error']['warning'] = $this->language->get('error_login');

                    $this->model_account_customer->addLoginAttempt($this->request->post['email']);
                } else {
                    $this->model_account_customer->deleteLoginAttempts($this->request->post['email']);

                    // Add to activity log
                    if ($this->config->get('config_customer_activity')) {
                        $this->load->model('account/activity');

                        $activity_data = array(
                            'customer_id' => $this->customer->getId(),
                            'name'        => $this->customer->getfirstname() . ' ' . $this->customer->getLastName()
                        );

                        $this->model_account_activity->addActivity('login', $activity_data);
                    }
                }
            }
        }

        if (!$json) {
            $json['redirect'] = $this->url->link('checkout/xd_checkout/checkout', '', true);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}

<?php
// require_once(DIR_SYSTEM . 'library/equotix/xd_checkout/equotix.php');
class ControllerExtensionModuleXdCheckout extends Controller
{
    protected $version = '11.0.0';
    protected $code = 'xd_checkout';
    protected $extension = 'XD Checkout';
    protected $extension_id = '58';
    protected $purchase_url = 'xd-checkout';
    protected $purchase_id = '7382';
    protected $error = array();

    public function index()
    {
        $this->load->language('extension/module/xd_checkout');

        $this->document->setTitle(strip_tags($this->language->get('heading_title')));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && ($this->validate())) {
            if (isset($this->request->post['xd_checkout']) && isset($this->request->post['xd_checkout']['status']) && $this->request->post['xd_checkout']['status']) {
                $status_array = array('module_xd_checkout_status' => 1);
            } else {
                $status_array = array('module_xd_checkout_status' => 0);
            }
            $this->model_setting_setting->editSetting('module_xd_checkout', $status_array);

            $this->model_setting_setting->editSetting('xd_checkout', $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            if (!isset($this->request->get['continue'])) {
                $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
            } else {
                $this->response->redirect($this->url->link('extension/module/xd_checkout', 'user_token=' . $this->session->data['user_token'], true));
            }
        }

        // All fields
        $fields = array(
            'firstname',
            'lastname',
            'email',
            'telephone',
            'company',
            'customer_group',
            'address_1',
            'address_2',
            'city',
            'postcode',
            'country',
            'zone',
            'newsletter',
            'register',
            'comment'
        );

        $data['fields'] = $fields;

        // Heading
        $data['heading_title'] = $this->language->get('heading_title');

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];

            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text'      => $this->language->get('text_home'),
            'href'      => $this->url->link('common/home', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['breadcrumbs'][] = array(
            'text'      => $this->language->get('text_module'),
            'href'      => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
        );

        $data['breadcrumbs'][] = array(
            'text'      => $this->language->get('heading_title'),
            'href'      => $this->url->link('extension/module/xd_checkout', 'user_token=' . $this->session->data['user_token'], true)
        );

        $data['action'] = $this->url->link('extension/module/xd_checkout', 'user_token=' . $this->session->data['user_token'], true);
        $data['continue'] = $this->url->link('extension/module/xd_checkout', 'user_token=' . $this->session->data['user_token'] . '&continue=1', true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

        $data['user_token'] = $this->session->data['user_token'];

        $xd_checkout = $this->config->get('xd_checkout');

        if (isset($this->request->post['xd_checkout'])) {
            $data['xd_checkout'] = $this->request->post['xd_checkout'];
        } else {
            $data['xd_checkout'] = $xd_checkout ? $xd_checkout : array();
        }

        // Languages
        $this->load->model('localisation/language');

        $data['languages'] = $this->model_localisation_language->getLanguages();

        // Customer Groups
        $this->load->model('customer/customer_group');
        $data['customer_groups'] = array();

        if (is_array($this->config->get('config_customer_group_display'))) {
            $customer_groups = $this->model_customer_customer_group->getCustomerGroups();

            foreach ($customer_groups as $customer_group) {
                if (in_array($customer_group['customer_group_id'], $this->config->get('config_customer_group_display'))) {
                    $data['customer_groups'][] = $customer_group;
                }
            }
        }

        // Countries
        $this->load->model('localisation/country');

        $data['countries'] = $this->model_localisation_country->getCountries();

        // Payment
        $files = glob(DIR_APPLICATION . 'controller/extension/payment/*.php');

        $data['payment_modules'] = array();

        if ($files) {
            foreach ($files as $file) {
                $extension = basename($file, '.php');

                if ($this->config->get('payment_' . $extension . '_status')) {
                    $this->load->language('extension/payment/' . $extension);

                    $data['payment_modules'][] = array(
                        'name'        => $this->language->get('heading_title'),
                        'code'        => $extension
                    );
                }
            }
        }

        // Shipping
        $files = glob(DIR_APPLICATION . 'controller/extension/shipping/*.php');

        $data['shipping_modules'] = array();

        if ($files) {
            foreach ($files as $file) {
                $extension = basename($file, '.php');

                if ($this->config->get('shipping_' . $extension . '_status')) {
                    $this->load->language('extension/shipping/' . $extension);

                    $data['shipping_modules'][] = array(
                        'name'        => $this->language->get('heading_title'),
                        'code'        => $extension
                    );
                }
            }
        }

        // Analytics
        if (file_exists(DIR_APPLICATION . 'controller/extension/module/rac.php')) {
            $data['analytics'] = $this->url->link('extension/module/rac', 'user_token=' . $this->session->data['user_token'], true);
        } else {
            $data['analytics'] = false;
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        // $this->generateOutput('extension/module/xd_checkout', $data);
        $this->response->setOutput($this->load->view('extension/module/xd_checkout', $data));
    }

    public function country()
    {
        $json = array();

        $this->load->model('localisation/country');

        $country_info = $this->model_localisation_country->getCountry($this->request->get['country_id']);

        if ($country_info) {
            $this->load->model('localisation/zone');

            $json = array(
                'country_id'        => $country_info['country_id'],
                'name'              => $country_info['name'],
                'iso_code_2'        => $country_info['iso_code_2'],
                'iso_code_3'        => $country_info['iso_code_3'],
                'address_format'    => $country_info['address_format'],
                'postcode_required' => $country_info['postcode_required'],
                'zone'              => $this->model_localisation_zone->getZonesByCountryId($this->request->get['country_id']),
                'status'            => $country_info['status']
            );
        }

        $this->response->setOutput(json_encode($json));
    }

    public function install()
    {
        if (!$this->user->hasPermission('modify', 'extension/extension/module')) {
            return;
        }

        $this->load->language('module/xd_checkout');

        $this->load->model('setting/setting');

        $data = array(
            'xd_checkout_status'                => '0',
            'xd_checkout_minimum_order'         => '0',
            'xd_checkout_debug'                 => '0',
            'xd_checkout_confirmation_page'     => '1',
            'xd_checkout_save_data'             => '1',
            'xd_checkout_edit_cart'             => '1',
            'xd_checkout_highlight_error'       => '1',
            'xd_checkout_text_error'            => '1',
            'xd_checkout_auto_submit'           => '0',
            'xd_checkout_payment_target'        => '#button-confirm, .button, .btn',
            'xd_checkout_load_screen'           => '1',
            'xd_checkout_loading_display'       => '1',
            'xd_checkout_layout'                => '2',
            'xd_checkout_responsive'            => '1',
            'xd_checkout_slide_effect'          => '0',
            'xd_checkout_field_firstname'       => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => '',
                'sort_order'        => '1'
            ),
            'xd_checkout_field_lastname'        => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => '',
                'sort_order'        => '2'
            ),
            'xd_checkout_field_email'            => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => '',
                'sort_order'        => '3'
            ),
            'xd_checkout_field_telephone'        => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => '',
                'sort_order'        => '4'
            ),
            'xd_checkout_field_company'        => array(
                'display'           => '1',
                'required'          => '0',
                'default'           => '',
                'sort_order'        => '6'
            ),
            'xd_checkout_field_customer_group' => array(
                'display'           => '1',
                'required'          => '',
                'default'           => '',
                'sort_order'        => '7'
            ),
            'xd_checkout_field_address_1'        => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => '',
                'sort_order'        => '8'
            ),
            'xd_checkout_field_address_2'        => array(
                'display'           => '0',
                'required'          => '0',
                'default'           => '',
                'sort_order'        => '9'
            ),
            'xd_checkout_field_city'            => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => '',
                'sort_order'        => '10'
            ),
            'xd_checkout_field_postcode'        => array(
                'display'           => '1',
                'required'          => '0',
                'default'           => '',
                'sort_order'        => '11'
            ),
            'xd_checkout_field_country'        => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => $this->config->get('config_country_id'),
                'sort_order'        => '12'
            ),
            'xd_checkout_field_zone'            => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => $this->config->get('config_zone_id'),
                'sort_order'        => '13'
            ),
            'xd_checkout_field_newsletter'    => array(
                'display'           => '1',
                'required'          => '0',
                'default'           => '1',
                'sort_order'        => ''
            ),
            'xd_checkout_field_register'        => array(
                'display'           => '1',
                'required'          => '0',
                'default'           => '',
                'sort_order'        => ''
            ),
            'xd_checkout_field_comment'        => array(
                'display'           => '1',
                'required'          => '0',
                'default'           => '',
                'sort_order'        => ''
            ),
            'xd_checkout_coupon'                => '1',
            'xd_checkout_voucher'               => '1',
            'xd_checkout_reward'                => '1',
            'xd_checkout_cart'                  => '1',
            'xd_checkout_login_module'          => '1',
            'xd_checkout_html_header'           => array(),
            'xd_checkout_html_footer'           => array(),
            'xd_checkout_payment_module'        => '1',
            'xd_checkout_payment_reload'        => '0',
            'xd_checkout_payment'               => '1',
            'xd_checkout_payment_logo'          => array(),
            'xd_checkout_shipping_module'       => '1',
            'xd_checkout_shipping'              => '1',
            'xd_checkout_shipping_reload'       => '0',
            'xd_checkout_shipping_logo'         => array(),
            'xd_checkout_survey'                => '0',
            'xd_checkout_survey_required'       => '0',
            'xd_checkout_survey_text'           => array(),
            'xd_checkout_delivery'              => '0',
            'xd_checkout_delivery_time'         => '0',
            'xd_checkout_delivery_required'     => '0',
            'xd_checkout_delivery_unavailable'  => '"2025-10-31", "2025-08-11", "2025-12-25"',
            'xd_checkout_delivery_min'          => '1',
            'xd_checkout_delivery_max'          => '30',
            'xd_checkout_delivery_days_of_week' => ''
        );

        // Layout
        if (!$this->getLayout()) {
            $this->load->model('design/layout');

            $layout_data = array(
                'name'            => 'XD Checkout',
                'layout_route'    => array(
                    array(
                        'route'        => 'xd_checkout/checkout'
                    )
                )
            );

            $this->model_design_layout->addLayout($layout_data);
        }

        $this->load->model('setting/event');

        $this->model_setting_event->addEvent('module_xd_checkout', 'catalog/controller/checkout/checkout/before', 'extension/xd_checkout/checkout/eventPreControllerCheckoutCheckout');
        $this->model_setting_event->addEvent('module_xd_checkout', 'catalog/controller/checkout/success/before', 'extension/xd_checkout/checkout/eventPreControllerCheckoutSuccess');
    }

    public function uninstall()
    {
        if (!$this->user->hasPermission('modify', 'extension/extension/module')) {
            return;
        }

        if ($this->getLayout()) {
            $this->load->model('design/layout');

            $this->model_design_layout->deleteLayout($this->getLayout());
        }

        $this->load->model('setting/event');

        $this->model_setting_event->deleteEventByCode('module_xd_checkout');
    }

    private function getLayout()
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "layout_route WHERE route = 'xd_checkout/checkout'");

        if ($query->num_rows) {
            return $query->row['layout_id'];
        }

        return false;
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/module/' . $this->code)) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }
}

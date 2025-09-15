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

        // Default settings
        // {"status":"0","minimum_order":"","debug":"0","confirmation_page":"0","save_data":"0","edit_cart":"0","highlight_error":"0","text_error":"0","auto_submit":"0","payment_target":"","proceed_button_text":{"1":""},"load_screen":"0","loading_display":"0","layout":"1","responsive":"1","slide_effect":"0","custom_css":"","field_firstname":{"default":{"1":""},"placeholder":{"1":""},"sort_order":""},"field_lastname":{"default":{"1":""},"placeholder":{"1":""},"sort_order":""},"field_email":{"default":{"1":""},"placeholder":{"1":""},"sort_order":""},"field_telephone":{"default":{"1":""},"placeholder":{"1":""},"sort_order":""},"field_company":{"default":{"1":""},"placeholder":{"1":""},"sort_order":""},"field_customer_group":{"default":"1","sort_order":""},"field_address_1":{"default":{"1":""},"placeholder":{"1":""},"sort_order":""},"field_address_2":{"default":{"1":""},"placeholder":{"1":""},"sort_order":""},"field_city":{"default":{"1":""},"placeholder":{"1":""},"sort_order":""},"field_postcode":{"default":{"1":""},"placeholder":{"1":""},"sort_order":""},"field_country":{"default":"176","sort_order":""},"field_zone":{"sort_order":""},"field_newsletter":{"sort_order":""},"field_register":{"sort_order":""},"field_comment":{"default":{"1":""},"placeholder":{"1":""}},"coupon_module":"0","voucher_module":"0","reward_module":"0","cart_module":"0","login_module":"0","html_header":{"1":""},"html_footer":{"1":""},"payment_module":"0","payment_reload":"0","payment":"0","payment_default":"cod","payment_logo":{"cod":"","free_checkout":""},"shipping_module":"0","shipping_reload":"0","shipping":"0","shipping_default":"flat","shipping_logo":{"flat":""},"survey":"0","survey_required":"0","survey_text":{"1":""},"survey_type":"0","delivery":"0","delivery_time":"0","delivery_required":"0","delivery_unavailable":"","delivery_min":"","delivery_max":"","delivery_min_hour":"","delivery_max_hour":"","delivery_days_of_week":"","countdown":"0","countdown_start":"0","countdown_date_start":"","countdown_date_end":"","countdown_time":"","countdown_text":{"1":""}}
        $data = array(
            'status'                => '0',
            'minimum_order'         => '0',
            'debug'                 => '0',
            'confirmation_page'     => '1',
            'save_data'             => '1',
            'edit_cart'             => '0',
            'highlight_error'       => '0',
            'text_error'            => '0',
            'auto_submit'           => '1',
            'payment_target'        => '#button-confirm, .button, .btn',
            'load_screen'           => '1',
            'loading_display'       => '1',
            'layout'                => '2',
            'responsive'            => '1',
            'slide_effect'          => '0',
            'custom_css'            => '',
            'field_firstname'       => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => [],
                'sort_order'        => '1'
            ),
            'field_lastname'        => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => [],
                'sort_order'        => '2'
            ),
            'field_email'           => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => [],
                'sort_order'        => '3'
            ),
            'field_telephone'       => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => [],
                'sort_order'        => '4'
            ),
            'field_company'        => array(
                'display'           => '0',
                'required'          => '0',
                'default'           => [],
                'sort_order'        => '6'
            ),
            'field_customer_group'  => array(
                'display'           => '1',
                'required'          => '',
                'default'           => $this->config->get('config_customer_group_id'),
                'sort_order'        => '7'
            ),
            'field_address_1'       => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => [],
                'sort_order'        => '8'
            ),
            'field_address_2'        => array(
                'display'           => '0',
                'required'          => '0',
                'default'           => [],
                'sort_order'        => '9'
            ),
            'field_city'            => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => [],
                'sort_order'        => '10'
            ),
            'field_postcode'        => array(
                'display'           => '0',
                'required'          => '0',
                'default'           => [],
                'sort_order'        => '11'
            ),
            'field_country'         => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => ($this->config->get('config_country_id')) ?? 0,
                'sort_order'        => '12'
            ),
            'field_zone'            => array(
                'display'           => '1',
                'required'          => '1',
                'default'           => ($this->config->get('config_zone_id')) ?? 0,
                'sort_order'        => '13'
            ),
            'field_newsletter'      => array(
                'display'           => '0',
                'required'          => '0',
                'default'           => '0',
                'sort_order'        => '14'
            ),
            'field_register'        => array(
                'display'           => '0',
                'required'          => '0',
                'default'           => '0',
                'sort_order'        => '15'
            ),
            'field_comment'         => array(
                'display'           => '0',
                'required'          => '0',
                'default'           => [],
                'sort_order'        => '16'
            ),
            'coupon_module'         => '0',
            'voucher_module'        => '0',
            'reward_module'         => '0',
            'cart_module'           => '1',
            'login_module'          => '0',
            'html_header'           => [],
            'html_footer'           => [],
            'payment_module'        => '1',
            'payment_reload'        => '0',
            'payment'               => '1',
            'payment_logo'          => [],
            'shipping_module'       => '1',
            'shipping'              => '1',
            'shipping_reload'       => '0',
            'shipping_logo'         => [],
            'survey'                => '0',
            'survey_required'       => '0',
            'survey_text'           => [],
            'delivery'              => '0',
            'delivery_time'         => '0',
            'delivery_required'     => '0',
            'delivery_unavailable'  => '"2025-10-31", "2025-08-11", "2025-12-25"',
            'delivery_min'          => '1',
            'delivery_max'          => '30',
            'delivery_days_of_week' => ''
        );

        // $this->model_setting_setting->editSetting('xd_checkout', $data);
        $this->model_setting_setting->editSetting('xd_checkout', [
            'xd_checkout' => $data
        ]);


        // Layout
        if (!$this->getLayout()) {
            $this->load->model('design/layout');

            $layout_data = array(
                'name'            => 'XD Checkout',
                'layout_route'    => array(
                    array(
                        'route'        => 'xd_checkout/checkout',
                        'store_id'    => 0,
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

        // Remove default settings
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('xd_checkout');

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

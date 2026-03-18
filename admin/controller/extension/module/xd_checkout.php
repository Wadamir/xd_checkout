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
    protected $uploadCitiesDebugLogFile = 'xd_checkout.log';

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
        $data['upload_cities_init'] = $this->url->link('extension/module/xd_checkout/uploadCitiesInit', 'user_token=' . $this->session->data['user_token'], true);
        $data['upload_cities_batch'] = $this->url->link('extension/module/xd_checkout/uploadCitiesBatch', 'user_token=' . $this->session->data['user_token'], true);

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
            'cdek_client_id'        => '',
            'cdek_client_secret'    => '',
            'cdek_api_environment'  => 'prod',
            'confirmation_page'     => '1',
            'save_data'             => '0',
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

        $this->load->model('extension/module/xd_checkout');
        $this->model_extension_module_xd_checkout->ensureCitiesTable();


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

    // Upload Cities
    public function uploadCitiesInit()
    {
        $this->load->language('extension/module/xd_checkout');
        $this->prepareUploadCitiesRuntime();

        $json = $this->getDefaultUploadResponse();
        $this->writeUploadCitiesDebug('uploadCitiesInit:start');

        if (!$this->validateUploadCitiesRequest()) {
            $json['error'] = $this->language->get('error_permission');
            $this->writeUploadCitiesDebug('uploadCitiesInit:permission_denied');
            return $this->sendJson($json);
        }

        $country_codes = $this->getUploadCountryCodesFromRequest();

        if (!$country_codes) {
            $json['error'] = $this->language->get('text_upload_cities_country_required');
            $this->writeUploadCitiesDebug('uploadCitiesInit:no_countries_selected');
            return $this->sendJson($json);
        }

        $background_api_requested = !empty($this->request->post['use_api_refresh']);

        $cities = $this->readLocalCdekCitiesFile($error_message);

        if ($cities === false) {
            $json['error'] = $error_message;
            $this->writeUploadCitiesDebug('uploadCitiesInit:read_failed', array(
                'error' => $json['error']
            ));
            return $this->sendJson($json);
        }

        $cities = $this->filterCitiesByCountryCodes($cities, $country_codes);

        if (!$cities) {
            if ($background_api_requested) {
                $json['success'] = true;
                $json['total'] = 0;
                $json['processed'] = 0;
                $json['inserted'] = 0;
                $json['next_offset'] = 0;
                $json['done'] = true;
                $json['message'] = $this->language->get('text_upload_cities_no_rows_api_start');

                $this->writeUploadCitiesDebug('uploadCitiesInit:no_rows_after_filter_start_api', array(
                    'country_codes' => $country_codes
                ));

                $this->sendJson($json);

                if ($this->detachClientConnection()) {
                    $this->runBackgroundApiCitiesRefresh($country_codes);
                    exit;
                }

                $this->writeUploadCitiesDebug('backgroundSync:skip_detach_unavailable');
                return;
            }

            $json['success'] = true;
            $json['total'] = 0;
            $json['processed'] = 0;
            $json['inserted'] = 0;
            $json['next_offset'] = 0;
            $json['done'] = true;
            $json['message'] = $this->language->get('text_upload_cities_no_rows_recommend_api');

            $this->writeUploadCitiesDebug('uploadCitiesInit:no_rows_after_filter_recommend_api', array(
                'country_codes' => $country_codes
            ));

            return $this->sendJson($json);
        } else {
            $this->writeUploadCitiesDebug('uploadCitiesInit:rows_after_filter', array(
                'country_codes' => $country_codes,
                'rows_count' => count($cities)
            ));
        }

        $this->load->model('extension/module/xd_checkout');
        $this->model_extension_module_xd_checkout->ensureCitiesTable();
        $this->model_extension_module_xd_checkout->truncateCities();

        $this->session->data['xd_checkout_upload_cities_country_codes'] = $country_codes;
        $this->session->data['xd_checkout_upload_cities_use_api_refresh'] = $background_api_requested ? 1 : 0;

        $json['success'] = true;
        $json['total'] = count($cities);
        $json['processed'] = 0;
        $json['inserted'] = 0;
        $json['next_offset'] = 0;
        $json['done'] = false;
        $json['message'] = $this->language->get('text_upload_cities_uploading');

        $this->writeUploadCitiesDebug('uploadCitiesInit:success', array(
            'total' => $json['total'],
            'background_sync_planned' => $background_api_requested ? 1 : 0,
            'country_codes' => $country_codes
        ));

        return $this->sendJson($json);
    }

    public function uploadCitiesBatch()
    {
        $this->load->language('extension/module/xd_checkout');
        $this->prepareUploadCitiesRuntime();

        $json = $this->getDefaultUploadResponse();

        if (!$this->validateUploadCitiesRequest()) {
            $json['error'] = $this->language->get('error_permission');
            $this->writeUploadCitiesDebug('uploadCitiesBatch:permission_denied');
            return $this->sendJson($json);
        }

        $country_codes = $this->getUploadCountryCodesFromRequest();

        if (!$country_codes && isset($this->session->data['xd_checkout_upload_cities_country_codes']) && is_array($this->session->data['xd_checkout_upload_cities_country_codes'])) {
            $country_codes = $this->getUploadCountryCodesFromArray($this->session->data['xd_checkout_upload_cities_country_codes']);
        }

        if (!$country_codes) {
            $json['error'] = $this->language->get('text_upload_cities_country_required');
            $this->writeUploadCitiesDebug('uploadCitiesBatch:no_countries_selected');
            return $this->sendJson($json);
        }

        $background_api_requested = isset($this->request->post['use_api_refresh'])
            ? !empty($this->request->post['use_api_refresh'])
            : (!empty($this->session->data['xd_checkout_upload_cities_use_api_refresh']));

        $cities = $this->readLocalCdekCitiesFile($error_message);

        if ($cities === false) {
            $json['error'] = $error_message;
            $this->writeUploadCitiesDebug('uploadCitiesBatch:read_failed', array(
                'error' => $json['error']
            ));
            return $this->sendJson($json);
        }

        $cities = $this->filterCitiesByCountryCodes($cities, $country_codes);

        if (!$cities) {
            $json['error'] = $this->language->get('text_upload_cities_no_rows_for_selected_countries');
            $this->writeUploadCitiesDebug('uploadCitiesBatch:no_rows_after_filter', array(
                'country_codes' => $country_codes
            ));
            return $this->sendJson($json);
        }

        $total = count($cities);
        $offset = isset($this->request->post['offset']) ? (int)$this->request->post['offset'] : (isset($this->request->get['offset']) ? (int)$this->request->get['offset'] : 0);
        $limit = isset($this->request->post['limit']) ? (int)$this->request->post['limit'] : (isset($this->request->get['limit']) ? (int)$this->request->get['limit'] : 500);

        $offset = max(0, $offset);
        $limit = max(1, min(2000, $limit));

        // $this->writeUploadCitiesDebug('uploadCitiesBatch:start', array(
        //     'offset' => $offset,
        //     'limit' => $limit,
        //     'total' => $total
        // ));

        if ($offset >= $total || $total === 0) {
            $json['success'] = true;
            $json['total'] = $total;
            $json['processed'] = $total;
            $json['inserted'] = 0;
            $json['next_offset'] = $total;
            $json['done'] = true;
            $json['message'] = $background_api_requested
                ? $this->language->get('text_upload_cities_local_background_sync')
                : $this->language->get('text_upload_cities_completed');

            unset($this->session->data['xd_checkout_upload_cities_country_codes']);
            unset($this->session->data['xd_checkout_upload_cities_use_api_refresh']);

            $this->writeUploadCitiesDebug('uploadCitiesBatch:already_done', array(
                'offset' => $offset,
                'total' => $total,
                'country_codes' => $country_codes,
                'background_sync_planned' => $background_api_requested ? 1 : 0
            ));

            $this->sendJson($json);

            if ($background_api_requested && $this->detachClientConnection()) {
                $this->runBackgroundApiCitiesRefresh($country_codes);
                exit;
            }

            if ($background_api_requested) {
                $this->writeUploadCitiesDebug('backgroundSync:skip_detach_unavailable');
            }

            return;
        }

        $batch = array_slice($cities, $offset, $limit);

        $this->load->model('extension/module/xd_checkout');
        $this->model_extension_module_xd_checkout->ensureCitiesTable();
        $inserted = $this->model_extension_module_xd_checkout->insertCitiesBatch($batch);

        $processed = min($total, $offset + count($batch));
        $done = ($processed >= $total);

        $json['success'] = true;
        $json['total'] = $total;
        $json['processed'] = $processed;
        $json['inserted'] = $inserted;
        $json['next_offset'] = $processed;
        $json['done'] = $done;
        $json['message'] = $done
            ? ($background_api_requested ? $this->language->get('text_upload_cities_local_background_sync') : $this->language->get('text_upload_cities_completed'))
            : $this->language->get('text_upload_cities_uploading');

        if ($done) {
            unset($this->session->data['xd_checkout_upload_cities_country_codes']);
            unset($this->session->data['xd_checkout_upload_cities_use_api_refresh']);

            $this->writeUploadCitiesDebug('uploadCitiesBatch:success', array(
                'processed' => $processed,
                'total' => $total,
                'done' => $done,
                'country_codes' => $country_codes,
                'background_sync_planned' => $background_api_requested ? 1 : 0
            ));

            $this->sendJson($json);

            if ($background_api_requested && $this->detachClientConnection()) {
                $this->runBackgroundApiCitiesRefresh($country_codes);
                exit;
            }

            if ($background_api_requested) {
                $this->writeUploadCitiesDebug('backgroundSync:skip_detach_unavailable');
            }

            return;
        }

        return $this->sendJson($json);
    }

    private function getDefaultUploadResponse()
    {
        return array(
            'success' => false,
            'error' => '',
            'message' => '',
            'total' => 0,
            'processed' => 0,
            'inserted' => 0,
            'next_offset' => 0,
            'done' => false
        );
    }

    private function importCitiesToDatabase($cities, $limit = 1000)
    {
        if (!is_array($cities)) {
            return 0;
        }

        $this->load->model('extension/module/xd_checkout');
        $this->model_extension_module_xd_checkout->ensureCitiesTable();
        $this->model_extension_module_xd_checkout->truncateCities();

        $total = count($cities);

        if ($total === 0) {
            return 0;
        }

        $limit = max(1, (int)$limit);
        $inserted = 0;

        for ($offset = 0; $offset < $total; $offset += $limit) {
            $batch = array_slice($cities, $offset, $limit);

            if (!$batch) {
                continue;
            }

            $inserted += (int)$this->model_extension_module_xd_checkout->insertCitiesBatch($batch);
        }

        return $inserted;
    }

    private function runBackgroundApiCitiesRefresh($country_codes = array())
    {
        $this->prepareUploadCitiesRuntime();

        $this->writeUploadCitiesDebug('backgroundSync:start', array(
            'country_codes' => $country_codes
        ));

        if (!$this->syncCdekCitiesFile($sync_message, $error_message, $country_codes)) {
            $this->writeUploadCitiesDebug('backgroundSync:sync_failed', array(
                'error' => $error_message
            ));

            return;
        }

        $cities = $this->readLocalCdekCitiesFile($read_error);

        if ($cities === false) {
            $this->writeUploadCitiesDebug('backgroundSync:read_failed', array(
                'error' => $read_error
            ));

            return;
        }

        $cities = $this->filterCitiesByCountryCodes($cities, $country_codes);

        if (!$cities) {
            $this->writeUploadCitiesDebug('backgroundSync:no_rows_after_filter', array(
                'country_codes' => $country_codes
            ));
            return;
        }

        $inserted = $this->importCitiesToDatabase($cities, 1000);

        $this->writeUploadCitiesDebug('backgroundSync:success', array(
            'total' => count($cities),
            'inserted' => $inserted,
            'sync_message' => $sync_message,
            'country_codes' => $country_codes
        ));
    }

    private function detachClientConnection()
    {
        ignore_user_abort(true);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (function_exists('session_write_close')) {
            @session_write_close();
        }

        if (!function_exists('fastcgi_finish_request')) {
            return false;
        }

        if (method_exists($this->response, 'output')) {
            $this->response->output();
        }

        fastcgi_finish_request();

        return true;
    }

    private function validateUploadCitiesRequest()
    {
        return $this->user->hasPermission('modify', 'extension/module/' . $this->code);
    }

    private function readCdekCitiesFile(&$error_message = '')
    {
        $updater = $this->getCdekCityUpdater();
        $cities = $updater->readCitiesFile($error_message);

        if ($cities === false) {
            $prefix = $this->language->get('text_upload_cities_file_unavailable');
            $error_message = $error_message !== '' ? ($prefix . ': ' . $error_message) : $prefix;
        }

        return $cities;
    }

    private function readLocalCdekCitiesFile(&$error_message = '')
    {
        $error_message = '';
        $file = DIR_SYSTEM . 'library/xd_checkout/cdek_city.json';

        if (!is_file($file)) {
            $error_message = $this->language->get('text_upload_cities_file_unavailable');
            return false;
        }

        $content = file_get_contents($file);

        if ($content === false || $content === '') {
            $error_message = $this->language->get('text_upload_cities_file_unavailable');
            return false;
        }

        $cities = json_decode($content, true);

        if (!is_array($cities)) {
            $error_message = $this->language->get('text_upload_cities_file_unavailable');
            return false;
        }

        return $cities;
    }

    private function syncCdekCitiesFile(&$message = '', &$error_message = '', $country_codes = array())
    {
        $updater = $this->getCdekCityUpdater();
        $result = $updater->syncFromApi($message, $error_message, $country_codes);

        if (!$result && $error_message === '') {
            $error_message = $this->language->get('text_upload_cities_file_unavailable');
        }

        // $this->writeUploadCitiesDebug('syncCdekCitiesFile:result', array(
        //     'success' => $result ? 1 : 0,
        //     'message' => $message,
        //     'error' => $error_message,
        //     'country_codes' => $country_codes
        // ));

        return $result;
    }

    private function getUploadCountryCodesFromRequest()
    {
        $codes = isset($this->request->post['country_codes']) ? $this->request->post['country_codes'] : array();

        return $this->getUploadCountryCodesFromArray($codes);
    }

    private function getUploadCountryCodesFromArray($codes)
    {

        if (!is_array($codes)) {
            if (is_string($codes) && strpos($codes, ',') !== false) {
                $codes = preg_split('/\s*,\s*/', $codes, -1, PREG_SPLIT_NO_EMPTY);
            } else {
                $codes = array($codes);
            }
        }

        $allowed = array(
            'RU' => true,
            'BY' => true,
            'KZ' => true
        );

        $result = array();

        foreach ($codes as $code) {
            $code = strtoupper(trim((string)$code));

            if ($code !== '' && isset($allowed[$code])) {
                $result[$code] = $code;
            }
        }

        return array_values($result);
    }

    private function filterCitiesByCountryCodes($cities, $country_codes)
    {
        if (!is_array($cities)) {
            return array();
        }

        if (!is_array($country_codes) || !$country_codes) {
            return $cities;
        }

        $allowed = array_fill_keys($country_codes, true);
        $result = array();

        foreach ($cities as $city) {
            if (!is_array($city)) {
                continue;
            }

            $city_country_code = $this->resolveCityCountryCode($city);

            if ($city_country_code !== '' && isset($allowed[$city_country_code])) {
                $result[] = $city;
            }
        }

        return $result;
    }

    private function resolveCityCountryCode($city)
    {
        if (isset($city['countryCode'])) {
            $code = strtoupper(trim((string)$city['countryCode']));

            if ($code !== '') {
                return $code;
            }
        }

        if (isset($city['country_code'])) {
            $code = strtoupper(trim((string)$city['country_code']));

            if ($code !== '') {
                return $code;
            }
        }

        if (isset($city['countryName'])) {
            $code = $this->inferCountryCodeFromText($city['countryName']);

            if ($code !== '') {
                return $code;
            }
        }

        if (isset($city['name'])) {
            $code = $this->inferCountryCodeFromText($city['name']);

            if ($code !== '') {
                return $code;
            }
        }

        return '';
    }

    private function inferCountryCodeFromText($value)
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);

        if (strpos($lower, 'russia') !== false || strpos($lower, 'росс') !== false) {
            return 'RU';
        }

        if (strpos($lower, 'belarus') !== false || strpos($lower, 'белар') !== false || strpos($lower, 'белорус') !== false) {
            return 'BY';
        }

        if (strpos($lower, 'kazakh') !== false || strpos($lower, 'казах') !== false || strpos($lower, 'казахстан') !== false) {
            return 'KZ';
        }

        return '';
    }

    private function prepareUploadCitiesRuntime()
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '768M');
        }
    }

    private function getCdekCityUpdater()
    {
        require_once(DIR_SYSTEM . 'library/xd_checkout/cdek_city_updater.php');

        return new XdCheckoutCdekCityUpdater($this->registry);
    }

    private function sendJson($json)
    {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');

        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $options |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $payload = json_encode($json, $options);

        if ($payload === false) {
            $fallback = array(
                'success' => false,
                'error' => 'JSON encode failed: ' . json_last_error_msg(),
                'message' => '',
                'total' => 0,
                'processed' => 0,
                'inserted' => 0,
                'next_offset' => 0,
                'done' => true
            );

            $this->writeUploadCitiesDebug('sendJson:encode_failed', array(
                'json_error' => json_last_error_msg()
            ));

            $payload = json_encode($fallback);
        }

        $this->response->setOutput($payload);
    }

    private function isUploadCitiesDebugEnabled()
    {
        $settings = $this->config->get('xd_checkout');

        if (!is_array($settings) || !isset($settings['debug'])) {
            return false;
        }

        return !empty($settings['debug']);
    }

    private function writeUploadCitiesDebug($event, $context = array())
    {
        if (!$this->isUploadCitiesDebugEnabled()) {
            return;
        }

        static $logger = null;

        if ($logger === null) {
            $logger = new Log($this->uploadCitiesDebugLogFile);
        }

        $line = '[xd_checkout][cities_import][' . $event . ']';

        if ($context) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $line .= ' ' . ($json !== false ? $json : 'context_encode_failed');
        }

        $logger->write($line);
    }
}

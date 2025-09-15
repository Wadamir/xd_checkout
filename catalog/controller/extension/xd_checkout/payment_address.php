<?php
class ControllerExtensionXdCheckoutPaymentAddress extends Controller
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

        // All variables
        $xd_checkout_settings = $this->xd_checkout_settings;

        if (isset($this->session->data['payment_address']['address_id'])) {
            $data['address_id'] = $this->session->data['payment_address']['address_id'];
        } else {
            $data['address_id'] = $this->customer->getAddressId();
        }

        $data['addresses'] = array();

        $this->load->model('account/address');

        $data['addresses'] = $this->model_account_address->getAddresses();

        if (isset($this->session->data['payment_address']['company'])) {
            $data['company'] = $this->session->data['payment_address']['company'];
        } else {
            $data['company'] = '';
        }

        if (isset($this->session->data['payment_address']['address_1'])) {
            $data['address_1'] = $this->session->data['payment_address']['address_1'];
        } else {
            $data['address_1'] = '';
        }

        if (isset($this->session->data['payment_address']['address_2'])) {
            $data['address_2'] = $this->session->data['payment_address']['address_2'];
        } else {
            $data['address_2'] = '';
        }

        if (isset($this->session->data['payment_address']['postcode'])) {
            $data['postcode'] = $this->session->data['payment_address']['postcode'];
        } elseif (isset($this->session->data['shipping_address']['postcode'])) {
            $data['postcode'] = $this->session->data['shipping_address']['postcode'];
        } else {
            $data['postcode'] = '';
        }

        if (isset($this->session->data['payment_address']['city'])) {
            $data['city'] = $this->session->data['payment_address']['city'];
        } else {
            $data['city'] = '';
        }

        if (isset($this->session->data['payment_address']['country_id'])) {
            $data['country_id'] = $this->session->data['payment_address']['country_id'];
        } elseif (isset($this->session->data['shipping_address']['country_id'])) {
            $data['country_id'] = $this->session->data['shipping_address']['country_id'];
        } else {
            $country = $this->xd_checkout_settings['field_country'];

            $data['country_id'] = isset($country['default']) ? $country['default'] : 0;
        }

        if (isset($this->session->data['payment_address']['zone_id'])) {
            $data['zone_id'] = $this->session->data['payment_address']['zone_id'];
        } elseif (isset($this->session->data['shipping_address']['zone_id'])) {
            $data['zone_id'] = $this->session->data['shipping_address']['zone_id'];
        } else {
            $zone = $this->xd_checkout_settings['field_zone'];

            $data['zone_id'] = isset($zone['default']) ? $zone['default'] : 0;
        }

        $this->load->model('localisation/country');

        $data['countries'] = $this->model_localisation_country->getCountries();

        $data['zones'] = array();
        if ($data['country_id']) {
            $this->load->model('localisation/zone');
            $data['zones'] = $this->model_localisation_zone->getZonesByCountryId($data['country_id']);
        }

        // Custom Fields
        $this->load->model('account/custom_field');

        $custom_fields = $this->model_account_custom_field->getCustomFields();
        $account_custom_fields = array_filter($custom_fields, function ($v) {
            return $v['location'] === 'address';
        });
        $data['custom_fields'] = array_values($account_custom_fields);

        if (isset($this->session->data['payment_address']['custom_field'])) {
            $data['payment_address_custom_field'] = $this->session->data['payment_address']['custom_field'];
        } else {
            $data['payment_address_custom_field'] = array();
        }

        // Fields Address
        $fields_address = array(
            'postcode',
            'country',
            'zone',
            'city',
            'address_1',
            'address_2',
        );

        // All variables
        $data['debug'] = $xd_checkout_settings['debug'];

        foreach ($fields_address as $key => $field) {
            $field_name = 'field_' . $field;
            $field_data = $this->xd_checkout_settings[$field_name];

            $field_data['name'] = 'field_' . $field;
            $field_data['display'] = !empty($field_data['display']) && ($field_data['display'] == 'on' || $field_data['display'] === true || $field_data['display'] === '1') ? true : false;
            $field_data['required'] = !empty($field_data['required']) && ($field_data['required'] == 'on' || $field_data['required'] === true || $field_data['required'] === '1') ? true : false;
            $field_data['sort_order'] = $key + 10;
            $field_data['default'] = !empty($field_data['default'][$this->config->get('config_language_id')]) ? $field_data['default'][$this->config->get('config_language_id')] : '';
            $field_data['placeholder'] = !empty($field_data['placeholder'][$this->config->get('config_language_id')]) ? $field_data['placeholder'][$this->config->get('config_language_id')] : '';
            $field_data['entry'] = $this->language->get('entry_' . $field);

            $data['fields_address']['field_' . $field] = $field_data;
        }

        return $this->load->view('extension/xd_checkout/payment_address', $data);
    }

    public function validate()
    {
        $this->load->language('checkout/checkout');
        $this->load->language('extension/xd_checkout/checkout');

        $xd_checkout_settings = $this->xd_checkout_settings;

        $json = array();

        // Validate if customer is logged in.
        if (!$this->customer->isLogged()) {
            // $json['redirect'] = $this->url->link('xd_checkout/checkout', '', true);
        }

        if (!$json) {
            if (isset($this->request->post['payment_address']) && $this->request->post['payment_address'] == 'existing') {
                $this->load->model('account/address');

                if (empty($this->request->post['address_id'])) {
                    $json['error']['warning'] = $this->language->get('error_address');
                } elseif (!$this->model_account_address->getAddress($this->request->post['address_id'])) {
                    $json['error']['warning'] = $this->language->get('error_address');
                }

                if (!$json) {
                    // Default Payment Address
                    $this->session->data['payment_address'] = $this->model_account_address->getAddress($this->request->post['address_id']);
                }
            }

            if ($this->request->post['payment_address'] == 'new') {
                // $firstname = $xd_checkout_settings['field_firstname'];

                // if (!empty($firstname['required'])) {
                //     if ((utf8_strlen($this->request->post['firstname']) < 1) || (utf8_strlen($this->request->post['firstname']) > 32)) {
                //         $json['error']['firstname'] = $this->language->get('error_firstname');
                //     }
                // }

                // $lastname = $xd_checkout_settings['field_lastname'];

                // if (!empty($lastname['required'])) {
                //     if ((utf8_strlen($this->request->post['lastname']) < 1) || (utf8_strlen($this->request->post['lastname']) > 32)) {
                //         $json['error']['lastname'] = $this->language->get('error_lastname');
                //     }
                // }

                $address_1 = $xd_checkout_settings['field_address_1'];

                if (!empty($address_1['required'])) {
                    if ((utf8_strlen($this->request->post['address_1']) < 3) || (utf8_strlen($this->request->post['address_1']) > 64)) {
                        $json['error']['address_1'] = $this->language->get('error_address_1');
                    }
                }

                $address_2 = $xd_checkout_settings['field_address_2'];

                if (!empty($address_2['required'])) {
                    if ((utf8_strlen($this->request->post['address_2']) < 3) || (utf8_strlen($this->request->post['address_2']) > 64)) {
                        $json['error']['address_2'] = $this->language->get('error_address_2');
                    }
                }

                $company = $xd_checkout_settings['field_company'];

                if (!empty($company['required'])) {
                    if ((utf8_strlen($this->request->post['company']) < 3) || (utf8_strlen($this->request->post['company']) > 64)) {
                        $json['error']['company'] = $this->language->get('error_company');
                    }
                }

                $city = $xd_checkout_settings['field_city'];

                if (!empty($city['required'])) {
                    if ((utf8_strlen($this->request->post['city']) < 2) || (utf8_strlen($this->request->post['city']) > 128)) {
                        $json['error']['city'] = $this->language->get('error_city');
                    }
                }

                $this->load->model('localisation/country');

                $country_info = $this->model_localisation_country->getCountry($this->request->post['country_id']);

                if ($country_info) {
                    if ($country_info['postcode_required']) {
                        if (utf8_strlen($this->request->post['postcode']) < 2 || (utf8_strlen($this->request->post['postcode']) > 10)) {
                            $json['error']['postcode'] = $this->language->get('error_postcode');
                        }
                    }
                }

                $country = $xd_checkout_settings['field_country'];

                if (!empty($country['required'])) {
                    if ($this->request->post['country_id'] == '') {
                        $json['error']['country'] = $this->language->get('error_country');
                    }
                }

                $zone = $xd_checkout_settings['field_zone'];

                if (!empty($zone['required'])) {
                    if ($this->request->post['zone_id'] == '') {
                        $json['error']['zone'] = $this->language->get('error_zone');
                    }
                }

                // Custom field validation
                $this->load->model('account/custom_field');

                $custom_fields = $this->model_account_custom_field->getCustomFields($this->config->get('config_customer_group_id'));

                foreach ($custom_fields as $custom_field) {
                    if ($custom_field['location'] == 'address' && $custom_field['required'] && empty($this->request->post['custom_field'][$custom_field['location']][$custom_field['custom_field_id']])) {
                        $json['error']['custom_field' . $custom_field['custom_field_id']] = sprintf($this->language->get('error_custom_field'), $custom_field['name']);
                    }
                }

                if (!$json) {
                    // Prepare address data for adding new address
                    $address_data = array(
                        'firstname'      => $this->customer->getFirstName(),
                        'lastname'       => $this->customer->getLastName(),
                        'company'        => ($this->request->post['company']) ?? '',
                        'address_1'      => $this->request->post['address_1'],
                        'address_2'      => ($this->request->post['address_2']) ?? '',
                        'postcode'       => ($this->request->post['postcode']) ?? '',
                        'city'           => ($this->request->post['city']) ?? '',
                        'zone_id'        => $this->request->post['zone_id'],
                        'country_id'     => $this->request->post['country_id'],
                        'custom_field'   => isset($this->request->post['custom_field']['address']) ? $this->request->post['custom_field']['address'] : array(),
                    );

                    // Default Payment Address
                    $this->load->model('account/address');

                    $address_id = $this->model_account_address->addAddress($this->customer->getId(), $address_data);

                    $this->session->data['payment_address'] = $this->model_account_address->getAddress($address_id);

                    if ($this->config->get('config_customer_activity')) {
                        $this->load->model('account/activity');

                        $activity_data = array(
                            'customer_id' => $this->customer->getId(),
                            'name'        => $this->customer->getfirstname() . ' ' . $this->customer->getlastname()
                        );

                        $this->model_account_activity->addActivity('address_add', $activity_data);
                    }
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}

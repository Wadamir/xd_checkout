<?php
class ControllerExtensionXdCheckoutGuest extends Controller
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

        $data['guest_checkout'] = ($this->config->get('config_checkout_guest') && !$this->config->get('config_customer_price') && !$this->cart->hasDownload());

        $data['customer_groups'] = array();

        if (is_array($this->config->get('config_customer_group_display'))) {
            $this->load->model('account/customer_group');

            $customer_groups = $this->model_account_customer_group->getCustomerGroups();

            foreach ($customer_groups as $customer_group) {
                if (in_array($customer_group['customer_group_id'], $this->config->get('config_customer_group_display'))) {
                    $data['customer_groups'][] = $customer_group;
                }
            }
        }

        if (isset($this->session->data['guest']['customer_group_id'])) {
            $data['customer_group_id'] = $this->session->data['guest']['customer_group_id'];
        } else {
            $data['customer_group_id'] = $this->config->get('config_customer_group_id');
        }

        if (isset($this->session->data['guest']['firstname'])) {
            $data['firstname'] = $this->session->data['guest']['firstname'];
        } else {
            $data['firstname'] = '';
        }

        if (isset($this->session->data['guest']['lastname'])) {
            $data['lastname'] = $this->session->data['guest']['lastname'];
        } else {
            $data['lastname'] = '';
        }

        if (isset($this->session->data['guest']['telephone'])) {
            $data['telephone'] = $this->session->data['guest']['telephone'];
        } else {
            $data['telephone'] = '';
        }

        if (isset($this->session->data['guest']['email'])) {
            $data['email'] = $this->session->data['guest']['email'];
        } else {
            $data['email'] = '';
        }

        // Customer Group
        $default_customer_group_id = $this->config->get('config_customer_group_id');
        if (isset($this->session->data['guest']['customer_group_id'])) {
            $data['customer_group_id'] = $this->session->data['guest']['customer_group_id'];
        } else {
            $data['customer_group_id'] = $default_customer_group_id;
        }


        // Custom Fields
        $this->load->model('account/custom_field');

        $custom_fields = $this->model_account_custom_field->getCustomFields();
        $account_custom_fields = array_filter($custom_fields, function ($v) {
            return $v['location'] === 'account';
        });
        $data['custom_fields'] = array_values($account_custom_fields);

        if (isset($this->session->data['guest']['custom_field'])) {
            $data['guest_custom_field'] = $this->session->data['guest']['custom_field'];
        } else {
            $data['guest_custom_field'] = array();
        }

        $data['shipping_required'] = $this->cart->hasShipping();

        if (isset($this->session->data['guest']['shipping_address'])) {
            $data['shipping_address'] = $this->session->data['guest']['shipping_address'];
        } else {
            $data['shipping_address'] = true;
        }

        $field_register = $this->xd_checkout_settings['field_register'];

        if (isset($this->session->data['guest']['create_account'])) {
            $data['create_account'] = $this->session->data['guest']['create_account'];
        } elseif (!empty($field_register['default'])) {
            $data['create_account'] = true;
        } else {
            $data['create_account'] = false;
        }

        // Fields Personal Data
        $fields_personal = array(
            'firstname',
            'lastname',
            'telephone',
            'email',
            'customer_group',
            'company',
        );

        // All variables
        $data['debug'] = $this->xd_checkout_settings['debug'];
        $data['field_register'] = $this->xd_checkout_settings['field_register'];

        foreach ($fields_personal as $key => $field) {
            $field_name = 'field_' . $field;
            $field_data = $this->xd_checkout_settings[$field_name];

            $field_data['name'] = 'field_' . $field;
            $field_data['display'] = !empty($field_data['display']) && ($field_data['display'] == 'on' || $field_data['display'] === true || $field_data['display'] === '1') ? true : false;
            $field_data['required'] = !empty($field_data['required']) && ($field_data['required'] == 'on' || $field_data['required'] === true || $field_data['required'] === '1') ? true : false;
            $field_data['sort_order'] = $key;
            $field_data['default'] = !empty($field_data['default'][$this->config->get('config_language_id')]) ? $field_data['default'][$this->config->get('config_language_id')] : '';
            $field_data['placeholder'] = !empty($field_data['placeholder'][$this->config->get('config_language_id')]) ? $field_data['placeholder'][$this->config->get('config_language_id')] : '';
            $field_data['entry'] = $this->language->get('entry_' . $field);

            $data['fields_personal']['field_' . $field] = $field_data;
        }

        $data['register'] = $this->load->controller('xd_checkout/register');

        return $this->load->view('extension/xd_checkout/guest', $data);
    }

    public function validate()
    {
        $this->load->language('checkout/checkout');
        $this->load->language('extension/xd_checkout/checkout');

        $json = array();

        // Validate if customer is logged in.
        if ($this->customer->isLogged()) {
            $json['redirect'] = $this->url->link('xd_checkout/checkout', '', true);
        }

        // All variables
        $xd_checkout_settings = $this->xd_checkout_settings;

        if (!$json) {
            $firstname = $xd_checkout_settings['field_firstname'];
            if (!empty($firstname['required'])) {
                if ((utf8_strlen($this->request->post['firstname']) < 2) || (utf8_strlen($this->request->post['firstname']) > 32)) {
                    $json['error']['firstname'] = $this->language->get('error_firstname');
                }
            }

            $lastname = $xd_checkout_settings['field_lastname'];
            if (!empty($lastname['required'])) {
                if ((utf8_strlen($this->request->post['lastname']) < 2) || (utf8_strlen($this->request->post['lastname']) > 32)) {
                    $json['error']['lastname'] = $this->language->get('error_lastname');
                }
            }

            $email = $xd_checkout_settings['field_email'];
            if (!empty($email['required'])) {
                if ((utf8_strlen($this->request->post['email']) > 96) || !filter_var($this->request->post['email'], FILTER_VALIDATE_EMAIL)) {
                    $json['error']['email'] = $this->language->get('error_email');
                }
            } else {
                // Generate random email address to stop OpenCart email error
                if (empty($this->request->post['email'])) {
                    $this->request->post['email'] = substr(uniqid('', true), -10) . '@example.com';
                } else {
                    if ((utf8_strlen($this->request->post['email']) > 96) || !filter_var($this->request->post['email'], FILTER_VALIDATE_EMAIL)) {
                        $json['error']['email'] = $this->language->get('error_email');
                    }
                }
            }

            $telephone = $xd_checkout_settings['field_telephone'];
            if (!empty($telephone['required'])) {
                $telephone_value = trim($this->request->post['telephone']);
                $digits_only = preg_replace('/\D/', '', $telephone_value); // Remove non-digit characters

                if (utf8_strlen($digits_only) < 8 || utf8_strlen($digits_only) > 15) {
                    $json['error']['telephone'] = $this->language->get('error_telephone');
                } elseif (!preg_match('/^\+?[0-9\s\-\(\)]+$/', $telephone_value)) {
                    $json['error']['telephone'] = $this->language->get('error_telephone');
                }
            }


            /*
            $company = $xd_checkout_settings['field_company'];

            if (!empty($company['required'])) {
                if ((utf8_strlen($this->request->post['company']) < 3) || (utf8_strlen($this->request->post['company']) > 32)) {
                    $json['error']['company'] = $this->language->get('error_company');
                }
            }

            $address_1 = $xd_checkout_settings['field_address_1'];

            if (!empty($address_1['required'])) {
                if ((utf8_strlen($this->request->post['address_1']) < 3) || (utf8_strlen($this->request->post['address_1']) > 128)) {
                    $json['error']['address_1'] = $this->language->get('error_address_1');
                }
            }

            $address_2 = $xd_checkout_settings['field_address_2'];

            if (!empty($address_2['required'])) {
                if ((utf8_strlen($this->request->post['address_2']) < 3) || (utf8_strlen($this->request->post['address_2']) > 128)) {
                    $json['error']['address_2'] = $this->language->get('error_address_2');
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
                if ($country_info['postcode_required'] && (utf8_strlen($this->request->post['postcode']) < 2) || (utf8_strlen($this->request->post['postcode']) > 10)) {
                    $json['error']['postcode'] = $this->language->get('error_postcode');
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
            */

            // Customer Group
            $customer_group = $xd_checkout_settings['field_customer_group'];
            if (!empty($customer_group['required'])) {
                if (isset($this->request->post['customer_group_id']) && is_array($this->config->get('config_customer_group_display')) && in_array($this->request->post['customer_group_id'], $this->config->get('config_customer_group_display'))) {
                    $customer_group_id = $this->request->post['customer_group_id'];
                } else {
                    $customer_group_id = $this->config->get('config_customer_group_id');
                }
            } else {
                $customer_group_id = $this->config->get('config_customer_group_id');
            }

            // Custom field validation
            $this->load->model('account/custom_field');

            $custom_fields = $this->model_account_custom_field->getCustomFields($customer_group_id);
            $custom_fields = array_filter($custom_fields, function ($v) {
                return $v['location'] === 'account';
            });

            foreach ($custom_fields as $custom_field) {
                if ($custom_field['required'] && empty($this->request->post['custom_field'][$custom_field['location']][$custom_field['custom_field_id']])) {
                    $json['error']['input-account_custom-field' . $custom_field['custom_field_id']] = sprintf($this->language->get('error_custom_field'), $custom_field['name']);
                }
            }
        }

        if (!$json) {
            $this->session->data['account'] = 'guest';

            $this->session->data['guest']['customer_group_id'] = $customer_group_id;

            $current_language_id = $this->config->get('config_language_id');

            $field_firstname = $xd_checkout_settings['field_firstname'];
            if (isset($this->request->post['firstname'])) {
                $this->session->data['guest']['firstname'] = $this->request->post['firstname'];
            } elseif (!empty($field_firstname['default'])) {
                $this->session->data['guest']['firstname'] = $field_firstname['default'][$current_language_id];
            } else {
                $this->session->data['guest']['firstname'] = '';
            }

            $field_lastname = $xd_checkout_settings['field_lastname'];
            if (isset($this->request->post['lastname'])) {
                $this->session->data['guest']['lastname'] = $this->request->post['lastname'];
            } elseif (!empty($field_lastname['default'])) {
                $this->session->data['guest']['lastname'] = $field_lastname['default'][$current_language_id];
            } else {
                $this->session->data['guest']['lastname'] = '';
            }

            $field_email = $xd_checkout_settings['field_email'];
            if (isset($this->request->post['email'])) {
                $this->session->data['guest']['email'] = $this->request->post['email'];
            } elseif (!empty($field_email['default'])) {
                $this->session->data['guest']['email'] = $field_email['default'][$current_language_id];
            } else {
                // Generate random email address to stop OpenCart email error
                $this->session->data['guest']['email'] = substr(uniqid('', true), -10) . '@example.com';
            }

            $field_telephone = $xd_checkout_settings['field_telephone'];
            if (isset($this->request->post['telephone'])) {
                $this->session->data['guest']['telephone'] = $this->request->post['telephone'];
            } elseif (!empty($field_telephone['default'])) {
                $this->session->data['guest']['telephone'] = $field_telephone['default'][$current_language_id];
            } else {
                $this->session->data['guest']['telephone'] = '';
            }

            if (isset($this->request->post['custom_field']['account'])) {
                $this->session->data['guest']['custom_field'] = $this->request->post['custom_field']['account'];
            } else {
                $this->session->data['guest']['custom_field'] = array();
            }

            $this->session->data['payment_address']['firstname'] = $this->session->data['guest']['firstname'];
            $this->session->data['payment_address']['lastname'] = $this->session->data['guest']['lastname'];
            $this->session->data['payment_address']['email'] = $this->session->data['guest']['email'];
            $this->session->data['payment_address']['telephone'] = $this->session->data['guest']['telephone'];

            $field_company = $xd_checkout_settings['field_company'];
            if (isset($this->request->post['company'])) {
                $this->session->data['payment_address']['company'] = $this->request->post['company'];
            } elseif (!empty($field_company['default'])) {
                $this->session->data['payment_address']['company'] = $field_company['default'][$current_language_id];
            } else {
                $this->session->data['payment_address']['company'] = '';
            }

            $field_address_1 = $xd_checkout_settings['field_address_1'];
            if (isset($this->request->post['address_1'])) {
                $this->session->data['payment_address']['address_1'] = $this->request->post['address_1'];
            } elseif (!empty($field_address_1['default'])) {
                $this->session->data['payment_address']['address_1'] = $field_address_1['default'][$current_language_id];
            } else {
                $this->session->data['payment_address']['address_1'] = '';
            }

            $field_address_2 = $xd_checkout_settings['field_address_2'];
            if (isset($this->request->post['address_2'])) {
                $this->session->data['payment_address']['address_2'] = $this->request->post['address_2'];
            } elseif (!empty($field_address_2['default'])) {
                $this->session->data['payment_address']['address_2'] = $field_address_2['default'][$current_language_id];
            } else {
                $this->session->data['payment_address']['address_2'] = '';
            }

            $field_postcode = $xd_checkout_settings['field_postcode'];
            if (isset($this->request->post['postcode'])) {
                $this->session->data['payment_address']['postcode'] = $this->request->post['postcode'];
            } elseif (!empty($field_postcode['default'])) {
                $this->session->data['payment_address']['postcode'] = $field_postcode['default'][$current_language_id];
            } else {
                $this->session->data['payment_address']['postcode'] = '';
            }

            $field_city = $xd_checkout_settings['field_city'];
            if (isset($this->request->post['city'])) {
                $this->session->data['payment_address']['city'] = $this->request->post['city'];
            } elseif (!empty($field_city['default'])) {
                $this->session->data['payment_address']['city'] = $field_city['default'][$current_language_id];
            } else {
                $this->session->data['payment_address']['city'] = '';
            }

            $field_country = $xd_checkout_settings['field_country'];
            if (isset($this->request->post['country_id'])) {
                $this->session->data['payment_address']['country_id'] = $this->request->post['country_id'];
            } elseif (!empty($field_country['default'])) {
                $this->session->data['payment_address']['country_id'] = $field_country['default'];
            } else {
                $this->session->data['payment_address']['country_id'] = '';
            }

            $field_zone = $xd_checkout_settings['field_zone'];
            if (isset($this->request->post['zone_id'])) {
                $this->session->data['payment_address']['zone_id'] = $this->request->post['zone_id'];
            } elseif (!empty($field_zone['default'])) {
                $this->session->data['payment_address']['zone_id'] = $field_zone['default'];
            } else {
                $this->session->data['payment_address']['zone_id'] = '';
            }



            $this->load->model('localisation/country');
            $country_info = $this->model_localisation_country->getCountry($this->session->data['payment_address']['country_id']);
            if ($country_info) {
                $this->session->data['payment_address']['country'] = $country_info['name'];
                $this->session->data['payment_address']['iso_code_2'] = $country_info['iso_code_2'];
                $this->session->data['payment_address']['iso_code_3'] = $country_info['iso_code_3'];
                $this->session->data['payment_address']['address_format'] = $country_info['address_format'];
            } else {
                $this->session->data['payment_address']['country'] = '';
                $this->session->data['payment_address']['iso_code_2'] = '';
                $this->session->data['payment_address']['iso_code_3'] = '';
                $this->session->data['payment_address']['address_format'] = '';
            }

            if (isset($this->request->post['custom_field']['address'])) {
                $this->session->data['payment_address']['custom_field'] = $this->request->post['custom_field']['address'];
            } else {
                $this->session->data['payment_address']['custom_field'] = array();
            }

            $this->load->model('localisation/zone');
            $zone_info = $this->model_localisation_zone->getZone($this->session->data['payment_address']['zone_id']);
            if ($zone_info) {
                $this->session->data['payment_address']['zone'] = $zone_info['name'];
                $this->session->data['payment_address']['zone_code'] = $zone_info['code'];
            } else {
                $this->session->data['payment_address']['zone'] = '';
                $this->session->data['payment_address']['zone_code'] = '';
            }

            if (!empty($this->request->post['shipping_address'])) {
                $this->session->data['guest']['shipping_address'] = $this->request->post['shipping_address'];
            } else {
                $this->session->data['guest']['shipping_address'] = false;
            }

            // Default Payment Address
            if ($this->session->data['guest']['shipping_address']) {
                $this->session->data['shipping_address']['firstname'] = $this->session->data['guest']['firstname'];
                $this->session->data['shipping_address']['lastname'] = $this->session->data['guest']['lastname'];
                $this->session->data['shipping_address']['email'] = $this->session->data['guest']['email'];
                $this->session->data['shipping_address']['telephone'] = $this->session->data['guest']['telephone'];

                $this->session->data['shipping_address']['company'] = $this->session->data['payment_address']['company'];
                $this->session->data['shipping_address']['address_1'] = $this->session->data['payment_address']['address_1'];
                $this->session->data['shipping_address']['address_2'] = $this->session->data['payment_address']['address_2'];
                $this->session->data['shipping_address']['postcode'] = $this->session->data['payment_address']['postcode'];
                $this->session->data['shipping_address']['city'] = $this->session->data['payment_address']['city'];
                $this->session->data['shipping_address']['country_id'] = $this->session->data['payment_address']['country_id'];
                $this->session->data['shipping_address']['zone_id'] = $this->session->data['payment_address']['zone_id'];

                if ($country_info) {
                    $this->session->data['shipping_address']['country'] = $country_info['name'];
                    $this->session->data['shipping_address']['iso_code_2'] = $country_info['iso_code_2'];
                    $this->session->data['shipping_address']['iso_code_3'] = $country_info['iso_code_3'];
                    $this->session->data['shipping_address']['address_format'] = $country_info['address_format'];
                } else {
                    $this->session->data['shipping_address']['country'] = '';
                    $this->session->data['shipping_address']['iso_code_2'] = '';
                    $this->session->data['shipping_address']['iso_code_3'] = '';
                    $this->session->data['shipping_address']['address_format'] = '';
                }

                if ($zone_info) {
                    $this->session->data['shipping_address']['zone'] = $zone_info['name'];
                    $this->session->data['shipping_address']['zone_code'] = $zone_info['code'];
                } else {
                    $this->session->data['shipping_address']['zone'] = '';
                    $this->session->data['shipping_address']['zone_code'] = '';
                }

                if (isset($this->request->post['custom_field']['address'])) {
                    $this->session->data['shipping_address']['custom_field'] = $this->request->post['custom_field']['address'];
                } else {
                    $this->session->data['shipping_address']['custom_field'] = array();
                }
            }

            // Shipping methods
            $shipping_module = $xd_checkout_settings['shipping_module'];
            if ($this->cart->hasShipping() && $shipping_module) {
                $method_data = array();
                $this->load->model('setting/extension');
                $results = $this->model_setting_extension->getExtensions('shipping');

                foreach ($results as $result) {
                    if ($this->config->get('shipping_' . $result['code'] . '_status')) {
                        $this->load->model('extension/shipping/' . $result['code']);

                        $quote = $this->{'model_extension_shipping_' . $result['code']}->getQuote($this->session->data['shipping_address']);

                        if ($quote) {
                            $method_data[$result['code']] = array(
                                'title'      => $quote['title'],
                                'quote'      => $quote['quote'],
                                'sort_order' => $quote['sort_order'],
                                'error'      => $quote['error']
                            );
                        }
                    }
                }

                $sort_order = array();

                foreach ($method_data as $key => $value) {
                    $sort_order[$key] = $value['sort_order'];
                }

                array_multisort($sort_order, SORT_ASC, $method_data);

                $this->session->data['shipping_methods'] = $method_data;
            }
        }

        $json['session'] = $this->session->data;

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}

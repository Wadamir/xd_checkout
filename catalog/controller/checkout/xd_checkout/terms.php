<?php
class ControllerCheckoutXdCheckoutTerms extends Controller
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

        $xd_checkout_settings = $this->xd_checkout_settings;

        if ($this->config->get('config_checkout_id')) {
            $this->load->model('catalog/information');

            $information_info = $this->model_catalog_information->getInformation($this->config->get('config_checkout_id'));

            if ($information_info) {
                $data['text_agree'] = sprintf($this->language->get('text_agree'), $this->url->link('information/information/agree', 'information_id=' . $this->config->get('config_checkout_id'), true), $information_info['title'], $information_info['title']);
            } else {
                $data['text_agree'] = '';
            }
        } else {
            $data['text_agree'] = '';
        }

        // All variables
        $data['confirmation_page'] = $xd_checkout_settings['confirmation_page'];

        $proceed_button_text = $xd_checkout_settings['proceed_button_text'];

        if (!empty($proceed_button_text[$this->config->get('config_language_id')])) {
            $data['button_continue'] = $proceed_button_text[$this->config->get('config_language_id')];
        }

        return $this->load->view('checkout/xd_checkout/terms', $data);
    }

    public function validate()
    {
        $this->load->language('checkout/checkout');
        $this->load->language('checkout/xd_checkout/checkout');

        $json = array();

        if ($this->config->get('config_checkout_id')) {
            $this->load->model('catalog/information');

            $information_info = $this->model_catalog_information->getInformation($this->config->get('config_checkout_id'));

            if ($information_info && !isset($this->request->post['agree'])) {
                $json['error']['warning'] = sprintf($this->language->get('error_agree'), $information_info['title']);
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }
}

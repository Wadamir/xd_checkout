<?php
class ModelExtensionXdCheckoutCity extends Model
{
    protected $cityTable = 'xd_checkout_city';

    public function searchCities($data = array())
    {
        $term = isset($data['term']) ? trim((string)$data['term']) : '';

        if (utf8_strlen($term) < 2) {
            return array();
        }

        if (!$this->isCityTableExists()) {
            return array();
        }

        $country_id = isset($data['country_id']) ? (int)$data['country_id'] : 0;
        $zone_id = isset($data['zone_id']) ? (int)$data['zone_id'] : 0;
        $limit = isset($data['limit']) ? (int)$data['limit'] : 10;
        $limit = max(5, min(20, $limit));

        $term_escaped = $this->db->escape($term);
        $where = array();
        $where[] = "(`city_name` LIKE '" . $term_escaped . "%' OR `city_name` LIKE '%" . $term_escaped . "%')";

        if ($country_id > 0) {
            $where[] = "`country_id` = '" . $country_id . "'";
        }

        if ($zone_id > 0) {
            $where[] = "`zone_id` = '" . $zone_id . "'";
        }

        $query = $this->db->query("SELECT `city_id`, `city_name`, `region_name`, `zone_id`, `country_id` FROM `" . DB_PREFIX . $this->cityTable . "` WHERE " . implode(' AND ', $where) . " ORDER BY `city_name` ASC LIMIT " . $limit);

        $result = array();

        foreach ($query->rows as $row) {
            $city_name = trim((string)$row['city_name']);

            if ($city_name === '') {
                continue;
            }

            $label = $city_name;

            if (!empty($row['region_name'])) {
                $label .= ', ' . $row['region_name'];
            }

            $result[] = array(
                'city_id' => (int)$row['city_id'],
                'city_name' => $city_name,
                'region_name' => (string)$row['region_name'],
                'zone_id' => (int)$row['zone_id'],
                'country_id' => (int)$row['country_id'],
                'label' => $label
            );
        }

        return $result;
    }

    public function isValidCity($city_name, $country_id = 0, $zone_id = 0)
    {
        $city_name = trim((string)$city_name);

        if ($city_name === '') {
            return false;
        }

        if (!$this->isCityTableExists()) {
            return false;
        }

        $where = array();
        $where[] = "`city_name` = '" . $this->db->escape($city_name) . "'";

        if ((int)$country_id > 0) {
            $where[] = "`country_id` = '" . (int)$country_id . "'";
        }

        if ((int)$zone_id > 0) {
            $where[] = "`zone_id` = '" . (int)$zone_id . "'";
        }

        $query = $this->db->query("SELECT `city_id` FROM `" . DB_PREFIX . $this->cityTable . "` WHERE " . implode(' AND ', $where) . " LIMIT 1");

        return !empty($query->rows);
    }

    public function getFirstCity($country_id = 0, $zone_id = 0)
    {
        if (!$this->isCityTableExists()) {
            return array();
        }

        $where = array('1 = 1');

        if ((int)$country_id > 0) {
            $where[] = "`country_id` = '" . (int)$country_id . "'";
        }

        if ((int)$zone_id > 0) {
            $where[] = "`zone_id` = '" . (int)$zone_id . "'";
        }

        $query = $this->db->query("SELECT `city_id`, `city_name`, `region_name`, `zone_id`, `country_id` FROM `" . DB_PREFIX . $this->cityTable . "` WHERE " . implode(' AND ', $where) . " ORDER BY `city_id` ASC LIMIT 1");

        if (empty($query->row) || empty($query->row['city_name'])) {
            return array();
        }

        $row = $query->row;
        $label = trim((string)$row['city_name']);

        if (!empty($row['region_name'])) {
            $label .= ', ' . $row['region_name'];
        }

        return array(
            'city_id' => (int)$row['city_id'],
            'city_name' => (string)$row['city_name'],
            'region_name' => (string)$row['region_name'],
            'zone_id' => (int)$row['zone_id'],
            'country_id' => (int)$row['country_id'],
            'label' => $label
        );
    }

    protected function isCityTableExists()
    {
        static $exists = null;

        if ($exists !== null) {
            return $exists;
        }

        $table = DB_PREFIX . $this->cityTable;
        $query = $this->db->query("SHOW TABLES LIKE '" . $this->db->escape($table) . "'");

        $exists = !empty($query->rows);

        return $exists;
    }
}

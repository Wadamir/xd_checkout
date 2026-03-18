<?php
class ModelExtensionModuleXdCheckout extends Model
{

    protected $cityTable = 'xd_checkout_city';
    protected $zoneIndexReady = false;
    protected $zoneByNormalized = array();
    protected $zoneByCompact = array();
    protected $zoneByCollapsed = array();
    protected $countryIndexReady = false;
    protected $countryByNormalized = array();

    public function ensureCitiesTable()
    {
        $table = DB_PREFIX . $this->cityTable;

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . $table . "` (
            `city_id` INT(10) UNSIGNED NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `city_name` VARCHAR(128) NOT NULL,
            `region_name` VARCHAR(128) NOT NULL,
            `zone_id` INT(11) NOT NULL DEFAULT '0',
            `country_id` INT(11) NOT NULL DEFAULT '0',
            `is_center` TINYINT(1) NOT NULL DEFAULT '0',
            `cache_limit` DECIMAL(12,4) NOT NULL DEFAULT '0.0000',
            `date_added` DATETIME NOT NULL,
            `date_modified` DATETIME NOT NULL,
            PRIMARY KEY (`city_id`),
            KEY `city_name` (`city_name`),
            KEY `region_name` (`region_name`),
            KEY `zone_id` (`zone_id`),
            KEY `country_id` (`country_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->ensureColumnExists($table, 'zone_id', "INT(11) NOT NULL DEFAULT '0' AFTER `region_name`");
        $this->ensureColumnExists($table, 'country_id', "INT(11) NOT NULL DEFAULT '0' AFTER `zone_id`");
        $this->ensureIndexExists($table, 'zone_id', "(`zone_id`)");
        $this->ensureIndexExists($table, 'country_id', "(`country_id`)");
    }

    public function truncateCities()
    {
        $this->db->query("TRUNCATE TABLE `" . DB_PREFIX . $this->cityTable . "`");
    }

    public function insertCitiesBatch($cities)
    {
        if (!is_array($cities) || !$cities) {
            return 0;
        }

        $this->ensureCitiesTable();
        $this->prepareZoneIndex();

        $values = array();
        $now = date('Y-m-d H:i:s');

        foreach ($cities as $city) {
            $row = $this->normalizeCityRow($city);

            if (!$row) {
                continue;
            }

            if ($this->shouldSkipCityByRegionName(isset($row['region_name']) ? $row['region_name'] : '')) {
                continue;
            }

            $resolved = $this->resolveZoneAndRegion($row);
            $region_name = ($resolved['region_name'] !== '') ? $resolved['region_name'] : $row['region_name'];

            $values[] = "('" . (int)$row['city_id'] . "', '" . $this->db->escape($row['name']) . "', '" . $this->db->escape($row['city_name']) . "', '" . $this->db->escape($region_name) . "', '" . (int)$resolved['zone_id'] . "', '" . (int)$resolved['country_id'] . "', '" . (int)$row['is_center'] . "', '" . (float)$row['cache_limit'] . "', '" . $now . "', '" . $now . "')";
        }

        if (!$values) {
            return 0;
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . $this->cityTable . "` (`city_id`, `name`, `city_name`, `region_name`, `zone_id`, `country_id`, `is_center`, `cache_limit`, `date_added`, `date_modified`) VALUES " . implode(', ', $values) . " ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `city_name` = VALUES(`city_name`), `region_name` = VALUES(`region_name`), `zone_id` = VALUES(`zone_id`), `country_id` = VALUES(`country_id`), `is_center` = VALUES(`is_center`), `cache_limit` = VALUES(`cache_limit`), `date_modified` = VALUES(`date_modified`)");

        return count($values);
    }

    public function getCitiesTotal()
    {
        $query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . $this->cityTable . "`");
        return isset($query->row['total']) ? (int)$query->row['total'] : 0;
    }

    private function normalizeCityRow($city)
    {
        if (!is_array($city) || !isset($city['id'])) {
            return array();
        }

        return array(
            'city_id' => (int)$city['id'],
            'name' => isset($city['name']) ? (string)$city['name'] : '',
            'city_name' => isset($city['cityName']) ? (string)$city['cityName'] : '',
            'region_name' => isset($city['regionName']) ? $this->fixRegionSpacing((string)$city['regionName']) : '',
            'is_center' => !empty($city['center']) ? 1 : 0,
            'cache_limit' => isset($city['cache_limit']) ? (float)$city['cache_limit'] : 0.0
        );
    }

    private function shouldSkipCityByRegionName($region_name)
    {
        $region_name = trim((string)$region_name);

        if ($region_name === '') {
            return false;
        }

        return (bool)preg_match('/\(\s*удал(?:ен|ён)\s*\)|фиктивн\p{L}*/ui', $region_name);
    }

    private function resolveZoneAndRegion($row)
    {
        $default = array(
            'zone_id' => 0,
            'country_id' => 0,
            'region_name' => ''
        );

        $region_override = $this->extractRegionOverride($row);
        $region_from_field = $this->mapKnownRegionCenters($this->fixRegionSpacing(isset($row['region_name']) ? $row['region_name'] : ''));
        $region_from_city_name = $this->mapKnownRegionCenters($this->extractRegionFromCityName(isset($row['city_name']) ? $row['city_name'] : ''));
        $region_from_name = $this->mapKnownRegionCenters($this->extractRegionFromName(isset($row['name']) ? $row['name'] : ''));

        $candidates = array();
        $seen = array();

        $this->addRegionCandidate($candidates, $seen, $region_override);
        $this->addRegionCandidate($candidates, $seen, $region_from_field);
        $this->addRegionCandidate($candidates, $seen, $region_from_city_name);
        $this->addRegionCandidate($candidates, $seen, $region_from_name);

        foreach ($candidates as $candidate) {
            $zone = $this->resolveZoneData($candidate);

            if (!empty($zone['zone_id'])) {
                return array(
                    'zone_id' => (int)$zone['zone_id'],
                    'country_id' => (int)$zone['country_id'],
                    'region_name' => $candidate
                );
            }
        }

        // If region_name is malformed and no zone matched, prefer extracted value from city_name.
        $fallback_region = $region_from_field;

        if ($region_override !== '') {
            $fallback_region = $region_override;
        } elseif ($region_from_city_name !== '') {
            $fallback_region = $region_from_city_name;
        } elseif ($region_from_name !== '') {
            $fallback_region = $region_from_name;
        }

        $default['region_name'] = $fallback_region;
        $default['country_id'] = $this->resolveFallbackCountryId($region_from_field, isset($row['name']) ? $row['name'] : '');

        return $default;
    }

    private function prepareZoneIndex()
    {
        if ($this->zoneIndexReady) {
            return;
        }

        $query = $this->db->query("SELECT zone_id, country_id, name FROM `" . DB_PREFIX . "zone` WHERE name <> ''");

        foreach ($query->rows as $row) {
            $zone = array(
                'zone_id' => (int)$row['zone_id'],
                'country_id' => (int)$row['country_id']
            );

            $normalized = $this->normalizeRegionName($row['name']);

            if ($normalized !== '' && !isset($this->zoneByNormalized[$normalized])) {
                $this->zoneByNormalized[$normalized] = $zone;
            }

            $compact = $this->compactRegionName($normalized);

            if ($compact !== '' && !isset($this->zoneByCompact[$compact])) {
                $this->zoneByCompact[$compact] = $zone;
            }

            $collapsed = $this->collapseRegionName($compact);

            if ($collapsed !== '' && !isset($this->zoneByCollapsed[$collapsed])) {
                $this->zoneByCollapsed[$collapsed] = $zone;
            }
        }

        $this->zoneIndexReady = true;
    }

    private function resolveZoneData($regionName)
    {
        $default = array(
            'zone_id' => 0,
            'country_id' => 0
        );

        $normalized = $this->normalizeRegionName($regionName);

        if ($normalized === '') {
            return $default;
        }

        if (isset($this->zoneByNormalized[$normalized])) {
            return $this->zoneByNormalized[$normalized];
        }

        $compact = $this->compactRegionName($normalized);

        if ($compact !== '' && isset($this->zoneByCompact[$compact])) {
            return $this->zoneByCompact[$compact];
        }

        $collapsed = $this->collapseRegionName($compact);

        if ($collapsed !== '' && isset($this->zoneByCollapsed[$collapsed])) {
            return $this->zoneByCollapsed[$collapsed];
        }

        if (strpos($normalized, ',') !== false) {
            $parts = explode(',', $normalized);

            foreach ($parts as $part) {
                $part = trim($part);

                if ($part === '') {
                    continue;
                }

                if (isset($this->zoneByNormalized[$part])) {
                    return $this->zoneByNormalized[$part];
                }

                $part_compact = $this->compactRegionName($part);

                if ($part_compact !== '' && isset($this->zoneByCompact[$part_compact])) {
                    return $this->zoneByCompact[$part_compact];
                }

                $part_collapsed = $this->collapseRegionName($part_compact);

                if ($part_collapsed !== '' && isset($this->zoneByCollapsed[$part_collapsed])) {
                    return $this->zoneByCollapsed[$part_collapsed];
                }
            }
        }

        return $default;
    }

    private function normalizeRegionName($value)
    {
        $value = $this->fixRegionSpacing($value);

        if ($value === '') {
            return '';
        }

        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = str_replace(array('Ё', 'ё'), array('Е', 'е'), $value);
        $value = $this->toLower($value);

        // Bring AO variants to one form so "авт. округ" and "АО" match each other.
        $value = preg_replace('/\bавт\.?\s*окр\.?\b/ui', ' ао ', $value);
        $value = preg_replace('/\bавт\.?\s*округ\b/ui', ' ао ', $value);
        $value = preg_replace('/\bавтономн(?:ый|ая|ое)\s+округ\b/ui', ' ао ', $value);

        // Expand short region markers in a position-independent way (including end-of-string forms like "обл").
        $value = preg_replace('/\bобл\.?\b/ui', ' область ', $value);
        $value = preg_replace('/\bресп\.?\b/ui', ' республика ', $value);
        $value = preg_replace('/\bр-н\b/ui', ' район ', $value);
        $value = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        // Regional aliases that differ between CDEK dataset and oc_zone naming.
        $value = preg_replace('/\bаламтинск(?:ая)?\s+область\b/u', 'алматинская область', $value);
        $value = preg_replace('/\bцелиноградск(?:ая)?\s+область\b/u', 'акмолинская область', $value);
        $value = preg_replace('/\bкокчетавск(?:ая)?\s+область\b/u', 'акмолинская область', $value);
        $value = preg_replace('/\bчимкентск(?:ая)?\s+область\b/u', 'южно-казахстанская область', $value);
        $value = preg_replace('/\bкустанайск(?:ая)?\s+область\b/u', 'костанайская область', $value);
        $value = preg_replace('/\bтургайск(?:ая)?\s+область\b/u', 'костанайская область', $value);
        $value = preg_replace('/\bкзыл[-\s]*ординск(?:ая)?\s+область\b/u', 'кызылординская область', $value);
        $value = preg_replace('/\bгурьевск(?:ая)?\s+область\b/u', 'атырауская область', $value);
        $value = preg_replace('/\bджезказганск(?:ая)?\s+область\b/u', 'карагандинская область', $value);
        $value = preg_replace('/\bмангистаунск(?:ая)?\s+область\b/u', 'мангистауская область', $value);
        $value = preg_replace('/\bмангышлакск(?:ая)?\s+область\b/u', 'мангистауская область', $value);
        $value = preg_replace('/\bсемипалатинск(?:ая)?\s+область\b/u', 'восточно-казахстанская область', $value);
        $value = preg_replace('/\bталды[-\s]*курганск(?:ая)?\s+область\b/u', 'алматинская область', $value);
        $value = preg_replace('/\b(алматы|астана|байконур)\s*-\s*город\s+республ(?:ик\p{L}*|-?го)?\s+значени\p{L}*\b/u', '$1', $value);
        $value = preg_replace('/\bгород\s+республ(?:ик\p{L}*|-?го)?\s+значени\p{L}*\s+(алматы|астана|байконур)\b/u', '$1', $value);
        $value = preg_replace('/\bчувашия\s+республика\b/u', 'чувашская республика', $value);
        $value = preg_replace('/\bудмуртия\s+республика\b/u', 'удмуртская республика', $value);
        $value = preg_replace('/\bа\.?\s*р\.?\s*крым\b/u', 'республика крым', $value);
        $value = preg_replace('/\bкабардино[-\s]*балкарская\s+республика\b/u', 'республика кабардино-балкария', $value);
        $value = preg_replace('/\bкарачаево[-\s]*черкесская\s+республика\b/u', 'карачаево-черкесия', $value);
        $value = preg_replace('/\bсаха\s+республика(?:\s+якутия)?\b/u', 'республика саха', $value);
        $value = preg_replace('/\bханты[-\s]*мансийский\s+ао(?:\s*-\s*югра)?\b/u', 'ханты-мансийский ао югра', $value);

        return trim($value);
    }

    private function extractRegionOverride($row)
    {
        $region_from_field = isset($row['region_name']) ? (string)$row['region_name'] : '';
        $name_value = isset($row['name']) ? (string)$row['name'] : '';
        $city_name_value = isset($row['city_name']) ? (string)$row['city_name'] : '';

        if ($this->isGlukhovUkraineName($name_value) || $this->isGlukhovUkraineName($city_name_value)) {
            return 'Сумы';
        }

        if ($this->isAlmatyRegionName($region_from_field) && ($this->isAlmatyCityName($name_value) || $this->isAlmatyCityName($city_name_value))) {
            return 'Алматы - город республиканского значения';
        }

        if ($this->isAkmolaRegionName($region_from_field) && ($this->isAstanaCityName($name_value) || $this->isAstanaCityName($city_name_value))) {
            return 'Астана - город республиканского значения';
        }

        if ($this->isBaikonurKazakhstanName($name_value) || $this->isBaikonurKazakhstanName($city_name_value)) {
            return 'Байконур - город республиканского значения';
        }

        $name = isset($row['name']) ? trim((string)$row['name']) : '';

        if ($name === '' || strpos($name, ',') === false) {
            return '';
        }

        $parts = explode(',', $name);

        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $part = $this->fixRegionSpacing($parts[$i]);

            if ($part === '' || !$this->hasRegionMarker($part)) {
                continue;
            }

            $mapped = $this->mapKnownRegionCenters($part);

            if ($mapped !== '' && $mapped !== $part) {
                return $mapped;
            }
        }

        return '';
    }

    private function mapKnownRegionCenters($value)
    {
        $value = $this->fixRegionSpacing($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^минск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Минск';
        }

        if (preg_match('/^гродненск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Гродно';
        }

        if (preg_match('/^могил[её]вск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Могилев';
        }

        if (preg_match('/^брестск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Брест';
        }

        if (preg_match('/^витебск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Витебск';
        }

        if (preg_match('/^гомельск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Гомель';
        }

        if (preg_match('/^алма[-\s]*атинск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Алматинская область';
        }

        if (preg_match('/^целиноградск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Акмолинская область';
        }

        if (preg_match('/^кокчетавск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Акмолинская область';
        }

        if (preg_match('/^чимкентск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Южно-Казахстанская область';
        }

        if (preg_match('/^кустанайск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Костанайская область';
        }

        if (preg_match('/^тургайск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Костанайская область';
        }

        if (preg_match('/^кзыл[-\s]*ординск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Кызылординская область';
        }

        if (preg_match('/^гурьевск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Атырауская область';
        }

        if (preg_match('/^джезказганск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Карагандинская область';
        }

        if (preg_match('/^мангистаунск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Мангистауская область';
        }

        if (preg_match('/^мангышлакск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Мангистауская область';
        }

        if (preg_match('/^семипалатинск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Восточно-Казахстанская область';
        }

        if (preg_match('/^талды[-\s]*курганск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Алматинская область';
        }

        if (preg_match('/^винницк(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Винница';
        }

        if (preg_match('/^днепропетровск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Днепропетровск';
        }

        if (preg_match('/^донецк(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Донецк';
        }

        if (preg_match('/^житомирск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Житомир';
        }

        if (preg_match('/^ивано[-\s]*франковск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Ивано-Франковск';
        }

        if (preg_match('/^луганск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Луганск';
        }

        if (preg_match('/^львовск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Львов';
        }

        if (preg_match('/^одесск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Одесса';
        }

        if (preg_match('/^николаевск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Николаев';
        }

        if (preg_match('/^полтавск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Полтава';
        }

        if (preg_match('/^ровенск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Ровно';
        }

        if (preg_match('/^харьковск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Харьков';
        }

        if (preg_match('/^хмельницк(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Хмельницкий';
        }

        if (preg_match('/^черкасск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Черкассы';
        }

        if (preg_match('/^черниговск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Чернигов';
        }

        if (preg_match('/^сумск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Сумы';
        }

        if (preg_match('/^черновицк(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Черновцы';
        }

        if (preg_match('/^волынск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Луцк';
        }

        if (preg_match('/^закарпатск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Ужгород';
        }

        if (preg_match('/^кировоградск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Кировоград';
        }

        if (preg_match('/^тернопольск(?:ая)?\s+обл(?:асть)?\.?$/ui', $value)) {
            return 'Тернополь';
        }

        if (preg_match('/^республика\s+саха(?:\s*\(\s*якутия\s*\))?$/ui', $value)) {
            return 'Республика Саха';
        }

        if (preg_match('/^республика\s+северн(?:ая|ой)\s+осетия(?:\s*-\s*алания)?$/ui', $value)) {
            return 'Республика Северная Осетия';
        }

        if (preg_match('/^(?:удмуртия|республика\s+удмуртия)$/ui', $value)) {
            return 'Удмуртская Республика';
        }

        if (preg_match('/^(?:чувашия|республика\s+чувашия)$/ui', $value)) {
            return 'Чувашская Республика';
        }

        return $value;
    }

    private function isAlmatyRegionName($value)
    {
        return ($this->normalizeRegionName($value) === 'алматинская область');
    }

    private function isAlmatyCityName($value)
    {
        $value = trim((string)$value);

        if ($value === '') {
            return false;
        }

        return (bool)preg_match('/^алматы(?:\s*,|$)/ui', $value);
    }

    private function isAkmolaRegionName($value)
    {
        return ($this->normalizeRegionName($value) === 'акмолинская область');
    }

    private function isAstanaCityName($value)
    {
        $value = trim((string)$value);

        if ($value === '') {
            return false;
        }

        return (bool)preg_match('/^(астана|нур[-\s]*султан)(?:\s*,|$)/ui', $value);
    }

    private function isBaikonurKazakhstanName($value)
    {
        $value = trim((string)$value);

        if ($value === '') {
            return false;
        }

        if (!preg_match('/^байконур(?:\s*,|$)/ui', $value)) {
            return false;
        }

        return (bool)preg_match('/казахстан/ui', $value);
    }

    private function isGlukhovUkraineName($value)
    {
        $value = trim((string)$value);

        if ($value === '') {
            return false;
        }

        if (!preg_match('/^глухов(?:\s*,|$)/ui', $value)) {
            return false;
        }

        return (bool)preg_match('/украин/ui', $value);
    }

    private function extractRegionFromCityName($city_name)
    {
        $city_name = trim((string)$city_name);

        if ($city_name === '' || strpos($city_name, ',') === false) {
            return '';
        }

        $parts = explode(',', $city_name);

        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $part = $this->fixRegionSpacing($parts[$i]);

            if ($part === '') {
                continue;
            }

            if ($this->hasRegionMarker($part)) {
                return $part;
            }
        }

        return '';
    }

    private function extractRegionFromName($name)
    {
        $name = trim((string)$name);

        if ($name === '' || strpos($name, ',') === false) {
            return '';
        }

        $parts = explode(',', $name);

        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $part = $this->fixRegionSpacing($parts[$i]);

            if ($part === '' || $this->isCountryChunk($part)) {
                continue;
            }

            if ($this->hasRegionMarker($part)) {
                return $part;
            }
        }

        return '';
    }

    private function addRegionCandidate(&$candidates, &$seen, $value)
    {
        $value = $this->fixRegionSpacing($value);

        if ($value === '') {
            return;
        }

        $key = $this->normalizeRegionName($value);

        if ($key === '' || isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $candidates[] = $value;
    }

    private function fixRegionSpacing($value)
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        // Handle malformed suffixes like "Брестскаяобл." before generic normalization.
        $value = preg_replace('/(\p{L})(обл\.)$/ui', '$1 $2', $value);

        $value = preg_replace('/(\p{L})(обл\.?|область|респ\.?|республика|край|р-н|район|ао|авт\.?\s*окр\.?|автономн\p{L}*\s*округ)(\b|$)/ui', '$1 $2$3', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    private function hasRegionMarker($value)
    {
        return (bool)preg_match('/\b(обл\.?|область|респ\.?|республика|край|ао|автоном|округ|р-н|район)\b/ui', $value);
    }

    private function isCountryChunk($value)
    {
        return (bool)preg_match('/(беларус|белорус|росси|казах|украин|груз|армени|киргиз|узбеки|латви|литв|эстон|польш|герман|франц|итал|испан|turk|turkey|china|usa)/ui', $value);
    }

    private function compactRegionName($value)
    {
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\b(область|республика|край|автономный|автономная|автономное|округ|район|город|г|ао)\b/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    private function collapseRegionName($value)
    {
        if ($value === '') {
            return '';
        }

        return preg_replace('/[\s-]+/u', '', $value);
    }

    private function resolveFallbackCountryId($regionName, $name)
    {
        $country_id = $this->resolveCountryIdByName($regionName);

        if ($country_id) {
            return $country_id;
        }

        $name_country = $this->extractCountryFromName($name);

        if ($name_country !== '') {
            $country_id = $this->resolveCountryIdByName($name_country);

            if ($country_id) {
                return $country_id;
            }
        }

        return 0;
    }

    private function resolveCountryIdByName($value)
    {
        $this->prepareCountryIndex();

        $normalized = $this->normalizeCountryName($value);

        if ($normalized === '') {
            return 0;
        }

        $aliases = $this->getCountryNameAliases($normalized);

        foreach ($aliases as $alias) {
            if (isset($this->countryByNormalized[$alias])) {
                return (int)$this->countryByNormalized[$alias];
            }
        }

        return 0;
    }

    private function getCountryNameAliases($normalized)
    {
        $aliases = array();
        $aliases[$normalized] = $normalized;

        if (preg_match('/(росси|^рф$|russia|russian\s+federation)/u', $normalized)) {
            $aliases['российская федерация'] = 'российская федерация';
            $aliases['россия'] = 'россия';
        }

        if (preg_match('/(беларус|белорус|belarus)/u', $normalized)) {
            $aliases['белоруссия беларусь'] = 'белоруссия беларусь';
            $aliases['белоруссия'] = 'белоруссия';
            $aliases['беларусь'] = 'беларусь';
        }

        if (preg_match('/(казах|kazakh|kazakhstan)/u', $normalized)) {
            $aliases['казахстан'] = 'казахстан';
            $aliases['республика казахстан'] = 'республика казахстан';
        }

        return array_values($aliases);
    }

    private function extractCountryFromName($name)
    {
        $name = trim((string)$name);

        if ($name === '' || strpos($name, ',') === false) {
            return '';
        }

        $parts = explode(',', $name);
        $last = trim((string)end($parts));

        return $last;
    }

    private function prepareCountryIndex()
    {
        if ($this->countryIndexReady) {
            return;
        }

        $query = $this->db->query("SELECT country_id, name FROM `" . DB_PREFIX . "country` WHERE name <> ''");

        foreach ($query->rows as $row) {
            $normalized = $this->normalizeCountryName($row['name']);

            if ($normalized === '' || isset($this->countryByNormalized[$normalized])) {
                continue;
            }

            $this->countryByNormalized[$normalized] = (int)$row['country_id'];
        }

        $this->countryIndexReady = true;
    }

    private function normalizeCountryName($value)
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '';
        }

        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = str_replace(array('Ё', 'ё'), array('Е', 'е'), $value);
        $value = $this->toLower($value);
        $value = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim($value);
    }

    private function toLower($value)
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    private function ensureColumnExists($table, $column, $definition)
    {
        $query = $this->db->query("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $this->db->escape($column) . "'");

        if (!$query->num_rows) {
            $this->db->query("ALTER TABLE `" . $table . "` ADD `" . $column . "` " . $definition);
        }
    }

    private function ensureIndexExists($table, $index_name, $definition)
    {
        $query = $this->db->query("SHOW INDEX FROM `" . $table . "` WHERE Key_name = '" . $this->db->escape($index_name) . "'");

        if (!$query->num_rows) {
            $this->db->query("ALTER TABLE `" . $table . "` ADD KEY `" . $index_name . "` " . $definition);
        }
    }
}

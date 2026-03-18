<?php

class XdCheckoutCdekCityUpdater
{
    const CURL_CONNECT_TIMEOUT = 5;
    const CURL_TIMEOUT = 20;
    const CITIES_PAGE_RETRY_ATTEMPTS = 3;
    const RETRY_DELAY_MICROSECONDS = 400000;

    private $registry;
    private $config;
    private $log;
    private $dataFile;
    private $lockFile;
    private $settings = null;
    private $debugLogger = null;
    private $debugLogFile = 'xd_checkout.log';

    public function __construct($registry)
    {
        $this->registry = $registry;
        $this->config = (method_exists($registry, 'has') && $registry->has('config')) ? $registry->get('config') : null;
        $this->log = (method_exists($registry, 'has') && $registry->has('log')) ? $registry->get('log') : null;

        $this->dataFile = DIR_SYSTEM . 'library/xd_checkout/cdek_city.json';
        $this->lockFile = $this->dataFile . '.lock';
    }

    public function syncFromApi(&$message = '', &$error = '', $country_codes = array())
    {
        $message = '';
        $error = '';

        $this->writeDebug('sync:start');

        $credentials = $this->getApiCredentials();

        $this->writeDebug('sync:credentials', array(
            'client_id_present' => $credentials['client_id'] !== '',
            'client_secret_present' => $credentials['client_secret'] !== '',
            'environment' => isset($credentials['environment']) ? $credentials['environment'] : 'prod',
            'token_url' => $credentials['token_url'],
            'cities_url' => $credentials['cities_url'],
            'page_size' => $credentials['page_size']
        ));

        if ($credentials['client_id'] === '' || $credentials['client_secret'] === '') {
            $this->writeDebug('sync:skip_no_credentials');
            return $this->fallbackOrFail('CDEK API credentials are not configured.', $message, $error);
        }

        $lock_handle = $this->acquireLock($lock_error);

        if (!$lock_handle) {
            $this->writeDebug('sync:lock_failed', array('error' => $lock_error));
            return $this->fallbackOrFail($lock_error, $message, $error);
        }

        $token = $this->requestAccessToken($credentials, $token_error);

        if ($token === '') {
            $this->writeDebug('sync:token_failed', array('error' => $token_error));
            $this->releaseLock($lock_handle);
            return $this->fallbackOrFail($token_error, $message, $error);
        }

        $cities = $this->downloadCities($credentials, $token, $download_error, $country_codes);

        if ($cities === false || !$cities) {
            $this->writeDebug('sync:download_failed', array('error' => $download_error));
            $this->cleanupTempCityFiles();
            $this->releaseLock($lock_handle);
            return $this->fallbackOrFail($download_error, $message, $error);
        }

        if (!$this->writeCitiesFile($cities, $write_error)) {
            $this->writeDebug('sync:write_failed', array('error' => $write_error));
            $this->cleanupTempCityFiles();
            $this->releaseLock($lock_handle);
            return $this->fallbackOrFail($write_error, $message, $error);
        }

        $this->releaseLock($lock_handle);

        $message = 'Cities file was updated from CDEK API.';
        // $this->writeDebug('sync:success', array('cities' => count($cities)));
        // $this->writeLog('CDEK API sync completed. Cities: ' . count($cities));

        return true;
    }

    public function readCitiesFile(&$error_message = '')
    {
        $error_message = '';

        $this->writeDebug('read:file_start', array('path' => $this->dataFile));

        if (!is_file($this->dataFile)) {
            $error_message = 'Data file is unavailable.';
            $this->writeDebug('read:file_missing');
            return false;
        }

        $content = file_get_contents($this->dataFile);

        if ($content === false || $content === '') {
            $error_message = 'Data file is unavailable.';
            $this->writeDebug('read:file_unreadable');
            return false;
        }

        $cities = json_decode($content, true);

        if (!is_array($cities)) {
            $error_message = 'Data file is unavailable.';
            $this->writeDebug('read:file_invalid_json');
            return false;
        }

        $this->writeDebug('read:file_success', array('cities' => count($cities)));

        return $cities;
    }

    private function getApiCredentials()
    {
        $settings = $this->getSettings();

        $environment = strtolower(trim((string)$this->firstNonEmpty(array(
            isset($settings['cdek_api_environment']) ? $settings['cdek_api_environment'] : '',
            getenv('CDEK_API_ENVIRONMENT') !== false ? getenv('CDEK_API_ENVIRONMENT') : ''
        ))));

        if (!in_array($environment, array('prod', 'test'), true)) {
            $environment = 'prod';
        }

        $base_url = $environment === 'test' ? 'https://api.edu.cdek.ru' : 'https://api.cdek.ru';

        $client_id = $this->firstNonEmpty(array(
            isset($settings['cdek_client_id']) ? $settings['cdek_client_id'] : '',
            isset($settings['cdek_api_client_id']) ? $settings['cdek_api_client_id'] : '',
            $this->constantOrEmpty('CDEK_CLIENT_ID'),
            getenv('CDEK_CLIENT_ID') !== false ? getenv('CDEK_CLIENT_ID') : ''
        ));

        $client_secret = $this->firstNonEmpty(array(
            isset($settings['cdek_client_secret']) ? $settings['cdek_client_secret'] : '',
            isset($settings['cdek_api_client_secret']) ? $settings['cdek_api_client_secret'] : '',
            $this->constantOrEmpty('CDEK_CLIENT_SECRET'),
            getenv('CDEK_CLIENT_SECRET') !== false ? getenv('CDEK_CLIENT_SECRET') : ''
        ));

        return array(
            'client_id' => trim((string)$client_id),
            'client_secret' => trim((string)$client_secret),
            'token_url' => $base_url . '/v2/oauth/token',
            'cities_url' => $base_url . '/v2/location/cities',
            'environment' => $environment,
            'page_size' => 1000
        );
    }

    private function requestAccessToken($credentials, &$error)
    {
        $error = '';

        // $this->writeDebug('token:request_start', array(
        //     'token_url' => $credentials['token_url']
        // ));

        $token_payload = array(
            'grant_type' => 'client_credentials',
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret']
        );

        $post_fields = http_build_query($token_payload);
        $token_url = $credentials['token_url'] . '?' . http_build_query($token_payload);

        $response = $this->sendCurlRequest(
            $token_url,
            'POST',
            array(
                'Content-Type: application/x-www-form-urlencoded'
            ),
            $post_fields,
            $http_code,
            $request_error
        );

        if ($response === false) {
            $error = $request_error;
            $this->writeDebug('token:request_error', array('error' => $error));
            return '';
        }

        if ($http_code < 200 || $http_code >= 300) {
            $error = 'CDEK token request failed with HTTP ' . $http_code . '.';
            $this->writeDebug('token:http_error', array('http_code' => $http_code));
            return '';
        }

        $json = json_decode($response, true);

        if (!is_array($json) || empty($json['access_token'])) {
            $error = 'CDEK token response is invalid.';
            $this->writeDebug('token:invalid_response');
            return '';
        }

        // $this->writeDebug('token:success');

        return (string)$json['access_token'];
    }

    private function downloadCities($credentials, $token, &$error, $country_codes = array())
    {
        $error = '';
        $cities_by_id = array();
        $page = 0;
        $max_pages = 1000;
        $country_codes = $this->getCountryCodesFilter($country_codes);

        if (!$country_codes) {
            $error = 'CDEK country filter is empty.';
            $this->writeDebug('cities:country_filter_empty');
            return false;
        }

        $country_codes_query = $this->buildCountryCodesQueryValue($country_codes);
        $allowed_country_codes = array_fill_keys($country_codes, true);

        $this->writeDebug('cities:download_start', array(
            'url' => $credentials['cities_url'],
            'page_size' => $credentials['page_size'],
            'max_pages' => $max_pages,
            'country_codes' => $country_codes
        ));

        while ($page < $max_pages) {
            $query = 'size=' . (int)$credentials['page_size']
                . '&country_codes=' . $country_codes_query
                . '&page=' . (int)$page
                . '&lang=rus';

            $response = $this->sendCurlRequestWithRetry(
                $credentials['cities_url'] . '?' . $query,
                'GET',
                array(
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json'
                ),
                '',
                self::CITIES_PAGE_RETRY_ATTEMPTS,
                $http_code,
                $request_error
            );

            if ($response === false) {
                $error = $request_error;
                $this->writeDebug('cities:page_error', array(
                    'page' => $page,
                    'error' => $error
                ));
                return false;
            }

            if ($http_code < 200 || $http_code >= 300) {
                $error = 'CDEK cities request failed with HTTP ' . $http_code . ' on page ' . $page . '.';
                $this->writeDebug('cities:page_http_error', array(
                    'page' => $page,
                    'http_code' => $http_code
                ));
                return false;
            }

            $json = json_decode($response, true);

            if (!is_array($json)) {
                $error = 'CDEK cities response is invalid on page ' . $page . '.';
                $this->writeDebug('cities:page_invalid_json', array('page' => $page));
                return false;
            }

            if (!isset($json[0])) {
                $this->writeDebug('cities:page_empty', array('page' => $page));
                break;
            }

            $items_count = count($json);

            foreach ($json as $item) {
                if (!$this->isCountryAllowed($item, $allowed_country_codes)) {
                    continue;
                }

                $mapped = $this->mapCityFromApiItem($item);

                if (!$mapped) {
                    continue;
                }

                $cities_by_id[$mapped['id']] = $mapped;
            }

            if ($items_count < $credentials['page_size']) {
                $this->writeDebug('cities:page_success', array(
                    'total_pages' => $page,
                    'collected_total' => count($cities_by_id)
                ));
                break;
            }

            $page++;
        }

        if (!$cities_by_id) {
            $error = 'CDEK API returned no city rows.';
            $this->writeDebug('cities:no_rows');
            return false;
        }

        $this->writeDebug('cities:download_success', array('cities' => count($cities_by_id)));

        return array_values($cities_by_id);
    }

    private function mapCityFromApiItem($item)
    {
        if (!is_array($item)) {
            return array();
        }

        $id = isset($item['code']) ? (int)$item['code'] : 0;

        if ($id <= 0) {
            return array();
        }

        $city_name = isset($item['city']) ? trim((string)$item['city']) : '';

        if ($city_name === '') {
            return array();
        }

        $region_name = isset($item['region']) ? trim((string)$item['region']) : '';

        if ($region_name === '') {
            $region_name = isset($item['sub_region']) ? trim((string)$item['sub_region']) : '';
        }

        $country_name = isset($item['country']) ? trim((string)$item['country']) : '';

        $name_parts = array();
        $name_parts[] = $city_name;

        if ($region_name !== '' && $region_name !== $city_name) {
            $name_parts[] = $region_name;
        }

        if ($country_name !== '' && $country_name !== $region_name) {
            $name_parts[] = $country_name;
        }

        $name_parts = array_values(array_unique($name_parts));

        return array(
            'id' => (string)$id,
            'name' => implode(', ', $name_parts),
            'cityName' => $city_name,
            'regionName' => $region_name,
            'countryCode' => isset($item['country_code'])
                ? strtoupper(trim((string)$item['country_code']))
                : (isset($item['countryCode']) ? strtoupper(trim((string)$item['countryCode'])) : ''),
            'countryName' => $country_name,
            'center' => '0',
            'cache_limit' => '0.0000'
        );
    }

    private function writeCitiesFile($cities, &$error)
    {
        $error = '';

        // $this->writeDebug('file:write_start', array('cities' => is_array($cities) ? count($cities) : 0));

        if (!is_array($cities) || !$cities) {
            $error = 'No city data to write.';
            $this->writeDebug('file:write_no_data');
            return false;
        }

        $dir = dirname($this->dataFile);

        if (!is_dir($dir)) {
            $error = 'Data directory does not exist.';
            $this->writeDebug('file:write_dir_missing', array('dir' => $dir));
            return false;
        }

        $tmp_file = $this->dataFile . '.tmp.' . getmypid() . '.' . uniqid('', true);

        $handle = @fopen($tmp_file, 'wb');

        if (!$handle) {
            $error = 'Failed to write temporary city file.';
            $this->writeDebug('file:write_tmp_failed', array('tmp_file' => $tmp_file));
            return false;
        }

        // $this->writeDebug('file:write_stream_start', array('tmp_file' => $tmp_file));

        $bytes_written = 0;

        if (!$this->writeToHandle($handle, '[', $bytes_written)) {
            fclose($handle);
            @unlink($tmp_file);
            $error = 'Failed to write temporary city file.';
            $this->writeDebug('file:write_stream_failed', array('tmp_file' => $tmp_file, 'stage' => 'open_bracket'));
            return false;
        }

        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $options |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $city_index = 0;

        foreach ($cities as $city) {
            $encoded_city = json_encode($city, $options);

            if ($encoded_city === false) {
                fclose($handle);
                @unlink($tmp_file);
                $error = 'Failed to encode city data.';
                $this->writeDebug('file:write_encode_failed', array('index' => $city_index));
                return false;
            }

            if ($city_index > 0 && !$this->writeToHandle($handle, ',', $bytes_written)) {
                fclose($handle);
                @unlink($tmp_file);
                $error = 'Failed to write temporary city file.';
                $this->writeDebug('file:write_stream_failed', array('tmp_file' => $tmp_file, 'stage' => 'comma', 'index' => $city_index));
                return false;
            }

            if (!$this->writeToHandle($handle, $encoded_city, $bytes_written)) {
                fclose($handle);
                @unlink($tmp_file);
                $error = 'Failed to write temporary city file.';
                $this->writeDebug('file:write_stream_failed', array('tmp_file' => $tmp_file, 'stage' => 'city_json', 'index' => $city_index));
                return false;
            }

            // if ($city_index > 0 && $city_index % 10000 === 0) {
            //     $this->writeDebug('file:write_stream_progress', array('written' => $city_index));
            // }

            $city_index++;
        }

        if (!$this->writeToHandle($handle, ']', $bytes_written)) {
            fclose($handle);
            @unlink($tmp_file);
            $error = 'Failed to write temporary city file.';
            $this->writeDebug('file:write_stream_failed', array('tmp_file' => $tmp_file, 'stage' => 'close_bracket'));
            return false;
        }

        if (!@fflush($handle)) {
            fclose($handle);
            @unlink($tmp_file);
            $error = 'Failed to flush temporary city file.';
            $this->writeDebug('file:write_stream_failed', array('tmp_file' => $tmp_file, 'stage' => 'flush'));
            return false;
        }

        fclose($handle);

        if (!@rename($tmp_file, $this->dataFile)) {
            @unlink($tmp_file);
            $error = 'Failed to replace city data file.';
            $this->writeDebug('file:write_rename_failed', array(
                'tmp_file' => $tmp_file,
                'target_file' => $this->dataFile
            ));
            return false;
        }

        $this->writeDebug('file:write_success', array(
            'file' => $this->dataFile,
            'bytes' => $bytes_written
        ));

        return true;
    }

    private function writeToHandle($handle, $chunk, &$bytes_written)
    {
        $result = @fwrite($handle, $chunk);

        if ($result === false) {
            return false;
        }

        $bytes_written += $result;

        return $result === strlen($chunk);
    }

    private function cleanupTempCityFiles()
    {
        $pattern = $this->dataFile . '.tmp.*';
        $files = glob($pattern);

        if (!is_array($files) || !$files) {
            return;
        }

        $removed = 0;

        foreach ($files as $file) {
            if (is_file($file) && @unlink($file)) {
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->writeDebug('file:temp_cleanup', array('removed' => $removed));
        }
    }

    private function fallbackOrFail($reason, &$message, &$error)
    {
        $message = '';
        $error = '';

        $reason = trim((string)$reason);

        if ($reason === '') {
            $reason = 'Unknown CDEK sync error.';
        }

        $this->writeLog($reason);
        $this->writeDebug('fallback:reason', array('reason' => $reason));

        if ($this->hasValidLocalFile()) {
            $message = 'CDEK API is unavailable. Using local city file.';
            $this->writeDebug('fallback:use_local_file');
            return true;
        }

        $error = $reason;
        $this->writeDebug('fallback:fail_no_local_file');
        return false;
    }

    private function hasValidLocalFile()
    {
        if (!is_file($this->dataFile)) {
            return false;
        }

        $content = file_get_contents($this->dataFile);

        if ($content === false || $content === '') {
            return false;
        }

        $json = json_decode($content, true);

        return is_array($json);
    }

    private function getCountryCodesFilter($country_codes = array())
    {
        $countries = is_array($country_codes) && $country_codes ? $country_codes : array('RU', 'BY', 'KZ');

        $result = array();

        foreach ($countries as $country) {
            $country = strtoupper(trim((string)$country));

            if ($country !== '') {
                $result[] = $country;
            }
        }

        return array_values(array_unique($result));
    }

    private function buildCountryCodesQueryValue($country_codes)
    {
        $encoded = array();

        foreach ((array)$country_codes as $country_code) {
            $country_code = strtoupper(trim((string)$country_code));

            if ($country_code !== '') {
                $encoded[] = rawurlencode($country_code);
            }
        }

        return implode(',', $encoded);
    }

    private function isCountryAllowed($item, $allowed_country_codes)
    {
        if (!$allowed_country_codes) {
            return true;
        }

        if (!is_array($item)) {
            return false;
        }

        $country_code = '';

        if (isset($item['country_code'])) {
            $country_code = $item['country_code'];
        } elseif (isset($item['countryCode'])) {
            $country_code = $item['countryCode'];
        }

        $country_code = strtoupper(trim((string)$country_code));

        if ($country_code === '') {
            return false;
        }

        return isset($allowed_country_codes[$country_code]);
    }

    private function sendCurlRequestWithRetry($url, $method, $headers, $body, $attempts, &$http_code, &$error)
    {
        $attempts = max(1, (int)$attempts);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->sendCurlRequest($url, $method, $headers, $body, $http_code, $error);

            if ($response !== false) {
                return $response;
            }

            if ($attempt >= $attempts || !$this->isTimeoutError($error)) {
                return false;
            }

            $this->writeDebug('http:request_retry', array(
                'attempt' => $attempt + 1,
                'max_attempts' => $attempts,
                'url' => $this->sanitizeUrlForDebug($url),
                'reason' => 'timeout'
            ));

            usleep(self::RETRY_DELAY_MICROSECONDS);
        }

        return false;
    }

    private function isTimeoutError($error)
    {
        $error = strtolower((string)$error);

        return strpos($error, 'timed out') !== false || strpos($error, 'timeout') !== false;
    }

    private function sendCurlRequest($url, $method, $headers, $body, &$http_code, &$error)
    {
        $http_code = 0;
        $error = '';

        // $this->writeDebug('http:request_start', array(
        //     'method' => strtoupper((string)$method),
        //     'url' => $this->sanitizeUrlForDebug($url),
        //     'connect_timeout' => self::CURL_CONNECT_TIMEOUT,
        //     'timeout' => self::CURL_TIMEOUT
        // ));

        if (!function_exists('curl_init')) {
            $error = 'cURL extension is not available.';
            $this->writeDebug('http:curl_missing');
            return false;
        }

        $ch = curl_init();

        if (!$ch) {
            $error = 'Failed to initialize cURL.';
            $this->writeDebug('http:curl_init_failed');
            return false;
        }

        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => 1,
            // CURLOPT_HEADER => false,
            // CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CURL_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::CURL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            // CURLOPT_USERAGENT => 'xd_checkout_city_sync/1.0',
            // CURLOPT_ENCODING => ''
        );

        if ($headers) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        if (strtoupper($method) === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = 'cURL error: ' . curl_error($ch);
            $this->writeDebug('http:request_error', array('error' => $error));
            curl_close($ch);
            return false;
        }

        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // $this->writeDebug('http:request_success', array('http_code' => $http_code));

        return $response;
    }

    private function acquireLock(&$error)
    {
        $error = '';

        $handle = @fopen($this->lockFile, 'c');

        if (!$handle) {
            $error = 'Failed to open city sync lock file.';
            $this->writeDebug('lock:open_failed', array('file' => $this->lockFile));
            return false;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            $error = 'Failed to acquire city sync lock.';
            $this->writeDebug('lock:acquire_failed', array('file' => $this->lockFile));
            return false;
        }

        // $this->writeDebug('lock:acquired', array('file' => $this->lockFile));

        return $handle;
    }

    private function releaseLock($handle)
    {
        if ($handle) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            // $this->writeDebug('lock:released', array('file' => $this->lockFile));
        }
    }

    private function isDebugEnabled()
    {
        $settings = $this->getSettings();

        return !empty($settings['debug']);
    }

    private function getSettings()
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $this->settings = array();

        if ($this->config) {
            $xd_checkout = $this->config->get('xd_checkout');

            if (is_array($xd_checkout)) {
                $this->settings = $xd_checkout;
            }
        }

        return $this->settings;
    }

    private function getDebugLogger()
    {
        if ($this->debugLogger === null) {
            $this->debugLogger = new Log($this->debugLogFile);
        }

        return $this->debugLogger;
    }

    private function firstNonEmpty($values)
    {
        foreach ((array)$values as $value) {
            $value = trim((string)$value);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function constantOrEmpty($name)
    {
        return defined($name) ? (string)constant($name) : '';
    }

    private function sanitizeUrlForDebug($url)
    {
        $parts = parse_url((string)$url);

        if (!$parts || !isset($parts['query'])) {
            return (string)$url;
        }

        parse_str($parts['query'], $params);

        if (isset($params['client_secret'])) {
            $params['client_secret'] = '***';
        }

        if (isset($params['client_id'])) {
            $params['client_id'] = '***';
        }

        $query = http_build_query($params);

        $base = '';

        if (isset($parts['scheme'])) {
            $base .= $parts['scheme'] . '://';
        }

        if (isset($parts['host'])) {
            $base .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }

        if (isset($parts['path'])) {
            $base .= $parts['path'];
        }

        return $query !== '' ? ($base . '?' . $query) : $base;
    }

    private function writeLog($message)
    {
        if ($this->log && method_exists($this->log, 'write')) {
            $this->log->write('[xd_checkout] ' . $message);
        }
    }

    private function writeDebug($event, $context = array())
    {
        if (!$this->isDebugEnabled()) {
            return;
        }

        $line = '[xd_checkout][cities_updater][' . $event . ']';

        if ($context) {
            $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $options |= JSON_INVALID_UTF8_SUBSTITUTE;
            }

            $json = json_encode($context, $options);
            $line .= ' ' . ($json !== false ? $json : 'context_encode_failed');
        }

        $this->getDebugLogger()->write($line);
    }
}

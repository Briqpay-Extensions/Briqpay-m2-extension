<?php

namespace Briqpay\Payments\Rest;

use Magento\Framework\HTTP\Client\Curl;

class BriqpayCurl extends Curl
{
    /**
     * Make a PATCH request
     *
     * @param string $uri
     * @param string $params
     * @return void
     */
    public function patch($uri, $params)
    {
        $this->_ch = curl_init(); // Initialize cURL session
        curl_setopt($this->_ch, CURLOPT_URL, $uri);
        curl_setopt($this->_ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->_ch, CURLOPT_POSTFIELDS, $params);

        // Set headers
        $headers = [];
        foreach ($this->_headers as $header => $value) {
            $headers[] = "$header: $value";
        }
        if (!empty($headers)) {
            curl_setopt($this->_ch, CURLOPT_HTTPHEADER, $headers);
        }

        $this->_responseBody = curl_exec($this->_ch);
        $this->_responseStatus = curl_getinfo($this->_ch, CURLINFO_HTTP_CODE);

        if ($this->_responseBody === false) {
            $this->_responseError = curl_error($this->_ch);
        }

        curl_close($this->_ch);
    }
}

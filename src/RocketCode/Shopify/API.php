<?php namespace RocketCode\Shopify;

/**
 * Class API
 * @package RocketCode\Shopify
 */
class API
{
	private $_API = array();
	private static $_KEYS = array('API_KEY', 'API_SECRET', 'ACCESS_TOKEN', 'SHOP_DOMAIN');

	const PREFIX = '/admin';

	/**
	 * Checks for presence of setup $data array and loads
	 * @param bool $data
	 */
	public function __construct($data = FALSE)
	{
		if (is_array($data))
		{
			$this->setup($data);
		}
	}

	/**
	 * Verifies data returned by OAuth call
	 * https://help.shopify.com/api/getting-started/authentication/oauth#confirming-installation
	 *
	 * @param array|string $data
	 * @return bool
	 * @throws \Exception
	 */
	public function verifyRequest($data = NULL, $bypassTimeCheck = FALSE)
	{
		$da = array();
		if (is_string($data))
		{
			$each = explode('&', $data);
			foreach($each as $e)
			{
				list($key, $val) = explode('=', $e);
				$da[$key] = $val;
			}
		}
		elseif (is_array($data))
		{
			$da = $data;
		}
		else
		{
			throw new \Exception('Data passed to verifyRequest() needs to be an array or URL-encoded string of key/value pairs.');
		}

		// Timestamp check; 1 hour tolerance
		if (!$bypassTimeCheck)
		{
			if (($da['timestamp'] - time() > 3600))
			{
				throw new \Exception('Timestamp is greater than 1 hour old. To bypass this check, pass TRUE as the second argument to verifyRequest().');
			}
		}

		//Ensure the provided nonce is the same one that your application provided to Shopify during the Step 2: Asking for permission.
		if ( !\Cache::has($cache_key = $da['shop'].'_oauth_state') || \Cache::get($cache_key) != $da['state'] )
		{
			throw new \Exception('Invalid nonce.');
		}

		//Ensure the provided hostname parameter is a valid hostname, ends with myshopify.com, and does not contain characters other than letters (a-z), numbers (0-9), dots, and hyphens.
		if (array_key_exists('shop', $da)) {
			$validHostnameRegex = '/^(([a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9\-]*[a-zA-Z0-9])\.)*([A-Za-z0-9]|[A-Za-z0-9][A-Za-z0-9\-]*[A-Za-z0-9])$/';
			if( ! preg_match($validHostnameRegex, $da['shop']) )
			{
				throw new \Exception('Shop parameter is not a valid hostname');
			}

			if ( substr($da['shop'], -13) != 'myshopify.com' )
			{
				throw new \Exception('Shop parameter does not end in myshopify.com');
			}

			$validShopifyNames = '/^[a-zA-Z0-9.-]+$/';
			if ( ! preg_match($validShopifyNames, $da['shop']) )
			{
				throw new \Exception('Shop parameter is not a valid shopify shop name.');
			}

		}
		else
		{
			throw new \Exception('Shop parameter missing');
		}

		//Ensure the provided hmac is valid. The hmac is signed by Shopify as explained below, in the Verification section.
		if (array_key_exists('hmac', $da))
		{
			// HMAC Validation
			$queryString = http_build_query(array('code' => $da['code'], 'shop' => $da['shop'], 'state' => $da['state'], 'timestamp' => $da['timestamp']));
			$match = $da['hmac'];
			$calculated = hash_hmac('sha256', $queryString, $this->_API['API_SECRET']);
		}
		else
		{
			// MD5 Validation, to be removed June 1st, 2015
			$queryString = http_build_query(array('code' => $da['code'], 'shop' => $da['shop'], 'timestamp' => $da['timestamp']), NULL, '');
			$match = $da['signature'];
			$calculated = md5($this->_API['API_SECRET'] . $queryString);
		}

		return $calculated === $match;
	}

	/**
	 * Calls API and returns OAuth Access Token, which will be needed for all future requests
	 * @param string $code
	 * @return mixed
	 * @throws \Exception
	 */
	public function getAccessToken($code = '')
	{
		$dta = array('client_id' => $this->_API['API_KEY'], 'client_secret' => $this->_API['API_SECRET'], 'code' => $code);

		$data = $this->call(
			[
				'METHOD' => 'POST',
				'URL' => 'https://' . $this->_API['SHOP_DOMAIN'] . self::PREFIX . '/oauth/access_token',
				'DATA' => $dta
			],
			FALSE);

		return $data->access_token;
	}

	/**
	 * Returns a string of the install URL for the app
     * and optionally stores in the cache some extra data about this store
     * that will expire together with the nonce
	 * @param array $data
     * @param mixed $extraData
	 * @return string
	 */
	public function installURL($data = array(), $extraData = null)
	{
		// https://{shop}.myshopify.com/admin/oauth/authorize?client_id={api_key}&scope={scopes}&redirect_uri={redirect_uri}
        $state = str_random(32);
        \Cache::put($this->_API['SHOP_DOMAIN'] . '_oauth_state', $state, 60);
        if (!is_null($extraData)) {
            \Cache::put($this->_API['SHOP_DOMAIN'] . '_extra_data', $extraData, 60);
        }
		return 'https://' . $this->_API['SHOP_DOMAIN'] . self::PREFIX . '/oauth/authorize?client_id=' . $this->_API['API_KEY'] . '&state=' . urlencode($state) . '&scope=' . urlencode(implode(',', $data['permissions'])) . (!empty($data['redirect']) ? '&redirect_uri=' . urlencode($data['redirect']) : '') . (!empty($data['grant_options']) ? '&grant_options[]=' . urlencode($data['grant_options']) : '');
	}

    /**
     * Returns the optional extra data stored by installURL()
     * @return mixed
     */
	public function getExtraData()
    {
        return \Cache::get($this->_API['SHOP_DOMAIN'] . '_extra_data');
    }

	/**
	 * Loops over each of self::$_KEYS, filters provided data, and loads into $this->_API
	 * @param array $data
	 */
	public function setup($data = array())
	{

		foreach (self::$_KEYS as $k)
		{
			if (array_key_exists($k, $data))
			{
				$this->_API[$k] = self::verifySetup($k, $data[$k]);
			}
		}
	}

	/**
	 * Checks that data provided is in proper format
	 * @example Removes http(s):// from SHOP_DOMAIN
	 * @param string $key
	 * @param string $value
	 * @return string
	 */
	private static function verifySetup($key = '', $value = '')
	{
		$value = trim($value);

		switch ($key)
		{
			case 'SHOP_DOMAIN':
				preg_match('/(https?:\/\/)?(([a-zA-Z0-9\-\.])+)/', $value, $matched);
				return $matched[2];
				break;

			default:
				return $value;
		}
	}

	/**
	 * Checks that data provided is in proper format
	 * @example Checks for presence of /admin/ in URL
	 * @param array $userData
	 * @return array
	 */
	private function setupUserData($userData = array())
	{
		$returnable = array();

		foreach($userData as $key => $value)
		{
			switch($key)
			{
				case 'URL':
					// Remove shop domain
					$url = str_replace($this->_API['SHOP_DOMAIN'], '', $value);

					// Verify it contains /admin/
					if (strpos($url, '/admin/') !== 0)
					{
						$url = str_replace('//', '/', '/admin/' . preg_replace('/\/?admin\/?/', '', $url));
					}
					$returnable[$key] = $url;
					break;

				default:
					$returnable[$key] = $value;

			}
		}

		return $returnable;
	}


	/**
	 * Executes the actual cURL call based on $userData
	 * @param array $userData
	 * @return mixed
	 * @throws \Exception
	 */
    public function call($userData = array(), $verifyData = TRUE)
    {
	    if ($verifyData)
	    {
		    foreach (self::$_KEYS as $k)
		    {
			    if ((!array_key_exists($k, $this->_API)) || (empty($this->_API[$k])))
			    {
				    throw new \Exception($k . ' must be set.');
			    }
		    }
	    }

	    $defaults = array(
		    'CHARSET'       => 'UTF-8',
		    'METHOD'        => 'GET',
		    'URL'           => '/',
			'HEADERS'       => array(),
	        'DATA'          => array(),
	        'FAILONERROR'   => TRUE,
	        'RETURNARRAY'   => FALSE,
	        'ALLDATA'       => FALSE
	    );

	    if ($verifyData)
	    {
		    $request = $this->setupUserData(array_merge($defaults, $userData));
	    }
	    else
	    {
		    $request = array_merge($defaults, $userData);
	    }


	    // Send & accept JSON data
	    $defaultHeaders = array();
	    $defaultHeaders[] = 'Content-Type: application/json; charset=' . $request['CHARSET'];
	    $defaultHeaders[] = 'Accept: application/json';
	    if (array_key_exists('ACCESS_TOKEN', $this->_API))
	    {
		    $defaultHeaders[] = 'X-Shopify-Access-Token: ' . $this->_API['ACCESS_TOKEN'];
	    }

        $headers = array_merge($defaultHeaders, $request['HEADERS']);


	    if ($verifyData)
	    {
		    $url = 'https://' . $this->_API['API_KEY'] . ':' . $this->_API['ACCESS_TOKEN'] . '@' . $this->_API['SHOP_DOMAIN'] . $request['URL'];
	    }
	    else
	    {
		    $url = $request['URL'];
	    }

	    // cURL setup
        $ch = curl_init();
        $options = array(
            CURLOPT_RETURNTRANSFER  => TRUE,
            CURLOPT_URL             => $url,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_CUSTOMREQUEST   => strtoupper($request['METHOD']),
            CURLOPT_ENCODING        => '',
            CURLOPT_USERAGENT       => 'RocketCode Shopify API Wrapper',
            CURLOPT_FAILONERROR     => $request['FAILONERROR'],
            CURLOPT_VERBOSE         => $request['ALLDATA'],
            CURLOPT_HEADER          => 1
        );

	    // Checks if DATA is being sent
	    if (!empty($request['DATA']))
	    {
		    if (is_array($request['DATA']))
		    {
			    $options[CURLOPT_POSTFIELDS] = json_encode($request['DATA']);
		    }
		    else
		    {
			    // Detect if already a JSON object
			    json_decode($request['DATA']);
			    if (json_last_error() == JSON_ERROR_NONE)
			    {
				    $options[CURLOPT_POSTFIELDS] = $request['DATA'];
			    }
			    else
			    {
				    throw new \Exception('DATA malformed.');
			    }
		    }
	    }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        // Data returned
        $result = json_decode(substr($response, $headerSize), $request['RETURNARRAY']);


        // Headers
        $info = array_filter(array_map('trim', explode("\n", substr($response, 0, $headerSize))));

        foreach($info as $k => $header)
        {
	        if (strpos($header, 'HTTP/') > -1)
	        {
                $_INFO['HTTP_CODE'] = $header;
                continue;
            }

            list($key, $val) = explode(':', $header);
            $_INFO[trim($key)] = trim($val);
        }


        // cURL Errors
        $_ERROR = array('NUMBER' => curl_errno($ch), 'MESSAGE' => curl_error($ch));

        curl_close($ch);

	    if ($_ERROR['NUMBER'])
	    {
		    throw new \Exception('ERROR #' . $_ERROR['NUMBER'] . ': ' . $_ERROR['MESSAGE']);
	    }


	    // Send back in format that user requested
	    if ($request['ALLDATA'])
	    {
		    if ($request['RETURNARRAY'])
		    {
			    $result['_ERROR'] = $_ERROR;
			    $result['_INFO'] = $_INFO;
		    }
		    else
		    {
		        if (!is_object($result)) {
		            $result = new \stdClass();
                }
			    $result->_ERROR = $_ERROR;
			    $result->_INFO = $_INFO;
		    }
		    return $result;
	    }
	    else
	    {
		    return $result;
	    }
    }

    public function createWebhook($topic, $address) {
        $this->call([
            'URL' => self::PREFIX . '/webhooks.json',
            'METHOD' => 'POST',
            'DATA' => [
                'webhook' => [
                    'topic' => $topic,
                    'address' => $address,
                    'format' => 'json',
                ]
            ]
        ]);
    }

    public function updateWebhook($id, $address) {
        $this->call([
            'URL' => self::PREFIX . '/webhooks/' . intval($id) . '.json',
            'METHOD' => 'PUT',
            'DATA' => [
                'webhook' => [
                    'id' => intval($id),
                    'address' => $address,
                ]
            ]
        ]);
    }

    public function deleteWebhook($id) {
        $this->call([
            'URL' => self::PREFIX . '/webhooks/' . intval($id) . '.json',
            'METHOD' => 'DELETE',
        ]);
    }

    /**
     * Make sure the passed webhooks (and only those) are up
     * @param array $targetWebhooks Array of webhooks in the form [topic => url, ...]
     *
     */
    public function setupWebhooks(array $targetWebhooks) {
        $call = $this->call([
            'URL' => self::PREFIX . '/webhooks.json',
            'METHOD' => 'GET',
        ]);
        foreach ($call->webhooks as $webhook) {
            if (!array_key_exists($webhook->topic, $targetWebhooks)) {
                $this->deleteWebhook($webhook->id);
            } else {
                if ($webhook->address != $targetWebhooks[$webhook->topic]) {
                    $this->updateWebhook($webhook->id, $targetWebhooks[$webhook->topic]);
                }
                unset($targetWebhooks[$webhook->topic]); // let's remove from $target this webhook that we already processed
            }
        }
        foreach ($targetWebhooks as $topic => $address) {
            $this->createWebhook($topic, $address);
        }
    }

} // End of API class
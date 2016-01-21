<?php

namespace Naji\T411;

/**
 * Base T411 library class, provides universal functions and variables
 *
 * @package T411
 * @author Naji Astier
 **/
class Client
{
    /**
     * login to connect to T411
     *
     * @var string
     */
	private $login;

    /**
     * password to connect to T411
     *
     * @var string
     */
	private $password;

    /**
     * token to use T411 API
     *
     * @var string
     */
	private $token;

    /**
     * Path to cache folder where cookies are stored
     *
     * @var string
     */
	private $cacheFolder;

    /**
     * Base url for T411
     *
     * @var string
     */
	private $baseUrl;

    /**
     * @param string $login Login to connect to T411
     * @param string $password Password to connect to T411
     * @param string $cacheFolder Path to cache folder where cookies are stored
     * @param string $baseUrl Domain name of T411 without trailing slash
     */
	public function __construct($login, $password, $cacheFolder, $baseUrl = 'https://api.t411.in')
	{
		$this->login       = $login;
		$this->password    = $password;
		$this->cacheFolder = $cacheFolder;
		$this->baseUrl     = $baseUrl;
		$this->loadToken();
	}

    /**
     * Send a request using cURL
     *
     * @param string $url URL of the request
     * @param string $params Post parameters to send
     * @param string $post Boolean whether it is a post request or not
     * @return array
     */
	private function sendRequest($url, $params, $post = 1, $headers = array('Accept: application/json, text/javascript, */*; q=0.01', 'Content-Type=application/x-www-form-urlencoded'))
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		if (null !== $params)
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, null, '&'));
		curl_setopt($ch, CURLOPT_POST, $post);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		if (isset($this->token))
		{
			$headers[] = 'Authorization: '.$this->token;
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cacheFolder."/cookies.txt");
		curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cacheFolder."/cookies.txt");
		// Fake user agent to simulate a real browser
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu Chromium/41.0.2272.76 Chrome/41.0.2272.76 Safari/537.36");
		curl_setopt($ch, CURLOPT_REFERER, $this->baseUrl);
		$response = curl_exec($ch);

		$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$header      = substr($response, 0, $header_size);
		$body        = substr($response, $header_size);
		$last_url    = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
		$info        = curl_getinfo($ch);

		curl_close($ch);

		return array('body' => $body, 'header' => $header, 'url' => $last_url);
	}

    /**
     * Convert an associative array into get parameters
     *
     * @param array $params Get parameters to convert
     * @return string
     */
	private function queryString($params)
	{
		$querystring = '?';
		foreach($params as $k => $v) {
			$querystring .= $k.'='.urlencode($v).'&';
		}
		return substr($querystring, 0, -1);
	}

    /**
     * Save token into a file
     */
	private function saveToken()
	{
		$fd = fopen($this->cacheFolder."/token.txt", 'w');
		fwrite($fd, $this->token);
		fclose($fd);
	}

    /**
     * Load token from a file
     */
	private function loadToken()
	{
		if (file_exists($this->cacheFolder."/token.txt"))
		{
			$fd = fopen($this->cacheFolder."/token.txt", 'r');
			$this->token = fgets($fd);
			fclose($fd);
		} else {
			$this->connect();
		}
	}

    /**
     * Connect to the T411 website to save the token key
     */
	public function connect()
	{
		$request  = $this->sendRequest($this->baseUrl."/auth", array('username' => $this->login, 'password' => $this->password), 2);

		$response = json_decode($request['body'], true);
		if (array_key_exists('error', $response))
		{
			throw new \Exception('Erreur '.$response['code'].' : '.$response['error']);
		} else {
			$this->token = $response['token'];
			$this->saveToken();
		}
	}

    /**
     * Perform a search on T411 website
     *
     * @param string $params GET parameters to send (filters)
     * @return array
     */
	public function search($query, $params = array())
	{
		// Set default category to "Série TV"
		if (!isset($params['cid']))
			$params['cid'] = '433';
		if (!isset($params['offset']))
			$params['offset'] = '0';
		if (!isset($params['limit']))
			$params['limit'] = '200';

		$request = $this->sendRequest($this->baseUrl."/torrents/search/".urlencode($query).$this->queryString($params), null, 0);

		$response = json_decode($request['body'], true);
		if (array_key_exists('error', $response))
		{
			if ($response['code'] == 201 || $response['code'] == 202) {
				$this->connect();
				return $this->search($query, $params);
			}
			throw new \Exception('Erreur '.$response['code'].' : '.$response['error']);
		} else {
			$torrents = array();
			if (intval($response['total']) > 0)
			{
				foreach ($response['torrents'] as $torrent){
					$torrents[] = array_merge($torrent, array(
						'url'             => 'http://www.t411.in/torrents/'.$torrent['rewritename'],
						'id'              => intval($torrent['id']),
						'seeders'         => intval($torrent['seeders']),
						'leechers'        => intval($torrent['leechers']),
						'comments'        => intval($torrent['comments']),
						'size'            => intval($torrent['size']),
						'times_completed' => intval($torrent['times_completed']),
						'owner'           => intval($torrent['owner']),
						'isVerified'      => (boolean) intval($torrent['isVerified']),
					));
				}
			}

			return $torrents;
		}
	}

    /**
     * Download the .torrent from ID
     *
     * @param string $id   The id of the torrent to download
     * @param string $path The path to save the .torrent
     */
	public function downloadTorrent($id, $path = '')
	{
		$request  = $this->sendRequest($this->baseUrl."/torrents/download/".$id, null, 0);
		$response = json_decode($request['body'], true);

		if (NULL === $response)
		{
			preg_match('/filename="(.*?)"/i', $request['header'], $filename);

			$file = fopen($path.'/'.$filename[1], 'w+');
			fwrite($file, $request['body']);
			fclose($file);
		} else {
			if (array_key_exists('error', $response))
			{
				if ($response['code'] == 201 || $response['code'] == 202) {
					$this->connect();
					return $this->downloadTorrent($id, $path);
				}
				throw new \Exception('Erreur '.$response['code'].' : '.$response['error']);
			}
		}
	}
}

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
	public function __construct($login, $password, $cacheFolder, $baseUrl = 'http://www.t411.io')
	{
		$this->login       = $login;
		$this->password    = $password;
		$this->cacheFolder = $cacheFolder;
		$this->baseUrl     = $baseUrl;
	}

    /**
     * Send a request using cURL
     *
     * @param string $url URL of the request
     * @param string $params Post parameters to send
     * @param string $post Boolean whether it is a post request or not
     * @return array
     */
	private function sendRequest($url, $params, $post = 1)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		if (null !== $params)
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, null, '&'));
		curl_setopt($ch, CURLOPT_POST, $post);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cacheFolder."/cookies.txt");
		curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cacheFolder."/cookies.txt");
		// Fake user agent to simulate a real browser
		curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu Chromium/40.0.2214.111 Chrome/40.0.2214.111 Safari/537.36");
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
     * Connect to the T411 website to generate cookies file
     */
	public function connect()
	{
		$this->sendRequest($this->baseUrl."/users/login/", array('url' => '/', 'remember' => 1, 'login' => $this->login, 'password' => $this->password));
	}

    /**
     * Perform a search on T411 website
     *
     * @param string $params GET parameters to send (filters)
     * @return array
     */
	public function search($params = array('search' => ''))
	{
		// Set default type to "Film/Video"
		if (!isset($params['cat']))
			$params['cat'] = '210';
		// Set default category to "Série TV"
		if (!isset($params['subcat']))
			$params['subcat'] = '433';
		$params['search'] = '@name '.$params['search'];

		$request = $this->sendRequest($this->baseUrl."/torrents/search/".$this->queryString($params), null, 0);
		// We search for all the names and links
		$results = preg_match_all('/<a href="\/\/(.*?)" title="(.*?)">/i', $request['body'], $links, PREG_SET_ORDER);
		// We search for all the number of seeders
		preg_match_all('/<td align="center" class="up">(.*?)<\/td>/i', $request['body'], $seeders, PREG_SET_ORDER);
		$torrents = array();
		if ($results)
		{
			for ($i = 0; $i < count($links); $i++) {
				$torrents[] = array('url' => $links[$i][1], 'name' => trim($links[$i][2]), 'seeder' => intval($seeders[$i][1]));
			}
		}

		return $torrents;
	}

    /**
     * Download the .torrent at a T411 URL
     *
     * @param string $url The URL of the torrent to download
     * @param string $path The path to save the .torrent
     */
	public function downloadTorrent($url, $path = '')
	{
		$req = $this->sendRequest($url, null, 0);

		$result = preg_match('/<a href="(.*?)" class="btn">T&#233;l&#233;charger<\/a>/i', $req['body'], $link);
		if ($result)
		{
			if (preg_match('/users\/login/i', $link[1]))
			{
				$this->connect();
				return $this->downloadTorrent($url);
			}

			$request = $this->sendRequest($this->baseUrl.$link[1], null, 0);

			preg_match('/filename="(.*?)"/i', $request['header'], $filename);

			$file = fopen($path.'/'.$filename[1], 'w+');
			fwrite($file, $request['body']);
			fclose($file);
		}
	}
}
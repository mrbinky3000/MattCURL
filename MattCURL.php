<?php
/**
 * @package mframe
 */
/**
 * MattCURL
 * 
 * This class wraps around PHP's CURL wrapper (a double wrapper) in order to
 * provide common CURL design patterns.
 *
 * Requires PHP version 5.1 or higher
 * 
 * @author Matthew Toledo <matthew.toledo@g####.com>
 * @subpackage classes
 */
class MattCURL
{
    
    public static $s_user_agent;
    public static $i_max_redirects;
	public static $i_connect_timeout;
	public static $i_fetch_timeout;
    
    public function __construct()
    {
        self::$s_user_agent = "MattCURL bot ({$_SERVER['HTTP_HOST']})";
        self::$i_max_redirects = 10;
		self::$i_connect_timeout = 30;
		self::$i_fetch_timeout = 30;
    }
    
    /**
     * Fetch the contents of a remote page after sending optional arguments via 
     * POST or GET
     * 
     * Features:
     * - Automatically follows redirects
     * 
     * Known Issues:
     * - If you are POST'ing large amounts of data and the server is in 
     *   "Safe Mode" or has been locked down with the base_dir ini setting, we
     *   resort to using our curl_redirect_exec() method.  Unforutnately, if
     *   there are redirects, curl_redirect_exec() has no choice but to POST
     *   that data to each page it encounters.  This can cause problems.
     * - If debugging is on and there are a large amount of redirects, FirePHP
     *   inflates the headers exponentially and can break things.
     * 
     * Returns an associative array:
     * - s_content : the body of the remote page,
     * - i_err : Curl error number if there was an error. Otherwise 0.
     * - s_errmsg : Error message or an empty string
     * - a_header : Associative array holding information about last transfer
     * 
     * @param string $s_url The URL of the remote page you wish to retreive.
     * @param array $a_args optional A list of arguments to send via POST or GET to the $s_url
     * @param boolean $b_post optional When TRUE sends any $a_args as POST.  Otherwise as GET 
     * @param type $s_credentials optional A string with the username and password formatted like so "username:password"
     * @return array
     */
    public static function get_web_page($s_url, $a_args = array(), $b_post = TRUE, $s_credentials = '')
    {
        
        $a_return = array();
        
        // init the CURL object
        $o_ch = curl_init($s_url);

        // Create a variable to hold GET args if we are passing GET args to 
        // the destination URL
        $s_url_data = ''; 
        if (!$b_post)
        {
            if (count($a_args)) 
            {
                $s_url_data = '?';

                foreach ($a_args as $s_key => $s_value)
                {
                        $s_url_data = urlencode($s_key) . '=' . urlencode($s_value) . '&amp;';
                }

                $s_url_data = substr($s_url_data, 0, -5);
            }                
        }


        // set options
        curl_setopt($o_ch, CURLOPT_RETURNTRANSFER, true); // return web page
        curl_setopt($o_ch, CURLOPT_HEADER, false); // don't return headers
        curl_setopt($o_ch, CURLOPT_ENCODING, ''); // handle all encodings
        curl_setopt($o_ch, CURLOPT_USERAGENT, self::$s_user_agent ); // set a name or some firewalls will block
        curl_setopt($o_ch, CURLOPT_CONNECTTIMEOUT, self::$i_connect_timeout);
        curl_setopt($o_ch, CURLOPT_TIMEOUT, self::$i_fetch_timeout);
		curl_setopt($o_ch, CURLOPT_FRESH_CONNECT, true); // don't use a cached version of the url
        // set options for POST (if we are sending args via post)
        if ($b_post) 
        {
            curl_setopt($o_ch, CURLOPT_POST, true);
            curl_setopt($o_ch, CURLOPT_POSTFIELDS, $a_args);
        }
		// if this is http_auth protected
		if ($s_credentials)
		{
			curl_setopt($o_ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
			curl_setopt($o_ch, CURLOPT_USERPWD, $s_credentials);			
		}
        // normal operation
        if (ini_get('open_basedir') == '' && ini_get('safe_mode' == 'Off'))
        {
            curl_setopt($o_ch, CURLOPT_FOLLOWLOCATION, true); // follow redirects
            curl_setopt($o_ch, CURLOPT_AUTOREFERER, true); // be polite: set referer on redirect
            curl_setopt($o_ch, CURLOPT_MAXREDIRS, self::$i_max_redirects); // be safe: stop after X redirects
            $s_content	= curl_exec( $o_ch );

        }

        // work-arround if this server has safemode or open_base dir 
        // restricting access -- which is completely useless because I can 
        // program arround it...
        else
        {
            $s_content = self::curl_redirect_exec( $o_ch );   
        }
        $i_err      = curl_errno( $o_ch );
        $s_errmsg   = curl_error( $o_ch );
        $a_header   = curl_getinfo( $o_ch );       
        curl_close( $o_ch );
        $a_return = array(
            's_content' => $s_content,
            'i_err'     => $i_err ,
            's_errmsg'  => $s_errmsg,
            'a_header'  => $a_header
        );
        return $a_return;

    }

    /**
     * Follow redirects regardless of PHP security settings.
     * 
     * When PHP is in "Safe Mode" or has been locked down with the base_dir
     * ini setting, CURL is prevented from following page redirects for 
     * security reasons.  I guess it helps with XSS.  It obviously doesn't
     * help if the attacker can upload PHP code to the server, because you
     * can easily circumvent this by emulating redirection.
     * 
     * This method follows the redirects until one of the following 
     * conditions are met:
     * - There are no more redirects, 
     * - An erro is encountered
     * - The max redirects limit is reached.
     * 
     * @param curl $o_ch
     * @param integer $i_redirects optional counts the redirects
     * @param boolean $b_curlopt_header optional True includes header in $s_content
     * @return array 
     */
    public static function curl_redirect_exec($o_ch, &$i_redirects = 0, $b_curlopt_header = FALSE) 
    {
        
        curl_setopt($o_ch, CURLOPT_RETURNTRANSFER, true); // return web page
        curl_setopt($o_ch, CURLOPT_HEADER, true); // return header in the body so we can parse it
        $s_response = curl_exec($o_ch);
        $i_err      = curl_errno( $o_ch );
        $s_errmsg   = curl_error( $o_ch );
        $a_info     = curl_getinfo( $o_ch ); 
        
        $s_return = '';
        
        // break recursion if there was an error
        if (!$i_err)
        {

            // Discard "HTTP/1.1 100 Continue" on some servers when POST'ing.
            $a_response_parts = explode("\r\n\r\n", $s_response);
            if ('http/1.1 100 continue' == trim(strtolower($a_response_parts[0])))
            {
                array_shift($a_response_parts);
            }
            
            // Seperate HTTP response header from body
            $s_header = array_shift($a_response_parts);
            $s_body = implode("\r\n\r\n",$a_response_parts);

            // if we encounter a redirect code, open the new page and follow recursively
            if ($a_info['http_code'] == 301 || $a_info['http_code'] == 302 || $a_info['http_code'] == 303) 
            {
                $a_matches = array();
                preg_match('/(Location:|URI:)(.*?)\n/', $s_header, $a_matches);
                $s_url = trim(array_pop($a_matches));
                $a_url_parsed = @parse_url($s_url);
                if (is_array($a_url_parsed)) 
                {
                    curl_setopt($o_ch, CURLOPT_URL, $s_url);
                    $i_redirects++;

                    // break recursion if there are too many redirects
                    if ($i_redirects < self::$i_max_redirects)
                    {
                        return self::curl_redirect_exec($o_ch, $i_redirects);
                    }
                    else
                    {
                        $s_errmsg = 'Max Redirects Reached';
                    }

                }
            }

        }
        
       
        if ($b_curlopt_header)
            $s_return = $s_response;
        else 
        {
            $s_return = $s_body;
        }
        
      return $s_return;
    }
	
    
}

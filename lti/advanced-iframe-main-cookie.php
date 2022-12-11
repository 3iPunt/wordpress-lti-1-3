<?php
/**
 *  This class fixes cookies that do not have Secret;SameSite=None set. 
 * 
 *  See 
 *  https://www.tinywebgallery.com/blog/advanced-iframe/advanced-iframe-pro-demo/how-to-use-the-samesite-cookie-fix 
 *  how to use this class directly. 
 *
 *  $filter = 'ALL' - all cookies are modified
 *  $filter = 'cookie1,cookie2' - cookie1 and cookie2 are modified
 *  $filter = 'cookie1,cookie2,parameter=aiFixSameSite' - cookie1 and cookie2 are only modified if the get parameter 
 *            is set to true. e.g. ?aiFixSameSite=true This setting has to be at the end and is saved in a 
 *            session cookie. This makes it possilbe to modify the cookies only if it is in an iframe 
 * 
 *  Debugging: You can enable the debug mode if you call you page with ?aiDebugCookies=true 
 *  or add aiDebugCookies to your $filter or set the false in line 18 to true.
 */
class AdvancedIframeCookie {
    static function addCookieSameSite($filter) {
		 // Enable this to debug the outcome on top of your page.
		 $debug = (isset($_GET['aiDebugCookies'])) || AdvancedIframeCookie::aiContains($filter, "aiDebugCookies") || false; 
		 $first = true;
		 $enableSameSiteFix = !empty($filter);
		 $cookieName = '';
		 $filterArray = array();
		 
		 // Find the get parameter name and fill the filter array with the cookie names. 
		 foreach (explode (',', $filter) as $value) {
			  if (AdvancedIframeCookie::aiContains($value, "=")) {
				 $value_array = explode ('=', $value);
				 $cookieName = trim($value_array[1]);
			  } else {
				 $filterArray[] = trim($value); 
			  }
		 }
		 
		 // GET parameter to enable the fix only when cookieName is set
		 if ($cookieName !== '' && isset($_GET[$cookieName])) {
			if ($_GET[$cookieName] === 'true') {
			  header("Set-Cookie: ".$cookieName."=1; path=/; Secure; SameSite=None");
			  $enableSameSiteFix = true;
			} else {
			  header("Set-Cookie: ".cookieName."=1; expires=0; path=/; Secure; SameSite=None");
			}
		}
		
		if(isset($_COOKIE['cookieName'])){
	        $enableSameSiteFix = true;
		}
		
		// This is a check if the page is in an iframe - not supported yet by all browsers! 
		// See https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Sec-Fetch-Dest -> 
		// Therefore the get parameter defined above should be used until then.
		// if( isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] == 'iframe' ) {}
		
		if ($enableSameSiteFix && AdvancedIframeCookie::isSecure()) {
			foreach (headers_list() as $value) {
			   if (AdvancedIframeCookie::aiStartsWith($value, "Set-Cookie")) {
				   if (AdvancedIframeCookie::isSameSiteCookie($value, $filterArray)) {
					   if (!AdvancedIframeCookie::aiContains($value, "Secure")) {
						  $value .= '; Secure';
					   }
					   if (!AdvancedIframeCookie::aiContains($value, "SameSite")) {
						  $value .= '; SameSite=None';					 
					   }
				   }
				   // The first cookie replaces all existing ones
				   // https://www.php.net/manual/de/function.header.php -> See replace
				   // All the others are appended
				   if ($first) {
				     header($value);
					 $first = false;
				   } else {
					 header($value, false);
				   }					 
			   }
			}
			if ($debug) {
			   echo 'SameSite cookie fix applied...<br>';
			}
		} else if ($debug) {
			   echo 'SameSite cookie fix is not applied...<br>';
		}
		
		
		if ($debug) {
		    echo 'headers list: <br>';
	        echo '<pre style="white-space:pre-wrap;overflow:hidden;">';
			print_r(headers_list()); 
			echo '</pre>';
		}
	}
	
	static function isSameSiteCookie($cookie, $filterArray) {
		foreach ($filterArray as $value) {
			if ($value === 'ALL' || AdvancedIframeCookie::aiContains($cookie,trim($value) . '=')) {
				return true;
			}
		} 
		return false;		
	}
	
	static function aiStartsWith($haystack, $needle) {
         return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== FALSE;
    }
	
	static function aiContains($str, $substr) {
        return strpos($str, $substr) !== false;
    }
	
	/** 
	* Duplicate of AdvancedIframeHelper::isSecure to be able to use this class standalone.
	*/
    static function isSecure() {
	    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| $_SERVER['SERVER_PORT'] == 443;
	}
}
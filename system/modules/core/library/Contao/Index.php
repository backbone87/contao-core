<?php

namespace Contao;

/**
 * Class Index
 *
 * Main front end controller.
 * @copyright  Leo Feyer 2005-2012
 * @author     Leo Feyer <http://contao.org>
 * @package    Core
 */
class Index extends Frontend
{

	/**
	 * Initialize the object
	 */
	public function __construct()
	{
		// Try to read from cache
		$this->outputFromCache();

		// Redirect to the install tool
		if (!\Config::getInstance()->isComplete())
		{
			$this->redirect('contao/install.php');
		}

		// Load the user object before calling the parent constructor
		$this->import('FrontendUser', 'User');
		parent::__construct();

		// Check whether a user is logged in
		define('BE_USER_LOGGED_IN', $this->getLoginStatus('BE_USER_AUTH'));
		define('FE_USER_LOGGED_IN', $this->getLoginStatus('FE_USER_AUTH'));
	}


	/**
	 * Run the controller
	 */
	public function run()
	{
		$objDispatcher = \Dispatcher::forCurrentRequest();
		
		$objDispatcher->dispatch();
		
		$objDispatcher->hasError();
		$objDispatcher->getError();
		$objDispatcher->getPage();
		$objDispatcher->getRootPage();
		
		foreach($objDispatcher->getParams() as $strKey => $varValue) {
			\Input::setGet($strKey, $varValue);
		}
		\Input::setGet('language', $objDispatcher->getLanguage());
		
		// Use the global date format if none is set
		$objPage->dateFormat == '' && $objPage->dateFormat = $GLOBALS['TL_CONFIG']['dateFormat'];
		$objPage->timeFormat == '' && $objPage->timeFormat = $GLOBALS['TL_CONFIG']['timeFormat'];
		$objPage->datimFormat == '' && $objPage->datimFormat = $GLOBALS['TL_CONFIG']['datimFormat'];
		
		// Set the admin e-mail address
		list($GLOBALS['TL_ADMIN_NAME'], $GLOBALS['TL_ADMIN_EMAIL']) = $this->splitFriendlyName(
			$objPage->adminEmail == '' ? $GLOBALS['TL_CONFIG']['adminEmail'] : $objPage->adminEmail 
		);
		
		// Stop the script (see #4565)
		exit;
	}
	
	protected function hasAccess() {
		!$this->User->authenticate();
	}


	/**
	 * Try to load the page from the cache
	 */
	protected function outputFromCache()
	{
		// Build the page if a user is logged in or there is POST data
		if (!empty($_POST) || $_SESSION['TL_USER_LOGGED_IN'] || $_SESSION['DISABLE_CACHE'] || isset($_SESSION['LOGIN_ERROR']) || $GLOBALS['TL_CONFIG']['debugMode'])
		{
			return;
		}

		/**
		 * If the request string is empty, look for a cached page matching the
		 * primary browser language. This is a compromise between not caching
		 * empty requests at all and considering all browser languages, which
		 * is not possible for various reasons.
		 */
		if (\Environment::get('request') == '' || \Environment::get('request') == 'index.php')
		{
			// Return if the language is added to the URL and the empty domain will be redirected
			if ($GLOBALS['TL_CONFIG']['addLanguageToUrl'] && !$GLOBALS['TL_CONFIG']['doNotRedirectEmpty'])
			{
				return;
			}

			$arrLanguage = \Environment::get('httpAcceptLanguage');
			$strCacheKey = \Environment::get('base') .'empty.'. $arrLanguage[0];
		}
		else
		{
			$strCacheKey = \Environment::get('base') . \Environment::get('request');
		}

		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['getCacheKey']) && is_array($GLOBALS['TL_HOOKS']['getCacheKey']))
		{
			foreach ($GLOBALS['TL_HOOKS']['getCacheKey'] as $callback)
			{
				$this->import($callback[0]);
				$strCacheKey = $this->$callback[0]->$callback[1]($strCacheKey);
			}
		}

		$blnFound = false;

		// Check for a mobile layout
		if (\Environment::get('agent')->mobile)
		{
			$strCacheKey = md5($strCacheKey . '.mobile');
			$strCacheFile = TL_ROOT . '/system/cache/html/' . substr($strCacheKey, 0, 1) . '/' . $strCacheKey . '.html';

			if (file_exists($strCacheFile))
			{
				$blnFound = true;
			}
		}

		// Check for a regular layout
		if (!$blnFound)
		{
			$strCacheKey = md5($strCacheKey);
			$strCacheFile = TL_ROOT . '/system/cache/html/' . substr($strCacheKey, 0, 1) . '/' . $strCacheKey . '.html';

			if (file_exists($strCacheFile))
			{
				$blnFound = true;
			}
		}

		// Return if the file does not exist
		if (!$blnFound)
		{
			return;
		}

		$expire = null;
		$content = null;

		// Include the file
		ob_start();
		require_once $strCacheFile;

		// The file has expired
		if ($expire < time())
		{
			ob_end_clean();
			return;
		}

		// Read the buffer
		$strBuffer = ob_get_contents();
		ob_end_clean();

		// Session required to determine the referer
		$this->import('Session');
		$session = $this->Session->getData();

		// Set the new referer
		if (!isset($_GET['pdf']) && !isset($_GET['file']) && !isset($_GET['id']) && $session['referer']['current'] != \Environment::get('requestUri'))
		{
			$session['referer']['last'] = $session['referer']['current'];
			$session['referer']['current'] = \Environment::get('requestUri');
		}

		// Store the session data
		$this->Session->setData($session);

		// Load the default language file (see #2644)
		$this->import('Config');
		$this->loadLanguageFile('default');

		// Replace the insert tags and then re-replace the request_token
		// tag in case a form element has been loaded via insert tag
		$strBuffer = $this->replaceInsertTags($strBuffer);
		$strBuffer = str_replace(array('{{request_token}}', '[{]', '[}]'), array(REQUEST_TOKEN, '{{', '}}'), $strBuffer);

		// Content type
		if (!$content)
		{
			$content = 'text/html';
		}

		header('Vary: User-Agent', false);
		header('Content-Type: ' . $content . '; charset=' . $GLOBALS['TL_CONFIG']['characterSet']);

		// Send the cache headers
		if ($expire !== null && ($GLOBALS['TL_CONFIG']['cacheMode'] == 'both' || $GLOBALS['TL_CONFIG']['cacheMode'] == 'browser'))
		{
			header('Cache-Control: public, max-age=' . ($expire - time()));
			header('Expires: ' . gmdate('D, d M Y H:i:s', $expire) . ' GMT');
			header('Last-Modified: ' . gmdate('D, d M Y H:i:s', time()) . ' GMT');
			header('Pragma: public');
		}
		else
		{
			header('Cache-Control: no-cache');
			header('Cache-Control: pre-check=0, post-check=0', false);
			header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
			header('Expires: Fri, 06 Jun 1975 15:10:00 GMT');
			header('Pragma: no-cache');
		}

		echo $strBuffer;
		exit;
	}
}

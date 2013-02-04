<?php

namespace Contao;

class Dispatcher extends \System {
	
	public static function forCurrentRequest() {
		$objDispatcher = new static();
		$objDispatcher->setID(\Input::get('id'));
		$objDispatcher->setHost(\Environment::get('host'));
		$objDispatcher->setRequest(\Environment::get('request'));
		$objDispatcher->setAliasEnabled(!$GLOBALS['TL_CONFIG']['disableAlias']);
		$objDispatcher->setSuffix($GLOBALS['TL_CONFIG']['urlSuffix']);
		$objDispatcher->setAutoItemEnabled($GLOBALS['TL_CONFIG']['useAutoItem']);
		$objDispatcher->setAutoItemKeywords((array) $GLOBALS['TL_AUTO_ITEM']);
		$objDispatcher->setFolderAliasEnabled($GLOBALS['TL_CONFIG']['folderUrl']);
		$objDispatcher->setLanguageFromRequestEnabled($GLOBALS['TL_CONFIG']['addLanguageToUrl']);
		$objDispatcher->setPublishCheckEnabled(!BE_USER_LOGGED_IN);
		$objDispatcher->setIgnoredPageTypes(array('error_403', 'error_404', 'root'));
		$objDispatcher->setAcceptLanguage(\Environment::get('httpAcceptLanguage'));
		$objDispatcher->setRewriteEnabled($GLOBALS['TL_CONFIG']['rewriteURL']);
		
		return $objDispatcher;
	}
	
	public function __construct() {
		parent::__construct();
		$this->intTime = time();
	}
	
	protected $intID = null;
	
	public function setID($intID = null) {
		$this->intID = $intID;
	}
	
	protected $strHost = '';
	
	public function setHost($strHost = '') {
		$this->strHost = strval($strHost);
	}
	
	protected $strRequest = '';
	
	public function setRequest($strRequest = '') {
		$this->strRequest = strval($strRequest);
	}
	
	protected $blnAlias = true;
	
	public function setAliasEnabled($blnAlias = true) {
		$this->blnAlias = $blnAlias;
	}
	
	protected $blnFolderAlias = true;
	
	public function setFolderAliasEnabled($blnFolderAlias = true) {
		$this->blnFolderAlias = $blnFolderAlias;
	}
	
	protected $strSuffix = '';
	
	public function setSuffix($strSuffix = '') {
		$this->strSuffix = strval($strSuffix);
	}
	
	protected $blnAutoItem = true;
	
	public function setAutoItemEnabled($blnAutoItem = true) {
		$this->blnAutoItem = $blnAutoItem;
	}
	
	protected $arrAutoItemKeywords = array();
	
	public function setAutoItemKeywords(array $arrAutoItemKeywords = null) {
		$this->arrAutoItemKeywords = (array) $arrAutoItemKeywords;
	}
	
	protected $blnLanguageFromRequest = true;
	
	public function setLanguageFromRequestEnabled($blnLanguageFromRequest = true) {
		$this->blnLanguageFromRequest = $blnLanguageFromRequest;
	}
	
	protected $blnRewrite = true;
	
	public function setRewriteEnabled($blnRewrite = true) {
		$this->blnRewrite = $blnRewrite;
	}
	
	protected $blnPublishCheck = true;
	
	protected $intTime;
	
	public function setPublishCheckEnabled($blnPublishCheck = true, $intTime = null) {
		$this->blnPublishCheck = $blnPublishCheck;
		$this->intTime = $intTime === null ? time() : intval($intTime);
	}
	
	protected $arrIgnoredPageTypes = array();
	
	public function setIgnoredPageTypes(array $arrIgnoredPageTypes = null) {
		$this->arrIgnoredPageTypes = (array) $arrIgnoredPageTypes;
	}
	
	protected $arrAcceptLanguage = array();
	
	public function setAcceptLanguage(array $arrAcceptLanguage = null) {
		$this->arrAcceptLanguage = (array) $arrAcceptLanguage;
	}
	
	
	
	
	protected $blnDispatched;
	
	protected $intPage;
	
	protected $strLanguage;
	
	protected $arrParams;
	
	protected $blnError;
	
	protected $strError;
	
	public function reset() {
		unset(
			$this->blnDispatched,
			$this->strLanguage,
			$this->arrParams,
			$this->blnError,
			$this->strError
		);
	}
	
	/*
	 * /de
	* /de.suffix
	* /de/
	* -> page root, with language set
	*
	* /alias/param1/value1
	* /alias/alias/param1/value1
	* -> regular/forward/redirect or 404, 403
	*
	* /
	* -> page root, language detect
	*/
	public function dispatch() {
		$this->reset();
		$this->blnDispatched = true;
		
		try {
			$this->blnAlias ? $this->dispatchByAlias() : $this->dispatchByID();
			
		} catch(\Exception $e) {
			$this->blnError = true;
			$this->strError = $e->getMessage();
			return false;
		}
		
		return true;
		
		
		global $objPage;
		$pageId = $this->getPageIdFromUrl();
		$objRootPage = null;
		
		// Load a website root page object if there is no page ID
		if ($pageId === null)
		{
			$objRootPage = $this->getRootPageFromUrl();
			$objHandler = new $GLOBALS['TL_PTY']['root']();
			$pageId = $objHandler->generate($objRootPage->id, true);
		}
		// Throw a 404 error if the request is not a Contao request (see #2864)
		elseif ($pageId === false)
		{
			$this->User->authenticate();
			$objHandler = new $GLOBALS['TL_PTY']['error_404']();
			$objHandler->generate($pageId);
		}
		
		
		
		
		
		// Authenticate the user
		if (!$this->User->authenticate() && $objPage->protected && !BE_USER_LOGGED_IN)
		{
			$objHandler = new $GLOBALS['TL_PTY']['error_403']();
			$objHandler->generate($pageId, $objRootPage);
		}
		
		// Check the user groups if the page is protected
		if ($objPage->protected && !BE_USER_LOGGED_IN)
		{
			$arrGroups = $objPage->groups; // required for empty()
		
			if (!is_array($arrGroups) || empty($arrGroups) || !count(array_intersect($arrGroups, $this->User->groups)))
			{
				$this->log('Page "' . $pageId . '" can only be accessed by groups "' . implode(', ', (array) $objPage->groups) . '" (current user groups: ' . implode(', ', $this->User->groups) . ')', 'Index run()', TL_ERROR);
		
				$objHandler = new $GLOBALS['TL_PTY']['error_403']();
				$objHandler->generate($pageId, $objRootPage);
			}
		}
		
		// Load the page object depending on its type
		$objHandler = new $GLOBALS['TL_PTY'][$objPage->type]();
		
		switch ($objPage->type)
		{
			case 'root':
			case 'error_404':
				$objHandler->generate($pageId);
				break;
		
			case 'error_403':
				$objHandler->generate($pageId, $objRootPage);
				break;
		
			default:
				$objHandler->generate($objPage);
				break;
		}
	}
	
	public function hasError() {
		$this->checkDispatched();
		return $this->blnError;
	}
	
	public function getError() {
		$this->checkDispatched();
		return $this->strError;
	}
	
	public function getPage() {
		$this->checkDispatched();
		return $this->intPage;
	}
	
	public function getRootPage() {
		$this->checkDispatched();
		return $this->intRoot;
	}
	
	public function getStartPage() {
		// TODO
		return;
	}
	
	public function getParams() {
		$this->checkDispatched();
		return $this->arrParams;
	}
	
	public function getLanguage() {
		$this->checkDispatched();
		return $this->strLanguage;
	}
	
	protected function checkDispatched() {
		if(!$this->blnDispatched) {
			throw new LogicException('Cannot access dispatching results before call to dispatch method');
		}
	}
	
	protected function dispatchByAlias() {
		$strRequest = $this->strRequest;
			
		if(strlen($strRequest)) {
			$strRequest = $this->removeQueryString($strRequest);
			if(strpos($strRequest, '//') !== false) {
				throw new Exception('Invalid request syntax: "//" is not allowed');
			}
		
			$strRequest = $this->removeIndexFragment($strRequest);
		
			list($strRequest, $strLanguage) = $this->parseLanguage($strRequest);
		
			$strRequest = $this->removeSuffix($strRequest);
		}
		
		$arrRoots = $this->findRootPages($strLanguage);
		if(!$arrRoots) {
			throw new Exception('No matching root page found');
		}
		
		$arrAliases = $this->parseAliases($strRequest);
		list($intPage, $intRoot, $strAlias) = $this->findPage($arrRoots, $arrAliases);
		if(!$intPage) {
			throw new Exception('No matching page found');
		}
		
		$arrFragments = $this->parseFragments($strRequest, $strAlias);
		$arrParams = $this->parseParams($arrFragments);
		
		$this->intRoot = $intRoot;
		$this->intPage = $arrFragments[0] == $objAlias->alias ? $objAlias->id : $arrFragments[0];
		$this->arrParams = $arrParams;
		$this->strLanguage = $strLanguage;
	}
	
	protected function dispatchByID() {
		$arrRoots = $this->findRootPages();
		if(!$arrRoots) {
			throw new Exception('No matching root page found');
		}
		
		$intID = $this->intID;
		if(!is_numeric($intID) || $intID < 1) {
			throw new Exception('No valid id supplied');
		}
		
		$arrQueryParams = $arrRoots;
		$arrQueryParams[] = intval($intID);
		
		$objPage = Database::getInstance()->prepare(
			'SELECT	id, root
			FROM	tl_page
			WHERE	root IN (' . self::generateWildcards($arrRoots) . ')
			AND		id = ?'
		)->execute($arrQueryParams);
		
		if(!$objPage->numRows) {
			throw new Exception('No matching page found');
		}
		
		$this->intPage = $objPage->id;
		$this->intRootPage = $objPage->root;
	}
	
	/**
	 * Removes the query string part, which is everything after and including
	 * the first "?".
	 * @param string $strRequest
	 * @return string The request without query string part
	 */
	protected function removeQueryString($strRequest) {
		list($strRequest) = explode('?', $strRequest, 2);
		return $strRequest;
	}
	
	/**
	 * Get the request string without the index.php fragment, if rewriting is
	 * enabled.
	 * @param string $strRequest
	 * @return string The request without index.php fragment
	 */
	protected function removeIndexFragment($strRequest) {
		if(!$this->blnRewrite && strncmp($strRequest, 'index.php/', 10) === 0) {
			$strRequest = substr($strRequest, 10);
		}
		return $strRequest;
	}
	
	/**
	 * If "add language to request" is enabled and the request is not empty,
	 * extracts and removes the language from the request.
	 * @param string $strRequest
	 * @return array The modified request at index 0 and the extracted
	 * 		language at index 1, if any.
	 * @throws Exception If "add language to request" is enabled, the request is
	 * 		not empty and no valid language tag could be extracted
	 */
	protected function parseLanguage($strRequest) {
		if($strRequest == '' || !$this->blnLanguageFromRequest) {
			return array($strRequest);
		}
		
		list($strLanguage, $strRequest) = explode('/', $strRequest, 2);
		
		if(!preg_match('@^[a-z]{2}$@', $strLanguage)) {
			throw new Exception('No language supplied in non-empty request'); // TODO
		}
		
		return array($strRequest, $strLanguage);
	}
	
	/**
	 * Remove the URL suffix.
	 * @param string $strRequest
	 * @return string The request without the suffix
	 * @throws \Exception If the suffix did not match.
	 */
	protected function removeSuffix($strRequest) {
		if($strRequest == '') {
			return '';
		}
		
		$intLength = strlen($this->strSuffix);
		if(!$intLength) {
			return $strRequest;
		}
		
		// Return false if the URL suffix does not match (see #2864)
		// OH: this disables the possibility to use content-type specific suffixes,
		// e.g. for file downloads
		if(substr($strRequest, -$intLength) != $this->strSuffix) {
			throw new Exception('Suffix does not match');
		}
		
		return substr($strRequest, 0, -$intLength);
	}
	
	protected function findRootPages($strLanguage) {
		$arrQueryParams = array();
		
		$arrQueryParams[] = $this->strHost;
		
		if($this->blnLanguageFromRequest && $strLanguage) {
			$strLangMatch = 'p1.language = ' . $strLanguage;
			$arrQueryParams[] = $strLanguage;
			
		} elseif($this->arrAcceptLanguage) {
			$strLangMatch = 'FIND_IN_SET(p1.language, ?)';
			$arrQueryParams[] = implode(',', array_reverse($this->arrAcceptLanguage));
			
		} else {
			$strLangMatch = '0';
		}
		
		if($this->blnPublishCheck) {
			$strPublishCond = '
				AND (p1.start = \'\' OR p1.start < ' . $this->intTime . ')
				AND (p1.stop = \'\' OR p1.stop > ' . $this->intTime . ')
				AND p1.published = 1
			';
		}
		
		return Database::getInstance()->prepare(
			'SELECT	p.id
			FROM	(
				SELECT	p1.id, p1.dns, p1.fallback, p1.sorting,
						' . $strLangMatch . ' AS langMatch
				FROM	tl_page AS p1
				WHERE	p1.type = \'root\'
				AND		(p1.dns = \'\' OR p1.dns = ?)
				' . $strPublishCond . '
			) AS p
			WHERE	(p.fallback = 1 OR p.langMatch != 0)
			ORDER BY p.dns = \'\', p.langMatch DESC, p.sorting'
		)->execute($arrParams)->fetchEach('id');
	}
	
	protected function parseAliases($strRequest) {
		$arrAliases = array();
		
		if($this->blnFolderAlias) {
			do {
				$arrAliases[] = $strRequest;
				$strRequest = dirname($strRequest);
			} while($strRequest != '.');
		
		} else {
			list($arrAliases[]) = explode('/', $strRequest, 2);
		}
		
		return $arrAliases;
	}
	
	protected function findPage(array $arrRoots, array $arrAliases) {
		$arrQueryParams = array_merge($arrAliases, $arrRoots);
		
		if($this->arrIgnoredPageTypes) {
			$strIgnoredPageTypesCond = ' AND type NOT IN (' . self::generateWildcards($this->arrIgnoredPageTypes) . ') ';
			$arrQueryParams = array_merge($arrQueryParams, array_values($this->arrIgnoredPageTypes));
		}
		
		if($this->blnPublishCheck) {
			$strPublishCond = '
				AND (start = \'\' OR start < ' . $this->intTime . ')
				AND (stop = \'\' OR stop > ' . $this->intTime . ')
				AND published = 1
			';
		}
		
		$objPage = Database::getInstance()->prepare(
			'SELECT	id, root, alias
			FROM	tl_page
			WHERE	root IN (' . self::generateWildcards($arrRoots) . ')
			AND		alias IN (' . self::generateWildcards($arrAliases) . ')
			' . $strIgnoredPageTypesCond . $strPublishCond . '
			ORDER BY LENGTH(alias) DESC'
		)->limit(1)->execute($arrParams);
		
		return array($objPage->id, $objPage->root, $objPage->alias);
	}
	
	protected function parseFragments($strRequest, $strAlias) {
		$intLength = strlen($strAlias);
		$intLength && $strRequest = substr($strRequest, $intLength + 1);
		
		$arrFragments = explode('/', $strRequest);
		array_unshift($arrFragments, $strAlias);
			
		// Add the second fragment as auto_item if the number of fragments is even
		if($this->blnAutoItem && count($arrFragments) % 2 == 0) {
			array_splice($arrFragments, 1, 0, 'auto_item');
		}
			
		// HOOK: add custom logic
		if(is_array($GLOBALS['TL_HOOKS']['getPageIdFromUrl'])) {
			foreach($GLOBALS['TL_HOOKS']['getPageIdFromUrl'] as $callback) {
				$arrFragments = static::importStatic($callback[0])->$callback[1]($arrFragments);
			}
		}
			
		$arrFragments = array_map('urldecode', $arrFragments);
		
		return $arrFragments;
	}
	
	protected function parseParams(array $arrFragments) {
		$arrAutoItemKeywords = array_flip($this->arrAutoItemKeywords);
		
		// The request contains an auto_item keyword, so throw an exception
		// to avoid duplicate content (see #4012)
		if($this->blnAutoItem) {
			for($i = 1; $i < count($arrFragments); $i += 2) {
				if(isset($arrAutoItemKeywords[$arrFragments[$i]])) {
					throw new Exception('Auto item keyword in request parameter list');
				}
			}
		}
		
		$arrParams = array();
		
		for($i = 1; $i < count($arrFragments); $i += 2) {
			$arrParams[$arrFragments[$i]] = $arrFragments[$i + 1];
		}
		
		return $arrParams;
	}
	
    public static function generateWildcards(array $arrQueryParams) {
    	return rtrim(str_repeat('?,', count($arrQueryParams)), ',');
    }
    
}

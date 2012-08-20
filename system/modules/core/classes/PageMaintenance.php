<?php

namespace Contao;

class PageMaintenance extends \Backend implements \executable {

	public function isActive() {
		return false;
	}
	
	public function run() {
		if(\Input::get('token') != '') {
			$this->regeneratePageRoots();
			$this->reload();
		}
		
		$objTemplate = new \BackendTemplate('be_page_maintenance');
		$objTemplate->isActive = $this->isActive();
		$objTemplate->headline = 'PAGE MAINTENANCE TODO'; //$GLOBALS['TL_LANG']['tl_maintenance']['pageMaintenance'];
		$objTemplate->action = ampersand(\Environment::get('request'));
		$objTemplate->submit = 'PAGE MAINTENANCE TODO'; //specialchars($GLOBALS['TL_LANG']['tl_maintenance']['pageMaintenance']);
		
		return $objTemplate->parse();
	}

	public function regeneratePageRoots($arrPageIDs = null, $blnOrphans = true) {
		if($arrPageIDs !== null) {
			$arrPageIDs = array_unique(array_map('intval', array_filter((array) $arrPageIDs, 'is_numeric')));
			$arrRoots = array();
			foreach($arrPageIDs as $intPageID) {
				$objPage = $this->getPageDetails($intPageID);
				$intRoot = $objPage->type == 'root' ? $objPage->id : intval($objPage->rootId);
				$arrRoots[$intRoot][] = $objPage->id;
			}
				
		} else {
			$arrRoots = $this->Database->query(
				'SELECT id FROM tl_page WHERE type = \'root\''
			)->fetchEach('id');
			$arrRoots = array_combine($arrRoots, $arrRoots);
		}
	
		foreach($arrRoots as $intRootID => $arrPageIDs) {
			$arrPageIDs = (array) $arrPageIDs;
				
			$arrDescendants = $this->getChildRecords($arrPageIDs, 'tl_page');
			$arrDescendants = array_merge($arrDescendants, $arrPageIDs);
				
			$this->Database->prepare(
				'UPDATE	tl_page SET root = ? WHERE id IN (' . implode(',', $arrDescendants) . ')'
			)->execute($intRootID);
		}
	
		if(!$blnOrphans) {
			return;
		}
	
		// retrieve all pages not within a root page
		$arrIDs = array();
		$arrPIDs = array(0);
		while($arrPIDs) {
			$arrPIDs = $this->Database->query(
				'SELECT id FROM tl_page WHERE pid IN (' . implode(',', $arrPIDs) . ') AND type != \'root\''
			)->fetchEach('id');
			$arrIDs[] = $arrPIDs;
		}
		$arrIDs = call_user_func_array('array_merge', $arrIDs);
	
		if($arrIDs) {
			$this->Database->query(
				'UPDATE	tl_page SET root = 0 WHERE id IN (' . implode(',', $arrIDs) . ')'
			);
		}
	}
	
}

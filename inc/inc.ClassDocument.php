<?php
//    MyDMS. Document Management System
//    Copyright (C) 2002-2005  Markus Westphal
//    Copyright (C) 2006-2008 Malcolm Cowe
//    Copyright (C) 2010 Matteo Lucarelli
//
//    This program is free software; you can redistribute it and/or modify
//    it under the terms of the GNU General Public License as published by
//    the Free Software Foundation; either version 2 of the License, or
//    (at your option) any later version.
//
//    This program is distributed in the hope that it will be useful,
//    but WITHOUT ANY WARRANTY; without even the implied warranty of
//    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//    GNU General Public License for more details.
//
//    You should have received a copy of the GNU General Public License
//    along with this program; if not, write to the Free Software
//    Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.

define("S_DRAFT_REV", 0);
define("S_DRAFT_APP", 1);
define("S_RELEASED",  2);
define("S_REJECTED", -1);
define("S_OBSOLETE", -2);
define("S_EXPIRED",  -3);

// these are the document information (all versions)
class LetoDMS_Document { /* {{{ */
	var $_id;
	var $_name;
	var $_comment;
	var $_ownerID;
	var $_folderID;
	var $_expires;
	var $_inheritAccess;
	var $_defaultAccess;
	var $_locked;
	var $_keywords;
	var $_sequence;
	var $_notifier;
	var $_dms;
	
	function LetoDMS_Document($id, $name, $comment, $date, $expires, $ownerID, $folderID, $inheritAccess, $defaultAccess, $locked, $keywords, $sequence)
	{
		$this->_id = $id;
		$this->_name = $name;
		$this->_comment = $comment;
		$this->_date = $date;
		$this->_expires = $expires;
		$this->_ownerID = $ownerID;
		$this->_folderID = $folderID;
		$this->_inheritAccess = $inheritAccess;
		$this->_defaultAccess = $defaultAccess;
		$this->_locked = ($locked == null || $locked == '' ? -1 : $locked);
		$this->_keywords = $keywords;
		$this->_sequence = $sequence;
		$this->_notifier = null;
		$this->_dms = null;
	}

	/**
	 * Return a document by its id
	 *
	 * This function retrieves a document from the database by its id.
	 *
	 * @param integer $id internal id of document
	 * @return object instance of LetoDMS_Document or false
	 */
	function getDocument($id) { /* {{{ */
		GLOBAL $db;
		
		if (!is_numeric($id)) return false;
		
		$queryStr = "SELECT * FROM tblDocuments WHERE id = " . $id;
		$resArr = $db->getResultArray($queryStr);
		if (is_bool($resArr) && $resArr == false)
			return false;
		if (count($resArr) != 1)
			return false;
		$resArr = $resArr[0];
	
		// New Locking mechanism uses a separate table to track the lock.
		$queryStr = "SELECT * FROM tblDocumentLocks WHERE document = " . $id;
		$lockArr = $db->getResultArray($queryStr);
		if ((is_bool($lockArr) && $lockArr==false) || (count($lockArr)==0)) {
			// Could not find a lock on the selected document.
			$lock = -1;
		}
		else {
			// A lock has been identified for this document.
			$lock = $lockArr[0]["userID"];
		}
	
		return new LetoDMS_Document($resArr["id"], $resArr["name"], $resArr["comment"], $resArr["date"], $resArr["expires"], $resArr["owner"], $resArr["folder"], $resArr["inheritAccess"], $resArr["defaultAccess"], $lock, $resArr["keywords"], $resArr["sequence"]);
	} /* }}} */

	/*
	 * Search the database for documents
	 *
	 * @param query string seach query with space separated words
	 * @param limit integer number of items in result set
	 * @param offset integer index of first item in result set
	 * @param mode string either AND or OR
	 * @param searchin array() list of fields to search in
	 * @param startFolder object search in the folder only (null for root folder)
	 * @param owner object search for documents owned by this user
	 * @param status array list of status
	 * @param creationstartdate array search for documents created after this date
	 * @param creationenddate array search for documents created before this date
	 * @return array containing the elements total and docs
	 */
	function search($query, $limit=0, $offset=0, $mode='AND', $searchin=array(), $startFolder=null, $owner=null, $status = array(), $creationstartdate=array(), $creationenddate=array()) {
		GLOBAL $db;
		
		// Split the search string into constituent keywords.
		$tkeys=array();
		if (strlen($query)>0) {
			$tkeys = split("[\t\r\n ,]+", $query);
		}
		
		// if none is checkd search all
		if (count($searchin)==0)
			$searchin=array( 0, 1, 2, 3);

		$searchKey = "";
		// Assemble the arguments for the concatenation function. This allows the
		// search to be carried across all the relevant fields.
		$concatFunction = "";
		if (in_array(1, $searchin)) {
			$concatFunction = "`tblDocuments`.`keywords`";
		}
		if (in_array(2, $searchin)) {
			$concatFunction = (strlen($concatFunction) == 0 ? "" : $concatFunction.", ")."`tblDocuments`.`name`";
		}
		if (in_array(3, $searchin)) {
			$concatFunction = (strlen($concatFunction) == 0 ? "" : $concatFunction.", ")."`tblDocuments`.`comment`";
		}
		
		if (strlen($concatFunction)>0 && count($tkeys)>0) {
			$concatFunction = "CONCAT_WS(' ', ".$concatFunction.")";
			foreach ($tkeys as $key) {
				$key = trim($key);
				if (strlen($key)>0) {
					$searchKey = (strlen($searchKey)==0 ? "" : $searchKey." ".$mode." ").$concatFunction." LIKE '%".$key."%'";
				}
			}
		}
		
		// Check to see if the search has been restricted to a particular sub-tree in
		// the folder hierarchy.
		$searchFolder = "";
		if ($startFolder) {
			$searchFolder = "`tblDocuments`.`folderList` LIKE '%:".$startFolder->getID().":%'";
		}
		
		// Check to see if the search has been restricted to a particular
		// document owner.
		$searchOwner = "";
		if ($owner) {
			$searchOwner = "`tblDocuments`.`owner` = '".$owner->getId()."'";
		}
		
		// Is the search restricted to documents created between two specific dates?
		$searchCreateDate = "";
		if ($creationstartdate) {
			$startdate = makeTimeStamp(0, 0, 0, $createstartdate["year"], $createstartdate["month"], $createstartdate["day"]);
			if ($startdate) {
				$searchCreateDate .= "`tblDocuments`.`date` >= ".$startdate;
			}
		}
		if ($creationenddate) {
			$stopdate = makeTimeStamp(23, 59, 59, $createenddate["year"], $createenddate["month"], $createenddate["day"]);
			if ($stopdate) {
				if($startdate)
					$searchCreateDate .= " AND ";
				$searchCreateDate = "`tblDocuments`.`date` <= ".$stopdate;
			}
		}
		
		// ---------------------- Suche starten ----------------------------------
		
		//
		// Construct the SQL query that will be used to search the database.
		//
		
		if (!$db->createTemporaryTable("ttcontentid") || !$db->createTemporaryTable("ttstatid")) {
			return false;
		}
		
		$searchQuery = "FROM `tblDocumentContent` ".
			"LEFT JOIN `tblDocuments` ON `tblDocuments`.`id` = `tblDocumentContent`.`document` ".
			"LEFT JOIN `tblDocumentStatus` ON `tblDocumentStatus`.`documentID` = `tblDocumentContent`.`document` ".
			"LEFT JOIN `tblDocumentStatusLog` ON `tblDocumentStatusLog`.`statusID` = `tblDocumentStatus`.`statusID` ".
			"LEFT JOIN `ttstatid` ON `ttstatid`.`maxLogID` = `tblDocumentStatusLog`.`statusLogID` ".
			"LEFT JOIN `ttcontentid` ON `ttcontentid`.`maxVersion` = `tblDocumentStatus`.`version` AND `ttcontentid`.`document` = `tblDocumentStatus`.`documentID` ".
			"LEFT JOIN `tblDocumentLocks` ON `tblDocuments`.`id`=`tblDocumentLocks`.`document` ".
			"WHERE `ttstatid`.`maxLogID`=`tblDocumentStatusLog`.`statusLogID` ".
			"AND `ttcontentid`.`maxVersion` = `tblDocumentContent`.`version`";
		
		if (strlen($searchKey)>0) {
			$searchQuery .= " AND (".$searchKey.")";
		}
		if (strlen($searchFolder)>0) {
			$searchQuery .= " AND ".$searchFolder;
		}
		if (strlen($searchOwner)>0) {
			$searchQuery .= " AND (".$searchOwner.")";
		}
		if (strlen($searchCreateDate)>0) {
			$searchQuery .= " AND (".$searchCreateDate.")";
		}

		// status
		if ($status) {
			$searchQuery .= " AND `tblDocumentStatusLog`.`status` IN (".implode(',', $status).")";
		}

		// Count the number of rows that the search will produce.
		$resArr = $db->getResultArray("SELECT COUNT(*) AS num ".$searchQuery);
		$totalDocs = 0;
		if (is_numeric($resArr[0]["num"]) && $resArr[0]["num"]>0) {
			$totalDocs = (integer)$resArr[0]["num"];
		}
		if($limit) {
			$totalPages = (integer)($totalDocs/$limit);
			if (($totalDocs%$limit) > 0) {
				$totalPages++;
			}
		} else {
			$totalPages = 1;
		}
		
		// If there are no results from the count query, then there is no real need
		// to run the full query. TODO: re-structure code to by-pass additional
		// queries when no initial results are found.

		// Prepare the complete search query, including the LIMIT clause.
		$searchQuery = "SELECT `tblDocuments`.*, ".
			"`tblDocumentContent`.`version`, ".
			"`tblDocumentStatusLog`.`status`, `tblDocumentLocks`.`userID` as `lockUser` ".$searchQuery;
		
		if($limit) {
			$searchQuery .= " LIMIT ".$offset.",".$limit;
		}
		
		// Send the complete search query to the database.
		$resArr = $db->getResultArray($searchQuery);
		
		// ------------------- Ausgabe der Ergebnisse ----------------------------
		$numResults = count($resArr);
		if ($numResults == 0) {
			return array('totalDocs'=>$totalDocs, 'totalPages'=>$totalPages, 'docs'=>array());
		}
		
		foreach ($resArr as $docArr) {
		
			$document = new LetoDMS_Document(
				$docArr["id"], $docArr["name"],
				$docArr["comment"], $docArr["date"],
				$docArr["expires"], $docArr["owner"],
				$docArr["folder"], $docArr["inheritAccess"],
				$docArr["defaultAccess"], $docArr["lockUser"],
				$docArr["keywords"], $docArr["sequence"]);
				
			$docs[] = $document;
		}
		return(array('totalDocs'=>$totalDocs, 'totalPages'=>$totalPages, 'docs'=>$docs));
	}

	function setDMS($dms) {
		$this->_dms = $dms;
	}

	function setNotifier($notifier) {
		$this->_notifier = $notifier;
	}

	function getDir() {
		return $this->_dms->contentOffsetDir."/".$this->_id."/";
	}


	function getID() { return $this->_id; }

	function getName() { return $this->_name; }

	function setName($newName) {
		GLOBAL $db, $user;
		
		$queryStr = "UPDATE tblDocuments SET name = '" . $newName . "' WHERE id = ". $this->_id;
		if (!$db->getResult($queryStr))
			return false;

		$this->getNotifyList();
		// Send notification to subscribers.
		if($this->_notifier) {
			$subject = "###SITENAME###: ".$this->_name." - ".getMLText("document_renamed_email");
			$message = getMLText("document_renamed_email")."\r\n";
			$message .= 
				getMLText("old").": ".$this->_name."\r\n".
				getMLText("new").": ".$newName."\r\n".
				getMLText("folder").": ".getFolderPathPlain($this->getFolder())."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_id."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);

			$this->_notifier->toList($user, $this->_notifyList["users"], $subject, $message);
			foreach ($this->_notifyList["groups"] as $grp) {
				$this->_notifier->toGroup($user, $grp, $subject, $message);
			}
			
			// if user is not owner send notification to owner
			if ($user->getID()!= $this->_ownerID) 
				$this->_notifier->toIndividual($user, $this->getOwner(), $subject, $message);		
		}

		$this->_name = $newName;
		return true;
	}

	function getComment() { return $this->_comment; }

	function setComment($newComment) {
		GLOBAL $db, $user;

		$queryStr = "UPDATE tblDocuments SET comment = '" . $newComment . "' WHERE id = ". $this->_id;
		if (!$db->getResult($queryStr))
			return false;

		$this->getNotifyList();
		// Send notification to subscribers.
		if($this->_notifier) {
			$subject = "###SITENAME###: ".$this->_name." - ".getMLText("comment_changed_email");
			$message = getMLText("comment_changed_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_name."\r\n".
				getMLText("folder").": ".getFolderPathPlain($this->getFolder())."\r\n".
				getMLText("comment").": ".$newComment."\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_id."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			$this->_notifier->toList($user, $this->_notifyList["users"], $subject, $message);
			foreach ($this->_notifyList["groups"] as $grp) {
				$this->_notifier->toGroup($user, $grp, $subject, $message);
			}

			// if user is not owner send notification to owner
			if ($user->getID()!= $this->_ownerID) 
				$this->_notifier->toIndividual($user, $this->getOwner(), $subject, $message);		
		}
		$this->_comment = $newComment;
		return true;
	}

	function getKeywords() { return $this->_keywords; }

	function setKeywords($newKeywords)
	{
		GLOBAL $db;
		
		$queryStr = "UPDATE tblDocuments SET keywords = '" . $newKeywords . "' WHERE id = ". $this->_id;
		if (!$db->getResult($queryStr))
			return false;
		
		$this->_keywords = $newKeywords;
		return true;
	}

	function getDate()
	{
		return $this->_date;
	}

	function getFolder()
	{
		if (!isset($this->_folder))
			$this->_folder = getFolder($this->_folderID);
		return $this->_folder;
	}

	function setFolder($newFolder)
	{
		GLOBAL $db, $user;
		
		$queryStr = "UPDATE tblDocuments SET folder = " . $newFolder->getID() . " WHERE id = ". $this->_id;
		if (!$db->getResult($queryStr))
			return false;
		$this->_folderID = $newFolder->getID();
		$this->_folder = $newFolder;

		// Make sure that the folder search path is also updated.
		$path = $newFolder->getPath();
		$flist = "";
		foreach ($path as $f) {
			$flist .= ":".$f->getID();
		}
		if (strlen($flist)>1) {
			$flist .= ":";
		}
		$queryStr = "UPDATE tblDocuments SET folderList = '" . $flist . "' WHERE id = ". $this->_id;
		if (!$db->getResult($queryStr))
			return false;

		$this->getNotifyList();
		// Send notification to subscribers.
		if($this->_notifier) {
			$subject = "###SITENAME###: ".$this->_name." - ".getMLText("document_moved_email");
			$message = getMLText("document_moved_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_name."\r\n".
				getMLText("folder").": ".getFolderPathPlain($this->getFolder())."\r\n".
				getMLText("comment").": ".$newComment."\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_id."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			$this->_notifier->toList($user, $this->_notifyList["users"], $subject, $message);
			foreach ($this->_notifyList["groups"] as $grp) {
				$this->_notifier->toGroup($user, $grp, $subject, $message);
			}
			
			// if user is not owner send notification to owner
			if ($user->getID()!= $this->_ownerID) 
				$this->_notifier->toIndividual($user, $this->getOwner(), $subject, $message);		
		}

		return true;
	}

	function getOwner() {
		if (!isset($this->_owner))
			$this->_owner = $this->_dms->getUser($this->_ownerID);
		return $this->_owner;
	}

	function setOwner($newOwner) {
		GLOBAL $db, $user;

		$oldOwner = $this->getOwner();

		$queryStr = "UPDATE tblDocuments set owner = " . $newOwner->getID() . " WHERE id = " . $this->_id;
		if (!$db->getResult($queryStr))
			return false;

		$this->getNotifyList();
		// Send notification to subscribers.
		if($this->_notifier) {
			$subject = "###SITENAME###: ".$this->_name." - ".getMLText("ownership_changed_email");
			$message = getMLText("ownership_changed_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_name."\r\n".
				getMLText("old").": ".$oldOwner->getFullName()."\r\n".
				getMLText("new").": ".$newOwner->getFullName()."\r\n".
				getMLText("folder").": ".getFolderPathPlain($this->getFolder())."\r\n".
				getMLText("comment").": ".$this->_comment."\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_id."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			$this->_notifier->toList($user, $this->_notifyList["users"], $subject, $message);
			foreach ($this->_notifyList["groups"] as $grp) {
				$this->_notifier->toGroup($user, $grp, $subject, $message);
			}
			// Send notification to previous owner.
			$this->_notifier->toIndividual($user, $oldOwner, $subject, $message);
		}

		$this->_ownerID = $newOwner->getID();
		$this->_owner = $newOwner;
		return true;
	}

	function getDefaultAccess()
	{
		if ($this->inheritsAccess())
		{
			$res = $this->getFolder();
			if (!$res) return false;
			return $this->_folder->getDefaultAccess();
		}
		return $this->_defaultAccess;
	}

	function setDefaultAccess($mode) {
		GLOBAL $db, $user;
		
		$queryStr = "UPDATE tblDocuments set defaultAccess = " . $mode . " WHERE id = " . $this->_id;
		if (!$db->getResult($queryStr))
			return false;

		$this->getNotifyList();
		if($this->_notifier) {
			// Send notification to subscribers.
			$subject = "###SITENAME###: ".$this->_name." - ".getMLText("access_permission_changed_email");
			$message = getMLText("access_permission_changed_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_name."\r\n".
				getMLText("folder").": ".getFolderPathPlain($this->getFolder())."\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_id."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			$this->_notifier->toList($user, $this->_notifyList["users"], $subject, $message);
			foreach ($this->_notifyList["groups"] as $grp) {
				$this->_notifier->toGroup($user, $grp, $subject, $message);
			}
		}

		$this->_defaultAccess = $mode;

		// If any of the notification subscribers no longer have read access,
		// remove their subscription.
		foreach ($this->_notifyList["users"] as $u) {
			if ($this->getAccessMode($u) < M_READ) {
				$this->removeNotify($u->getID(), true);
			}
		}
		foreach ($this->_notifyList["groups"] as $g) {
			if ($this->getGroupAccessMode($g) < M_READ) {
				$this->removeNotify($g->getID(), false);
			}
		}

		return true;
	}

	function inheritsAccess() { return $this->_inheritAccess; }

	function setInheritAccess($inheritAccess) {
		GLOBAL $db, $user;
		
		$queryStr = "UPDATE tblDocuments SET inheritAccess = " . ($inheritAccess ? "1" : "0") . " WHERE id = " . $this->_id;
		if (!$db->getResult($queryStr))
			return false;
		
		$this->_inheritAccess = ($inheritAccess ? "1" : "0");

		$this->getNotifyList();
		if($this->_notifier) {
			// Send notification to subscribers.
			$subject = "###SITENAME###: ".$this->_name." - ".getMLText("access_permission_changed_email");
			$message = getMLText("access_permission_changed_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_name."\r\n".
				getMLText("folder").": ".getFolderPathPlain($this->getFolder())."\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_id."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			$this->_notifier->toList($user, $this->_notifyList["users"], $subject, $message);
			foreach ($this->_notifyList["groups"] as $grp) {
				$this->_notifier->toGroup($user, $grp, $subject, $message);
			}
		}

		// If any of the notification subscribers no longer have read access,
		// remove their subscription.
		foreach ($this->_notifyList["users"] as $u) {
			if ($this->getAccessMode($u) < M_READ) {
				$this->removeNotify($u->getID(), true);
			}
		}
		foreach ($this->_notifyList["groups"] as $g) {
			if ($this->getGroupAccessMode($g) < M_READ) {
				$this->removeNotify($g->getID(), false);
			}
		}

		return true;
	}

	function expires()
	{
		if (intval($this->_expires) == 0)
			return false;
		else
			return true;
	}

	function getExpires()
	{
		if (intval($this->_expires) == 0)
			return false;
		else
			return $this->_expires;
	}

	function setExpires($expires) {
		GLOBAL $db, $user;
		
		$expires = (!$expires) ? 0 : $expires;

		if ($expires == $this->_expires) {
			// No change is necessary.
			return true;
		}

		$queryStr = "UPDATE tblDocuments SET expires = " . $expires . " WHERE id = " . $this->_id;
		if (!$db->getResult($queryStr))
			return false;

		$this->getNotifyList();
		if($this->_notifier) {
			// Send notification to subscribers.
			$subject = "###SITENAME###: ".$this->_name." - ".getMLText("expiry_changed_email");
			$message = getMLText("expiry_changed_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_name."\r\n".
				getMLText("folder").": ".getFolderPathPlain($this->getFolder())."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_id."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			$this->_notifier->toList($user, $this->_notifyList["users"], $subject, $message);
			foreach ($this->_notifyList["groups"] as $grp) {
				$this->_notifier->toGroup($user, $grp, $subject, $message);
			}
		}

		$this->_expires = $expires;
		return true;
	}

	function hasExpired(){
	
		if (intval($this->_expires) == 0) return false;
		if (time()>$this->_expires+24*60*60) return true;
		return false;
	}
	
	// return true if status has changed (to reload page)
	function verifyLastestContentExpriry(){
		
		$lc=$this->getLatestContent();
		$st=$lc->getStatus();
		
		if (($st["status"]==S_DRAFT_REV || $st["status"]==S_DRAFT_APP) && $this->hasExpired()){
			$lc->setStatus(S_EXPIRED,"");
			return true;
		}
		else if ($st["status"]==S_EXPIRED && !$this->hasExpired() ){
			$lc->verifyStatus(true);
			return true;
		}
		return false;
	}
	
	function isLocked() { return $this->_locked != -1; }

	function setLocked($falseOrUser)
	{
		GLOBAL $db;
		
		$lockUserID = -1;
		if (is_bool($falseOrUser) && !$falseOrUser) {
			$queryStr = "DELETE FROM tblDocumentLocks WHERE document = ".$this->_id;
		}
		else if (is_object($falseOrUser)) {
			$queryStr = "INSERT INTO tblDocumentLocks (document, userID) VALUES (".$this->_id.", ".$falseOrUser->getID().")";
			$lockUserID = $falseOrUser->getID();
		}
		else {
			return false;
		}
		if (!$db->getResult($queryStr)) {
			return false;
		}
		unset($this->_lockingUser);
		$this->_locked = $lockUserID;
		return true;
	}

	function getLockingUser()
	{
		if (!$this->isLocked())
			return false;
		
		if (!isset($this->_lockingUser))
			$this->_lockingUser = $this->_dms->getUser($this->_locked);
		return $this->_lockingUser;
	}

	function getSequence() { return $this->_sequence; }

	function setSequence($seq)
	{
		GLOBAL $db;
		
		$queryStr = "UPDATE tblDocuments SET sequence = " . $seq . " WHERE id = " . $this->_id;
		if (!$db->getResult($queryStr))
			return false;
		
		$this->_sequence = $seq;
		return true;
	}

	function clearAccessList()
	{
		GLOBAL $db;
		
		$queryStr = "DELETE FROM tblACLs WHERE targetType = " . T_DOCUMENT . " AND target = " . $this->_id;
		if (!$db->getResult($queryStr))
			return false;
		
		unset($this->_accessList);
		return true;
	}

	function getAccessList($mode = M_ANY, $op = O_EQ)
	{
		GLOBAL $db;
		
		if ($this->inheritsAccess())
		{
			$res = $this->getFolder();
			if (!$res) return false;
			return $this->_folder->getAccessList($mode, $op);
		}
		
		if (!isset($this->_accessList[$mode]))
		{
			if ($op!=O_GTEQ && $op!=O_LTEQ && $op!=O_EQ) {
				return false;
			}
			$modeStr = "";
			if ($mode!=M_ANY) {
				$modeStr = " AND mode".$op.$mode;
			}
			$queryStr = "SELECT * FROM tblACLs WHERE targetType = ".T_DOCUMENT.
				" AND target = " . $this->_id .	$modeStr . " ORDER BY targetType";
			$resArr = $db->getResultArray($queryStr);
			if (is_bool($resArr) && !$resArr)
				return false;
			
			$this->_accessList[$mode] = array("groups" => array(), "users" => array());
			foreach ($resArr as $row)
			{
				if ($row["userID"] != -1)
					array_push($this->_accessList[$mode]["users"], new LetoDMS_UserAccess($row["userID"], $row["mode"]));
				else //if ($row["groupID"] != -1)
					array_push($this->_accessList[$mode]["groups"], new LetoDMS_GroupAccess($row["groupID"], $row["mode"]));
			}
		}
		
		return $this->_accessList[$mode];
	}

	function addAccess($mode, $userOrGroupID, $isUser) {
		GLOBAL $db;
		
		$userOrGroup = ($isUser) ? "userID" : "groupID";
		
		$queryStr = "INSERT INTO tblACLs (target, targetType, ".$userOrGroup.", mode) VALUES 
					(".$this->_id.", ".T_DOCUMENT.", " . $userOrGroupID . ", " .$mode. ")";
		if (!$db->getResult($queryStr))
			return false;
		
		unset($this->_accessList);

		// Update the notify list, if necessary.
		if ($mode == M_NONE) {
			$this->removeNotify($userOrGroupID, $isUser);
		}

		return true;
	}

	function changeAccess($newMode, $userOrGroupID, $isUser) {
		GLOBAL $db;

		$userOrGroup = ($isUser) ? "userID" : "groupID";

		$queryStr = "UPDATE tblACLs SET mode = " . $newMode . " WHERE targetType = ".T_DOCUMENT." AND target = " . $this->_id . " AND " . $userOrGroup . " = " . $userOrGroupID;
		if (!$db->getResult($queryStr))
			return false;

		unset($this->_accessList);

		// Update the notify list, if necessary.
		if ($newMode == M_NONE) {
			$this->removeNotify($userOrGroupID, $isUser);
		}

		return true;
	}

	function removeAccess($userOrGroupID, $isUser) {
		GLOBAL $db;

		$userOrGroup = ($isUser) ? "userID" : "groupID";

		$queryStr = "DELETE FROM tblACLs WHERE targetType = ".T_DOCUMENT." AND target = ".$this->_id." AND ".$userOrGroup." = " . $userOrGroupID;
		if (!$db->getResult($queryStr))
			return false;

		unset($this->_accessList);

		// Update the notify list, if necessary.
		$mode = ($isUser ? $this->getAccessMode($this->_dms->getUser($userOrGroupID)) : $this->getGroupAccessMode($this->_dms->getGroup($userOrGroupID)));
		if ($mode == M_NONE) {
			$this->removeNotify($userOrGroupID, $isUser);
		}

		return true;
	}

	/*
	 * Liefert die Art der Zugriffsberechtigung f�r den User $user; M�gliche Rechte: n (keine), r (lesen), w (schreiben+lesen), a (alles)
	 * Zun�chst wird Gepr�ft, ob die Berechtigung geerbt werden soll; in diesem Fall wird die Anfrage an den Eltern-Ordner weitergeleitet.
	 * Ansonsten werden die ACLs durchgegangen: Die h�chstwertige Berechtigung gilt.
	 * Wird bei den ACLs nicht gefunden, wird die Standard-Berechtigung zur�ckgegeben.
	 * Ach ja: handelt es sich bei $user um den Besitzer ist die Berechtigung automatisch "a".
	 */
	function getAccessMode($user)
	{
		GLOBAL $settings;
		
		//Administrator??
		if ($user->isAdmin()) return M_ALL;
		
		//Besitzer??
		if ($user->getID() == $this->_ownerID) return M_ALL;
		
		//Gast-Benutzer??
		if (($user->getID() == $settings->_guestID) && ($settings->_enableGuestLogin))
		{
			$mode = $this->getDefaultAccess();
			if ($mode >= M_READ) return M_READ;
			else return M_NONE;
		}
		
		//Berechtigung erben??
		// wird �ber GetAccessList() bereits realisiert.
		// durch das Verwenden der folgenden Zeilen w�ren auch Owner-Rechte vererbt worden.
		/*
		if ($this->inheritsAccess())
		{
			if (!$this->getFolder())
				return false;
			return $this->_folder->getAccessMode($user);
		}
		*/
		//ACLs durchforsten
		$accessList = $this->getAccessList();
		if (!$accessList) return false;
		
		foreach ($accessList["users"] as $userAccess)
		{
			if ($userAccess->getUserID() == $user->getID())
			{
				return $userAccess->getMode();
			}
		}
		foreach ($accessList["groups"] as $groupAccess)
		{
			if ($user->isMemberOfGroup($groupAccess->getGroup()))
			{
				return $groupAccess->getMode();
			}
		}
		return $this->getDefaultAccess();
	}


	function getGroupAccessMode($group) {
		$highestPrivileged = M_NONE;
		
		//ACLs durchforsten
		$foundInACL = false;
		$accessList = $this->getAccessList();
		if (!$accessList)
			return false;
		
		foreach ($accessList["groups"] as $groupAccess) {
			if ($groupAccess->getGroupID() == $group->getID()) {
				$foundInACL = true;
				if ($groupAccess->getMode() > $highestPrivileged)
					$highestPrivileged = $groupAccess->getMode();
				if ($highestPrivileged == M_ALL) //h�her geht's nicht -> wir k�nnen uns die arbeit schenken
					return $highestPrivileged;
			}
		}

		if ($foundInACL)
			return $highestPrivileged;
		
		//Standard-Berechtigung verwenden
		return $this->getDefaultAccess();
	}

	function getNotifyList() {
		if (!isset($this->_notifyList))
		{
			GLOBAL $db;
			
			$queryStr ="SELECT * FROM tblNotify WHERE targetType = " . T_DOCUMENT . " AND target = " . $this->_id;
			$resArr = $db->getResultArray($queryStr);
			if (is_bool($resArr) && $resArr == false)
				return false;
			
			$this->_notifyList = array("groups" => array(), "users" => array());
			foreach ($resArr as $row)
			{
				if ($row["userID"] != -1)
					array_push($this->_notifyList["users"], $this->_dms->getUser($row["userID"]) );
				else //if ($row["groupID"] != -1)
					array_push($this->_notifyList["groups"], $this->_dms->getGroup($row["groupID"]) );
			}
		}
		return $this->_notifyList;
	}

	function addNotify($userOrGroupID, $isUser,$send_email=TRUE) {

		// Return values:
		// -1: Invalid User/Group ID.
		// -2: Target User / Group does not have read access.
		// -3: User is already subscribed.
		// -4: Database / internal error.
		//  0: Update successful.

		global $db, $settings, $user;

		$userOrGroup = ($isUser ? "userID" : "groupID");

		//
		// Verify that user / group exists.
		//
		$obj = ($isUser ? $this->_dms->getUser($userOrGroupID) : $this->_dms->getGroup($userOrGroupID));
		if (!is_object($obj)) {
			return -1;
		}

		//
		// Verify that the requesting user has permission to add the target to
		// the notification system.
		//
		if ($user->getID() == $settings->_guestID) {
			return -2;
		}
		if (!$user->isAdmin()) {
			if ($isUser) {
				if ($user->getID() != $obj->getID()) {
					return -2;
				}
			}
			else {
				if (!$obj->isMember($user)) {
					return -2;
				}
			}
		}

		//
		// Verify that target user / group has read access to the document.
		//
		if ($isUser) {
			// Users are straightforward to check.
			if ($this->getAccessMode($obj) < M_READ) {
				return -2;
			}
		}
		else {
			// Groups are a little more complex.
			if ($this->getDefaultAccess() >= M_READ) {
				// If the default access is at least READ-ONLY, then just make sure
				// that the current group has not been explicitly excluded.
				$acl = $this->getAccessList(M_NONE, O_EQ);
				$found = false;
				foreach ($acl["groups"] as $group) {
					if ($group->getGroupID() == $userOrGroupID) {
						$found = true;
						break;
					}
				}
				if ($found) {
					return -2;
				}
			}
			else {
				// The default access is restricted. Make sure that the group has
				// been explicitly allocated access to the document.
				$acl = $this->getAccessList(M_READ, O_GTEQ);
				if (is_bool($acl)) {
					return -4;
				}
				$found = false;
				foreach ($acl["groups"] as $group) {
					if ($group->getGroupID() == $userOrGroupID) {
						$found = true;
						break;
					}
				}
				if (!$found) {
					return -2;
				}
			}
		}
		//
		// Check to see if user/group is already on the list.
		//
		$queryStr = "SELECT * FROM `tblNotify` WHERE `tblNotify`.`target` = '".$this->_id."' ".
			"AND `tblNotify`.`targetType` = '".T_DOCUMENT."' ".
			"AND `tblNotify`.`".$userOrGroup."` = '".$userOrGroupID."'";
		$resArr = $db->getResultArray($queryStr);
		if (is_bool($resArr)) {
			return -4;
		}
		if (count($resArr)>0) {
			return -3;
		}

		$queryStr = "INSERT INTO tblNotify (target, targetType, " . $userOrGroup . ") VALUES (" . $this->_id . ", " . T_DOCUMENT . ", " . $userOrGroupID . ")";
		if (!$db->getResult($queryStr))
			return -4;

		// Email user / group, informing them of subscription.
		if ($send_email && $this->_notifier){
			$path="";
			$folder = $this->getFolder();
			$folderPath = $folder->getPath();
			for ($i = 0; $i  < count($folderPath); $i++) {
				$path .= $folderPath[$i]->getName();
				if ($i +1 < count($folderPath))
					$path .= " / ";
			}
			$subject = "###SITENAME###: ".$this->getName()." - ".getMLText("notify_added_email");
			$message = getMLText("notify_added_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->getName()."\r\n".
				getMLText("folder").": ".$path."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_id."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			if ($isUser) {
				$this->_notifier->toIndividual($user, $obj, $subject, $message);
			}
			else {
				$this->_notifier->toGroup($user, $obj, $subject, $message);
			}
		}

		unset($this->_notifyList);
		return 0;
	}

	function removeNotify($userOrGroupID, $isUser) {

		// Return values:
		// -1: Invalid User/Group ID.
		// -3: User is not subscribed. No action taken.
		// -4: Database / internal error.
		//  0: Update successful.

		GLOBAL $db, $settings, $user;
		
		//
		// Verify that user / group exists.
		//
		$obj = ($isUser ? $this->_dms->getUser($userOrGroupID) : $this->_dms->getGroup($userOrGroupID));
		if (!is_object($obj)) {
			return -1;
		}

		$userOrGroup = ($isUser) ? "userID" : "groupID";

		//
		// Verify that the requesting user has permission to add the target to
		// the notification system.
		//
		if ($user->getID() == $settings->_guestID) {
			return -2;
		}
		if (!$user->isAdmin()) {
			if ($isUser) {
				if ($user->getID() != $obj->getID()) {
					return -2;
				}
			}
			else {
				if (!$obj->isMember($user)) {
					return -2;
				}
			}
		}

		//
		// Check to see if the target is in the database.
		//
		$queryStr = "SELECT * FROM `tblNotify` WHERE `tblNotify`.`target` = '".$this->_id."' ".
			"AND `tblNotify`.`targetType` = '".T_DOCUMENT."' ".
			"AND `tblNotify`.`".$userOrGroup."` = '".$userOrGroupID."'";
		$resArr = $db->getResultArray($queryStr);
		if (is_bool($resArr)) {
			return -4;
		}
		if (count($resArr)==0) {
			return -3;
		}

		$queryStr = "DELETE FROM tblNotify WHERE target = " . $this->_id . " AND targetType = " . T_DOCUMENT . " AND " . $userOrGroup . " = " . $userOrGroupID;
		if (!$db->getResult($queryStr))
			return -4;
		
		// Email user / group, informing them of subscription change.
		$path="";
		$folder = $this->getFolder();
		$folderPath = $folder->getPath();
		for ($i = 0; $i  < count($folderPath); $i++) {
			$path .= $folderPath[$i]->getName();
			if ($i +1 < count($folderPath))
				$path .= " / ";
		}
		if($this->_notifier) {
			$subject = "###SITENAME###: ".$this->getName()." - ".getMLText("notify_deleted_email");
			$message = getMLText("notify_deleted_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->getName()."\r\n".
				getMLText("folder").": ".$path."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_id."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
	
			if ($isUser) {
				$this->_notifier->toIndividual($user, $obj, $subject, $message);
			}
			else {
				$this->_notifier->toGroup($user, $obj, $subject, $message);
			}
		}

		unset($this->_notifyList);
		return 0;
	}
	
	function addContent($comment, $user, $tmpFile, $orgFileName, $fileType, $mimeType, $reviewers=array(), $approvers=array(),$version=0,$send_email=TRUE)
	{
		GLOBAL $db, $settings;
		
		// the doc path is id/version.filetype
		$dir = $this->getDir();

		//Eintrag in tblDocumentContent
		$date = mktime();
		
		if ((int)$version<1){

			$queryStr = "INSERT INTO tblDocumentContent (document, comment, date, createdBy, dir, orgFileName, fileType, mimeType) VALUES ".
						"(".$this->_id.", '".$comment."', ".$date.", ".$user->getID().", '".$dir."', '".$orgFileName."', '".$fileType."', '" . $mimeType . "')";
			if (!$db->getResult($queryStr)) return false;

			$version = $db->getInsertID();
		
		}else{		
			$queryStr = "INSERT INTO tblDocumentContent (document, version, comment, date, createdBy, dir, orgFileName, fileType, mimeType) VALUES ".
						"(".$this->_id.", ".(int)$version.",'".$comment."', ".$date.", ".$user->getID().", '".$dir."', '".$orgFileName."', '".$fileType."', '" . $mimeType . "')";
			if (!$db->getResult($queryStr)) return false;
		}

		// copy file
		if (!makeDir($this->_dms->contentDir . $dir)) return false;
		if (!copyFile($tmpFile, $this->_dms->contentDir . $dir . $version . $fileType)) return false;

		unset($this->_content);
		unset($this->_latestContent);
		$docResultSet = new LetoDMS_AddContentResultSet(new LetoDMS_DocumentContent($this, $version, $comment, $date, $user->getID(), $dir, $orgFileName, $fileType, $mimeType));

		// TODO - verify
		if ($settings->_enableConverting && in_array($docResultSet->_content->getFileType(), array_keys($settings->_convertFileTypes)))
			$docResultSet->_content->convert(); //Auch wenn das schiefgeht, wird deswegen nicht gleich alles "hingeschmissen" (sprich: false zur�ckgegeben)

		$queryStr = "INSERT INTO `tblDocumentStatus` (`documentID`, `version`) ".
			"VALUES ('". $this->_id ."', '". $version ."')";
		if (!$db->getResult($queryStr))
			return false;

		$statusID = $db->getInsertID();

		// Add reviewers into the database. Reviewers must review the document
		// and submit comments, if appropriate. Reviewers can also recommend that
		// a document be rejected.
		$pendingReview=false;
		$reviewRes = array();
		foreach (array("i", "g") as $i){
			if (isset($reviewers[$i])) {
				foreach ($reviewers[$i] as $reviewerID) {
					$reviewer=($i=="i" ?$this->_dms->getUser($reviewerID) : $this->_dms->getGroup($reviewerID));
					$res = ($i=="i" ? $docResultSet->_content->addIndReviewer($reviewer, $user, true) : $docResultSet->_content->addGrpReviewer($reviewer, $user, true));
					$docResultSet->addReviewer($reviewer, $i, $res);
					// If no error is returned, or if the error is just due to email
					// failure, mark the state as "pending review".
					if ($res==0 || $res=-3 || $res=-4) {
						$pendingReview=true;
					}
				}
			}
		}
		// Add approvers to the database. Approvers must also review the document
		// and make a recommendation on its release as an approved version.
		$pendingApproval=false;
		$approveRes = array();
		foreach (array("i", "g") as $i){
			if (isset($approvers[$i])) {
				foreach ($approvers[$i] as $approverID) {
					$approver=($i=="i" ? $this->_dms->getUser($approverID) : $this->_dms->getGroup($approverID));
					$res=($i=="i" ? $docResultSet->_content->addIndApprover($approver, $user, !$pendingReview) : $docResultSet->_content->addGrpApprover($approver, $user, !$pendingReview));
					$docResultSet->addApprover($approver, $i, $res);
					if ($res==0 || $res=-3 || $res=-4) {
						$pendingApproval=true;
					}
				}
			}
		}

		// If there are no reviewers or approvers, the document is automatically
		// promoted to the released state.
		if ($pendingReview) {
			$status = S_DRAFT_REV;
			$comment = "";
		}
		else if ($pendingApproval) {
			$status = S_DRAFT_APP;
			$comment = "";
		}
		else {
			$status = S_RELEASED;
			$comment="";
		}
		$queryStr = "INSERT INTO `tblDocumentStatusLog` (`statusID`, `status`, `comment`, `date`, `userID`) ".
			"VALUES ('". $statusID ."', '". $status."', 'New document content submitted". $comment ."', NOW(), '". $user->getID() ."')";
		if (!$db->getResult($queryStr))
			return false;

		$docResultSet->setStatus($status,$comment,$user);

		$this->getNotifyList();
		// Send notification to subscribers.
		if ($send_email && $this->_notifier){
			$subject = "###SITENAME###: ".$this->_name." - ".getMLText("document_updated_email");
			$message = getMLText("document_updated_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_name."\r\n".
				getMLText("folder").": ".getFolderPathPlain($this->getFolder())."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_id."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			$this->_notifier->toList($user, $this->_notifyList["users"], $subject, $message);
			foreach ($this->_notifyList["groups"] as $grp) {
				$this->_notifier->toGroup($user, $grp, $subject, $message);
			}
		
			// if user is not owner send notification to owner
			if ($user->getID()!= $this->_ownerID) 
				$this->_notifier->toIndividual($user, $this->getOwner(), $subject, $message);
		}

		return $docResultSet;
	}

	function getContent()
	{
		GLOBAL $db;
		
		if (!isset($this->_content))
		{
			$queryStr = "SELECT * FROM tblDocumentContent WHERE document = ".$this->_id." ORDER BY version";
			$resArr = $db->getResultArray($queryStr);
			if (is_bool($resArr) && !$res)
				return false;
			
			$this->_content = array();
			foreach ($resArr as $row)
				array_push($this->_content, new LetoDMS_DocumentContent($this, $row["version"], $row["comment"], $row["date"], $row["createdBy"], $row["dir"], $row["orgFileName"], $row["fileType"], $row["mimeType"]));
		}
		
		return $this->_content;
	}

	function getContentByVersion($version)
	{
		if (!is_numeric($version)) return false;
		
		if (isset($this->_content))
		{
			foreach ($this->_content as $revision)
			{
				if ($revision->getVersion() == $version)
					return $revision;
			}
			return false;
		}
		
		GLOBAL $db;
		$queryStr = "SELECT * FROM tblDocumentContent WHERE document = ".$this->_id." AND version = " . $version;
		$resArr = $db->getResultArray($queryStr);
		if (is_bool($resArr) && !$res)
			return false;
		if (count($resArr) != 1)
			return false;
		
		$resArr = $resArr[0];
		return new LetoDMS_DocumentContent($this, $resArr["version"], $resArr["comment"], $resArr["date"], $resArr["createdBy"], $resArr["dir"], $resArr["orgFileName"], $resArr["fileType"], $resArr["mimeType"]);
	}

	function getLatestContent()
	{
		if (!isset($this->_latestContent))
		{
			GLOBAL $db;
			$queryStr = "SELECT * FROM tblDocumentContent WHERE document = ".$this->_id." ORDER BY version DESC LIMIT 0,1";
			$resArr = $db->getResultArray($queryStr);
			if (is_bool($resArr) && !$resArr)
				return false;
			if (count($resArr) != 1)
				return false;
			
			$resArr = $resArr[0];
			$this->_latestContent = new LetoDMS_DocumentContent($this, $resArr["version"], $resArr["comment"], $resArr["date"], $resArr["createdBy"], $resArr["dir"], $resArr["orgFileName"], $resArr["fileType"], $resArr["mimeType"]);
		}
		return $this->_latestContent;
	}

	function getDocumentLink($linkID) {
	
		GLOBAL $db;
		
		if (!is_numeric($linkID)) return false;
	
		$queryStr = "SELECT * FROM tblDocumentLinks WHERE document = " . $this->_id ." AND id = " . $linkID;
		$resArr = $db->getResultArray($queryStr);
		if ((is_bool($resArr) && !$resArr) || count($resArr)==0)
			return false;
	
		$resArr = $resArr[0];
		$document = $this->_dms->getDocument($resArr["document"]);
		$target = $this->_dms->getDocument($resArr["target"]);
		return new LetoDMS_DocumentLink($resArr["id"], $document, $target, $resArr["userID"], $resArr["public"]);
	}

	function getDocumentLinks()
	{
		if (!isset($this->_documentLinks))
		{
			GLOBAL $db;
			
			$queryStr = "SELECT * FROM tblDocumentLinks WHERE document = " . $this->_id;
			$resArr = $db->getResultArray($queryStr);
			if (is_bool($resArr) && !$resArr)
				return false;
			$this->_documentLinks = array();
			
			foreach ($resArr as $row) {
				$target = $this->_dms->getDocument($row["target"]);
				array_push($this->_documentLinks, new LetoDMS_DocumentLink($row["id"], $this, $target, $row["userID"], $row["public"]));
			}
		}
		return $this->_documentLinks;
	}

	function addDocumentLink($targetID, $userID, $public)
	{
		GLOBAL $db;
		
		$public = ($public) ? "1" : "0";
		
		$queryStr = "INSERT INTO tblDocumentLinks(document, target, userID, public) VALUES (".$this->_id.", ".$targetID.", ".$userID.", " . $public.")";
		if (!$db->getResult($queryStr))
			return false;
		
		unset($this->_documentLinks);
		return true;
	}

	function removeDocumentLink($linkID)
	{
		GLOBAL $db;
		
		$queryStr = "DELETE FROM tblDocumentLinks WHERE document = " . $this->_id ." AND id = " . $linkID;
		if (!$db->getResult($queryStr)) return false;
		unset ($this->_documentLinks);
		return true;
	}
	
	function getDocumentFile($ID) {
		GLOBAL $db;
		
		if (!is_numeric($ID)) return false;
	
		$queryStr = "SELECT * FROM tblDocumentFiles WHERE document = " . $this->_id ." AND id = " . $ID;
		$resArr = $db->getResultArray($queryStr);
		if ((is_bool($resArr) && !$resArr) || count($resArr)==0) return false;
	
		$resArr = $resArr[0];
		return new LetoDMS_DocumentFile($resArr["id"], $this, $resArr["userID"], $resArr["comment"], $resArr["date"], $resArr["dir"], $resArr["fileType"], $resArr["mimeType"], $resArr["orgFileName"], $resArr["name"]);
	}

	function getDocumentFiles()
	{
		if (!isset($this->_documentFiles))
		{
			GLOBAL $db;
			
			$queryStr = "SELECT * FROM tblDocumentFiles WHERE document = " . $this->_id." ORDER BY `date` DESC";
			$resArr = $db->getResultArray($queryStr);
			if (is_bool($resArr) && !$resArr) return false;
				
			$this->_documentFiles = array();
			
			foreach ($resArr as $row) {
				array_push($this->_documentFiles, new LetoDMS_DocumentFile($row["id"], $this, $row["userID"], $row["comment"], $row["date"], $row["dir"], $row["fileType"], $row["mimeType"], $row["orgFileName"], $row["name"]));
			}
		}
		return $this->_documentFiles;		
	}

	function addDocumentFile($name, $comment, $user, $tmpFile, $orgFileName,$fileType, $mimeType )
	{
		GLOBAL $db;
		
		$dir = $this->getDir();
	
		$queryStr = "INSERT INTO tblDocumentFiles (comment, date, dir, document, fileType, mimeType, orgFileName, userID, name) VALUES ".
			"('".$comment."', '".mktime()."', '" . $dir ."', " . $this->_id.", '".$fileType."', '".$mimeType."', '".$orgFileName."',".$user->getID().",'".$name."')";
		if (!$db->getResult($queryStr)) return false;
			
		$id = $db->getInsertID();
		
		$file = $this->getDocumentFile($id);
		if (is_bool($file) && !$file) return false;

		// copy file
		if (!makeDir($this->_dms->contentDir . $dir)) return false;
		if (!copyFile($tmpFile, $this->_dms->contentDir . $file->getPath() )) return false;
		
		$this->getNotifyList();
		// Send notification to subscribers.
		if($this->_notifier) {
			$subject = "###SITENAME###: ".$this->_name." - ".getMLText("new_file_email");
			$message = getMLText("new_file_email")."\r\n";
			$message .= 
				getMLText("name").": ".$name."\r\n".
				getMLText("comment").": ".$comment."\r\n".
				getMLText("user").": ".$user->getFullName()." <". $user->getEmail() .">\r\n".	
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->getID()."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			$this->_notifier->toList($user, $this->_notifyList["users"], $subject, $message);
			foreach ($this->_notifyList["groups"] as $grp) {
				$this->_notifier->toGroup($user, $grp, $subject, $message);
			}
		}
		
		return true;
	}
	
	function removeDocumentFile($ID)
	{
		global $db, $user;
	
		$file = $this->getDocumentFile($ID);
		if (is_bool($file) && !$file) return false;
					
		if (file_exists( $this->_dms->contentDir . $file->getPath() )){
			if (!removeFile( $this->_dms->contentDir . $file->getPath() ))
				return false;
		}
		
		$name=$file->getName();
		$comment=$file->getcomment();
		
		$queryStr = "DELETE FROM tblDocumentFiles WHERE document = " . $this->getID() . " AND id = " . $ID;
		if (!$db->getResult($queryStr))
			return false;

		unset ($this->_documentFiles);
		
		$this->getNotifyList();
		if($this->_notifier) {
			// Send notification to subscribers.
			$subject = "###SITENAME###: ".$this->_name." - ".getMLText("removed_file_email");
			$message = getMLText("removed_file_email")."\r\n";
			$message .= 
				getMLText("name").": ".$name."\r\n".
				getMLText("comment").": ".$comment."\r\n".
				getMLText("user").": ".$user->getFullName()." <". $user->getEmail() .">\r\n".	
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->getID()."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			$this->_notifier->toList($user, $this->_notifyList["users"], $subject, $message);
			foreach ($this->_notifyList["groups"] as $grp) {
				$this->_notifier->toGroup($user, $grp, $subject, $message);
			}
		}

		return true;
	}

	function remove($send_email=TRUE)
	{
		GLOBAL $db, $user;
		
		$res = $this->getContent();
		if (is_bool($res) && !$res) return false;
		
		for ($i = 0; $i < count($this->_content); $i++)
			if (!$this->_content[$i]->remove(FALSE))
				return false;
				
		// remove document file
		$res = $this->getDocumentFiles();
		if (is_bool($res) && !$res) return false;
		
		for ($i = 0; $i < count($this->_documentFiles); $i++)
			if (!$this->_documentFiles[$i]->remove())
				return false;
				
		// TODO: versioning file?
				
		if (file_exists( $this->_dms->contentDir . $this->getDir() ))
			if (!removeDir( $this->_dms->contentDir . $this->getDir() ))
				return false;
		
		$queryStr = "DELETE FROM tblDocuments WHERE id = " . $this->_id;
		if (!$db->getResult($queryStr))
			return false;
		$queryStr = "DELETE FROM tblACLs WHERE target = " . $this->_id . " AND targetType = " . T_DOCUMENT;
		if (!$db->getResult($queryStr))
			return false;
		$queryStr = "DELETE FROM tblDocumentLinks WHERE document = " . $this->_id . " OR target = " . $this->_id;
		if (!$db->getResult($queryStr))
			return false;
		$queryStr = "DELETE FROM tblDocumentLocks WHERE document = " . $this->_id;
		if (!$db->getResult($queryStr))
			return false;
		$queryStr = "DELETE FROM tblDocumentFiles WHERE document = " . $this->_id;
		if (!$db->getResult($queryStr))
			return false;

		$path = "";
		$folder = $this->getFolder();
		$folderPath = $folder->getPath();
		for ($i = 0; $i  < count($folderPath); $i++) {
			$path .= $folderPath[$i]->getName();
			if ($i +1 < count($folderPath))
				$path .= " / ";
		}
		
		$this->getNotifyList();
		if ($send_email && $this->_notifier){
	
			$subject = "###SITENAME###: ".$this->getName()." - ".getMLText("document_deleted_email");
			$message = getMLText("document_deleted_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->getName()."\r\n".
				getMLText("folder").": ".$path."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				getMLText("user").": ".$user->getFullName()." <". $user->getEmail() ."> ";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			// Send notification to subscribers.
			$this->_notifier->toList($user, $this->_notifyList["users"], $subject, $message);
			foreach ($this->_notifyList["groups"] as $grp) {
				$this->_notifier->toGroup($user, $grp, $subject, $message);
			}
		}
		
		// Delete the notification list.
		$queryStr = "DELETE FROM tblNotify WHERE target = " . $this->_id . " AND targetType = " . T_DOCUMENT;
		if (!$db->getResult($queryStr))
			return false;

		return true;
	}

	function getApproversList() {

		GLOBAL $db, $settings;

		if (!isset($this->_approversList)) {
		
			$this->_approversList = array("groups" => array(), "users" => array());
			$userIDs = "";
			$groupIDs = "";
			$defAccess  = $this->getDefaultAccess();

			if ($defAccess<M_READ) {
				// Get the list of all users and groups that are listed in the ACL as
				// having read access to the document.
				$tmpList = $this->getAccessList(M_READ, O_GTEQ);
			}
			else {
				// Get the list of all users and groups that DO NOT have read access
				// to the document.
				$tmpList = $this->getAccessList(M_NONE, O_LTEQ);
			}
			foreach ($tmpList["groups"] as $group) {
				$groupIDs .= (strlen($groupIDs)==0 ? "" : ", ") . $group->getGroupID();
			}
			foreach ($tmpList["users"] as $c_user) {
			
				if (!$settings->_enableAdminRevApp && $c_user->getUserID()==$settings->_adminID) continue;
				$userIDs .= (strlen($userIDs)==0 ? "" : ", ") . $c_user->getUserID();
			}

			// Construct a query against the users table to identify those users
			// that have read access to this document, either directly through an
			// ACL entry, by virtue of ownership or by having administrative rights
			// on the database.
			$queryStr="";
			if ($defAccess < M_READ) {
				if (strlen($groupIDs)>0) {
					$queryStr = "(SELECT `tblUsers`.* FROM `tblUsers` ".
						"LEFT JOIN `tblGroupMembers` ON `tblGroupMembers`.`userID`=`tblUsers`.`id` ".
						"WHERE `tblGroupMembers`.`groupID` IN (". $groupIDs .") ".
						"AND `tblUsers`.`id` !='".$settings->_guestID."')";
				}
				$queryStr .= (strlen($queryStr)==0 ? "" : " UNION ").
					"(SELECT `tblUsers`.* FROM `tblUsers` ".
					"WHERE (`tblUsers`.`id` !='".$settings->_guestID."') ".
					"AND ((`tblUsers`.`id` = ". $this->_ownerID . ") ".
					"OR (`tblUsers`.`isAdmin` = 1)".
					(strlen($userIDs) == 0 ? "" : " OR (`tblUsers`.`id` IN (". $userIDs ."))").
					")) ORDER BY `login`";
			}
			else {
				if (strlen($groupIDs)>0) {
					$queryStr = "(SELECT `tblUsers`.* FROM `tblUsers` ".
						"LEFT JOIN `tblGroupMembers` ON `tblGroupMembers`.`userID`=`tblUsers`.`id` ".
						"WHERE `tblGroupMembers`.`groupID` NOT IN (". $groupIDs .")".
						"AND `tblUsers`.`id` != '".$settings->_guestID."' ".
						(strlen($userIDs) == 0 ? ")" : " AND (`tblUsers`.`id` NOT IN (". $userIDs .")))");
				}
				$queryStr .= (strlen($queryStr)==0 ? "" : " UNION ").
					"(SELECT `tblUsers`.* FROM `tblUsers` ".
					"WHERE (`tblUsers`.`id` = ". $this->_ownerID . ") ".
					"OR (`tblUsers`.`isAdmin` = 1))".
					"UNION ".
					"(SELECT `tblUsers`.* FROM `tblUsers` ".
					"WHERE `tblUsers`.`id` != '".$settings->_guestID."' ".
					(strlen($userIDs) == 0 ? ")" : " AND (`tblUsers`.`id` NOT IN (". $userIDs .")))").
					" ORDER BY `login`";
			}
			$resArr = $db->getResultArray($queryStr);
			if (!is_bool($resArr)) {
				foreach ($resArr as $row) {
					if ((!$settings->_enableAdminRevApp) && ($row["id"]==$settings->_adminID)) continue;					
					$this->_approversList["users"][] = new LetoDMS_User($row["id"], $row["login"], $row["pwd"], $row["fullName"], $row["email"], $row["language"], $row["theme"], $row["comment"], $row["isAdmin"]);
				}
			}

			// Assemble the list of groups that have read access to the document.
			$queryStr="";
			if ($defAccess < M_READ) {
				if (strlen($groupIDs)>0) {
					$queryStr = "SELECT `tblGroups`.* FROM `tblGroups` ".
						"WHERE `tblGroups`.`id` IN (". $groupIDs .")";
				}
			}
			else {
				if (strlen($groupIDs)>0) {
					$queryStr = "SELECT `tblGroups`.* FROM `tblGroups` ".
						"WHERE `tblGroups`.`id` NOT IN (". $groupIDs .")";
				}
				else {
					$queryStr = "SELECT `tblGroups`.* FROM `tblGroups`";
				}
			}
			if (strlen($queryStr)>0) {
				$resArr = $db->getResultArray($queryStr);
				if (!is_bool($resArr)) {
					foreach ($resArr as $row) {
						$this->_approversList["groups"][] = new LetoDMS_Group($row["id"], $row["name"], $row["comment"]);
					}
				}
			}
		}
		return $this->_approversList;
	}
} /* }}} */

 /* --------------------------------------------------------------------- */

/**
 * Die Datei wird als "data.ext" (z.b. data.txt) gespeichert. Getrennt davon wird in der DB der urspr�ngliche
 * Dateiname festgehalten (-> $orgFileName). Die Datei wird deshalb nicht unter diesem urspr�nglichen Namen
 * gespeichert, da es zu Problemen mit verschiedenen Dateisystemen kommen kann: Linux hat z.b. Probleme mit
 * deutschen Umlauten, w�hrend Windows wiederum den Doppelpunkt in Dateinamen nicht verwenden kann.
 * Der urspr�ngliche Dateiname wird nur zum Download verwendet (siehe op.Download.pgp)
 */
 
// these are the version information
class LetoDMS_DocumentContent { /* {{{ */

	// if status is released and there are reviewers set status draft_rev	
	// if status is released or draft_rev and there are approves set status draft_app
	// if status is draft and there are no approver and no reviewers set status to release	
	function verifyStatus($ignorecurrentstatus=false,$user=null){
	
		unset($this->_status);
		$st=$this->getStatus();
		
		if (!$ignorecurrentstatus && ($st["status"]==S_OBSOLETE || $st["status"]==S_REJECTED || $st["status"]==S_EXPIRED )) return;
		
		$pendingReview=false;
		unset($this->_reviewStatus);  // force to be reloaded from DB
		$reviewStatus=$this->getReviewStatus(true);	
		if (is_array($reviewStatus) && count($reviewStatus)>0) {
			foreach ($reviewStatus as $r){
				if ($r["status"]==0){	
					$pendingReview=true;
					break;
				}
			}
		}
		$pendingApproval=false;		
		unset($this->_approvalStatus);  // force to be reloaded from DB
		$approvalStatus=$this->getApprovalStatus(true);
		if (is_array($approvalStatus) && count($approvalStatus)>0) {
			foreach ($approvalStatus as $a){
				if ($a["status"]==0){
					$pendingApproval=true;
					break;
				}
			}
		}
		if ($pendingReview) $this->setStatus(S_DRAFT_REV,"",$user);
		else if ($pendingApproval) $this->setStatus(S_DRAFT_APP,"",$user);
		else $this->setStatus(S_RELEASED,"",$user);
	}

	function LetoDMS_DocumentContent($document, $version, $comment, $date, $userID, $dir, $orgFileName, $fileType, $mimeType)
	{
		$this->_document = $document;
		$this->_version = $version;
		$this->_comment = $comment;
		$this->_date = $date;
		$this->_userID = $userID;
		$this->_dir = $dir;
		$this->_orgFileName = $orgFileName;
		$this->_fileType = $fileType;
		$this->_mimeType = $mimeType;
		$this->_notifier = null;
	}

	function setNotifier($notifier) {
		$this->_notifier = $notifier;
	}

	function getVersion() { return $this->_version; }
	function getComment() { return $this->_comment; }
	function getDate() { return $this->_date; }
	function getOriginalFileName() { return $this->_orgFileName; }
	function getFileType() { return $this->_fileType; }
	function getFileName(){ return "data" . $this->_fileType; }
	function getDir() { return $this->_dir; }
	function getMimeType() { return $this->_mimeType; }
	function getUser()
	{
		if (!isset($this->_user))
			$this->_user = $this->_document->_dms->getUser($this->_userID);
		return $this->_user;
	}
	function getPath() { return $this->_dir . $this->_version . $this->_fileType; }
	
	function setComment($newComment) {
	
		GLOBAL $db, $user;

		$queryStr = "UPDATE tblDocumentContent SET comment = '" . $newComment . "' WHERE `document` = " . $this->_document->getID() .	" AND `version` = " . $this->_version;
		if (!$db->getResult($queryStr))
			return false;

		$this->_comment = $newComment;
		
		$nl=$this->_document->getNotifyList();

		if($this->_notifier) {
			$subject = "###SITENAME###: ".$this->_document->getName().", v.".$this->_version." - ".getMLText("comment_changed_email");
			$message = getMLText("comment_changed_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_document->getName()."\r\n".
				getMLText("version").": ".$this->_version."\r\n".
				getMLText("comment").": ".$newComment."\r\n".
				getMLText("user").": ".$user->getFullName()." <". $user->getEmail() .">\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_document->getID()."&version=".$this->_version."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			LetoDMS_Email::toList($user, $nl["users"], $subject, $message);
			foreach ($nl["groups"] as $grp) {
				LetoDMS_Email::toGroup($user, $grp, $subject, $message);
			}
		}

		return true;
	}


	function convert()
	{
		GLOBAL $settings;
		
		if (file_exists($this->_document->dms->contentDir . $this->_dir . "index.html"))
			return true;
		
		if (!in_array($this->_fileType, array_keys($settings->_convertFileTypes)))
			return false;
		
		$source = $this->_document->dms->contentDir . $this->_dir . $this->getFileName();
		$target = $this->_document->dms->contentDir . $this->_dir . "index.html";
	//	$source = str_replace("/", "\\", $source);
	//	$target = str_replace("/", "\\", $target);
		
		$command = $settings->_convertFileTypes[$this->_fileType];
		$command = str_replace("{SOURCE}", "\"$source\"", $command);
		$command = str_replace("{TARGET}", "\"$target\"", $command);
		
		$output = array();
		$res = 0;
		exec($command, $output, $res);
		
		if ($res != 0)
		{
			print (implode("\n", $output));
			return false;
		}
		return true;
	}

	function viewOnline()
	{
		GLOBAL $settings;

		if (!isset($settings->_viewOnlineFileTypes) || !is_array($settings->_viewOnlineFileTypes)) {
			return false;
		}

		if (in_array(strtolower($this->_fileType), $settings->_viewOnlineFileTypes)) return true;
		
		if ($settings->_enableConverting && in_array($this->_fileType, array_keys($settings->_convertFileTypes)))
			if ($this->wasConverted()) return true;
		
		return false;
	}

	function wasConverted() {
		return file_exists($this->_document->dms->contentDir . $this->_dir . "index.html");
	}

	function getURL()
	{
		GLOBAL $settings;
		
		if (!$this->viewOnline())return false;
		
		if (in_array(strtolower($this->_fileType), $settings->_viewOnlineFileTypes))
			return "/" . $this->_document->getID() . "/" . $this->_version . "/" . $this->getOriginalFileName();
		else
			return "/" . $this->_document->getID() . "/" . $this->_version . "/index.html";
	}

	// $send_email=FALSE is used when removing entire document 
	// to avoid one email for every version
	function remove($send_email=TRUE)
	{
		GLOBAL $db, $user;

		$emailList = array();
		$emailList[] = $this->_userID;

		if (file_exists( $this->_document->_dms->contentDir.$this->getPath() ))
			if (!removeFile( $this->_document->_dms->contentDir.$this->getPath() ))
				return false;
			
		$status = $this->getStatus();
		$stID = $status["statusID"];
			
		$queryStr = "DELETE FROM tblDocumentContent WHERE `document` = " . $this->_document->getID() .	" AND `version` = " . $this->_version;
		if (!$db->getResult($queryStr))
			return false;
		
		$queryStr = "DELETE FROM `tblDocumentStatusLog` WHERE `statusID` = '".$stID."'";
		if (!$db->getResult($queryStr))
			return false;
			
		$queryStr = "DELETE FROM `tblDocumentStatus` WHERE `documentID` = '". $this->_document->getID() ."' AND `version` = '" . $this->_version."'";
		if (!$db->getResult($queryStr))
			return false;

		$status = $this->getReviewStatus();
		$stList = "";
		foreach ($status as $st) {
			$stList .= (strlen($stList)==0 ? "" : ", "). "'".$st["reviewID"]."'";
			if ($st["status"]==0 && !in_array($st["required"], $emailList)) {
				$emailList[] = $st["required"];
			}
		}
		if (strlen($stList)>0) {
			$queryStr = "DELETE FROM `tblDocumentReviewLog` WHERE `tblDocumentReviewLog`.`reviewID` IN (".$stList.")";
			if (!$db->getResult($queryStr))
				return false;
		}
		$queryStr = "DELETE FROM `tblDocumentReviewers` WHERE `documentID` = '". $this->_document->getID() ."' AND `version` = '" . $this->_version."'";
		if (!$db->getResult($queryStr))
			return false;
		$status = $this->getApprovalStatus();
		$stList = "";
		foreach ($status as $st) {
			$stList .= (strlen($stList)==0 ? "" : ", "). "'".$st["approveID"]."'";
			if ($st["status"]==0 && !in_array($st["required"], $emailList)) {
				$emailList[] = $st["required"];
			}
		}
		if (strlen($stList)>0) {
			$queryStr = "DELETE FROM `tblDocumentApproveLog` WHERE `tblDocumentApproveLog`.`approveID` IN (".$stList.")";
			if (!$db->getResult($queryStr))
				return false;
		}
		$queryStr = "DELETE FROM `tblDocumentApprovers` WHERE `documentID` = '". $this->_document->getID() ."' AND `version` = '" . $this->_version."'";
		if (!$db->getResult($queryStr))
			return false;

		// Notify affected users.
		if ($send_email && $this->_notifier){
		
			$recipients = array();
			foreach ($emailList as $eID) {
				$eU = $this->_document->_dms->getUser($eID);
				$recipients[] = $eU;
			}
			$subject = "###SITENAME###: ".$this->_document->getName().", v.".$this->_version." - ".getMLText("version_deleted_email");
			$message = getMLText("version_deleted_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_document->getName()."\r\n".
				getMLText("version").": ".$this->_version."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				getMLText("user").": ".$user->getFullName()." <". $user->getEmail() ."> ";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			LetoDMS_Email::toList($user, $recipients, $subject, $message);
			
			// Send notification to subscribers.
			$nl=$this->_document->getNotifyList();
			LetoDMS_Email::toList($user, $nl["users"], $subject, $message);
			foreach ($nl["groups"] as $grp) {
				LetoDMS_Email::toGroup($user, $grp, $subject, $message);
			}
		}

		return true;
	}

	function getStatus($forceTemporaryTable=false) {
		GLOBAL $db;

		// Retrieve the current overall status of the content represented by
		// this object.
		if (!isset($this->_status)) {
			if (!$db->createTemporaryTable("ttstatid", $forceTemporaryTable)) {
				return false;
			}
			$queryStr="SELECT `tblDocumentStatus`.*, `tblDocumentStatusLog`.`status`, ".
				"`tblDocumentStatusLog`.`comment`, `tblDocumentStatusLog`.`date`, ".
				"`tblDocumentStatusLog`.`userID` ".
				"FROM `tblDocumentStatus` ".
				"LEFT JOIN `tblDocumentStatusLog` USING (`statusID`) ".
				"LEFT JOIN `ttstatid` ON `ttstatid`.`maxLogID` = `tblDocumentStatusLog`.`statusLogID` ".
				"WHERE `ttstatid`.`maxLogID`=`tblDocumentStatusLog`.`statusLogID` ".
				"AND `tblDocumentStatus`.`documentID` = '". $this->_document->getID() ."' ".
				"AND `tblDocumentStatus`.`version` = '". $this->_version ."' ";
			$res = $db->getResultArray($queryStr);
			if (is_bool($res) && !$res)
				return false;
			if (count($res)!=1)
				return false;
			$this->_status = $res[0];
		}
		return $this->_status;
	}

	function setStatus($status, $comment, $updateUser = null) {
		
		GLOBAL $db, $user, $settings;

		// If the supplied value lies outside of the accepted range, return an
		// error.
		if ($status < -3 || $status > 2) {
			return false;
		}

		// Retrieve the current overall status of the content represented by
		// this object, if it hasn't been done already.
		if (!isset($this->_status)) {
			$this->getStatus();
		}
		if ($this->_status["status"]==$status) {
			return false;
		}
		$queryStr = "INSERT INTO `tblDocumentStatusLog` (`statusID`, `status`, `comment`, `date`, `userID`) ".
			"VALUES ('". $this->_status["statusID"] ."', '". $status ."', '". $comment ."', NOW(), '". (is_null($updateUser) ? $settings->_adminID : $updateUser->getID()) ."')";
		$res = $db->getResult($queryStr);
		if (is_bool($res) && !$res)
			return false;

		$nl=$this->_document->getNotifyList();
		// Send notification to subscribers.
		if($this->_notifier) {
			$subject = "###SITENAME###: ".$this->_document->_name." - ".getMLText("document_status_changed_email");
			$message = getMLText("document_status_changed_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_document->_name."\r\n".
				getMLText("status").": ".getOverallStatusText($status)."\r\n".
				getMLText("folder").": ".getFolderPathPlain($this->_document->getFolder())."\r\n".
				getMLText("comment").": ".$this->_document->getComment()."\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_document->getID()."&version=".$this->_version."\r\n";

			$uu = (is_null($updateUser) ? $this->_document->_dms->getUser($settings->_adminID) : $updateUser);

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			LetoDMS_Email::toList($uu, $nl["users"], $subject, $message);
			foreach ($nl["groups"] as $grp) {
				LetoDMS_Email::toGroup($uu, $grp, $subject, $message);
			}
		}
		
		// TODO: if user os not owner send notification to owner

		return true;
	}

	function getReviewStatus($forceTemporaryTable=false) {
		GLOBAL $db;

		// Retrieve the current status of each assigned reviewer for the content
		// represented by this object.
		if (!isset($this->_reviewStatus)) {
			if (!$db->createTemporaryTable("ttreviewid", $forceTemporaryTable)) {
				return false;
			}
			$queryStr="SELECT `tblDocumentReviewers`.*, `tblDocumentReviewLog`.`status`, ".
				"`tblDocumentReviewLog`.`comment`, `tblDocumentReviewLog`.`date`, ".
				"`tblDocumentReviewLog`.`userID`, `tblUsers`.`fullName`, `tblGroups`.`name` AS `groupName` ".
				"FROM `tblDocumentReviewers` ".
				"LEFT JOIN `tblDocumentReviewLog` USING (`reviewID`) ".
				"LEFT JOIN `ttreviewid` on `ttreviewid`.`maxLogID` = `tblDocumentReviewLog`.`reviewLogID` ".
				"LEFT JOIN `tblUsers` on `tblUsers`.`id` = `tblDocumentReviewers`.`required`".
				"LEFT JOIN `tblGroups` on `tblGroups`.`id` = `tblDocumentReviewers`.`required`".
				"WHERE `ttreviewid`.`maxLogID`=`tblDocumentReviewLog`.`reviewLogID` ".
				"AND `tblDocumentReviewers`.`documentID` = '". $this->_document->getID() ."' ".
				"AND `tblDocumentReviewers`.`version` = '". $this->_version ."' ";
			$res = $db->getResultArray($queryStr);
			if (is_bool($res) && !$res)
				return false;
			// Is this cheating? Certainly much quicker than copying the result set
			// into a separate object.
			$this->_reviewStatus = $res;
		}
		return $this->_reviewStatus;
	}

	function getApprovalStatus($forceTemporaryTable=false) {
		GLOBAL $db;

		// Retrieve the current status of each assigned approver for the content
		// represented by this object.
		if (!isset($this->_approvalStatus)) {
			if (!$db->createTemporaryTable("ttapproveid", $forceTemporaryTable)) {
				return false;
			}
			$queryStr="SELECT `tblDocumentApprovers`.*, `tblDocumentApproveLog`.`status`, ".
				"`tblDocumentApproveLog`.`comment`, `tblDocumentApproveLog`.`date`, ".
				"`tblDocumentApproveLog`.`userID`, `tblUsers`.`fullName`, `tblGroups`.`name` AS `groupName` ".
				"FROM `tblDocumentApprovers` ".
				"LEFT JOIN `tblDocumentApproveLog` USING (`approveID`) ".
				"LEFT JOIN `ttapproveid` on `ttapproveid`.`maxLogID` = `tblDocumentApproveLog`.`approveLogID` ".
				"LEFT JOIN `tblUsers` on `tblUsers`.`id` = `tblDocumentApprovers`.`required`".
				"LEFT JOIN `tblGroups` on `tblGroups`.`id` = `tblDocumentApprovers`.`required`".
				"WHERE `ttapproveid`.`maxLogID`=`tblDocumentApproveLog`.`approveLogID` ".
				"AND `tblDocumentApprovers`.`documentID` = '". $this->_document->getID() ."' ".
				"AND `tblDocumentApprovers`.`version` = '". $this->_version ."'";
			$res = $db->getResultArray($queryStr);
			if (is_bool($res) && !$res)
				return false;
			$this->_approvalStatus = $res;
		}
		return $this->_approvalStatus;
	}

	function addIndReviewer($user, $requestUser, $sendEmail=false) {

		GLOBAL $db;

		$userID = $user->getID();

		// Get the list of users and groups with write access to this document.
		if (!isset($this->_approversList)) {
			$this->_approversList = $this->_document->getApproversList();
		}
		$approved = false;
		foreach ($this->_approversList["users"] as $appUser) {
			if ($userID == $appUser->getID()) {
				$approved = true;
				break;
			}
		}
		if (!$approved) {
			return -2;
		}

		// Check to see if the user has already been added to the review list.
		$reviewStatus = $user->getReviewStatus($this->_document->getID(), $this->_version);
		if (is_bool($reviewStatus) && !$reviewStatus) {
			return -1;
		}
		if (count($reviewStatus["indstatus"]) > 0 && $reviewStatus["indstatus"][0]["status"]!=-2) {
			// User is already on the list of reviewers; return an error.
			return -3;
		}

		// Add the user into the review database.
		if (! isset($reviewStatus["indstatus"][0]["status"])|| (isset($reviewStatus["indstatus"][0]["status"]) && $reviewStatus["indstatus"][0]["status"]!=-2)) {
			$queryStr = "INSERT INTO `tblDocumentReviewers` (`documentID`, `version`, `type`, `required`) ".
				"VALUES ('". $this->_document->getID() ."', '". $this->_version ."', '0', '". $userID ."')";
			$res = $db->getResult($queryStr);
			if (is_bool($res) && !$res) {
				return -1;
			}
			$reviewID = $db->getInsertID();
		}
		else {
			$reviewID = isset($reviewStatus["indstatus"][0]["reviewID"])?$reviewStatus["indstatus"][0]["reviewID"]:NULL;
		}
		
		$queryStr = "INSERT INTO `tblDocumentReviewLog` (`reviewID`, `status`, `comment`, `date`, `userID`) ".
			"VALUES ('". $reviewID ."', '0', '', NOW(), '". $requestUser->getID() ."')";
		$res = $db->getResult($queryStr);
		if (is_bool($res) && !$res) {
			return -1;
		}

		// Add reviewer to event notification table.
		//$this->_document->addNotify($userID, true);

		// Send an email notification to the new reviewer.
		if ($sendEmail && $this->_notifier) {
			$subject = "###SITENAME###: ".$this->_document->getName().", v.".$this->_version." - ".getMLText("review_request_email");
			$message = getMLText("review_request_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_document->getName()."\r\n".
				getMLText("version").": ".$this->_version."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				getMLText("user").": ".$requestUser->getFullName()." <". $requestUser->getEmail() .">\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_document->getID()."&version=".$this->_version."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			return (LetoDMS_Email::toIndividual($requestUser, $user, $subject, $message) < 0 ? -4 : 0);
		}
		return 0;
	}

	function addGrpReviewer($group, $requestUser, $sendEmail=false) {
		GLOBAL $db;

		$groupID = $group->getID();

		// Get the list of users and groups with write access to this document.
		if (!isset($this->_approversList)) {
			// TODO: error checking.
			$this->_approversList = $this->_document->getApproversList();
		}
		$approved = false;
		foreach ($this->_approversList["groups"] as $appGroup) {
			if ($groupID == $appGroup->getID()) {
				$approved = true;
				break;
			}
		}
		if (!$approved) {
			return -2;
		}

		// Check to see if the group has already been added to the review list.
		$reviewStatus = $group->getReviewStatus($this->_document->getID(), $this->_version);
		if (is_bool($reviewStatus) && !$reviewStatus) {
			return -1;
		}
		if (count($reviewStatus) > 0 && $reviewStatus[0]["status"]!=-2) {
			// Group is already on the list of reviewers; return an error.
			return -3;
		}

		// Add the group into the review database.
		if (!isset($reviewStatus[0]["status"]) || (isset($reviewStatus[0]["status"]) && $reviewStatus[0]["status"]!=-2)) {
			$queryStr = "INSERT INTO `tblDocumentReviewers` (`documentID`, `version`, `type`, `required`) ".
				"VALUES ('". $this->_document->getID() ."', '". $this->_version ."', '1', '". $groupID ."')";
			$res = $db->getResult($queryStr);
			if (is_bool($res) && !$res) {
				return -1;
			}
			$reviewID = $db->getInsertID();
		}
		else {
			$reviewID = isset($reviewStatus[0]["reviewID"])?$reviewStatus[0]["reviewID"]:NULL;
		}
		
		$queryStr = "INSERT INTO `tblDocumentReviewLog` (`reviewID`, `status`, `comment`, `date`, `userID`) ".
			"VALUES ('". $reviewID ."', '0', '', NOW(), '". $requestUser->getID() ."')";
		$res = $db->getResult($queryStr);
		if (is_bool($res) && !$res) {
			return -1;
		}

		// Add reviewer to event notification table.
		//$this->_document->addNotify($groupID, false);

		// Send an email notification to the new reviewer.
		if ($sendEmail && $this->_notifier) {
			$subject = "###SITENAME###: ".$this->_document->getName().", v.".$this->_version." - ".getMLText("review_request_email");
			$message = getMLText("review_request_email")."\r\n";
			$message .=
				getMLText("document").": ".$this->_document->getName()."\r\n".
				getMLText("version").": ".$this->_version."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				getMLText("user").": ".$requestUser->getFullName()." <". $requestUser->getEmail() .">\r\n".
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_document->getID()."&version=".$this->_version."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			return (LetoDMS_Email::toGroup($requestUser, $group, $subject, $message) < 0 ? -4 : 0);
		}
		return 0;
	}

	function addIndApprover($user, $requestUser, $sendEmail=false) {
		GLOBAL $db;

		$userID = $user->getID();

		// Get the list of users and groups with write access to this document.
		if (!isset($this->_approversList)) {
			// TODO: error checking.
			$this->_approversList = $this->_document->getApproversList();
		}
		$approved = false;
		foreach ($this->_approversList["users"] as $appUser) {
			if ($userID == $appUser->getID()) {
				$approved = true;
				break;
			}
		}
		if (!$approved) {
			return -2;
		}

		// Check to see if the user has already been added to the approvers list.
		$approvalStatus = $user->getApprovalStatus($this->_document->getID(), $this->_version);
		if (is_bool($approvalStatus) && !$approvalStatus) {
			return -1;
		}
		if (count($approvalStatus["indstatus"]) > 0 && $approvalStatus["indstatus"][0]["status"]!=-2) {
			// User is already on the list of approvers; return an error.
			return -3;
		}

		if ( !isset($approvalStatus["indstatus"][0]["status"]) || (isset($approvalStatus["indstatus"][0]["status"]) && $approvalStatus["indstatus"][0]["status"]!=-2)) {
			// Add the user into the approvers database.
			$queryStr = "INSERT INTO `tblDocumentApprovers` (`documentID`, `version`, `type`, `required`) ".
				"VALUES ('". $this->_document->getID() ."', '". $this->_version ."', '0', '". $userID ."')";
			$res = $db->getResult($queryStr);
			if (is_bool($res) && !$res) {
				return -1;
			}
			$approveID = $db->getInsertID();
		}
		else {
			$approveID = isset($approvalStatus["indstatus"][0]["approveID"]) ? $approvalStatus["indstatus"][0]["approveID"] : NULL;
		}

		$queryStr = "INSERT INTO `tblDocumentApproveLog` (`approveID`, `status`, `comment`, `date`, `userID`) ".
			"VALUES ('". $approveID ."', '0', '', NOW(), '". $requestUser->getID() ."')";
		$res = $db->getResult($queryStr);
		if (is_bool($res) && !$res) {
			return -1;
		}

		// Send an email notification to the new approver.
		if ($sendEmail && $this->_notifier) {
			$subject = "###SITENAME###: ".$this->_document->getName().", v.".$this->_version." - ".getMLText("approval_request_email");
			$message = getMLText("approval_request_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_document->getName()."\r\n".
				getMLText("version").": ".$this->_version."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				getMLText("user").": ".$requestUser->getFullName()." <". $requestUser->getEmail() .">\r\n".			
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_document->getID()."&version=".$this->_version."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			return (LetoDMS_Email::toIndividual($requestUser, $user, $subject, $message) < 0 ? -4 : 0);
		}
		return 0;
	}

	function addGrpApprover($group, $requestUser, $sendEmail=false) {
		GLOBAL $db;

		$groupID = $group->getID();

		// Get the list of users and groups with write access to this document.
		if (!isset($this->_approversList)) {
			// TODO: error checking.
			$this->_approversList = $this->_document->getApproversList();
		}
		$approved = false;
		foreach ($this->_approversList["groups"] as $appGroup) {
			if ($groupID == $appGroup->getID()) {
				$approved = true;
				break;
			}
		}
		if (!$approved) {
			return -2;
		}

		// Check to see if the group has already been added to the approver list.
		$approvalStatus = $group->getApprovalStatus($this->_document->getID(), $this->_version);
		if (is_bool($approvalStatus) && !$approvalStatus) {
			return -1;
		}
		if (count($approvalStatus) > 0 && $approvalStatus[0]["status"]!=-2) {
			// Group is already on the list of approvers; return an error.
			return -3;
		}

		// Add the group into the approver database.
		if (!isset($approvalStatus[0]["status"]) || (isset($approvalStatus[0]["status"]) && $approvalStatus[0]["status"]!=-2)) {
			$queryStr = "INSERT INTO `tblDocumentApprovers` (`documentID`, `version`, `type`, `required`) ".
				"VALUES ('". $this->_document->getID() ."', '". $this->_version ."', '1', '". $groupID ."')";
			$res = $db->getResult($queryStr);
			if (is_bool($res) && !$res) {
				return -1;
			}
			$approveID = $db->getInsertID();
		}
		else {
			$approveID = isset($approvalStatus[0]["approveID"])?$approvalStatus[0]["approveID"]:NULL;
		}
		
		$queryStr = "INSERT INTO `tblDocumentApproveLog` (`approveID`, `status`, `comment`, `date`, `userID`) ".
			"VALUES ('". $approveID ."', '0', '', NOW(), '". $requestUser->getID() ."')";
		$res = $db->getResult($queryStr);
		if (is_bool($res) && !$res) {
			return -1;
		}

		// Add approver to event notification table.
		//$this->_document->addNotify($groupID, false);

		// Send an email notification to the new approver.
		if ($sendEmail && $this->_notifier) {
			$subject = "###SITENAME###: ".$this->_document->getName().", v.".$this->_version." - ".getMLText("approval_request_email");
			$message = getMLText("approval_request_email")."\r\n";
			$message .=
				getMLText("document").": ".$this->_document->getName()."\r\n".
				getMLText("version").": ".$this->_version."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				getMLText("user").": ".$requestUser->getFullName()." <". $requestUser->getEmail() .">\r\n".			
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_document->getID()."&version=".$this->_version."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			return (LetoDMS_Email::toGroup($requestUser, $group, $subject, $message) < 0 ? -4 : 0);
		}
		return 0;
	}

	function delIndReviewer($user, $requestUser, $sendEmail=false) {
		GLOBAL $db;

		$userID = $user->getID();

		// Check to see if the user can be removed from the review list.
		$reviewStatus = $user->getReviewStatus($this->_document->getID(), $this->_version);
		if (is_bool($reviewStatus) && !$reviewStatus) {
			return -1;
		}
		if (count($reviewStatus["indstatus"])==0) {
			// User is not assigned to review this document. No action required.
			// Return an error.
			return -3;
		}
		if ($reviewStatus["indstatus"][0]["status"]!=0) {
			// User has already submitted a review or has already been deleted;
			// return an error.
			return -3;
		}
		
		$queryStr = "INSERT INTO `tblDocumentReviewLog` (`reviewID`, `status`, `comment`, `date`, `userID`) ".
			"VALUES ('". $reviewStatus["indstatus"][0]["reviewID"] ."', '-2', '', NOW(), '". $requestUser->getID() ."')";
		$res = $db->getResult($queryStr);
		if (is_bool($res) && !$res) {
			return -1;
		}

		// Send an email notification to the reviewer.
		if ($sendEmail && $this->_notifier) {
		
			$subject = "###SITENAME###: ".$this->_document->getName().", v.".$this->_version." - ".getMLText("review_deletion_email");
			$message = getMLText("review_deletion_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_document->getName()."\r\n".
				getMLText("version").": ".$this->_version."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				getMLText("user").": ".$requestUser->getFullName()." <". $requestUser->getEmail() .">\r\n".			
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_document->getID()."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			return (LetoDMS_Email::toIndividual($requestUser, $user, $subject, $message) < 0 ? -4 : 0);
		}
		return 0;
	}

	function delGrpReviewer($group, $requestUser, $sendEmail=false) {
		GLOBAL $db;

		$groupID = $group->getID();

		// Check to see if the user can be removed from the review list.
		$reviewStatus = $group->getReviewStatus($this->_document->getID(), $this->_version);
		if (is_bool($reviewStatus) && !$reviewStatus) {
			return -1;
		}
		if (count($reviewStatus)==0) {
			// User is not assigned to review this document. No action required.
			// Return an error.
			return -3;
		}
		if ($reviewStatus[0]["status"]!=0) {
			// User has already submitted a review or has already been deleted;
			// return an error.
			return -3;
		}
		
		$queryStr = "INSERT INTO `tblDocumentReviewLog` (`reviewID`, `status`, `comment`, `date`, `userID`) ".
			"VALUES ('". $reviewStatus[0]["reviewID"] ."', '-2', '', NOW(), '". $requestUser->getID() ."')";
		$res = $db->getResult($queryStr);
		if (is_bool($res) && !$res) {
			return -1;
		}

		// Send an email notification to the review group.
		if ($sendEmail && $this->_notifier) {
		
			$subject = "###SITENAME###: ".$this->_document->getName().", v.".$this->_version." - ".getMLText("review_deletion_email");
			$message = getMLText("review_deletion_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_document->getName()."\r\n".
				getMLText("version").": ".$this->_version."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				getMLText("user").": ".$requestUser->getFullName()." <". $requestUser->getEmail() .">\r\n".			
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_document->getID()."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			return (LetoDMS_Email::toGroup($requestUser, $group, $subject, $message) < 0 ? -4 : 0);
		}
		return 0;
	}

	function delIndApprover($user, $requestUser, $sendEmail=false) {
		GLOBAL $db;

		$userID = $user->getID();

		// Check to see if the user can be removed from the approval list.
		$approvalStatus = $user->getApprovalStatus($this->_document->getID(), $this->_version);
		if (is_bool($approvalStatus) && !$approvalStatus) {
			return -1;
		}
		if (count($approvalStatus["indstatus"])==0) {
			// User is not assigned to approve this document. No action required.
			// Return an error.
			return -3;
		}
		if ($approvalStatus["indstatus"][0]["status"]!=0) {
			// User has already submitted an approval or has already been deleted;
			// return an error.
			return -3;
		}
		
		$queryStr = "INSERT INTO `tblDocumentApproveLog` (`approveID`, `status`, `comment`, `date`, `userID`) ".
			"VALUES ('". $approvalStatus["indstatus"][0]["approveID"] ."', '-2', '', NOW(), '". $requestUser->getID() ."')";
		$res = $db->getResult($queryStr);
		if (is_bool($res) && !$res) {
			return -1;
		}

		// Send an email notification to the approver.
		if ($sendEmail && $this->_notifier) {
		
			$subject = "###SITENAME###: ".$this->_document->getName().", v.".$this->_version." - ".getMLText("approval_deletion_email");
			$message = getMLText("approval_deletion_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_document->getName()."\r\n".
				getMLText("version").": ".$this->_version."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				getMLText("user").": ".$requestUser->getFullName()." <". $requestUser->getEmail() .">\r\n".			
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_document->getID()."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			return (LetoDMS_Email::toIndividual($requestUser, $user, $subject, $message) < 0 ? -4 : 0);
		}
		return 0;
	}

	function delGrpApprover($group, $requestUser, $sendEmail=false) {
		GLOBAL $db;

		$groupID = $group->getID();

		// Check to see if the user can be removed from the approver list.
		$approvalStatus = $group->getApprovalStatus($this->_document->getID(), $this->_version);
		if (is_bool($approvalStatus) && !$approvalStatus) {
			return -1;
		}
		if (count($approvalStatus)==0) {
			// User is not assigned to approve this document. No action required.
			// Return an error.
			return -3;
		}
		if ($approvalStatus[0]["status"]!=0) {
			// User has already submitted an approval or has already been deleted;
			// return an error.
			return -3;
		}
		
		$queryStr = "INSERT INTO `tblDocumentApproveLog` (`approveID`, `status`, `comment`, `date`, `userID`) ".
			"VALUES ('". $approvalStatus[0]["approveID"] ."', '-2', '', NOW(), '". $requestUser->getID() ."')";
		$res = $db->getResult($queryStr);
		if (is_bool($res) && !$res) {
			return -1;
		}

		// Send an email notification to the approval group.
		if ($sendEmail && $this->_notifier) {
		
			$subject = "###SITENAME###: ".$this->_document->getName().", v.".$this->_version." - ".getMLText("approval_deletion_email");
			$message = getMLText("approval_deletion_email")."\r\n";
			$message .= 
				getMLText("document").": ".$this->_document->getName()."\r\n".
				getMLText("version").": ".$this->_version."\r\n".
				getMLText("comment").": ".$this->getComment()."\r\n".
				getMLText("user").": ".$requestUser->getFullName()." <". $requestUser->getEmail() .">\r\n".			
				"URL: ###URL_PREFIX###out/out.ViewDocument.php?documentid=".$this->_document->getID()."\r\n";

			$subject=mydmsDecodeString($subject);
			$message=mydmsDecodeString($message);
			
			return (LetoDMS_Email::toGroup($requestUser, $group, $subject, $message) < 0 ? -4 : 0);
		}
		return 0;
	}
} /* }}} */


/* ----------------------------------------------------------------------- */
 
function filterDocumentLinks($user, $links)
{
	GLOBAL $settings;
	
	$tmp = array();
	foreach ($links as $link)
		if ($link->isPublic() || ($link->_userID == $user->getID()) || ($user->getID() == $settings->_adminID) )
			array_push($tmp, $link);
	return $tmp;
}

class LetoDMS_DocumentLink { /* {{{ */
	var $_id;
	var $_document;
	var $_target;
	var $_userID;
	var $_public;

	function LetoDMS_DocumentLink($id, $document, $target, $userID, $public)
	{
		$this->_id = $id;
		$this->_document = $document;
		$this->_target = $target;
		$this->_userID = $userID;
		$this->_public = $public;
	}

	function getID() { return $this->_id; }

	function getDocument()
	{
		return $this->_document;
	}

	function getTarget()
	{
		return $this->_target;
	}

	function getUser()
	{
		if (!isset($this->_user))
			$this->_user = $this->_document->_dms->getUser($this->_userID);
		return $this->_user;
	}

	function isPublic() { return $this->_public; }

	function __remove() // Do not use anymore
	{
		GLOBAL $db;
		
		$queryStr = "DELETE FROM tblDocumentLinks WHERE id = " . $this->_id;
		if (!$db->getResult($queryStr))
			return false;
		
		return true;
	}
} /* }}} */

/* ---------------------------------------------------------------------- */

class LetoDMS_DocumentFile { /* {{{ */
	var $_id;
	var $_document;
	var $_userID;
	var $_comment;
	var $_date;
	var $_dir;
	var $_fileType;
	var $_mimeType;
	var $_orgFileName;
	var $_name;

	function LetoDMS_DocumentFile($id, $document, $userID, $comment, $date, $dir, $fileType, $mimeType, $orgFileName,$name)
	{
		$this->_id = $id;
		$this->_document = $document;
		$this->_userID = $userID;
		$this->_comment = $comment;
		$this->_date = $date;
		$this->_dir = $dir;
		$this->_fileType = $fileType;
		$this->_mimeType = $mimeType;
		$this->_orgFileName = $orgFileName;
		$this->_name = $name;
	}

	function getID() { return $this->_id; }
	function getDocument() { return $this->_document; }
	function getUserID() { return $this->_userID; }
	function getComment() { return $this->_comment; }
	function getDate() { return $this->_date; }
	function getDir() { return $this->_dir; }
	function getFileType() { return $this->_fileType; }
	function getMimeType() { return $this->_mimeType; }
	function getOriginalFileName() { return $this->_orgFileName; }
	function getName() { return $this->_name; }
	
	function getUser()
	{
		if (!isset($this->_user))
			$this->_user = $this->_dms->getUser($this->_userID);
		return $this->_user;
	}
	
	function getPath()
	{
		return $this->_dir . "f" .$this->_id . $this->_fileType;
	}

	function __remove() // do not use anymore, will be called from document->removeDocumentFile
	{
		GLOBAL $db;
		
		if (file_exists( $this->_document->_dms->contentDir.$this->getPath() ))
			if (!removeFile( $this->_document->_dms->contentDir.$this->getPath() ))
				return false;
	
		
		$queryStr = "DELETE FROM tblDocumentFiles WHERE document = " . $this->_document->getID() . " AND id = " . $this->_id;
		if (!$db->getResult($queryStr))
			return false;
		
		return true;
	}
} /* }}} */

//
// Perhaps not the cleanest object ever devised, it exists to encapsulate all
// of the data generated during the addition of new content to the database.
// The object stores a copy of the new DocumentContent object, the newly assigned
// reviewers and approvers and the status.
//
class LetoDMS_AddContentResultSet { /* {{{ */
	
	var $_indReviewers;
	var $_grpReviewers;
	var $_indApprovers;
	var $_grpApprovers;
	var $_content;
	var $_status;

	function LetoDMS_AddContentResultSet($content) {

		$this->_content = $content;
		$this->_indReviewers = null;
		$this->_grpReviewers = null;
		$this->_indApprovers = null;
		$this->_grpApprovers = null;
		$this->_status = null;
	}

	function addReviewer($reviewer, $type, $status) {
		
		if (!is_object($reviewer) || (strcasecmp($type, "i") && strcasecmp($type, "g")) && !is_integer($status)){
			return false;
		}
		if (!strcasecmp($type, "i")) {
			if (strcasecmp(get_class($reviewer), "LetoDMS_User")) {
				return false;
			}
			if ($this->_indReviewers == null) {
				$this->_indReviewers = array();
			}
			$this->_indReviewers[$status][] = $reviewer;
		}
		if (!strcasecmp($type, "g")) {
			if (strcasecmp(get_class($reviewer), "LetoDMS_Group")) {
				return false;
			}
			if ($this->_grpReviewers == null) {
				$this->_grpReviewers = array();
			}
			$this->_grpReviewers[$status][] = $reviewer;
		}
		return true;
	}

	function addApprover($approver, $type, $status) {
		
		if (!is_object($approver) || (strcasecmp($type, "i") && strcasecmp($type, "g")) && !is_integer($status)){
			return false;
		}
		if (!strcasecmp($type, "i")) {
			if (strcasecmp(get_class($approver), "LetoDMS_User")) {
				return false;
			}
			if ($this->_indApprovers == null) {
				$this->_indApprovers = array();
			}
			$this->_indApprovers[$status][] = $approver;
		}
		if (!strcasecmp($type, "g")) {
			if (strcasecmp(get_class($approver), "LetoDMS_Group")) {
				return false;
			}
			if ($this->_grpApprovers == null) {
				$this->_grpApprovers = array();
			}
			$this->_grpApprovers[$status][] = $approver;
		}
		return true;
	}

	function setStatus($status) {
		if (!is_integer($status)) {
			return false;
		}
		if ($status<-3 || $status>2) {
			return false;
		}
		$this->_status = $status;
		return true;
	}

	function getStatus() {
		return $this->_status;
	}

	function getReviewers($type) {
		if (strcasecmp($type, "i") && strcasecmp($type, "g")) {
			return false;
		}
		if (!strcasecmp($type, "i")) {
			return ($this->_indReviewers == null ? array() : $this->_indReviewers);
		}
		else {
			return ($this->_grpReviewers == null ? array() : $this->_grpReviewers);
		}
	}

	function getApprovers($type) {
		if (strcasecmp($type, "i") && strcasecmp($type, "g")) {
			return false;
		}
		if (!strcasecmp($type, "i")) {
			return ($this->_indApprovers == null ? array() : $this->_indApprovers);
		}
		else {
			return ($this->_grpApprovers == null ? array() : $this->_grpApprovers);
		}
	}
} /* }}} */
?>

<?php
//    MyDMS. Document Management System
//    Copyright (C) 2002-2005  Markus Westphal
//    Copyright (C) 2006-2008 Malcolm Cowe
//    Copyright (C) 2010 Matteo Lucarelli
//    Copyright (C) 2011 Matteo Lucarelli
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

include("../inc/inc.Version.php");
include("../inc/inc.Settings.php");
include("../inc/inc.DBInit.php");
include("../inc/inc.Language.php");
include("../inc/inc.ClassUI.php");
include("../inc/inc.Authentication.php");

function tree($folder, $repair, $path=':', $indent='') { /* {{{ */
	global $dms, $repair, $needsrepair;

	/* Don't do folderlist check for root folder */
	if($path != ':') {
		$folderList = $folder->getFolderList();
		/* Check the folder */
		if($folderList != $path) {
			print "<tr>\n";
			$needsrepair = true;
			print "<td><a class=\"standardText\" href=\"../out/out.ViewFolder.php?folderid=".$folder->getID()."\"><img src=\"../out/images/folder_closed.gif\" width=18 height=18 border=0></a></td>";
			print "<td><a class=\"standardText\" href=\"../out/out.ViewFolder.php?folderid=".$folder->getID()."\">";
			$tmppath = $folder->getPath();
			for ($i = 1; $i  < count($tmppath); $i++) {
				print "/".htmlspecialchars($tmppath[$i]->getName());
			}
			print $foldername;
			print "</a></td>";
			
			$owner = $folder->getOwner();
			print "<td>".htmlspecialchars($owner->getFullName())."</td>";
			print "<td>Folderlist is '".$folderList."', should be '".$path."'</td>";
			if($repair) {
				$folder->repair();
				print "<td><span class=\"success\">Repaired</span></td>\n";
			} else {
				print "<td></td>\n";
			}
			print "</tr>\n";
		}
	}

	$subfolders = $folder->getSubFolders();
	foreach($subfolders as $subfolder) {
		tree($subfolder, $indent.'  ', $path.$folder->getId().':');
	}
	$path .= $folder->getId().':';
	$documents = $folder->getDocuments();
	foreach($documents as $document) {
		/* Check the folder list of the document */
		$folderList = $document->getFolderList();
		if($folderList != $path) {
			print "<tr>\n";
			$needsrepair = true;
			$lc = $document->getLatestContent();
			print "<td><a class=\"standardText\" href=\"../out/out.ViewDocument.php?documentid=".$document->getID()."\"><img class=\"mimeicon\" src=\"../out/images/icons/".UI::getMimeIcon($lc->getFileType())."\" title=\"".$lc->getMimeType()."\"></a></td>";
			print "<td><a class=\"standardText\" href=\"../out/out.ViewDocument.php?documentid=".$document->getID()."\">/";
			$folder = $document->getFolder();
			$tmppath = $folder->getPath();
			for ($i = 1; $i  < count($tmppath); $i++) {
				print htmlspecialchars($tmppath[$i]->getName())."/";
			}
			print htmlspecialchars($document->getName());
			print "</a></td>";
			$owner = $document->getOwner();
			print "<td>".htmlspecialchars($owner->getFullName())."</td>";
			print "<td>Folderlist is '".$folderList."', should be '".$path."'</td>";
			if($repair) {
				$document->repair();
				print "<td><span class=\"success\">Repaired</span></td>\n";
			} else {
				print "<td></td>\n";
			}
			print "</tr>\n";
		}

		/* Check if the content is available */
		$versions = $document->getContent();
		if($versions) {
			foreach($versions as $version) {
				$filepath = $dms->contentDir . $version->getPath();
				if(!file_exists($filepath)) {
				print "<tr>\n";
				print "<td><a class=\"standardText\" href=\"../out/out.ViewDocument.php?documentid=".$document->getID()."\"><img class=\"mimeicon\" src=\"../out/images/icons/".UI::getMimeIcon($version->getFileType())."\" title=\"".$version->getMimeType()."\"></a></td>";
				print "<td><a class=\"standardText\" href=\"../out/out.ViewDocument.php?documentid=".$document->getID()."\">/";
				$folder = $document->getFolder();
				$tmppath = $folder->getPath();
				for ($i = 1; $i  < count($tmppath); $i++) {
					print htmlspecialchars($tmppath[$i]->getName())."/";
				}
				print htmlspecialchars($document->getName());
				print "</a></td>";
				$owner = $document->getOwner();
				print "<td>".htmlspecialchars($owner->getFullName())."</td>";
				print "<td>Document content of version ".$version->getVersion()." is missing ('".$path."')</td>";
				if($repair) {
					print "<td><span class=\"warning\">Cannot repaired</span></td>\n";
				} else {
					print "<td></td>\n";
				}
				print "</tr>\n";
				}
			}
		} else {
			print "<tr>\n";
			print "<td></td>\n";
			print "<td><a class=\"standardText\" href=\"../out/out.ViewDocument.php?documentid=".$document->getID()."\">/";
			$folder = $document->getFolder();
			$tmppath = $folder->getPath();
			for ($i = 1; $i  < count($tmppath); $i++) {
				print htmlspecialchars($tmppath[$i]->getName())."/";
			}
			print htmlspecialchars($document->getName());
			print "</a></td>";
			$owner = $document->getOwner();
			print "<td>".htmlspecialchars($owner->getFullName())."</td>";
			print "<td>Document has no content! Delete the document manually.</td>";
			print "</tr>\n";
		}
	}
} /* }}} */

if (!$user->isAdmin()) {
	UI::exitError(getMLText("admin_tools"),getMLText("access_denied"));
}

$v = new LetoDMS_Version;

UI::htmlStartPage(getMLText("admin_tools"));
UI::globalNavigation();
UI::pageNavigation(getMLText("admin_tools"), "admin_tools");
UI::contentHeading(getMLText("objectcheck"));
UI::contentContainerStart();

if(isset($_GET['repair']) && $_GET['repair'] == 1) {
	$repair = 1;
	echo "<p>".getMLText('repairing_objects')."</p>";
} else {
	$repair = 0;
}

$folder = $dms->getFolder($settings->_rootFolderID);
print "<table class=\"folderView\">";
print "<thead>\n<tr>\n";
print "<th></th>\n";
print "<th>".getMLText("name")."</th>\n";
print "<th>".getMLText("owner")."</th>\n";
print "<th>".getMLText("error")."</th>\n";
print "<th></th>\n";
print "</tr>\n</thead>\n<tbody>\n";
$needsrepair = false;
tree($folder, $repair);
print "</tbody></table>\n";

if($needsrepair && $repair == 0) {
	echo '<p><a href="out.ObjectCheck.php?repair=1">'.getMLText('do_object_repair').'</a></p>';
}
UI::contentContainerEnd();

UI::contentHeading(getMLText("unlinked_content"));
UI::contentContainerStart();
if(isset($_GET['unlink']) && $_GET['unlink'] == 1) {
	$unlink = 1;
	echo "<p>".getMLText('unlinking_objects')."</p>";
} else {
	$unlink = 0;
}

if($versions = $dms->getUnlinkedDocumentContent()) {
	print "<table class=\"folderView\">";
	print "<thead>\n<tr>\n";
	print "<th>".getMLText("document")."</th>\n";
	print "<th>".getMLText("version")."</th>\n";
	print "<th>".getMLText("original_filename")."</th>\n";
	print "<th>".getMLText("mimetype")."</th>\n";
	print "<th></th>\n";
	print "</tr>\n</thead>\n<tbody>\n";
	foreach($versions as $version) {
		$doc = $version->getDocument();
		print "<tr><td>".$doc->getId()."</td><td>".$version->getVersion()."</td><td>".$version->getOriginalFileName()."</td><td>".$version->getMimeType()."</td>";
		if($unlink) {
			$doc->removeContent($version);
		}
		print "</tr>\n";
	}
	print "</tbody></table>\n";
	if($unlink == 0) {
		echo '<p><a href="out.ObjectCheck.php?unlink=1">'.getMLText('do_object_unlink').'</a></p>';
	}
}

UI::contentContainerEnd();
UI::htmlEndPage();
?>

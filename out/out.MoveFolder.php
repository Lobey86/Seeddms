<?php
//    MyDMS. Document Management System
//    Copyright (C) 2002-2005  Markus Westphal
//    Copyright (C) 2006-2008 Malcolm Cowe
//    Copyright (C) 2006-2008 Malcolm Cowe
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

include("../inc/inc.Settings.php");
include("../inc/inc.Utils.php");
include("../inc/inc.DBInit.php");
include("../inc/inc.Language.php");
include("../inc/inc.ClassUI.php");
include("../inc/inc.Authentication.php");

if (!isset($_GET["folderid"]) || !is_numeric($_GET["folderid"]) || intval($_GET["folderid"])<1) {
	UI::exitError(getMLText("folder_title", array("foldername" => getMLText("invalid_folder_id"))),getMLText("invalid_folder_id"));
}

$folderid = intval($_GET["folderid"]);
$folder = $dms->getFolder($folderid);

if (!is_object($folder)) {
	UI::exitError(getMLText("folder_title", array("foldername" => getMLText("invalid_folder_id"))),getMLText("invalid_folder_id"));
}

$folderPathHTML = getFolderPathHTML($folder, true);

if ($folderid == $settings->_rootFolderID || !$folder->getParent()) {
	UI::exitError(getMLText("folder_title", array("foldername" => htmlspecialchars($folder->getName()))),getMLText("cannot_move_root"));
}

if ($folder->getAccessMode($user) < M_READWRITE) {
	UI::exitError(getMLText("folder_title", array("foldername" => htmlspecialchars($folder->getName()))),getMLText("access_denied"));
}

UI::htmlStartPage(getMLText("folder_title", array("foldername" => htmlspecialchars($folder->getName()))));
UI::globalNavigation($folder);
UI::pageNavigation($folderPathHTML, "view_folder", $folder);
UI::contentHeading(getMLText("move_folder"));
UI::contentContainerStart();

?>
<form action="../op/op.MoveFolder.php" name="form1">
	<input type="Hidden" name="folderid" value="<?php print $folderid;?>">
	<input type="Hidden" name="showtree" value="<?php echo showtree();?>">
	<table>
		<tr>
			<td><?php printMLText("choose_target_folder");?>:</td>
			<td><?php UI::printFolderChooser("form1", M_READWRITE, $folder->getID());?></td>
		</tr>
		<tr>
			<td colspan="2"><input type="Submit" value="<?php printMLText("move_folder"); ?>"></td>
		</tr>
	</table>
	</form>


<?php
UI::contentContainerEnd();
UI::htmlEndPage();
?>

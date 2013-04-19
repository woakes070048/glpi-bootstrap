<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2013 by the INDEPNET Development Team.

 http://indepnet.net/   http://glpi-project.org
 -------------------------------------------------------------------------

 LICENSE

 This file is part of GLPI.

 GLPI is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 GLPI is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

/** @file
* @brief
*/


include ('../inc/includes.php');

Session::checkCentralAccess();

if (!isset($_GET["id"])) {
   $_GET["id"] = "";
}
if (!isset($_GET["computers_id"])) {
   $_GET["computers_id"] = "";
}

$disk = new ComputerDisk();
if (isset($_POST["add"])) {
   $disk->check(-1, CommonDBTM::CREATE, $_POST);

   if ($newID = $disk->add($_POST)) {
      Event::log($_POST['computers_id'], "computers", 4, "inventory",
                 //TRANS: %s is the user login
                 sprintf(__('%s adds a volume'), $_SESSION["glpiname"]));
   }
   Html::back();

} else if (isset($_POST["delete"])) {
   $disk->check($_POST["id"], ProfileRight::DELETE);

   if ($disk->delete($_POST)) {
      Event::log($disk->fields['computers_id'], "computers", 4, "inventory",
                 //TRANS: %s is the user login
                 sprintf(__('%s deletes a volume'), $_SESSION["glpiname"]));
   }
   $computer = new Computer();
   $computer->getFromDB($disk->fields['computers_id']);
   Html::redirect(Toolbox::getItemTypeFormURL('Computer').'?id='.$disk->fields['computers_id'].
                  ($computer->fields['is_template']?"&withtemplate=1":""));

} else if (isset($_POST["update"])) {
   $disk->check($_POST["id"], ProfileRight::UPDATE);

   if ($disk->update($_POST)) {
      Event::log($disk->fields['computers_id'], "computers", 4, "inventory",
                 //TRANS: %s is the user login
                 sprintf(__('%s updates a volume'), $_SESSION["glpiname"]));
   }
   Html::back();

} else {
   Html::header(Computer::getTypeName(2), $_SERVER['PHP_SELF'], "assets", "computer");
   $disk->display(array('id'           => $_GET["id"],
                        'computers_id' => $_GET["computers_id"]));
   Html::footer();
}
?>
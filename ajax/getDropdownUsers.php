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

// Direct access to file
if (strpos($_SERVER['PHP_SELF'],"getDropdownUsers.php")) {
   $AJAX_INCLUDE = 1;
   include ('../inc/includes.php');
   header("Content-Type: text/html; charset=UTF-8");
   Html::header_nocache();
}

if (!defined('GLPI_ROOT')) {
   die("Can not acces directly to this file");
}

Session::checkLoginUser();

if (!isset($_GET['right'])) {
   $_GET['right'] = "all";
}

// Default view : Nobody
if (!isset($_GET['all'])) {
   $_GET['all'] = 0;
}

$used = array();

if (isset($_GET['used'])) {
   $used = $_GET['used'];
}

if (!isset($_GET['value'])) {
   $_GET['value'] = 0;
}

$result = User::getSqlSearchResult(false, $_GET['right'], $_GET["entity_restrict"],
                                   $_GET['value'], $used, $_GET['searchText']);

$users = array();

if ($DB->numrows($result)) {
   while ($data=$DB->fetch_assoc($result)) {
      $users[$data["id"]] = formatUserName($data["id"], $data["name"], $data["realname"],
                                           $data["firstname"]);
      $logins[$data["id"]] = $data["name"];
   }
}

if (!function_exists('dpuser_cmp')) {
   function dpuser_cmp($a, $b) {
      return strcasecmp($a, $b);
   }
}

// Sort non case sensitive
uasort($users, 'dpuser_cmp');

$datas = array();

if ($_GET['all']==0) {
   array_push($datas, array('id'   => 0,
                            'text' => Dropdown::EMPTY_VALUE));
//    echo "<option value='0'>".Dropdown::EMPTY_VALUE."</option>";
} else if ($_GET['all']==1) {
   array_push($datas, array('id'   => 0,
                            'text' => __('All')));
}

if (count($users)) {
   foreach ($users as $ID => $output) {
      $title = sprintf(__('%1$s - %2$s'), $output, $logins[$ID]);

      array_push($datas, array('id'   => 0,
                              'text'  => Toolbox::substr($output, 0, $_SESSION["glpidropdown_chars_limit"]),
                              'title' => $title));
   }
}

$ret['results'] = $datas;

echo json_encode($ret);
?>
<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2014 by the INDEPNET Development Team.

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

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Central class
**/
class Central extends CommonGLPI {


   static function getTypeName($nb=0) {

      // No plural
      return __('Standard interface');
   }


   function defineTabs($options=array()) {

      $ong = array();
      $this->addStandardTab(__CLASS__, $ong, $options);

      return $ong;
   }


   function getTabNameForItem(CommonGLPI $item, $withtemplate=0) {

      if ($item->getType() == __CLASS__) {
         $tabs[1] = __('Personal View');
         $tabs[2] = __('Group View');
         $tabs[3] = __('Global View');
         $tabs[4] = _n('RSS feed', 'RSS feeds', Session::getPluralNumber());

         return $tabs;
      }
      return '';
   }


   static function displayTabContentForItem(CommonGLPI $item, $tabnum=1, $withtemplate=0) {

      if ($item->getType() == __CLASS__) {
         switch ($tabnum) {
            case 1 : // all
               $item->showMyView();
               break;

            case 2 :
               $item->showGroupView();
               break;

            case 3 :
               $item->showGlobalView();
               break;

            case 4 :
               $item->showRSSView();
               break;
         }
      }
      return true;
   }


   /**
    * Show the central global view
   **/
   static function showGlobalView() {

      $showticket  = Session::haveRight("ticket", Ticket::READALL);
      $showproblem = Session::haveRight("problem", Problem::READALL);

      if ($showticket) {
         Ticket::showCentralCount();
      }
      if ($showproblem) {
         Problem::showCentralCount();
      }
      if (Contract::canView()) {
         Contract::showCentral();
      }

      if (Session::haveRight("logs", READ)) {
         //Show last add events
         Event::showForUser($_SESSION["glpiname"]);
      }


      if ($_SESSION["glpishow_jobs_at_login"] && $showticket) {
         Ticket::showCentralNewList();
      }
   }


   static function showSecurityMessages(){
        global $DB, $CFG_GLPI;
       if (Session::haveRight("config", UPDATE)) {
         $logins = User::checkDefaultPasswords();
         $user   = new User();
         if (!empty($logins)) {
            $accouts = array();
            foreach ($logins as $login) {
               $user->getFromDBbyName($login);
               $accounts[] = $user->getLink();
            }
            $message = sprintf(__('For security reasons, please change the password for the default users: %s'),
                               implode(" ", $accounts));

            Html::displayTitle($CFG_GLPI['root_doc']."/pics/warning.png", $message, $message);

         }
         if (file_exists(GLPI_ROOT . "/install/install.php")) {

            $message = sprintf(__('For security reasons, please remove file: %s'),
                               "install/install.php");
            Html::displayTitle($CFG_GLPI['root_doc']."/pics/warning.png", $message, $message);
         }
      }
      if ($DB->isSlave()
          && !$DB->first_connection) {

         Html::displayTitle($CFG_GLPI['root_doc']."/pics/warning.png", __('MySQL replica: read only'),
                            __('MySQL replica: read only'));
      }

 
   }
   
      
      
   /**
    * Show the central personal view
   **/
   static function showMyView() {
      global $DB, $CFG_GLPI;

    if (Session::haveRightsOr("ticket", array(Ticket::READMY, Ticket::READALL, Ticket::READASSIGN))){

        if (Ticket::isAllowedStatus(Ticket::SOLVED, Ticket::CLOSED)){
            Ticket::showCentralList(0, "toapprove", false); 
        }
        Ticket::showCentralList(0, "rejected", false); 
        Ticket::showCentralList(0, "requestbyself", false); 
        Ticket::showCentralList(0, "observed", false); 

        Ticket::showCentralList(0, "process", false); 
        Ticket::showCentralList(0, "waiting", false); 

    }

    if (Session::haveRightsOr('problem', array(Problem::READALL, Problem::READMY))){
        Problem::showCentralList(0, "process", false);
    }

    if (Session::haveRightsOr('ticketvalidation', TicketValidation::getValidateRights())){
        Ticket::showCentralList(0, "tovalidate", false);
    }
    
   }


   /**
    * Show the central RSS view
    *
    * @since version 0.84
   **/
   static function showRSSView() {

      RSSFeed::showListForCentral();
      if (RSSFeed::canView()) {
         RSSFeed::showListForCentral(false);
      }
   }


   /**
    * Show the central group view
   **/
   static function showGroupView() {

      $showticket = Session::haveRightsOr("ticket", array(Ticket::READALL, Ticket::READASSIGN));
      $showproblem = Session::haveRightsOr('problem', array(Problem::READALL, Problem::READMY));


      if ($showticket) {
         Ticket::showCentralList(0, "process", true);
      }
      if (Session::haveRight('ticket', Ticket::READGROUP)) {
         Ticket::showCentralList(0, "waiting", true);
      }
      if ($showproblem) {
         Problem::showCentralList(0, "process", true);
      }


      if (Session::haveRight('ticket', Ticket::READGROUP)) {
         Ticket::showCentralList(0, "observed", true);
         Ticket::showCentralList(0, "toapprove", true);
         Ticket::showCentralList(0, "requestbyself", true);
      } else {
         Ticket::showCentralList(0, "waiting", true);
      }

   }

}
?>

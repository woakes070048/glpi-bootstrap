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

/**
 * Update from 0.84 to 0.85
 *
 * @return bool for success (will die for most error)
**/
function update084to085() {
   global $DB, $migration;

   $updateresult     = true;
   $ADDTODISPLAYPREF = array();

   //TRANS: %s is the number of new version
   $migration->displayTitle(sprintf(__('Update to %s'), '0.85'));
   $migration->setVersion('0.85');

   $backup_tables = false;
   $newtables     = array('glpi_changes', 'glpi_changes_groups', 'glpi_changes_items',
                          'glpi_changes_problems', 'glpi_changes_suppliers',
                          'glpi_changes_tickets', 'glpi_changes_users',
                          'glpi_changetasks'
                          // Only do profilerights once : so not delete it
                          /*, 'glpi_profilerights'*/);

   foreach ($newtables as $new_table) {
      // rename new tables if exists ?
      if (TableExists($new_table)) {
         $migration->dropTable("backup_$new_table");
         $migration->displayWarning("$new_table table already exists. ".
                                    "A backup have been done to backup_$new_table.");
         $backup_tables = true;
         $query         = $migration->renameTable("$new_table", "backup_$new_table");
      }
   }
   if ($backup_tables) {
      $migration->displayWarning("You can delete backup tables if you have no need of them.",
                                 true);
   }


   $migration->displayMessage(sprintf(__('Data migration - %s'), 'config table'));

   if (FieldExists('glpi_configs', 'version')) {
      if (!TableExists('origin_glpi_configs')) {
         $migration->copyTable('glpi_configs', 'origin_glpi_configs');
      }

      $query  = "SELECT *
                 FROM `glpi_configs`
                 WHERE `id` = '1'";
      $result_of_configs = $DB->query($query);

      // Update glpi_configs
      $migration->addField('glpi_configs', 'context', 'VARCHAR(150) COLLATE utf8_unicode_ci',
                           array('update' => "'core'"));
      $migration->addField('glpi_configs', 'name', 'VARCHAR(150) COLLATE utf8_unicode_ci',
                           array('update' => "'version'"));
      $migration->addField('glpi_configs', 'value', 'text', array('update' => "'0.85'"));
      $migration->addKey('glpi_configs', array('context', 'name'), 'unicity', 'UNIQUE');

      $migration->migrationOneTable('glpi_configs');

      $fields = array();
      if ($DB->numrows($result_of_configs) == 1) {
         $configs = $DB->fetch_assoc($result_of_configs);
         unset($configs['id']);
         unset($configs['version']);
         // First drop fields not to have constraint on insert
         foreach ($configs as $name => $value) {
            $migration->dropField('glpi_configs', $name);
         }
         $migration->migrationOneTable('glpi_configs');
         // Then insert new values
         foreach ($configs as $name => $value) {
            $query = "INSERT INTO `glpi_configs`
                             (`context`, `name`, `value`)
                      VALUES ('core', '$name', '".addslashes($value)."');";
            $DB->query($query);
         }
      }
      $migration->dropField('glpi_configs', 'version');
      $migration->migrationOneTable('glpi_configs');
      $migration->dropTable('origin_glpi_configs');

   }

   $migration->displayMessage(sprintf(__('Data migration - %s'), 'profile table'));

   if (!TableExists('glpi_profilerights')) {
      if (!TableExists('origin_glpi_profiles')) {
         $migration->copyTable('glpi_profiles', 'origin_glpi_profiles');
      }

      /// TODO : right using char(1) ? not able to store others configs... But interesting to change it ?

      $query = "CREATE TABLE `glpi_profilerights` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `profiles_id` int(11) NOT NULL DEFAULT '0',
                  `name` varchar(255) DEFAULT NULL,
                  `right` char(1) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `unicity` (`profiles_id`, `name`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "0.85 add table glpi_profilerights");

      $query = "DESCRIBE `origin_glpi_profiles`";

      $rights = array();
      foreach ($DB->request($query) as $field) {
         if ($field['Type'] == 'char(1)') {
            $rights[$field['Field']] = $field['Field'];
            $migration->dropField('glpi_profiles', $field['Field']);
         }
      }
      $query = "SELECT *
                FROM `origin_glpi_profiles`";
      foreach ($DB->request($query) as $profile) {
         $profiles_id = $profile['id'];
         foreach ($rights as $right) {
            if ($profile[$right] == NULL) {
               $new_right = '';
            } else {
               $new_right = $profile[$right];
            }
            $query = "INSERT INTO `glpi_profilerights`
                             (`profiles_id`, `name`, `right`)
                      VALUES ('$profiles_id', '$right', '".$profile[$right]."')";
            $DB->query($query);
         }
      }
      $migration->migrationOneTable('glpi_profiles');
      $migration->dropTable('origin_glpi_profiles');

      ProfileRight::addProfileRights(array('show_my_change', 'show_all_change', 'edit_all_change'));

      ProfileRight::updateProfileRightAsOtherRight('show_my_change', '1',
                                                   "`name` = 'own_ticket' AND `right`='1'");
      ProfileRight::updateProfileRightAsOtherRight('show_all_change', '1',
                                                   "`name` = 'show_all_ticket' AND `right`='1'");
      ProfileRight::updateProfileRightAsOtherRight('edit_all_change', '1',
                                                   "`name` = 'update_ticket' AND `right`='1'");

   }

   // New system of profiles
   $migration->addField('glpi_profilerights', 'rights', 'integer');
   $migration->migrationOneTable('glpi_profilerights');
   // update standard rights r and w
   $right = array('r' => 1,
                  'w' => 31,
                  '1' => 1);

   foreach ($right as $old => $new) {
//       if (($new != '1') || ($new != '31')) {
//          // profile already migrated and values changed in the profile with new rights
//       } else {
         $query  = "UPDATE `glpi_profilerights`
                    SET `rights` = $new
                    WHERE `right` = '$old'";
//          echo $query.'<br>';
         $DB->queryOrDie($query, "0.85 right in profile $old to $new");
//       }
   }

// delete import_externalauth_users
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'import_externalauth_users' AND `right` = 'w'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . User::IMPORTEXTAUTHUSERS ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                       AND `name` = 'User'";
      $DB->queryOrDie($query, "0.85 update user with import_externalauth_users right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'import_externalauth_users'";
   $DB->queryOrDie($query, "0.85 delete import_externalauth_users right");


   // delete rule_ticket
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'rule_ticket' AND `right` = 'r'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . RuleTicket::RULETICKET ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                       AND `name` = 'entity_rule_ticket'";
      $DB->queryOrDie($query, "0.85 update entity_rule_ticket with rule_ticket right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'rule_ticket'";
   $DB->queryOrDie($query, "0.85 delete rule_ticket right");


   // delete knowbase_admin
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'knowbase_admin' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . KnowbaseItem::KNOWBASEADMIN ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'knowbase'";
      $DB->queryOrDie($query, "0.85 update knowbase with knowbase_admin right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'knowbase_admin'";
   $DB->queryOrDie($query, "0.85 delete knowbase_admin right");


   // delete notes
   $tables = array('budget', 'cartridge', 'computer', 'consumable', 'contact_enterprise',
                   'contract', 'document', 'entity', 'monitor', 'networking', 'peripheral',
                   'phone', 'printer', 'software');
   // TODO voir aussi pour 'glpi_changes','glpi_problems'
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'notes' AND `right` = 'r'") as $profrights) {

      foreach ($tables as $table) {
         $query  = "UPDATE `glpi_profilerights`
                    SET `rights` = `rights` | " . READNOTE ."
                    WHERE `profiles_id` = '".$profrights['profiles_id']."'
                          AND `name` = '$table'";
         $DB->queryOrDie($query, "0.85 update $table with read notes right");
      }
   }
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'notes' AND `right` = 'w'") as $profrights) {

      foreach ($tables as $table) {
         $query  = "UPDATE `glpi_profilerights`
                    SET `rights` = `rights` | " . READNOTE ." | ".UPDATENOTE ."
                    WHERE `profiles_id` = '".$profrights['profiles_id']."'
                          AND `name` = '$table'";
         $DB->queryOrDie($query, "0.85 update $table with update notes right");
      }
   }
/* DELETE AT THE END
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'notes'";
   $DB->queryOrDie($query, "0.85 delete notes right");
*/


   // delete faq
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'faq' AND `right` = 'r'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . KnowbaseItem::READFAQ ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                       AND `name` = 'knowbase'";
      $DB->queryOrDie($query, "0.85 update knowbase with read faq right");
   }
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'faq' AND `right` = 'w'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . KnowbaseItem::READFAQ ." | ".KnowbaseItem::PUBLISHFAQ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                       AND `name` = 'knowbase'";
      $DB->queryOrDie($query, "0.85 update knowbase with write faq right");
   }

   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'faq'";
   $DB->queryOrDie($query, "0.85 delete faq right");


   // delete user_authtype
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'user_authtype' AND `right` = 'r'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . User::READAUTHENT ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                       AND `name` = 'user'";
      $DB->queryOrDie($query, "0.85 update user with read user_authtype right");
   }
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'user_authtype' AND `right` = 'w'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . User::READAUTHENT ." | ".User::UPDATEAUTHENT."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                       AND `name` = 'user'";
         $DB->queryOrDie($query, "0.85 update user with write user_authtype right");
   }

   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'user_authtype'";
   $DB->queryOrDie($query, "0.85 delete user_authtype right");


   // delete entity_helpdesk
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'entity_helpdesk' AND `right` = 'r'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . Entity::READHELPDESK ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                       AND `name` = 'entity'";
         $DB->queryOrDie($query, "0.85 update entity with read entity_helpdesk right");
   }
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'entity_helpdesk' AND `right` = 'w'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . Entity::READHELPDESK ." | ".Entity::UPDATEHELPDESK."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                       AND `name` = 'entity'";
         $DB->queryOrDie($query, "0.85 update user with write entity_helpdesk right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'entity_helpdesk'";
   $DB->queryOrDie($query, "0.85 delete entity_helpdesk right");


   // delete reservation_helpdesk
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'reservation_helpdesk' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . ReservationItem::RESERVEANITEM ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'reservation_central'";
         $DB->queryOrDie($query, "0.85 update reservation_central with reservation_helpdesk right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'reservation_helpdesk'";
   $DB->queryOrDie($query, "0.85 delete reservation_helpdesk right");


   // rename reservation_central
   $query  = "UPDATE `glpi_profilerights`
              SET `name` = 'reservation'
              WHERE `name` = 'reservation_central'";
   $DB->queryOrDie($query, "0.85 delete reservation_central");


   // pour que la procédure soit ré-entrante et ne pas perdre les sélections dans le profile
   if (countElementsInTable("glpi_profilerights", "`name` = 'ticket'") == 0) {
      // rename create_ticket
      $query  = "UPDATE `glpi_profilerights`
                 SET `name` = 'ticket'
                 WHERE `name` = 'create_ticket'";
      $DB->queryOrDie($query, "0.85 rename create_ticket to ticket");

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = ". CREATE ."
                 WHERE `name` = 'ticket'
                       AND `right` = '1'";
      $DB->queryOrDie($query, "0.85 update ticket with create_ticket right");
   }


   // delete update_ticket
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'update_ticket' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . UPDATE  ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'ticket'";
      $DB->queryOrDie($query, "0.85 update ticket with update_ticket right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'update_ticket'";
   $DB->queryOrDie($query, "0.85 delete update_ticket right");


   // delete delete_ticket
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'delete_ticket' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . DELETE ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'ticket'";
      $DB->queryOrDie($query, "0.85 update ticket with delete_ticket right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'delete_ticket'";
   $DB->queryOrDie($query, "0.85 delete delete_ticket right");


   // delete show_all_ticket
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'show_all_ticket' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . Ticket::READALL ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'ticket'";
      $DB->queryOrDie($query, "0.85 update ticket with show_all_ticket right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'show_all_ticket'";
   $DB->queryOrDie($query, "0.85 delete show_all_ticket right");


   // delete show_group_ticket
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'show_group_ticket' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . Ticket::READGROUP ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'ticket'";
      $DB->queryOrDie($query, "0.85 update ticket with show_group_ticket right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'show_group_ticket'";
   $DB->queryOrDie($query, "0.85 delete show_group_ticket right");


   // delete show_assign_ticket
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'show_assign_ticket' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . Ticket::READASSIGN ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'ticket'";
         $DB->queryOrDie($query, "0.85 update ticket with show_assign_ticket right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'show_assign_ticket'";
   $DB->queryOrDie($query, "0.85 delete show_assign_ticket right");


   // delete assign_ticket
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'assign_ticket' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . Ticket::ASSIGN ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'ticket'";
      $DB->queryOrDie($query, "0.85 update ticket with assign_ticket right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'assign_ticket'";
   $DB->queryOrDie($query, "0.85 delete assign_ticket right");


   // delete steal_ticket
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'steal_ticket' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . Ticket::STEAL ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'ticket'";
      $DB->queryOrDie($query, "0.85 update ticket with steal_ticket right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'steal_ticket'";
   $DB->queryOrDie($query, "0.85 delete steal_ticket right");


   // delete own_ticket
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'own_ticket' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . Ticket::OWN ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'ticket'";
      $DB->queryOrDie($query, "0.85 update ticket with own_ticket right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'own_ticket'";
   $DB->queryOrDie($query, "0.85 delete own_ticket right");


   // delete update_priority
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'update_priority' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . Ticket::CHANGEPRIORITY ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'ticket'";
         $DB->queryOrDie($query, "0.85 update ticket with update_priority right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'update_priority'";
   $DB->queryOrDie($query, "0.85 delete update_priority right");


   // pour que la procédure soit ré-entrante et ne pas perdre les sélections dans le profile
   if (countElementsInTable("glpi_profilerights", "`name` = 'followup'") == 0) {
      // rename create_ticket
      $query  = "UPDATE `glpi_profilerights`
                 SET `name` = 'followup'
                 WHERE `name` = 'global_add_followups'";
      $DB->queryOrDie($query, "0.85 rename global_add_followups to followup");

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = ". TicketFollowup::ADDALLTICKET ."
                 WHERE `name` = 'followup'
                       AND `right` = '1'";
      $DB->queryOrDie($query, "0.85 update followup with global_add_followups right");
   }


   // delete add_followups
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'add_followups' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . TicketFollowup::ADDMYTICKET  ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'followup'";
         $DB->queryOrDie($query, "0.85 update followup with add_followups right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'add_followups'";
   $DB->queryOrDie($query, "0.85 delete add_followups right");


   // delete group_add_followups
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'group_add_followups' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . TicketFollowup::ADDGROUPTICKET  ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'followup'";
      $DB->queryOrDie($query, "0.85 update followup with group_add_followups right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'group_add_followups'";
   $DB->queryOrDie($query, "0.85 delete group_add_followups right");


   // delete observe_ticket for followup
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'observe_ticket' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . TicketFollowup::SEEPUBLIC  ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'followup'";
      $DB->queryOrDie($query, "0.85 update followup with observe_ticket right");
   }
    // don't delete observe_ticket because already use for task


   // delete show_full_ticket for followup
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'show_full_ticket' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " .TicketFollowup::SEEPUBLIC ." | ".TicketFollowup::SEEPRIVATE ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'followup'";
         $DB->queryOrDie($query, "0.85 update followup with show_full_ticket right");
   }
   // don't delete show_full_ticket because already use for task


   // delete update_followups
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'update_followups' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . READ  ." | ". TicketFollowup::UPDATEALL  ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'followup'";
      $DB->queryOrDie($query, "0.85 update followup with update_followups right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'update_followups'";
   $DB->queryOrDie($query, "0.85 delete update_followups right");


   // delete update_own_followups
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'update_own_followups' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . READ  ." | ". TicketFollowup::UPDATEMY  ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'followup'";
      $DB->queryOrDie($query, "0.85 update followup with update_own_followups right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'update_own_followups'";
   $DB->queryOrDie($query, "0.85 delete update_own_followups right");


   // delete delete_followups
   foreach ($DB->request("glpi_profilerights",
                         "`name` = 'delete_followups' AND `right` = '1'") as $profrights) {

      $query  = "UPDATE `glpi_profilerights`
                 SET `rights` = `rights` | " . PURGE  ."
                 WHERE `profiles_id` = '".$profrights['profiles_id']."'
                      AND `name` = 'followup'";
      $DB->queryOrDie($query, "0.85 update followup with delete_followups right");
   }
   $query = "DELETE
             FROM `glpi_profilerights`
             WHERE `name` = 'delete_followups'";
   $DB->queryOrDie($query, "0.85 delete delete_followups right");



   // don't drop column right  - be done later




   $migration->displayMessage(sprintf(__('Change of the database layout - %s'), 'Change'));

   // changes management
   if (!TableExists('glpi_changes')) {
      $query = "CREATE TABLE `glpi_changes` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `name` varchar(255) DEFAULT NULL,
                  `entities_id` int(11) NOT NULL DEFAULT '0',
                  `is_recursive` tinyint(1) NOT NULL DEFAULT '0',
                  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
                  `status` int(11) NOT NULL DEFAULT '1',
                  `content` longtext DEFAULT NULL,
                  `date_mod` DATETIME DEFAULT NULL,
                  `date` DATETIME DEFAULT NULL,
                  `solvedate` DATETIME DEFAULT NULL,
                  `closedate` DATETIME DEFAULT NULL,
                  `due_date` DATETIME DEFAULT NULL,
                  `users_id_recipient` int(11) NOT NULL DEFAULT '0',
                  `users_id_lastupdater` int(11) NOT NULL DEFAULT '0',
                  `urgency` int(11) NOT NULL DEFAULT '1',
                  `impact` int(11) NOT NULL DEFAULT '1',
                  `priority` int(11) NOT NULL DEFAULT '1',
                  `itilcategories_id` int(11) NOT NULL DEFAULT '0',
                  `impactcontent` longtext DEFAULT NULL,
                  `controlistcontent` longtext DEFAULT NULL,
                  `rolloutplancontent` longtext DEFAULT NULL,
                  `backoutplancontent` longtext DEFAULT NULL,
                  `checklistcontent` longtext DEFAULT NULL,
                  `solutiontypes_id` int(11) NOT NULL DEFAULT '0',
                  `solution` text COLLATE utf8_unicode_ci,
                  `actiontime` int(11) NOT NULL DEFAULT '0',
                  `notepad` LONGTEXT NULL,
                  PRIMARY KEY (`id`),
                  KEY `name` (`name`),
                  KEY `entities_id` (`entities_id`),
                  KEY `is_recursive` (`is_recursive`),
                  KEY `is_deleted` (`is_deleted`),
                  KEY `date` (`date`),
                  KEY `closedate` (`closedate`),
                  KEY `status` (`status`),
                  KEY `priority` (`priority`),
                  KEY `date_mod` (`date_mod`),
                  KEY `itilcategories_id` (`itilcategories_id`),
                  KEY `users_id_recipient` (`users_id_recipient`),
                  KEY `solvedate` (`solvedate`),
                  KEY `solutiontypes_id` (`solutiontypes_id`),
                  KEY `urgency` (`urgency`),
                  KEY `impact` (`impact`),
                  KEY `due_date` (`due_date`),
                  KEY `users_id_lastupdater` (`users_id_lastupdater`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "0.85 create glpi_changes");
   }

   if (!TableExists('glpi_changes_users')) {
      $query = "CREATE TABLE `glpi_changes_users` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `changes_id` int(11) NOT NULL DEFAULT '0',
                  `users_id` int(11) NOT NULL DEFAULT '0',
                  `type` int(11) NOT NULL DEFAULT '1',
                  `use_notification` tinyint(1) NOT NULL DEFAULT '0',
                  `alternative_email` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `unicity` (`changes_id`,`type`,`users_id`,`alternative_email`),
                  KEY `user` (`users_id`,`type`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "0.85 add table glpi_changes_users");
   }

   if (!TableExists('glpi_changes_groups')) {
      $query = "CREATE TABLE `glpi_changes_groups` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `changes_id` int(11) NOT NULL DEFAULT '0',
                  `groups_id` int(11) NOT NULL DEFAULT '0',
                  `type` int(11) NOT NULL DEFAULT '1',
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `unicity` (`changes_id`,`type`,`groups_id`),
                  KEY `group` (`groups_id`,`type`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "0.85 add table glpi_changes_groups");
   }

   if (!TableExists('glpi_changes_suppliers')) {
      $query = "CREATE TABLE `glpi_changes_suppliers` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `changes_id` int(11) NOT NULL DEFAULT '0',
                  `suppliers_id` int(11) NOT NULL DEFAULT '0',
                  `type` int(11) NOT NULL DEFAULT '1',
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `unicity` (`changes_id`,`type`,`suppliers_id`),
                  KEY `group` (`suppliers_id`,`type`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "0.85 add table glpi_changes_suppliers");
   }

   if (!TableExists('glpi_changes_items')) {
      $query = "CREATE TABLE `glpi_changes_items` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `changes_id` int(11) NOT NULL DEFAULT '0',
                  `itemtype` varchar(100) default NULL,
                  `items_id` int(11) NOT NULL DEFAULT '0',
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `unicity` (`changes_id`,`itemtype`,`items_id`),
                  KEY `item` (`itemtype`,`items_id`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "0.85 add table glpi_changes_items");
   }

   if (!TableExists('glpi_changes_tickets')) {
      $query = "CREATE TABLE `glpi_changes_tickets` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `changes_id` int(11) NOT NULL DEFAULT '0',
                  `tickets_id` int(11) NOT NULL DEFAULT '0',
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `unicity` (`changes_id`,`tickets_id`),
                  KEY `tickets_id` (`tickets_id`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "0.85 add table glpi_changes_tickets");
   }

   if (!TableExists('glpi_changes_problems')) {
      $query = "CREATE TABLE `glpi_changes_problems` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `changes_id` int(11) NOT NULL DEFAULT '0',
                  `problems_id` int(11) NOT NULL DEFAULT '0',
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `unicity` (`changes_id`,`problems_id`),
                  KEY `problems_id` (`problems_id`)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "0.85 add table glpi_changes_problems");
   }

   if (!TableExists('glpi_changetasks')) {
      $query = "CREATE TABLE `glpi_changetasks` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `name` varchar(255) DEFAULT NULL,
                  `changes_id` int(11) NOT NULL DEFAULT '0',
                  `changetasks_id` int(11) NOT NULL DEFAULT '0',
                  `is_blocked` tinyint(1) NOT NULL DEFAULT '0',
                  `taskcategories_id` int(11) NOT NULL DEFAULT '0',
                  `status` varchar(255) DEFAULT NULL,
                  `priority` int(11) NOT NULL DEFAULT '1',
                  `percentdone` int(11) NOT NULL DEFAULT '0',
                  `date` datetime DEFAULT NULL,
                  `begin` datetime DEFAULT NULL,
                  `end` datetime DEFAULT NULL,
                  `users_id` int(11) NOT NULL DEFAULT '0',
                  `users_id_tech` int(11) NOT NULL DEFAULT '0',
                  `content` longtext COLLATE utf8_unicode_ci,
                  `actiontime` int(11) NOT NULL DEFAULT '0',
                  PRIMARY KEY (`id`),
                  KEY `name` (`name`),
                  KEY `changes_id` (`changes_id`),
                  KEY `changetasks_id` (`changetasks_id`),
                  KEY `is_blocked` (`is_blocked`),
                  KEY `priority` (`priority`),
                  KEY `status` (`status`),
                  KEY `percentdone` (`percentdone`),
                  KEY `users_id` (`users_id`),
                  KEY `users_id_tech` (`users_id_tech`),
                  KEY `date` (`date`),
                  KEY `begin` (`begin`),
                  KEY `end` (`end`),
                  KEY `taskcategories_id` (taskcategories_id)
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "0.85 add table glpi_changetasks");
   }

   /// TODO add changetasktypes table as dropdown
   /// TODO review users linked to changetask
   /// TODO add display prefs
/*
   ProfileRight::addProfileRights(array('show_my_change',
                                        'show_all_change',
                                        'edit_all_change'));

   ProfileRight::updateProfileRightAsOtherRight('show_my_change', '1',
                                 "`name` = 'own_ticket' AND `right`='1'");
   ProfileRight::updateProfileRightAsOtherRight('show_all_change', '1',
                                 "`name` = 'show_all_ticket' AND `right`='1'");
   ProfileRight::updateProfileRightAsOtherRight('edit_all_change', '1',
                                 "`name` = 'update_ticket' AND `right`='1'");
*/
   $migration->addField('glpi_profiles', 'change_status', "text",
                        array('comment' => "json encoded array of from/dest allowed status change"));


   $migration->displayMessage(sprintf(__('Data migration - %s'), 'drop rules cache'));
   $migration->dropTable('glpi_rulecachecomputermodels');
   $migration->dropTable('glpi_rulecachecomputertypes');
   $migration->dropTable('glpi_rulecachemanufacturers');
   $migration->dropTable('glpi_rulecachemonitormodels');
   $migration->dropTable('glpi_rulecachemonitortypes');
   $migration->dropTable('glpi_rulecachenetworkequipmentmodels');
   $migration->dropTable('glpi_rulecachenetworkequipmenttypes');
   $migration->dropTable('glpi_rulecacheoperatingsystems');
   $migration->dropTable('glpi_rulecacheoperatingsystemservicepacks');
   $migration->dropTable('glpi_rulecacheoperatingsystemversions');
   $migration->dropTable('glpi_rulecacheperipheralmodels');
   $migration->dropTable('glpi_rulecacheperipheraltypes');
   $migration->dropTable('glpi_rulecachephonemodels');
   $migration->dropTable('glpi_rulecachephonetypes');
   $migration->dropTable('glpi_rulecacheprintermodels');
   $migration->dropTable('glpi_rulecacheprinters');
   $migration->dropTable('glpi_rulecacheprintertypes');
   $migration->dropTable('glpi_rulecachesoftwares');

   $migration->displayMessage(sprintf(__('Data migration - %s'), 'glpi_rules'));

   $migration->addField("glpi_rules", 'uuid', "string");
   $migration->migrationOneTable('glpi_rules');

   // Dropdown translations
   $migration->displayMessage(sprintf(__('Data migration - %s'), 'glpi_knowbaseitemtranslations'));
   Config::setConfigurationValues('core', array('translate_kb' => 0));
   if (!TableExists("glpi_knowbaseitemtranslations")) {
      $query = "CREATE TABLE IF NOT EXISTS `glpi_knowbaseitemtranslations` (
            `id`               int(11) NOT NULL AUTO_INCREMENT,
            `knowbaseitems_id` int(11) NOT NULL DEFAULT '0',
            `language`         varchar(5) COLLATE utf8_unicode_ci DEFAULT NULL,
            `name`             text COLLATE utf8_unicode_ci,
            `answer`           longtext COLLATE utf8_unicode_ci,
            PRIMARY            KEY (`id`),
            KEY                `item` (`knowbaseitems_id`, `language`)
         )  ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";         
      $DB->query($query)
         or die("0.85 add table glpi_knowbaseitemtranslations");
   }

   // kb translations
   $migration->displayMessage(sprintf(__('Data migration - %s'), 'glpi_dropdowntranslations'));
   Config::setConfigurationValues('core', array('translate_dropdowns' => 0));
   if (!TableExists("glpi_dropdowntranslations")) {
      $query = "CREATE TABLE IF NOT EXISTS `glpi_dropdowntranslations` (
           `id` int(11) NOT NULL AUTO_INCREMENT,
           `items_id` int(11) NOT NULL DEFAULT '0',
           `itemtype` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
           `language` varchar(5) COLLATE utf8_unicode_ci DEFAULT NULL,
           `field` varchar(100) COLLATE utf8_unicode_ci DEFAULT NULL,
           `value` text COLLATE utf8_unicode_ci,
           PRIMARY KEY (`id`),
           UNIQUE KEY `unicity` (`itemtype`,`items_id`,`language`,`field`),
           KEY `typeid` (`itemtype`,`items_id`),
           KEY `language` (`language`),
           KEY `field` (`field`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";

      $DB->query($query)
         or die("0.85 add table glpi_dropdowntranslations");
   }


   //generate uuid for the basic rules of glpi
   // we use a complete sql where for cover all migration case (0.78 -> 0.85)
   $rules = array(array('sub_type'    => 'RuleImportEntity',
                        'name'        => 'Root',
                        'match'       => 'AND',
                        'description' => ''),

                  array('sub_type'    => 'RuleRight',
                        'name'        => 'Root',
                        'match'       => 'AND',
                        'description' => ''),

                  array('sub_type'    => 'RuleMailCollector',
                        'name'        => 'Root',
                        'match'       => 'AND',
                        'description' => ''),

                  array('sub_type'    => 'RuleMailCollector',
                        'name'        => 'Auto-Reply X-Auto-Response-Suppress',
                        'match'       => 'AND',
                        'description' => 'Exclude Auto-Reply emails using X-Auto-Response-Suppress header'),

                  array('sub_type'    => 'RuleMailCollector',
                        'name'        => 'Auto-Reply Auto-Submitted',
                        'match'       => 'AND',
                        'description' => 'Exclude Auto-Reply emails using Auto-Submitted header'),

                  array('sub_type'    => 'RuleTicket',
                        'name'        => 'Ticket location from item',
                        'match'       => 'AND',
                        'description' => ''),

                  array('sub_type'    => 'RuleTicket',
                        'name'        => 'Ticket location from user',
                        'match'       => 'AND',
                        'description' => ''));

   $i = 0;
   foreach ($rules as $rule) {
      $query  = "UPDATE `glpi_rules`
                 SET `uuid` = 'STATIC-UUID-$i'
                 WHERE `entities_id` = 0
                       AND `is_recursive` = 0
                       AND `sub_type` = '".$rule['sub_type']."'
                       AND `name` = '".$rule['name']."'
                       AND `description` = '".$rule['description']."'
                       AND `match` = '".$rule['match']."'
                 ORDER BY id ASC
                 LIMIT 1";
      $DB->queryOrDie($query, "0.85 add uuid to basic rules (STATIC-UUID-$i)");
      $i++;
   }

   //generate uuid for the rules of user
   foreach ($DB->request('glpi_rules', array('uuid' => NULL)) as $data) {
      $uuid  = Rule::getUuid();
      $query = "UPDATE `glpi_rules`
                SET `uuid` = '$uuid'
                WHERE `id` = '".$data['id']."'";
      $DB->queryOrDie($query, "0.85 add uuid to existing rules");
   }

   $migration->addField('glpi_users', 'is_deleted_ldap', 'bool');
   $migration->addKey('glpi_users', 'is_deleted_ldap');

   Config::deleteConfigurationValues('core', array('use_ajax'));
   Config::deleteConfigurationValues('core', array('ajax_min_textsearch_load'));
   Config::deleteConfigurationValues('core', array('ajax_buffertime_load'));

   Config::setConfigurationValues('core', array('use_unicodefont' => 0));
   $migration->addField("glpi_users", 'use_unicodefont', "int(11) DEFAULT NULL");
   $migration->addField("glpi_users", 'picture', "string", array('value' => 'NULL'));

   $migration->addField("glpi_authldaps", 'picture_field','string');

   $migration->addField('glpi_links', 'open_window', 'bool', array('value' => 1));

   $migration->displayMessage(sprintf(__('Data migration - %s'), 'glpi_states'));
   foreach (array('is_visible_computer', 'is_visible_monitor', 'is_visible_networkequipment',
                  'is_visible_peripheral', 'is_visible_phone', 'is_visible_printer',
                  'is_visible_softwareversion') as $field)  {
      $migration->addField('glpi_states', $field, 'bool',
                           array('value' => '1'));
      $migration->addKey('glpi_states', $field);
   }


   // glpi_domains by entity
   $migration->addField('glpi_domains', 'entities_id', 'integer', array('after' => 'name'));
   $migration->addField('glpi_domains', 'is_recursive', 'bool', array('update' => '1',
                                                                      'after'  => 'entities_id'));

   // glpi_states by entity
   $migration->addField('glpi_states', 'entities_id', 'integer', array('after' => 'name'));
   $migration->addField('glpi_states', 'is_recursive', 'bool', array('update' => '1',
                                                                     'after'  => 'entities_id'));


   // add validity date for a user
   $migration->addField('glpi_users', 'begin_date', 'datetime');
   $migration->addField('glpi_users', 'end_date', 'datetime');

   // Create notification for reply to satisfaction survey based on satisfaction notif
   // Check if notifications already exists
   if (countElementsInTable('glpi_notifications',
                            "`itemtype` = 'Ticket'
                              AND `event` = 'replysatisfaction'")==0) {
   // No notifications duplicate all

      $query = "SELECT *
                FROM `glpi_notifications`
                WHERE `itemtype` = 'Ticket'
                      AND `event` = 'satisfaction'";
      foreach ($DB->request($query) as $notif) {
         $query = "INSERT INTO `glpi_notifications`
                          (`name`, `entities_id`, `itemtype`, `event`, `mode`,
                          `notificationtemplates_id`, `comment`, `is_recursive`, `is_active`,
                          `date_mod`)
                   VALUES ('".addslashes($notif['name'])." Answer',
                           '".$notif['entities_id']."', 'Ticket',
                           'replysatisfaction', '".$notif['mode']."',
                           '".$notif['notificationtemplates_id']."',
                           '".addslashes($notif['comment'])."', '".$notif['is_recursive']."',
                           '".$notif['is_active']."', NOW());";
         $DB->queryOrDie($query, "0.85 insert replysatisfaction notification");
         $newID  = $DB->insert_id();
         $query2 = "SELECT *
                    FROM `glpi_notificationtargets`
                    WHERE `notifications_id` = '".$notif['id']."'";
         // Add same recipent of satisfaction
         foreach ($DB->request($query2) as $target) {
            $query = "INSERT INTO `glpi_notificationtargets`
                             (`notifications_id`, `type`, `items_id`)
                      VALUES ($newID, '".$target['type']."', '".$target['items_id']."')";
            $DB->queryOrDie($query, "0.85 insert targets for replysatisfaction notification");
         }
         // Add Tech in charge
            $query = "INSERT INTO `glpi_notificationtargets`
                             (`notifications_id`, `type`, `items_id`)
                      VALUES ($newID, '".Notification::USER_TYPE."', '".Notification::ITEM_TECH_IN_CHARGE."')";
            $DB->queryOrDie($query, "0.85 insert tech in charge target for replysatisfaction notification");
      }
   }

   // Convert html fields from numeric encoding to raw encoding
   /// TODO : report it to 0.84.1 see #4331
   $fields_to_clean = array('glpi_knowbaseitems'     => 'answer',
                            'glpi_tickets'           => 'solution',
                            'glpi_problems'          => 'solution',
                            'glpi_reminders'         => 'text',
                            'glpi_solutiontemplates' => 'content',
                            'glpi_notificationtemplatetranslations' => 'content_text');
   foreach ($fields_to_clean as $table => $field) {
      foreach ($DB->request($table) as $data) {
         $text = Toolbox::unclean_html_cross_side_scripting_deep($data[$field]);
         $text = html_entity_decode($text,ENT_NOQUOTES,'UTF-8');
         $text = addslashes($text);
         $text = Toolbox::clean_cross_side_scripting_deep($text);
         $query = "UPDATE `$table` SET `$field` = '$text' WHERE `id` = '".$data['id']."';";
//          echo "<pre>".htmlentities($query)."</pre>";
         $DB->queryOrDie($query, "0.85 fix encoding of html field : $table.$field");
      }
   }

   // ************ Keep it at the end **************
   //TRANS: %s is the table or item to migrate
   $migration->displayMessage(sprintf(__('Data migration - %s'), 'glpi_displaypreferences'));

   foreach ($ADDTODISPLAYPREF as $type => $tab) {
      $query = "SELECT DISTINCT `users_id`
                FROM `glpi_displaypreferences`
                WHERE `itemtype` = '$type'";

      if ($result = $DB->query($query)) {
         if ($DB->numrows($result)>0) {
            while ($data = $DB->fetch_assoc($result)) {
               $query = "SELECT MAX(`rank`)
                         FROM `glpi_displaypreferences`
                         WHERE `users_id` = '".$data['users_id']."'
                               AND `itemtype` = '$type'";
               $result = $DB->query($query);
               $rank   = $DB->result($result,0,0);
               $rank++;

               foreach ($tab as $newval) {
                  $query = "SELECT *
                            FROM `glpi_displaypreferences`
                            WHERE `users_id` = '".$data['users_id']."'
                                  AND `num` = '$newval'
                                  AND `itemtype` = '$type'";
                  if ($result2=$DB->query($query)) {
                     if ($DB->numrows($result2)==0) {
                        $query = "INSERT INTO `glpi_displaypreferences`
                                         (`itemtype` ,`num` ,`rank` ,`users_id`)
                                  VALUES ('$type', '$newval', '".$rank++."',
                                          '".$data['users_id']."')";
                        $DB->query($query);
                     }
                  }
               }
            }

         } else { // Add for default user
            $rank = 1;
            foreach ($tab as $newval) {
               $query = "INSERT INTO `glpi_displaypreferences`
                                (`itemtype` ,`num` ,`rank` ,`users_id`)
                         VALUES ('$type', '$newval', '".$rank++."', '0')";
               $DB->query($query);
            }
         }
      }
   }

   // must always be at the end
   $migration->executeMigration();

   return $updateresult;
}

?>
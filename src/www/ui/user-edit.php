<?php
/***********************************************************
 Copyright (C) 2014 Hewlett-Packard Development Company, L.P.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 version 2 as published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 ***********************************************************/

use Fossology\Lib\Auth\Auth;
use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Plugin\DefaultPlugin;
use Symfony\Component\HttpFoundation\Request;

class UserEditPage extends DefaultPlugin
{
  const NAME = "user_edit";

  /** @var DbManager */
  private $dbManager;

  function __construct()
  {
    parent::__construct(self::NAME, array(
        self::TITLE => _("Edit User Account"),
        self::MENU_LIST => 'Admin::Users::Edit User Account',
        self::REQUIRES_LOGIN => true,
        self::PERMISSION => Auth::PERM_WRITE
    ));

    $this->dbManager = $this->getObject('db.manager');
  }
  
  /**
   * @brief Allow user to change their account settings (users db table).  
   *        If the user is an Admin, they can change settings for any user.\n
   *        This is called in the following circumstances:\n
   *        1) User clicks on Admin > Edit User Account\n
   *        2) User has chosen a user to edit from the 'userid' select list  \n
   *        3) User hit submit to update user data\n
   */
  protected function handle(Request $request)
  {
    /* Is the session owner an admin?  */
    $user_pk = Auth::getUserId();
    $SessionUserRec = $this->GetUserRec($user_pk);
    $SessionIsAdmin = $this->IsSessionAdmin($SessionUserRec);

    $vars = array('refreshUri' => Traceback_uri() . "?mod=" . self::NAME);

    /* If this is a POST (the submit button was clicked), then process the request. */
    $BtnText = $request->get('UpdateBtn');
    if (!empty($BtnText)) 
    {
      /* Get the form data to in an associated array */
      $UserRec = $this->CreateUserRec("");

      $rv = $this->UpdateUser($UserRec, $SessionIsAdmin);
      if (empty($rv)) 
      {
        // Successful db update
        $vars['message'] = "User $UserRec[user_name] updated.";

        /* Reread the user record as update verification */
        $UserRec = $this->CreateUserRec($UserRec['user_pk']);
      }
      else 
      {
        $vars['message'] = $rv;
      }
    }
    else  
    {
      $NewUserpk = intval($request->get('newuser'));
      $UserRec = empty($NewUserpk) ? $this->CreateUserRec($user_pk) : $this->CreateUserRec($NewUserpk);
    }
    
    /* display the edit form with the requested user data */
    $vars = array_merge($vars, $this->DisplayForm($UserRec, $SessionIsAdmin));
    $vars['userId'] = $UserRec['user_pk'];

    return $this->render('user_edit.html.twig', $this->mergeWithDefault($vars));
  }

  /**
   * \brief Display the user record edit form
   * 
   * \param $UserRec - Database users record for the user to be edited.
   * \param $SessionIsAdmin - Boolean: This session is by an admin
   * \return the text of the display form on success, or error on failure.
   */
  private function DisplayForm($UserRec, $SessionIsAdmin) 
  {
    $vars = array('isSessionAdmin' => $SessionIsAdmin,
                  'userId' => $UserRec['user_pk']);

    /* For Admins, get the list of all users 
     * For non-admins, only show themself
     */
    if ($SessionIsAdmin)
    {
      $stmt = __METHOD__ . '.asSssionAdmin';
      $sql = "SELECT * FROM users ORDER BY user_name";
      $this->dbManager->prepare($stmt, $sql);
      $res = $this->dbManager->execute($stmt);
      $allUsers = array();
      while ($row = $this->dbManager->fetchArray($res))
      {
        $allUsers[$row['user_pk']] = htmlentities($row['user_name']);
      }
      $this->dbManager->freeResult($res);
      $vars['allUsers'] = $allUsers;
    }
    
    $vars['userName'] = $UserRec['user_name'];
    $vars['userDescription'] = $UserRec['user_desc'];
    $vars['userEMail'] = $UserRec["user_email"];

    $OutS = "";  // Output string
        
    $Field = "email_notify";
    $Checked = ($UserRec[$Field] == 'y') ? "checked" : "";
    $text = _("E-mail notification on job completion");
    $OutS .= "<tr class='classic'><th width='25%'>$text</th>";
    $OutS .= "<td><input type=checkbox name='$Field' $Checked></td>";
    $OutS .= "</tr>\n";

    if ($SessionIsAdmin)
    {
      $Field = "user_perm";
      $Val = htmlentities($UserRec[$Field], ENT_QUOTES);
      $text = _("Select the user's access level.");
      $OutS .= "<tr class='classic'><th width='25%'>$text</th>";
      $OutS .= "<td><select name='$Field'>\n";
      $text1 = _("None (very basic, no database access)");
      $text2 = _("Read-only (read, but no writes or downloads)");
      $text4 = _("Read-Write (read, download, or edit information)");
      $text9 = _("Full Administrator (all access including adding and deleting users)");
      $OutS .= "<option " . (($Val==PLUGIN_DB_NONE)?"selected":"") . " value='" . PLUGIN_DB_NONE . "'>$text1</option>\n";
      $OutS .= "<option " . (($Val==PLUGIN_DB_READ)?"selected":"") . " value='" . PLUGIN_DB_READ . "'>$text2</option>\n";
      $OutS .= "<option " . (($Val==PLUGIN_DB_WRITE)?"selected":"") . " value='" . PLUGIN_DB_WRITE . "'>$text4</option>\n";
      $OutS .= "<option " . (($Val==PLUGIN_DB_ADMIN)?"selected":"") . " value='" . PLUGIN_DB_ADMIN . "'>$text9</option>\n";
      $OutS .= "</select></td>\n";
      $OutS .= "</tr>\n";
    }

    if ($SessionIsAdmin)
    {
      $Field = "root_folder_fk";
      $Val = htmlentities($UserRec[$Field], ENT_QUOTES);
      $text = _("Select the user's top-level folder. Access is restricted to this folder.");
      $OutS .= "<tr class='classic'><th width='25%'>$text</th>";
      $OutS .= "<td><select name='$Field'>";
      $ParentFolder = -1;
      $Depth = 0;
      $IncludeTop = 1;  // include top level folder in selecet list
      $SelectedFolderPk = $UserRec[$Field];
      $OutS .= FolderListOption($ParentFolder, $Depth, $IncludeTop, $SelectedFolderPk);
      $OutS .= "</select></td>\n";
      $OutS .= "</tr>\n";
    }

    $vars['isBlankPassword'] = ($UserRec['_blank_pass'] == 'on');
    $vars['agentSelector'] = AgentCheckBoxMake(-1, array("agent_unpack", "agent_adj2nest", "wget_agent"), $UserRec['user_name']);
    $vars['bucketPool'] = SelectBucketPool($UserRec["default_bucketpool_fk"]);
    $vars['content'] = $OutS;
    
    return $vars;
  }

  /**
   * \brief Validate and update the user data.
   * \param $UserRec - Database record for the user to be edited.
   * 
   * \return NULL on success, string (error text) on failure.
   */
  function UpdateUser($UserRec, $SessionIsAdmin) 
  {
    global $PG_CONN;

    $Errors = "";

    /**** Validations ****/
    /* Make sure we have a user_pk */
    if (empty($UserRec['user_pk'])) {
      $Errors .= "<li>" . _("Consistency error (User_pk missing).  Please start over.");
    }

    /* Make sure username looks valid */
    if (empty($UserRec['user_name'])) {
      $Errors .= "<li>" . _("Username must be specified.");
    }

    /* Verify the user_name is not a duplicate  */
    $CheckUserRec = GetSingleRec("users", "WHERE user_name='$UserRec[user_name]'");
    if ((!empty($CheckUserRec)) and ( $CheckUserRec['user_pk'] != $UserRec['user_pk'])) {
      $Errors .= "<li>" . _("Username is not unique.");
    }

    /* Make sure password matches */
    if ($UserRec['_pass1'] != $UserRec['_pass2']) {
      $Errors .= "<li>" . _("Passwords do not match.");
    }

    /* Make sure email looks valid */
    $Check = preg_replace("/[^a-zA-Z0-9@_.+-]/", "", $UserRec['user_email']);
    if ($Check != $UserRec['user_email']) {
      $Errors .= "<li>" . _("Invalid email address.");
    }

    /* Did they specify a password and also request a blank password?  */
    if (!empty($UserRec['_blank_pass']) and ( !empty($UserRec['_pass1']) or ! empty($UserRec['_pass2']))) {
      $Errors .= "<li>" . _("You cannot specify both a password and a blank password.");
    }

    /* If we have any errors, return them */
    if (!empty($Errors)) {
      return _("Errors") . ":<ol>$Errors </ol>";
    }


    /**** Update the users database record ****/
    /* First remove user_pass and user_seed if the password wasn't changed. */
    if (!empty($UserRec['_blank_pass']) )
    {
      $UserRec['user_seed'] = rand() . rand();
      $UserRec['user_pass'] = sha1($UserRec['user_seed'] . "");
    }
    else if (empty($UserRec['_pass1']))   // password wasn't changed
    {
      unset( $UserRec['user_pass']);
      unset( $UserRec['user_seed']);
    }
    
    /* Build the sql update */
    $sql = "UPDATE users SET ";
    $first = TRUE;
    foreach($UserRec as $key=>$val)
    {
      if ($key[0] == '_' || $key == "user_pk") {
        continue;
      }
      if (!$SessionIsAdmin && ($key == "user_perm" || $key == "root_folder_fk")) {
        continue;
      }

      if (!$first) $sql .= ",";
      $sql .= "$key='" . pg_escape_string($val) . "'";
      $first = FALSE;
    }
    $sql .= " where user_pk=$UserRec[user_pk]";
    $result = pg_query($PG_CONN, $sql);
    DBCheckResult($result, $sql, __FILE__, __LINE__);
    pg_free_result($result);

    return (NULL);
  } // UpdateUser()

  /**
   * \brief Get a user record
   * \param $user_pk  fetch this users db record
   * 
   * \return users db record
   */
  function GetUserRec($user_pk) 
  {
    if (empty($user_pk))
    {
      throw new Exception("Invalid access.  Your session has expired.",1);
    }

    $UserRec = GetSingleRec("users", "WHERE user_pk=$user_pk");
    if (empty($UserRec))
    {
      throw new Exception("Invalid user. ",1);
    }
    return $UserRec;
  }

  /**
   * \brief Determine if the session user is an admin
   * 
   * \return TRUE if the session user is an admin.  Otherwise, return FALSE
   */
  private function IsSessionAdmin($UserRec) 
  {
    return ($UserRec['user_perm'] == PLUGIN_DB_ADMIN);
  }

  /**
   * \brief Create a user record.
   * \param integer $user_pk: If empty, use form data
   * 
   * \return A user record in the same associated array format that you get from a pg_fetch_assoc().
   *         However, there may be additional fields from the data input form that are not in the 
   *         users table.  These additional fields start with an underscore (_pass1, _pass2, _blank_pass)
   *         that come from the edit form.
   */
  function CreateUserRec($user_pk="") 
  {
    /* If a $user_pk was given, use it to read the user db record.
     * Otherwise, use the form data.
     */
    if (!empty($user_pk)) 
    {
      $UserRec = $this->GetUserRec($user_pk);
      $UserRec['_pass1'] = "";
      $UserRec['_pass2'] = "";
      $UserRec['_blank_pass'] = ($UserRec['user_pass'] == sha1($UserRec['user_seed'] . "")) ? "on" : "";
    }
    else
    {
      $UserRec = array();
      $UserRec['user_pk'] = GetParm('user_pk', PARM_TEXT);
      $UserRec['user_name'] = GetParm('user_name', PARM_TEXT);
      $UserRec['root_folder_fk'] = GetParm('root_folder_fk', PARM_INTEGER);
      $UserRec['user_desc'] = GetParm('user_desc', PARM_TEXT);

      $UserRec['_pass1'] = GetParm('_pass1', PARM_TEXT);
      $UserRec['_pass2'] = GetParm('_pass2', PARM_TEXT);
      if (!empty($UserRec['_pass1']))
      {
        $UserRec['user_seed'] = rand() . rand();
        $UserRec['user_pass'] = sha1($UserRec['user_seed'] . $UserRec['_pass1']);
        $UserRec['_blank_pass'] = "";
      }
      else
      {
        $UserRec['user_pass'] = "";
        $UserRec['_blank_pass'] = GetParm("_blank_pass", PARM_TEXT);
        if (empty($UserRec['_blank_pass']))  // check for blank password
        {
          // get the stored seed
          $StoredUserRec = $this->GetUserRec($UserRec['user_pk']);
          $UserRec['_blank_pass'] = ($UserRec['user_pass'] == sha1($StoredUserRec['user_seed'] . "")) ? "on" : "";
        }
      }

      $UserRec['user_perm'] = GetParm('user_perm', PARM_INTEGER);
      $UserRec['user_email'] = GetParm('user_email', PARM_TEXT);
      $UserRec['email_notify'] = GetParm('email_notify', PARM_TEXT);
      if (!empty($UserRec['email_notify'])) {
        $UserRec['email_notify'] = 'y';
      }
      $UserRec['user_agent_list'] = userAgents();
      $UserRec['default_bucketpool_fk'] = GetParm("default_bucketpool_fk", PARM_INTEGER);
    }
    return $UserRec;
  }
}

register_plugin(new UserEditPage());
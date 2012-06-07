<?php
/***********************************************************
Copyright (C) 2010 Hewlett-Packard Development Company, L.P.

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
/*************************************************
Restrict usage: Every PHP file should have this
at the very beginning.
This prevents hacking attempts.
*************************************************/
global $GlobalReady;
if (!isset($GlobalReady)) {
  exit;
}

global $WEBDIR;
require_once("$WEBDIR/common/common.php");

class ui_browse extends FO_Plugin {
  var $Name = "browse";
  var $Title = "Browse";
  var $Version = "1.0";
  var $MenuList = "Browse";
  var $Dependency = array(
    "db"
  );
  var $DBaccess = PLUGIN_DB_READ;
  var $LoginFlag = 0;

  /***********************************************************
  Install(): Create and configure database tables
  ***********************************************************/
  function Install() {
    global $DB;
    if (empty($DB)) {
      return (1);
    } /* No DB */
    /****************
    The top-level folder must exist.
    ****************/
    /* check if the table needs population */
    $SQL = "SELECT * FROM folder WHERE folder_pk=1;";
    $Results = $DB->Action($SQL);
    if ($Results[0]['folder_pk'] != "1") {
      $SQL = "INSERT INTO folder (folder_pk,folder_name,folder_desc) VALUES (1,'Software Repository','Top Folder');";
      $DB->Action($SQL);
      $SQL = "INSERT INTO foldercontents (parent_fk,foldercontents_mode,child_id) VALUES (1,0,0);";
      $DB->Action($SQL);
      /* Now fix the sequence number so the first insert does not fail */
      $Results = $DB->Action("SELECT max(folder_pk) AS max FROM folder LIMIT 1;");
      $Max = intval($Results[0]['max']);
      if (empty($Max) || ($Max < 1)) {
        $Max = 1;
      }
      else {
        $Max++;
      }
      $DB->Action("SELECT setval('folder_folder_pk_seq',$Max);");
    }
    return (0);
  } // Install()

  /***********************************************************
  RegisterMenus(): Customize submenus.
  ***********************************************************/
  function RegisterMenus() {
    $Upload = GetParm("upload", PARM_INTEGER);
    if (empty($Upload)) {
      return;
    }
    // For the Browse menu, permit switching between detail and simple.
    $URI = $this->Name . Traceback_parm_keep(array(
      "upload",
      "item"
    ));
    if (GetParm("mod", PARM_STRING) == $this->Name) {
      menu_insert("Browse::Browse", 1);
    }
    else {
      menu_insert("Browse::Browse", 1, $URI);
    }
  } // RegisterMenus()

  /***********************************************************
  ShowItem(): Given a upload_pk, list every item in it.
  If it is an individual file, then list the file contents.
  ***********************************************************/
  function ShowItem($Upload, $Item, $Show, $Folder) 
  {
    global $PG_CONN;

    $V = "";
    /* Use plugin "view" and "download" if they exist. */
    $Uri = Traceback_uri() . "?mod=" . $this->Name . "&folder=$Folder";
    $MenuPfile = menu_find("Browse-Pfile", $MenuDepth);

    /* Get the (non artifact) children  */
    $Results = GetNonArtifactChildren($Item);
    $ShowSomething = 0;
    $V.= "<table class='text' style='border-collapse: collapse' border=0 padding=0>\n";
    foreach($Results as $Row) 
    {
      if (empty($Row['uploadtree_pk'])) continue;
      $ShowSomething = 1;
      $Link = NULL;
      $Name = $Row['ufile_name'];
      $V.= "<tr>";

      /* Check for children so we know if the file should by hyperlinked */
      $sql = "select uploadtree_pk from uploadtree 
                where parent=$Row[uploadtree_pk] limit 1";
      $result = pg_query($PG_CONN, $sql);
      DBCheckResult($result, $sql, __FILE__, __LINE__);
      $HasChildren = pg_num_rows($result);
      pg_free_result($result);

      $Parm = "upload=$Upload&show=$Show&item=" . $Row['uploadtree_pk'];
      if ($HasChildren) 
        $Link = $Uri . "&show=$Show&upload=$Upload&item=" . $Row['uploadtree_pk'];

      /* Show details children */
      if ($Show == 'detail') {
        $V.= "<td class='mono'>" . DirMode2String($Row['ufile_mode']) . "</td>";
        if (!Isdir($Row['ufile_mode'])) {
          $V.= "<td align='right'>&nbsp;&nbsp;" . number_format($Row['pfile_size'], 0, "", ",") . "&nbsp;&nbsp;</td>";
        }
        else {
          $V.= "<td>&nbsp;</td>";
        }
      }
      /* Display item */
      $V.= "<td>";
      if (Iscontainer($Row['ufile_mode'])) {
        $V.= "<b>";
      }
      if (!empty($Link)) {
        $V.= "<a href='$Link'>";
      }
      $V.= $Name;
      if (Isdir($Row['ufile_mode'])) {
        $V.= "/";
      }
      if (!empty($Link)) {
        $V.= "</a>";
      }
      if (Iscontainer($Row['ufile_mode'])) {
        $V.= "</b>";
      }
      $V.= "</td>\n";
      if (!Isdir($Row['ufile_mode'])) {
        $V.= menu_to_1list($MenuPfile, $Parm, "<td>", "</td>\n");
      }
      $V.= "</td>";
    } /* foreach($Results as $Row) */
    $V.= "</table>\n";
    if (!$ShowSomething) {
      $V.= "<b>No files</b>\n";
    }
    else {
      $V.= "<hr>\n";
      if (count($Results) == 1) {
        $V.= "1 item\n";
      }
      else {
        $V.= count($Results) . " items\n";
      }
    }
    return ($V);
  } // ShowItem()

  /***********************************************************
  ShowFolder(): Given a Folder_pk, list every upload in the folder.
  ***********************************************************/
  function ShowFolder($Folder, $Show) {
    global $Plugins;
    global $DB;
    $ReAnalyze = & $Plugins[plugin_find_id("agent_reset_license") ]; /* may be null */
    $Analyze = & $Plugins[plugin_find_id("agent_license") ]; /* may be null */
    $V = "";
    /* Get list of fully unpacked uploads */
    /*** last unpack task: lft is set by adj2nest ***/
    $Sql = "SELECT * FROM upload
	INNER JOIN uploadtree ON upload_fk = upload_pk
	AND upload.pfile_fk = uploadtree.pfile_fk
	AND parent IS NULL
	AND lft IS NOT NULL
	WHERE upload_pk IN
	(SELECT child_id FROM foldercontents WHERE foldercontents_mode & 2 != 0 AND parent_fk = $Folder)
	ORDER BY upload_filename,upload_desc,upload_pk,upload_origin;";
    $Results = $DB->Action($Sql);
    $Uri = Traceback_uri() . "?mod=" . $this->Name;
    $V.= "<table border=1 width='100%'>";
    $V.= "<tr><td valign='top' width='20%'>\n";
    $V.= FolderListScript();
    $V.= "<center><H3>Folder Navigation</H3></center>\n";
    $V.= "<center><small>";
    if ($Folder != FolderGetTop()) {
      $V.= "<a href='" . Traceback_uri() . "?mod=" . $this->Name . "'>Top</a> |";
    }
    $V.= "<a href='javascript:Expand();'>Expand</a> |";
    $V.= "<a href='javascript:Collapse();'>Collapse</a> |";
    $V.= "<a href='" . Traceback() . "'>Refresh</a>";
    $V.= "</small></center>";
    $V.= "<P>\n";
    $V.= "<form>\n";
    $V.= FolderListDiv($Folder, 0, $Folder, 1);
    $V.= "</form>\n";
    $V.= "</td><td valign='top'>\n";
    $V.= "<center><H3>Uploads</H3></center>\n";
    $V.= "<table class='text' id='browsetbl' border=0 width='100%' cellpadding=0>\n";
    $V.= "<th>Upload Name and Description</th><th>Upload Date</th></tr>\n";
    foreach($Results as $Row) {
      if (empty($Row['upload_pk'])) {
        continue;
      }
      $Desc = htmlentities($Row['upload_desc']);
      $UploadPk = $Row['upload_pk'];
      if (empty($Desc)) {
        $Desc = "<i>No description</i>";
      }
      $Name = $Row['ufile_name'];
      if (empty($Name)) {
        $Name = $Row['upload_filename'];
      }
      $uploadOrigin = $Row['upload_origin'];

      /* If UploadtreePk is not an artifact, then use it as the root.
         Else get the first non artifact under it.
       */
      if (Isartifact($Row['ufile_mode'])) 
      $UploadtreePk = DirGetNonArtifact($Row['uploadtree_pk']);
      else
      $UploadtreePk = $Row['uploadtree_pk'];

      $V.= "<tr><td>";
      if (IsContainer($Row['ufile_mode'])) {
        $V.= "<a href='$Uri&upload=$UploadPk&folder=$Folder&item=$UploadtreePk&show=$Show'>";
        $V.= "<b>" . $Name . "</b>";
        $V.= "</a>";
      }
      else {
        $V.= "<b>" . $Name . "</b>";
      }
      if ($Row['upload_mode'] & 1 << 2) {
        $V.= "<br>Added by URL: " . htmlentities($uploadOrigin);
      }
      if ($Row['upload_mode'] & 1 << 3) {
        $V.= "<br>Added by file upload: " . htmlentities($uploadOrigin);
      }
      if ($Row['upload_mode'] & 1 << 4) {
        $V.= "<br>Added from filesystem: " . htmlentities($uploadOrigin);
      }
      $V.= "<br>";
      $MenuPfile = menu_find("Browse-Pfile", $MenuDepth);
      $Upload = $Row['upload_pk'];
      $Parm = "upload=$Upload&show=$Show&item=" . $Row['uploadtree_pk'];
      $V.= menu_to_1list($MenuPfile, $Parm, " ", " ");
      $V.= "<br>" . $Desc;
      //          $V .= "<br>Contains $ItemCount ";
      //	  if ($ItemCount != "1") { $V .= "items."; }
      //	  else { $V .= "item."; }
      $V.= "</td>\n";
      $V.= "<td align='right'>" . substr($Row['upload_ts'], 0, 19) . "</td></tr>\n";
      /* Check job status */
      $Status = JobListSummary($UploadPk);
      $V.= "<td>Scheduled ";
      if (plugin_find_id('showjobs') >= 0) {
        $V.= "<a href='" . Traceback_uri() . "?mod=showjobs&show=summary&history=1&upload=$UploadPk'>jobs</a>: ";
      }
      else {
        $V.= "jobs: ";
      }
      $V.= $Status['total'] . " total; ";
      $V.= $Status['completed'] . " completed; ";
      if (!empty($Status['pending'])) {
        $V.= $Status['pending'] . " pending; ";
      }
      if (!empty($Status['pending'])) {
        $V.= $Status['active'] . " active; ";
      }
      $V.= $Status['failed'] . " failed.";

/* bobg: bsam license analysis is deprecated */
if (isset($__OBSOLETE__))
{
      /* Check for re-do license analysis */
      if (!empty($ReAnalyze)) {
        /* Check if the analysis already exists and is not running */
        $V.= "<tr><td>";
        $Status = $Analyze->AgentCheck($UploadPk);
        $Uri = Traceback_uri() . "?mod=" . $this->Name . Traceback_parm_keep(array(
          "folder"
        ));
        if ($Status == 0) {
          $V.= "<a href='";
          $V.= $Uri . "&analyze=$UploadPk";
          $V.= "'>Schedule</a> license analysis";
        }
        else if ($Status == 2) {
          $V.= "<a href='";
          $V.= $Uri . "&reanalyze=$UploadPk";
          $V.= "'>Reschedule</a> license analysis";
        }
      }
}
      /* End of the record */
      $V.= "<tr><td colspan=2>&nbsp;</td></tr>\n";
    }
    $V.= "</table>\n";
    $V.= "</td></tr>\n";
    $V.= "</table>\n";
    return ($V);
  } /* ShowFolder() */

  /***********************************************************
  Output(): This function returns the scheduler status.
  ***********************************************************/
  function Output() {
    global $PG_CONN;

    if ($this->State != PLUGIN_STATE_READY) {
      return (0);
    }
    $V = "";
    $Folder = GetParm("folder", PARM_INTEGER);
    if (empty($Folder)) {
      $Folder = FolderGetTop();
    }
    $Upload = GetParm("upload", PARM_INTEGER);
    $Item = GetParm("item", PARM_INTEGER);
    $Uri = Traceback_uri() . "?mod=" . $this->Name;
    global $Plugins;
    global $DB;
  
    $Show = 'detail';						// always use detail

    /* Check for re-analysis */
    $ReAnalyze = & $Plugins[plugin_find_id("agent_reset_license") ]; /* may be null */
    $Analyze = & $Plugins[plugin_find_id("agent_license") ]; /* may be null */
    $UploadPk = GetParm("reanalyze", PARM_INTEGER);
    if (!empty($ReAnalyze) && !empty($UploadPk)) {
      $rc = $ReAnalyze->RemoveLicenseMeta($UploadPk, NULL, 1);
      if (empty($rc)) {
        $V.= displayMessage('License data re-analysis added to job queue');
      }
      else {
        $V.= displayMessage("Scheduling of re-analysis failed, return code: $rc");
      }
    }
    $UploadPk = GetParm("analyze", PARM_INTEGER);
    if (!empty($Analyze) && !empty($UploadPk)) {
      $rc = $Analyze->AgentAdd($UploadPk);
      if (empty($rc)) {
        $V.= displayMessage('License data analysis added to job queue');
      }
      else {
        $V.= displayMessage("Scheduling of re-analysis failed, return code: $rc");
      }
    }
    switch ($this->OutputType) {
      case "XML":
      break;
      case "HTML":
        /************************/
        /* Show the folder path */
        /************************/
        if (!empty($Item)) {
          /* Make sure the item is not a file */
          $Results = $DB->Action("SELECT ufile_mode FROM uploadtree WHERE uploadtree_pk = '$Item';");
          if (!Iscontainer($Results[0]['ufile_mode'])) {
            /* Not a container! */
            $View = & $Plugins[plugin_find_id("view") ];
            if (!empty($View)) {
              return ($View->ShowView(NULL, "browse"));
            }
          }
          $V.= "<font class='text'>\n";
          $V.= Dir2Browse($this->Name, $Item, NULL, 1, "Browse") . "\n";
        }
        else if (!empty($Upload)) {
          $V.= "<font class='text'>\n";
          $V.= Dir2BrowseUpload($this->Name, $Upload, NULL, 1, "Browse") . "\n";
        }
        else {
          $V.= "<font class='text'>\n";
        }
        /******************************/
        /* Get the folder description */
        /******************************/
        if (!empty($Upload)) {
          if (empty($Item))
          {
            $sql = "select uploadtree_pk from uploadtree 
                where parent is NULL and upload_fk=$Upload ";
            $result = pg_query($PG_CONN, $sql);
            DBCheckResult($result, $sql, __FILE__, __LINE__);
            if ( pg_num_rows($result))
            {
              $row = pg_fetch_assoc($result);
              $Item = $row['uploadtree_pk'];
            }
            else
            {
              $V.= "<hr><h2>Missing upload tree parent for upload $Upload</h2><hr>";
              break;
            }
            pg_free_result($result);
          }
          $V.= $this->ShowItem($Upload, $Item, $Show, $Folder);
        }
        else if (!empty($Folder)) {
          $V.= $this->ShowFolder($Folder, $Show);
        }
        $V.= "</font>\n";
        break;
      case "Text":
        break;
      default:
        break;
      }
      if (!$this->OutputToStdout) {
        return ($V);
      }
      print "$V";
      return;
    }
};
$NewPlugin = new ui_browse;
?>
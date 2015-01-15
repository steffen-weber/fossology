<?php
/***********************************************************
 * Copyright (C) 2008-2014 Hewlett-Packard Development Company, L.P.
 *               2014 Siemens AG
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 ***********************************************************/
use Fossology\Lib\Dao\AgentsDao;
use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Dao\LicenseDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Data\FileTreeBounds;
use Fossology\Lib\Data\LicenseRef;
use Fossology\Lib\Db\DbManager;
use Fossology\Lib\View\LicenseProcessor;
use Fossology\Lib\View\LicenseRenderer;


/**
 * \file ui-browse-license.php
 * \brief browse a directory to display all licenses in this directory
 */
define("TITLE_ui_license", _("License Browser"));

class ui_browse_license extends FO_Plugin
{

  private $uploadtree_tablename = "";
  /**
   * @var UploadDao
   */
  private $uploadDao;

  /**
   * @var LicenseDao
   */
  private $licenseDao;

  /**
   * @var ClearingDao
   */
  private $clearingDao;
  /**
   * @var LicenseProcessor
   */
  private $licenseProcessor;

  /**
   * @var AgentsDao
   */
  private $agentsDao;
  /**
   * @var LicenseRenderer
   */
  private $licenseRenderer;

  /** @var DbManager  */
  private $dbManager;

  function __construct()
  {
    $this->Name = "license";
    $this->Title = TITLE_ui_license;
    $this->Version = "1.0";
    $this->Dependency = array("browse", "view");
    $this->DBaccess = PLUGIN_DB_READ;
    $this->LoginFlag = 0;

    global $container;
    $this->uploadDao = $container->get('dao.upload');
    $this->licenseDao = $container->get('dao.license');
    $this->clearingDao = $container->get('dao.clearing');
    $this->agentsDao = $container->get('dao.agents');
    $this->licenseProcessor = $container->get('view.license_processor');
    $this->licenseRenderer = $container->get('view.license_renderer');
    $this->dbManager = $container->get('db.manager');
    parent::__construct();
  }

  /**
   * \brief  Only used during installation.
   * \return 0 on success, non-zero on failure.
   */
  function Install()
  {
    global $PG_CONN;
    return (int)(!$PG_CONN);
  }

  /**
   * \brief Customize submenus.
   */
  function RegisterMenus()
  {
    // For all other menus, permit coming back here.
    $URI = $this->Name . Traceback_parm_keep(array("upload", "item"));

    $Item = GetParm("item", PARM_INTEGER);
    $Upload = GetParm("upload", PARM_INTEGER);
    if (empty($Item) || empty($Upload))
      return;
    $viewLicenseURI = "view-license" . Traceback_parm_keep(array("show", "format", "page", "upload", "item"));
    $menuName = $this->Title;
    if (GetParm("mod", PARM_STRING) == $this->Name)
    {
      menu_insert("Browse::$menuName", 100);
    } else
    {
      $text = _("license histogram");
      menu_insert("Browse::$menuName", 100, $URI, $text);
      menu_insert("View::$menuName", 100, $viewLicenseURI, $text);
    }
  }

  /**
   * \brief This is called before the plugin is used.
   * It should assume that Install() was already run one time
   * (possibly years ago and not during this object's creation).
   *
   * \return true on success, false on failure.
   * A failed initialize is not used by the system.
   *
   * \note This function must NOT assume that other plugins are installed.
   */
  function Initialize()
  {
    if ($this->State != PLUGIN_STATE_INVALID)
    {
      return (1);
    } // don't re-run

    if ($this->Name !== "") // Name must be defined
    {
      global $Plugins;
      $this->State = PLUGIN_STATE_VALID;
      array_push($Plugins, $this);
    }

    return ($this->State == PLUGIN_STATE_VALID);
  }

  /**
   * \brief Given an $Uploadtree_pk, display:
   *   - The histogram for the directory BY LICENSE.
   *   - The file listing for the directory.
   */
  function ShowUploadHist($Uploadtree_pk, $Uri, $tag_pk)
  {
    $UniqueTagArray = array();
    global $Plugins;

    $ModLicView = & $Plugins[plugin_find_id("view-license")];

    $fileTreeBounds = $this->uploadDao->getFileTreeBounds($Uploadtree_pk, $this->uploadtree_tablename);

    $left = $fileTreeBounds->getLeft();
    if (empty($left))
    {
      $text = _("Job unpack/adj2nest hasn't completed.");
      $V = "<b>$text</b><p>";
      return $V;
    }

    $uploadId = GetParm('upload', PARM_NUMBER);
    $scannerAgents = array('nomos', 'monk');



    list($V, $allScans, $latestAgentIds) = $this->createHeader($scannerAgents, $uploadId);

    if (empty($allScans))
    {
      $out = $this->handleAllScansEmpty($scannerAgents, $uploadId);
      return $out;
    }

    $V .= $this->buildAgentSelector($allScans);

    $selectedAgentId = GetParm('agentId', PARM_INTEGER);
    $licenseCandidates = $this->clearingDao->getFileClearingsFolder($fileTreeBounds);
    list($jsBlockLicenseHist, $VLic) = $this->createLicenseHistogram($Uploadtree_pk, $tag_pk, $fileTreeBounds, $selectedAgentId ?: $latestAgentIds, $licenseCandidates);
    list($ChildCount, $jsBlockDirlist, $AddInfoText) = $this->createFileListing($Uri, $tag_pk, $fileTreeBounds, $ModLicView, $UniqueTagArray, $selectedAgentId, $licenseCandidates);

    /***************************************
     * Problem: $ChildCount can be zero!
     * This happens if you have a container that does not
     * unpack to a directory.  For example:
     * file.gz extracts to archive.txt that contains a license.
     * Same problem seen with .pdf and .Z files.
     * Solution: if $ChildCount == 0, then just view the license!
     *
     * $ChildCount can also be zero if the directory is empty.
     * **************************************/
    if ($ChildCount == 0)
    {
      $uploadEntry = $this->uploadDao->getUploadEntry($Uploadtree_pk, $this->uploadtree_tablename);

      if (IsDir($uploadEntry['ufile_mode']))
      {
        return "";
      }

      $ModLicView = & $Plugins[plugin_find_id("view-license")];
      return ($ModLicView->Output());
    }

    /******  Filters  *******/
    /* Only display the filter pulldown if there are filters available
     * Currently, this is only tags.
     */
    /** @todo qualify with tag namespace to avoid tag name collisions.  * */
    /* turn $UniqueTagArray into key value pairs ($SelectData) for select list */
    $SelectData = array();
    if (count($UniqueTagArray))
    {
      foreach ($UniqueTagArray as $UTA_row)
        $SelectData[$UTA_row['tag_pk']] = $UTA_row['tag_name'];
      $V .= "Tag filter";
      $myurl = "?mod=" . $this->Name . Traceback_parm_keep(array("upload", "item"));
      $Options = " id='filterselect' onchange=\"js_url(this.value, '$myurl&tag=')\"";
      $V .= Array2SingleSelectTag($SelectData, "tag_ns_pk", $tag_pk, true, false, $Options);
    }

    $dirlistPlaceHolder = "<table border=0 id='dirlist' style=\"margin-left: 9px;\" class='semibordered'></table>\n";
    /****** Combine VF and VLic ********/
    $V .= "<table border=0 cellpadding=2 width='100%'>\n";
    $V .= "<tr><td valign='top' width='25%'>$VLic</td><td valign='top' width='75%'>$dirlistPlaceHolder</td></tr>\n";
    $V .= "</table>\n";
    $V .= "<hr />\n";
    $V .= $jsBlockDirlist;
    $V .= $jsBlockLicenseHist;
    return ($V);
  }

  /**
   * \brief This function returns the scheduler status.
   */
  function Output()
  {
    $uTime = microtime(true);
    if ($this->State != PLUGIN_STATE_READY)
    {
      return (0);
    }
    $Upload = GetParm("upload", PARM_INTEGER);
    $UploadPerm = GetUploadPerm($Upload);
    if ($UploadPerm < PERM_READ)
    {
      $text = _("Permission Denied");
      echo "<h2>$text<h2>";
      return;
    }

    $Item = GetParm("item", PARM_INTEGER);
    $tag_pk = GetParm("tag", PARM_INTEGER);
    $updateCache = GetParm("updcache", PARM_INTEGER);

    $this->uploadtree_tablename = GetUploadtreeTableName($Upload);
    list($CacheKey, $V) = $this->cleanGetArgs($updateCache);


    if (empty($V)) // no cache exists
    {
      switch ($this->OutputType)
      {
        case "XML":
          break;
        case "HTML":
          /* Show the folder path */
          $V .= Dir2Browse($this->Name, $Item, NULL, 1, "Browse", -1, '', '', $this->uploadtree_tablename) . "<P />\n";

          if (!empty($Upload))
          {
            $Uri = preg_replace("/&item=([0-9]*)/", "", Traceback());
            $V .= js_url();
            $V .= $this->ShowUploadHist($Item, $Uri, $tag_pk);
          }

          $V .= $this->createJavaScriptBlock();
          break;
        case "Text":
          break;
        default:
      }

      $Cached = false;
    } else
      $Cached = true;

    if (!$this->OutputToStdout)
    {
      return ($V);
    }
    print "$V";
    $Time = microtime(true) - $uTime; // convert usecs to secs
    $text = _("Elapsed time: %.3f seconds");
    printf("<br/><small>$text</small>", $Time);

    if ($Cached)
    {
      $text = _("cached");
      $text1 = _("Update");
      echo " <i>$text</i>   <a href=\"$_SERVER[REQUEST_URI]&updcache=1\"> $text1 </a>";
    } else
    {
      /*  Cache Report if this took longer than 1/2 second */
      if ($Time > 0.5)
        ReportCachePut($CacheKey, $V);
    }
    return "";
  }

  private function createJavaScriptBlock()
  {
    $output = "\n<script src=\"scripts/jquery-1.11.1.min.js\" type=\"text/javascript\"></script>\n";
    $output .= "\n<script src=\"scripts/jquery.dataTables-1.9.4.min.js\" type=\"text/javascript\"></script>\n";
    $output .= "\n<script src=\"scripts/job-queue-poll.js\" type=\"text/javascript\"></script>\n";
    $output .= "<script src='scripts/license.js' type='text/javascript'  ></script>\n";
    return $output;
  }

  /**
   * @param $updcache
   * @return array
   */
  public function cleanGetArgs($updcache)
  {
    /* Remove "updcache" from the GET args.
         * This way all the url's based on the input args won't be
         * polluted with updcache
         * Use Traceback_parm_keep to ensure that all parameters are in order */
    $CacheKey = "?mod=" . $this->Name . Traceback_parm_keep(array("upload", "item", "tag", "agent", "orderBy", "orderl", "orderc"));
    if ($updcache)
    {
      $_SERVER['REQUEST_URI'] = preg_replace("/&updcache=[0-9]*/", "", $_SERVER['REQUEST_URI']);
      unset($_GET['updcache']);
      $V = ReportCachePurgeByKey($CacheKey);
      return array($CacheKey, $V);
    } else
    {
      $V = ReportCacheGet($CacheKey);
      return array($CacheKey, $V);
    }
  }

  /**
   * @param $Uri
   * @param $tagId
   * @param FileTreeBounds $fileTreeBounds
   * @param $ModLicView
   * @param $UniqueTagArray
   * @param $selectedAgentId
   * @internal param $uploadTreeId
   * @internal param $uploadId
   * @return array
   */
  public function createFileListing($Uri, $tagId, FileTreeBounds $fileTreeBounds, $ModLicView, &$UniqueTagArray, $selectedAgentId, $licenseCandidates)
  {
    /** change the license result when selecting one version of nomos */
    $uploadId = $fileTreeBounds->getUploadId();
    $uploadTreeId = $fileTreeBounds->getUploadTreeId();

    $latestNomos=LatestAgentpk($uploadId, "nomos_ars");
    $newestNomos=$this->getNewestAgent("nomos");
    $latestMonk=LatestAgentpk($uploadId, "monk_ars");
    $newestMonk=$this->getNewestAgent("monk");
    $goodAgents = array('nomos' => array('name' => 'N', 'latest' => $latestNomos, 'newest' =>$newestNomos, 'latestIsNewest' =>$latestNomos==$newestNomos['agent_pk']  ),
        'monk' => array('name' => 'M', 'latest' => $latestMonk, 'newest' =>$newestMonk, 'latestIsNewest' =>$latestMonk==$newestMonk['agent_pk']  ));

    /*******    File Listing     ************/
    $VF = ""; // return values for file listing
    $AddInfoText = "";
    $pfileLicenses = $this->licenseDao->getTopLevelLicensesPerFileId($fileTreeBounds, $selectedAgentId, array());
    $editedPfileLicenses = $this->clearingDao->newestEditedLicenseSelector->extractGoodClearingDecisionsPerFileID($licenseCandidates);
    /* Get ALL the items under this Uploadtree_pk */
    $Children = GetNonArtifactChildren($uploadTreeId, $this->uploadtree_tablename);

    /* Filter out Children that don't have tag */
    if (!empty($tagId))
      TagFilter($Children, $tagId, $this->uploadtree_tablename);

    $ChildCount = 0;

    if (!empty($Children))
    {
      $haveOldVersionResult = false;
      $haveRunningResult = false;
      $tableData = array();

      foreach ($Children as $child)
      {
        if (empty($child))
        {
          continue;
        }

        /* Determine the hyperlink for non-containers to view-license  */
        $fileId = $child['pfile_fk'];
        $childUploadTreeId = $child['uploadtree_pk'];

        if (!empty($fileId) && !empty($ModLicView))
        {
          $LinkUri = Traceback_uri();
          $LinkUri .= "?mod=view-license&upload=$uploadId&item=$childUploadTreeId";
          if ($selectedAgentId)
          {
            $LinkUri .= "&agentId=$selectedAgentId";
          }
        } else
        {
          $LinkUri = NULL;
        }

        /* Determine link for containers */
        $isContainer = Iscontainer($child['ufile_mode']);
        if ($isContainer)
        {
          $uploadtree_pk = DirGetNonArtifact($childUploadTreeId, $this->uploadtree_tablename);
          $LicUri = "$Uri&item=" . $uploadtree_pk;
          if ($selectedAgentId)
          {
            $LicUri .= "&agentId=$selectedAgentId";
          }
        } else
        {
          $LicUri = NULL;
        }

        /* Populate the output ($VF) - file list */
        /* id of each element is its uploadtree_pk */
        $fileName = $child['ufile_name'];
        if ($isContainer)
          $fileName = "<a href='$LicUri'><span style='color: darkblue'> <b>$fileName</b> </span></a>";
        else if (!empty($LinkUri))
          $fileName = "<a href='$LinkUri'>$fileName</a>";
        $ChildCount++;


        /* show licenses under file name */
        $licenseList = "";
        $editedLicenseList = "";
        if ($isContainer)
        {
          $containerFileTreeBounds = $this->uploadDao->getFileTreeBounds($childUploadTreeId, $this->uploadtree_tablename);
          $licenses = $this->licenseDao->getLicenseShortnamesContained($containerFileTreeBounds , array());
          $licenseList = implode(', ', $licenses);
          $editedLicenses = $this->clearingDao->getEditedLicenseShortnamesContained($containerFileTreeBounds);
          $editedLicenseList .= implode(', ', $editedLicenses);
        } else
        {
          if (array_key_exists($fileId, $pfileLicenses))
          {
            $licenseEntries = array();
            foreach ($pfileLicenses[$fileId] as $shortName => $rfInfo)
            {
              $agentEntries = array();
              foreach ($rfInfo as $agent => $lic)
              {
                $agentInfo = $goodAgents[$agent];
                $agentEntry = "<a href='?mod=view-license&upload=$child[upload_fk]&item=$childUploadTreeId&format=text&agentId=$lic[agent_id]&licenseId=$lic[license_id]#highlight'>$agentInfo[name]</a>";

                if($agentInfo['latestIsNewest']) {
                  if ($lic['agent_id'] != $agentInfo['latest'])
                  {
                    $agentEntry .= "&dagger;";
                    $haveOldVersionResult = true;
                  }
                }
                else {
                  if($lic['agent_id'] == $agentInfo['newest']['agent_pk']) {
                    $agentEntry .= "&sect;";
                    $haveRunningResult = true;
                  }
                  else {
                    $agentEntry .= "&dagger;";
                    $haveOldVersionResult = true;
                  }
                }
                if ($lic['match_percentage'] > 0)
                {
                  $agentEntry .= ": $lic[match_percentage]%";
                }
                $agentEntries[] = $agentEntry;
              }
              $licenseEntries[] = $shortName . " [" . implode("][", $agentEntries) . "]";
            }
            $licenseList = implode(", ", $licenseEntries);
          }
          if (array_key_exists($fileId, $editedPfileLicenses))
          {
            $editedLicenseList .= implode(", ", array_map(function ($licenseRef) use ($uploadId, $childUploadTreeId)
            {
              /** @var LicenseRef $licenseRef */
              return "<a href='?mod=view-license&upload=$uploadId&item=$childUploadTreeId&format=text'>" . $licenseRef->getShortName() . "</a>";
            }, $editedPfileLicenses[$fileId]->getLicenses()));
          }
        }
        $fileListLinks = FileListLinks($uploadId, $childUploadTreeId, 0, $fileId, true, $UniqueTagArray, $this->uploadtree_tablename);
        $tableData[] = array($fileName, $licenseList, $editedLicenseList, $fileListLinks);
      }

      if ($haveOldVersionResult)
      {
        $AddInfoText .= "<br/>" . _("Hint: Results marked with ") . "&dagger;" . _(" are from an older version, or were not confirmed in latest successful scan or were found during incomplete scan.");
      }
      if($haveRunningResult)
      {
        $AddInfoText .= "<br/>" . _("Hint: Results marked with ") . "&sect;" . _(" are from the newest version and come from a currently running or incomplete scan.");
      }


      $tableColumns = array(
          array("sTitle" => _("Files"), "sClass" => "left", "sType" => "html"),
          array("sTitle" => _("Scanner Results (N: nomos, M: monk)"), "sClass" => "left", "sType" => "html"),
          array("sTitle" => _("Edited Results"), "sClass" => "left", "sType" => "html"),
          array("sTitle" => _("Actions"), "sClass" => "left", "bSortable" => false, "bSearchable" => false, "sWidth" => "14.6%")
      );

      $tableSorting = array(
          array(0, "asc"),
          array(2, "desc"),
          array(1, "desc")
      );

      $tableLanguage = array(
          "sInfo" => "Showing _START_ to _END_ of _TOTAL_ files",
          "sSearch" => "_INPUT_"
         . "<button onclick='clearSearchFiles()' >" . _("Clear") . "</button>",
          "sInfoPostFix" => $AddInfoText,
          "sLengthMenu" => "Display <select>
                              <option value=\"10\">10</option>
                              <option value=\"25\">25</option>
                              <option value=\"50\">50</option>
                              <option value=\"100\">100</option>
                              <option value=\"999999\">All</option>
                            </select> files"
      );

      $dataTableConfig = array(
          "aaData" => $tableData,
          "aoColumns" => $tableColumns,
          "aaSorting" => $tableSorting,
          "iDisplayLength" => 50,
          "oLanguage" => $tableLanguage
      );

      $VF .= "<script>
    function createDirlistTable() {
        dTable=$('#dirlist').dataTable(" . json_encode($dataTableConfig) . ");
    }
</script>";
      return array($ChildCount, $VF, $AddInfoText);
    }
    return array($ChildCount, $VF, $AddInfoText);
  }

  /**
   * @param $uploadTreeId
   * @param $tagId
   * @param FileTreeBounds $fileTreeBounds
   * @param int|null $agentId
   * @return string
   */
  public function createLicenseHistogram($uploadTreeId, $tagId, FileTreeBounds $fileTreeBounds, $agentId, $licenseCandidates)
  {
    $FileCount = $this->uploadDao->countPlainFiles($fileTreeBounds);
    $licenseHistogram = $this->licenseDao->getLicenseHistogram($fileTreeBounds, $orderStmt = "", $agentId);
    $editedLicensesHist = $this->clearingDao->getEditedLicenseShortnamesContainedWithCount($fileTreeBounds, $this->clearingDao
                          ->newestEditedLicenseSelector->extractGoodLicenses($licenseCandidates));

    return $this->licenseRenderer->renderLicenseHistogram($licenseHistogram, $editedLicensesHist, $uploadTreeId, $tagId, $FileCount);
  }

  private function buildAgentSelector($allScans)
  {
    $selectedAgentId = GetParm('agentId', PARM_INTEGER);
    if (count($allScans) == 1)
    {
      $run = reset($allScans);
      return "Only one revision of <b>$run[agent_name]</b> ran for this upload. <br/>";
    }
    $URI = Traceback_uri() . '?mod=' . Traceback_parm() . '&updcache=1';
    $V = "<form action='$URI' method='post' data-autosubmit=\"\"><select name='agentId' id='agentId'>";
    $isSelected = (0 == $selectedAgentId) ? " selected='selected'" : '';
    $V .= "<option value='0' $isSelected>" . _('Latest run of all available agents') . "</option>\n";
    foreach ($allScans as $run)
    {
      if ($run['agent_pk'] == $selectedAgentId)
      {
        $isSelected = " selected='selected'";
      } else
      {
        $isSelected = "";
      }
      $V .= "<option value='$run[agent_pk]'$isSelected>$run[agent_name] $run[agent_rev]</option>\n";
    }
    $V .= "</select><noscript><input type='submit' name='' value='Show'/></noscript></form>";
    return $V;
  }

  /**
   * @param $uploadId
   * @return string
   */
  public function getViewJobsLink($uploadId)
  {
    $URL = Traceback_uri() . "?mod=showjobs&upload=$uploadId";
    $linkText = _("View Jobs");
    $out = "<a href=$URL>$linkText</a>";
    return $out;
  }


  public function scheduleScan($uploadId, $agentName, $buttonText) {
    $out = "<span id=".$agentName."_span><br><button type=\"button\" id=$agentName name=$agentName onclick='scheduleScan($uploadId, \"agent_$agentName\",\"#job".$agentName."IdResult\")'>$buttonText</button> </span>";
    $out .= "<br><span id=\"job".$agentName."IdResult\" name=\"job".$agentName."IdResult\" hidden></span>";

    return $out;
  }

  /**
   * @param string $agentName
   * @return array
   */
  public function getNewestAgent($agentName)
  {
    global $container;
    $dbManager = $container->get('db.manager');
    /** @var DbManager $dbManager */
    return $dbManager->getSingleRow("SELECT agent_pk,agent_rev from agent WHERE agent_enabled AND agent_name=$1 "
        . "ORDER BY agent_pk DESC LIMIT 1", array($agentName));
  }

  /**
   * @param $scannerAgents
   * @param $uploadId
   * @return string
   */
  private function handleAllScansEmpty($scannerAgents, $uploadId)
  {
    $out = "";
    $out .= _("There is no successful scan for this upload, please schedule one license scanner on this upload. ");

    foreach ($scannerAgents as $agentName)
    {
      $runningJobs = $this->agentsDao->RunningAgentpks($uploadId, $agentName . "_ars");
      if (count($runningJobs) > 0)
      {
        $out .= _("The agent ") . $agentName . _(" was already scheduled. Maybe it is running at the moment?");
        $out .= $this->getViewJobsLink($uploadId);
        $out .= "<br/>";
      }
    }
    $out .= _("Do you want to ");
    $link = Traceback_uri() . '?mod=agent_add&upload=' . $uploadId;
    $out .= "<a href='$link'>" . _("schedule agents") . "</a>";
    return $out;
  }

  /**
   * @param $scannerAgents
   * @param $dbManager
   * @param $uploadId
   * @param $allScans
   * @return array
   */
  private function createHeader($scannerAgents, $uploadId)
  {
    $latestAgentIds = array();
    $allScans = array();
    $V = ""; // total return value
    foreach ($scannerAgents as $agentName)
    {
      $agentHasArsTable = DB_TableExists($agentName . "_ars");
      if (empty($agentHasArsTable))
      {
        continue;
      }

      $newestAgent = $this->getNewestAgent($agentName);
      $stmt = __METHOD__ . ".getAgent.$agentName";
      $this->dbManager->prepare($stmt,
          $sql = "SELECT agent_pk,agent_rev,agent_name FROM agent LEFT JOIN " . $agentName . "_ars ON agent_fk=agent_pk "
              . "WHERE agent_name=$2 AND agent_enabled "
              . "  AND upload_fk=$1 AND ars_success "
              . "ORDER BY agent_pk DESC");
      $res = $this->dbManager->execute($stmt, array($uploadId, $agentName));
      $latestRun = $this->dbManager->fetchArray($res);
      if ($latestRun)
      {
        $allScans[] = $latestRun;
      }
      while ($run = $this->dbManager->fetchArray($res))
      {
        $allScans[] = $run;
      }
      $this->dbManager->freeResult($res);

      if (false === $latestRun)
      {

        $V .= _("The agent") . " <b>$agentName</b> " . _("has not been run on this upload.");

        $runningJobs = $this->agentsDao->RunningAgentpks($uploadId, $agentName . "_ars");
        if (count($runningJobs) > 0)
        {
          $V .= _("But there were scheduled jobs for this agent. So it is either running or has failed.");
          $V .= $this->getViewJobsLink($uploadId);
          $V .= $this->scheduleScan($uploadId, $agentName, sprintf(_("Reschedule %s scan"), $agentName));
        } else
        {
          $V .= $this->scheduleScan($uploadId, $agentName, sprintf(_("Schedule %s scan"), $agentName));
        }
        continue;
      }

      $V .= _("The latest results of agent") . " <b>$agentName</b> " . _("are from revision ") . "$latestRun[agent_rev].";
      if ($latestRun['agent_pk'] != $newestAgent['agent_pk'])
      {

        $runningJobs = $this->agentsDao->RunningAgentpks($uploadId, $agentName . "_ars");
        if (in_array($newestAgent['agent_pk'], $runningJobs))
        {
          $V .= _(" The newest agent revision ") . $newestAgent['agent_rev'] . _(" is scheduled to run on this upload.");
          $V .= $this->getViewJobsLink($uploadId);
          $V .= " " . _("or") . " ";
        } else
        {
          $V .= _(" The newest agent revision ") . $newestAgent['agent_rev'] . _(" has not been run on this upload.");

        }
        $V .= $this->scheduleScan($uploadId, $agentName, sprintf(_("Schedule %s scan"), $agentName));
      }

      $latestAgentIds[$agentName] = intval($latestRun['agent_pk']);
      $V .= '<br/>';
    }
    return array($V, $allScans, $latestAgentIds);
  }

}

$NewPlugin = new ui_browse_license;
$NewPlugin->Initialize();

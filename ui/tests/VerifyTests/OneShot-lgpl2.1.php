<?php
/***********************************************************
 Copyright (C) 2008 Hewlett-Packard Development Company, L.P.

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
/**
 * Perform a one-shot license analysis
 *
 *@TODO needs setup and account to really work well...
 *
 * @version "$Id$"
 *
 * Created on Aug 1, 2008
 */

require_once ('../../../tests/fossologyTestCase.php');
require_once ('../../../tests/TestEnvironment.php');

global $URL;

class OneShotgplv21Test extends fossologyTestCase
{
  public $mybrowser;

  function setUp()
  {
    /* check to see if the user and material exist*/
    $this->assertTrue(file_exists('/home/fosstester/.bashrc'),
                      "OneShotgplv21Test FAILURE! .bashrc not found\n");
    $this->Login();
  }

  function testOneShotgplv21()
  {
    global $URL;

    print "starting OneShotgplv21Test\n";
    $loggedIn = $this->mybrowser->get($URL);
    $this->assertTrue($this->myassertText($loggedIn, '/Upload/'),
                      "OneShotgplv21Test FAILED! Did not find Upload Menu\n");
    $this->assertTrue($this->myassertText($loggedIn, '/One-Shot Analysis/'),
                      "OneShotgplv21Test FAILED! Did not find One-Shot Analysis Menu\n");

    $page = $this->mybrowser->get("$URL?mod=agent_license_once");
    $this->assertTrue($this->myassertText($page, '/One-Shot License Analysis/'),
    "OneShotgplv21Test FAILED! Did not find One-Shot License Analysis Title\n");
    $this->assertTrue($this->myassertText($page, '/The analysis is done in real-time/'),
             "OneShotgplv21Test FAILED! Did not find real-time Text\n");

    $this->assertTrue($this->mybrowser->setField('licfile', '/home/fosstester/licenses/gplv2.1'));
    /* we won't select highlights' */
    $this->assertTrue($this->mybrowser->clickSubmit('Analyze!'),
                      "FAILED! Count not click Analyze button\n");
    /* Check for the correct analysis.... */
    $page = $this->mybrowser->getContent();
    $this->assertTrue($this->myassertText($page, '/LGPL v2\.1 Preamble, LGPL v2\.1\+/'),
    "OneShotgplv21Test FAILED! Did not find exactly 'LGPL v2.1 Preamble, LGPL v2.1+'\n");

    $this->assertTrue($this->myassertText($page, '/One-Shot License Analysis/'),
    "OneShotgplv21Test FAILED! Did not find One-Shot License Analysis Title\n");
    // should not see -partial anymore
    $this->assertFalse($this->myassertText($page, '/-partial/'),
    "OneShotgplv21Test FAILED! Found -partial in a non partial license file\n");
  }
}
?>
<?php
/*
 SPDX-FileCopyrightText: © 2021 Siemens AG
 Author: Gaurav Mishra <mishra.gaurav@siemens.com>

 SPDX-License-Identifier: GPL-2.0-only
*/
/**
 * @file
 * @brief Tests for Scan model
 */

namespace Fossology\UI\Api\Test\Models
{
  use Mockery as M;
  use Fossology\UI\Api\Models\Analysis;
  use Fossology\UI\Api\Models\ScanOptions;
  use Fossology\UI\Api\Models\Reuser;
  use Fossology\UI\Api\Models\Decider;
  use Fossology\UI\Api\Models\Scancode;
  use Fossology\UI\Api\Models\Info;
  use Fossology\Lib\Dao\UserDao;
  use Fossology\Lib\Auth\Auth;
  use Symfony\Component\HttpFoundation\Request;
  use Fossology\UI\Api\Models\ApiVersion;

  /**
   * @class ScanOptionsTest
   * @brief Tests for ScanOption model
   */
  class ScanOptionsTest extends \PHPUnit\Framework\TestCase
  {
    /**
     * @var \Mockery\MockInterface $functions
     * Public function mock
     */
    public static $functions;

    /**
     * @var AgentAdder $agentAdderMock
     * Mock object of overloaded AgentAdder class
     */
    private $agentAdderMock;

    /**
     * @var UserDao $userDao
     * UserDao mock
     */
    private $userDao;

    /**
     * @brief Setup test objects
     * @see PHPUnit_Framework_TestCase::setUp()
     */
    public function setUp() : void
    {
      global $container;
      
      $this->agentAdderMock = M::mock('overload:\AgentAdder');
      $this->userDao = M::mock(UserDao::class);
      
      // Mock ApiVersion::getVersionFromUri
      $apiVersionMock = M::mock('alias:' . ApiVersion::class);
      $apiVersionMock->shouldReceive('getVersionFromUri')
        ->andReturn(ApiVersion::V1);
      
      $container = M::mock('ContainerBuilder');
      $container->shouldReceive('get')->withArgs(["dao.user"])
        ->andReturn($this->userDao);
      $container->shouldReceive('get')->andReturn(null);

      self::$functions = M::mock(\stdClass::class);
      self::$functions->shouldReceive('FolderListUploads_perm')
        ->withArgs([2, Auth::PERM_WRITE])->andReturn([
          ['upload_pk' => 2],
          ['upload_pk' => 3],
          ['upload_pk' => 4]
        ]);
      self::$functions->shouldReceive('register_plugin')
        ->with(\Hamcrest\Matchers::identicalTo(
          new ScanOptions(null, null, null, null)));
    }

    /**
     * @brief Teardown test objects
     * @see PHPUnit_Framework_TestCase::tearDown()
     */
    protected function tearDown(): void
    {
      M::close();
      parent::tearDown();
    }

    /**
     * Prepare request for scan
     * @param array $reuserOpts
     * @param array $deciderOpts
     * @return Request
     */
    private function prepareRequest($reuserOpts, $deciderOpts)
    {
      $request = new Request();

      if (!empty($reuserOpts)) {
        $reuserSelector = $reuserOpts['upload'] . "," . $reuserOpts['group'];
        $request->request->set('uploadToReuse', $reuserSelector);
        if (key_exists('rules', $reuserOpts)) {
          $request->request->set('reuseMode', $reuserOpts['rules']);
        }
      }
      if (!empty($deciderOpts)) {
        $request->request->set('deciderRules', $deciderOpts);
        if (in_array('nomosInMonk', $deciderOpts)) {
          $request->request->set('Check_agent_nomos', 1);
        }
      }
      return $request;
    }

    /**
     * @test
     * -# Test for ScanOptions::scheduleAgents() with API V1
     */
    public function testScheduleAgentsV1()
    {
      $reuseUploadId = 2;
      $uploadId = 4;
      $folderId = 2;
      $groupId = 2;
      $groupName = "fossy";
      $agentsToAdd = ['agent_nomos', 'agent_ojo', 'agent_monk'];
      $reuserOpts = [
        'upload' => $reuseUploadId,
        'group' => $groupId,
        'rules' => []
      ];
      $deciderOpts = [
        'nomosInMonk',
        'ojoNoContradiction'
      ];
      
      $request = $this->prepareRequest($reuserOpts, $deciderOpts);

      $analysis = new Analysis();
      $analysis->setUsingString("nomos,ojo,monk");

      $reuse = new Reuser($reuseUploadId, $groupName);

      $decider = new Decider();
      $decider->setOjoDecider(true);
      $decider->setNomosMonk(true);
      $decider->setConcludeLicenseType("Permissive");

      $scancode = new Scancode();

      $scanOption = new ScanOptions($analysis, $reuse, $decider, $scancode);

      $this->userDao->shouldReceive('getGroupIdByName')
        ->withArgs([$groupName])->andReturn($groupId);

      $mockInfo = M::mock(Info::class);
      $mockInfo->shouldReceive('getCode')->andReturn(201);
      $mockInfo->shouldReceive('getMessage')->andReturn('Schedule successful');
      
      $this->agentAdderMock->shouldReceive('scheduleAgents')
        ->once()
        ->andReturn($mockInfo);

      $result = $scanOption->scheduleAgents($folderId, $uploadId);
      
      $this->assertInstanceOf(Info::class, $result);
      $this->assertEquals(201, $result->getCode());
      $this->assertEquals('Schedule successful', $result->getMessage());
    }

    /**
     * @test
     * -# Test for ScanOptions::scheduleAgents() with API V2
     */
    public function testScheduleAgentsV2()
    {
      // Mock ApiVersion to return V2
      $apiVersionMock = M::mock('alias:' . ApiVersion::class);
      $apiVersionMock->shouldReceive('getVersionFromUri')
        ->andReturn(ApiVersion::V2);

      $reuseUploadId = 2;
      $uploadId = 4;
      $folderId = 2;
      $groupId = 2;
      $groupName = "fossy";
      
      // V2-specific agent setup
      $reuserOpts = [
        'upload' => $reuseUploadId,
        'group' => $groupId,
        'rules' => []
      ];
      $deciderOpts = [
        'nomosInMonk',
        'ojoNoContradiction'
      ];
      
      $request = $this->prepareRequest($reuserOpts, $deciderOpts);

      $analysis = new Analysis();
      // Using V2 specific agent names
      $analysis->setUsingString("nomos,copyrightEmailAuthor,ipra,pkgagent,softwareHeritage");

      $reuse = new Reuser($reuseUploadId, $groupName);

      $decider = new Decider();
      $decider->setOjoDecider(true);
      $decider->setNomosMonk(true);
      $decider->setConcludeLicenseType("Permissive");

      $scancode = new Scancode();
      $scancode->setScanLicense(true);
      $scancode->setScanCopyright(true);
      $scancode->setScanEmail(true);
      $scancode->setScanUrl(true);

      $scanOption = new ScanOptions($analysis, $reuse, $decider, $scancode);

      $this->userDao->shouldReceive('getGroupIdByName')
        ->withArgs([$groupName])->andReturn($groupId);

      $mockInfo = M::mock(Info::class);
      $mockInfo->shouldReceive('getCode')->andReturn(201);
      $mockInfo->shouldReceive('getMessage')->andReturn('Schedule successful');
      
      // Expected V2 specific agents
      $this->agentAdderMock->shouldReceive('scheduleAgents')
        ->once()
        ->andReturn($mockInfo);

      $result = $scanOption->scheduleAgents($folderId, $uploadId);
      
      $this->assertInstanceOf(Info::class, $result);
      $this->assertEquals(201, $result->getCode());
      $this->assertEquals('Schedule successful', $result->getMessage());
    }
  }
}

namespace Fossology\UI\Api\Models
{
  function register_plugin($obj)
  {
    return \Fossology\Ui\Api\Test\Models\ScanOptionsTest::$functions
      ->register_plugin($obj);
  }

  function FolderListUploads_perm($parentFolder, $perm)
  {
    return \Fossology\Ui\Api\Test\Models\ScanOptionsTest::$functions
      ->FolderListUploads_perm($parentFolder, $perm);
  }
}
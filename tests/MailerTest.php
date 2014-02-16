<?php
/**
 * FluxBB
 *
 * LICENSE
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301, USA.
 *
 * @category	FluxBB
 * @package		Mailer
 * @subpackage	Tests
 * @copyright	Copyright (c) 2011 FluxBB (http://fluxbb.org)
 * @license		http://www.gnu.org/licenses/lgpl.html	GNU Lesser General Public License
 */

namespace fluxbb\mailer\tests;

require_once dirname(__FILE__).'/../vendor/autoload.php';

require_once dirname(__FILE__).'/../src/FluxBB/Mailer/Mailer.php';
require_once dirname(__FILE__).'/../src/FluxBB/Mailer/Email.php';
require_once dirname(__FILE__).'/../src/FluxBB/Mailer/Exception.php';

require_once dirname(__FILE__).'/../src/FluxBB/Mailer/Transport/Mock.php';

class MailerTest extends \PHPUnit_Framework_TestCase
{
	protected $mailer;

	public function setUp()
	{
		$this->mailer = \FluxBB\Mailer\Mailer::load('Mock', 'test@fluxbb.org');
	}

	public function testNewMail()
	{
		$result = $this->mailer->send($this->mailer->newEmail('subject', 'message'));

		$this->assertEquals('test@fluxbb.org', $result['from']);
		$this->assertEquals('subject', $result['subject']);
		$this->assertEquals('message', $result['message']);
	}
}

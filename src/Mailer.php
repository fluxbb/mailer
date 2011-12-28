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
 * @package		Flux_Mailer
 * @copyright	Copyright (c) 2011 FluxBB (http://fluxbb.org)
 * @license		http://www.gnu.org/licenses/lgpl.html	GNU Lesser General Public License
 */


if (!defined('MAILER_ROOT'))
	define('MAILER_ROOT', dirname(__FILE__).'/');

if (!defined('UTF8_CORE') || !defined('UTF8'))
	throw new Exception('The fluxbb-utf8 module is required for fluxbb-mailer to function properly!');

require MAILER_ROOT.'Mailer/Email.php';

abstract class Flux_Mailer
{
	public static final function load($type, $from, $args = array())
	{
		if (!class_exists('Flux_Mailer_Transport_'.$type))
			require MAILER_ROOT.'Mailer/Transport/'.$type.'.php';

		// Instantiate the transport
		$type = 'Flux_Mailer_Transport_'.$type;
		$mailer = new $type($args);

		// Set the from address for all emails
		$mailer->from = $from;

		return $mailer;
	}

	private $from;

	public function new_email($subject = null, $message = null)
	{
		$email = new Flux_Mailer_Email($this->from, $this);

		if ($subject !== null)
			$email->setSubject($subject);

		if ($message !== null)
			$email->setBody($message);

		return $email;
	}

	public abstract function send($from, $recipients, $message, $headers);
}

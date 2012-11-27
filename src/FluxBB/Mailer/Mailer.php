<?php
/**
 * FluxBB Mailer - Lightweight email library with transport abstraction
 * Copyright (C) 2011-2012 FluxBB.org
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
 * @copyright	Copyright (c) 2011-2012 FluxBB (http://fluxbb.org)
 * @license		http://www.gnu.org/licenses/lgpl.html	GNU Lesser General Public License
 */

namespace FluxBB\Mailer;

abstract class Mailer
{
	public static final function load($type, $from, $args = array())
	{
		// Instantiate the transport
		$type = 'FluxBB\\Mailer\\Transport\\'.$type;
		$mailer = new $type($args);

		// Set the from address for all emails
		$mailer->from = $from;

		return $mailer;
	}

	private $from;

	public function newEmail($subject = null, $message = null)
	{
		$email = new Email($this->from, $this);

		if ($subject !== null)
			$email->setSubject($subject);

		if ($message !== null)
			$email->setBody($message);

		return $email;
	}

	public abstract function send($from, $recipients, $message, $headers);
}

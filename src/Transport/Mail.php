<?php
/**
 * Sends email using PHPs built in mail() function
 * http://uk3.php.net/manual/en/function.mail.php
 *
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


class Flux_Mailer_Transport_Mail extends Flux_Mailer
{
	/**
	 * Initialise a new basic PHP mailer.
	 */
	public function __construct($config)
	{
	}

	public function send($from, $recipients, $message, $headers)
	{
		// $recipients is ignored - we use the contents of the to, cc, and bcc headers instead

		// Extract the subject since PHP mail() wants it explicitly
		$subject = '';
		if (isset($headers['Subject']))
		{
			$subject = $headers['Subject'];
			unset ($headers['Subject']);
		}

		// Extract the to header since mail() adds this itself
		$to = null;
		if (isset($headers['To']))
		{
			$to = $headers['To'];
			unset ($headers['To']);
		}

		return mail($to, $subject, $message, Flux_Email::createHeaderStr($headers));
	}
}

<?php

/**
 * Sends email using PHPs built in mail() function
 * http://uk3.php.net/manual/en/function.mail.php
 *
 * Copyright (C) 2011 FluxBB (http://fluxbb.org)
 * License: LGPL - GNU Lesser General Public License (http://www.gnu.org/licenses/lgpl.html)
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

		return mail($to, $subject, $message, Email::createHeaderStr($headers));
	}
}

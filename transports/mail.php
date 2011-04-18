<?php

/**
 * Sends email using PHPs built in mail() function
 * http://uk3.php.net/manual/en/function.mail.php
 *
 * Copyright (C) 2011 FluxBB (http://fluxbb.org)
 * License: LGPL - GNU Lesser General Public License (http://www.gnu.org/licenses/lgpl.html)
 */

class MailMailTransport extends MailTransport
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

		// Start with a blank message
		$header_str = '';

		// Append the header strings
		foreach ($headers as $key => $value)
			$header_str .= $key.': '.$value.PHP_EOL;

		return mail($to, $subject, $message, $header_str);
	}
}

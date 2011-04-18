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

	public function send($email, $to)
	{
		$message = $email->get_message();
		$headers = $email->get_headers();

		// Extract the subject since PHP mail() wants it explicitly
		if (empty($headers['Subject']))
			$subject = '';
		else
		{
			$subject = $headers['Subject'];
			unset ($headers['Subject']);
		}

		// Start with a blank message
		$header_str = '';

		// Append the header strings
		foreach ($headers as $key => $value)
			$header_str .= $key.': '.$value."\r\n";

		return mail($to, $subject, $message, $header_str);
	}
}

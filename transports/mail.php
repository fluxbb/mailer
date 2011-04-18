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

		// TODO: Does this handle multiple to, cc, etc?

		return mail($to, $subject, $message, implode("\r\n", $headers));
	}
}

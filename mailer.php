<?php

/**
* Copyright (C) 2011 FluxBB (http://fluxbb.org)
* License: LGPL - GNU Lesser General Public License (http://www.gnu.org/licenses/lgpl.html)
*/

if (!defined('MAILER_ROOT'))
	define('MAILER_ROOT', dirname(__FILE__).'/');

if (!defined('UTF8_CORE') || !defined('UTF8'))
	throw new Exception('The fluxbb-utf8 module is required for fluxbb-mailer to function properly!');

require MAILER_ROOT.'email.php';

abstract class MailTransport
{
	public static final function load($type, $from, $args = array())
	{
		if (!class_exists($type.'MailTransport'))
			require MAILER_ROOT.'transports/'.$type.'.php';

		// Instantiate the transport
		$type = $type.'MailTransport';
		$mailer = new $type($args);

		// Set the from address for all emails
		$mailer->from = $from;

		return $mailer;
	}

	private $from;

	public function new_email($subject = null, $message = null)
	{
		$email = new Email($this->from, $this);

		if ($subject !== null)
			$email->set_subject($subject);

		if ($message !== null)
			$email->set_body($message);

		return $email;
	}

	public abstract function send($from, $recipients, $message, $headers);
}

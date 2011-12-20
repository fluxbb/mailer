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

abstract class Flux_Mailer
{
	public static final function load($type, $from, $args = array())
	{
		if (!class_exists('Flux_Mailer_Transport_'.$type))
			require MAILER_ROOT.'Transport/'.$type.'.php';

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
		$email = new Flux_Email($this->from, $this);

		if ($subject !== null)
			$email->setSubject($subject);

		if ($message !== null)
			$email->setBody($message);

		return $email;
	}

	public abstract function send($from, $recipients, $message, $headers);
}

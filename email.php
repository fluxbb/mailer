<?php

/**
* Copyright (C) 2011 FluxBB (http://fluxbb.org)
* License: LGPL - GNU Lesser General Public License (http://www.gnu.org/licenses/lgpl.html)
*/

class Email
{
	const MAILER_TAG = 'FluxBB Mailer';

	public static function encode_utf8($str)
	{
		// Only encode the string if it does contain UTF8
		if (preg_match('/(?:[^\x00-\x7F])/', $str))
			return '=?UTF-8?B?'.base64_encode($str).'?=';

		return $str;
	}

	public static function sanitize_header($value)
	{
		return str_replace(array("\n", "\r"), '', $value);
	}

	private $message;
	private $headers;
	private $from;
	private $mailer;

	public function __construct($from, $mailer)
	{
		$this->from = $from;
		$this->mailer = $mailer;

		$this->message = '';
		$this->headers = array(
			'Content-transfer-encoding'	=> '8bit',
			'Content-type'				=> 'text/plain; charset=utf-8',
			'X-Mailer'					=> self::MAILER_TAG,
		);
	}

	public function set_reply_to($reply_to)
	{
		$this->headers['Reply-To'] = self::encode_utf8($reply_to);

		// Allow chaining
		return $this;
	}

	public function set_subject($subject)
	{
		$this->headers['Subject'] = self::encode_utf8($subject);

		// Allow chaining
		return $this;
	}

	public function set_body($message)
	{
		$this->message = $message;

		// Allow chaining
		return $this;
	}

	private function get_message()
	{
		// Change \n and \r linefeeds into \r\n
		$message = preg_replace(array('%(?<!\r)\n%', '%\r(?!\n)%'), "\r\n", $this->message);

		// a single leading . signifies an end of the data, so double any legitimate ones
		$message = str_replace("\r\n.", "\r\n..", $message);
		// A single leading . at the start, so prepend another
		if ($message{0} == '.')
			$message = '.'.$message;

		return $message;
	}

	private function get_headers()
	{
		return array_merge($this->headers, array(
			'Date'	=> gmdate('r'),
		));
	}

	public function send($to, $cc = array(), $bcc = array())
	{
		// Make to, cc, and bcc into arrays if they aren't already
		if (!is_array($to)) $to = array($to);
		if (!is_array($cc)) $cc = array($cc);
		if (!is_array($bcc)) $bcc = array($bcc);

		// Create a list of all recipients
		$recipients = array_merge($to, $cc, $bcc);

		$message = $this->get_message();
		$headers = $this->get_headers();

		// Encode the from as UTF8 if required
		$headers['From'] = self::encode_utf8($this->from);

		// Add the to, cc, and bcc headers - don't encode them since they must be a plain email
		$headers['To'] = implode(', ', $to);
		$headers['Cc'] = implode(', ', $cc);
		$headers['Bcc'] = implode(', ', $bcc);

		// Sanitize the headers (values only, keys are assumed to be legitimate!)
		$headers = array_map(array('Email', 'sanitize_header'), $headers);

		return $this->mailer->send($this->from, $recipients, $message, $headers);
	}
}

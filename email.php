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
	private $mailer;

	public function __construct($from, $mailer)
	{
		$this->mailer = $mailer;

		$this->message = '';
		$this->headers = array(
			'Content-transfer-encoding'	=> '8bit',
			'Content-type'				=> 'text/plain; charset=utf-8',
			'X-Mailer'					=> self::MAILER_TAG,
		);

		if ($from !== null)
			$this->headers['From'] = $from;
	}

	public function set_reply_to($reply_to)
	{
		$this->headers['Reply-To'] = $reply_to;

		// Allow chaining
		return $this;
	}

	public function add_cc($cc)
	{
		if (!isset($this->headers['Cc']))
			$this->headers['Cc'] = array();

		$this->headers['Cc'][] = $cc;

		// Allow chaining
		return $this;
	}

	public function set_subject($subject)
	{
		$this->headers['Subject'] = $subject;

		// Allow chaining
		return $this;
	}

	public function set_body($message)
	{
		$this->message = $message;

		// Allow chaining
		return $this;
	}

	public function get_message()
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

	public function get_headers()
	{
		$headers = array_merge($this->headers, array(
			'Date'	=> gmdate('r'),
		));

		// Encode the subject as UTF8 if required
		if (!empty($headers['Subject']))
			$headers['Subject'] = self::encode_utf8($headers['Subject']);

		// TODO: Handle UTF-8 to/from/cc/reply-to names?

		if (!empty($headers['Cc']))
			$headers['Cc'] = implode(',', $headers['Cc']);

		// Sanitize the headers (values only, keys are assumed to be legitimate!)
		$headers = array_map(array('Email', 'sanitize_header'), $headers);

		return $headers;
	}

	public function send($to)
	{
		return $this->mailer->send($this, $to);
	}
}

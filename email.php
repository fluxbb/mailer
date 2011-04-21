<?php

/**
* Copyright (C) 2011 FluxBB (http://fluxbb.org)
* License: LGPL - GNU Lesser General Public License (http://www.gnu.org/licenses/lgpl.html)
*/

class Email
{
	const MAILER_TAG = 'FluxBB Mailer';
	const ATTACHMENT_LIMIT = 10485760; // 10Mb

	public static function encode_utf8($str)
	{
		// Only encode the string if it does contain UTF8
		if (preg_match('/(?:[^\x00-\x7F])/', $str))
			return '=?UTF-8?B?'.base64_encode($str).'?=';

		return $str;
	}

	public static function linewrap($message)
	{
		return $message; // TODO: linewrap (with utf8 support)
	}

	public static function sanitize_header($value)
	{
		return str_replace(array("\n", "\r"), '', $value);
	}

	public static function create_header_str($headers)
	{
		$str = '';

		// Append the header strings
		foreach ($headers as $key => $value)
			$str .= $key.': '.$value."\r\n";

		return $str;
	}

	public static function get_mime_type($file)
	{
		if (extension_loaded('fileinfo'))
		{
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mime_type = finfo_file($finfo, $file);
			finfo_close($finfo);
		}
		else
			$mime_type = @mime_content_type($file);

		return $mime_type;
	}

	private $message;
	private $headers;
	private $from;
	private $attachments;
	private $mailer;

	public function __construct($from, $mailer)
	{
		$this->from = $from;
		$this->mailer = $mailer;

		$this->message = '';
		$this->attachments = array();
		$this->headers = array(
			'MIME-Version'				=> '1.0',
			'Content-Transfer-Encoding'	=> '8bit',
			'Content-Type'				=> 'text/plain; charset="utf-8"',
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

	public function add_attachment($file)
	{
		if (!is_readable($file))
			throw new Exception('Unable to read file: '.$file);

		$size = filesize($file);
		if ($size > self::ATTACHMENT_LIMIT)
			throw new Exception('File too large: '.$file);

		$this->attachments[] = array(
			'path'		=> $file,
			'name'		=> basename($file),
			'size'		=> $size,
			'mime'		=> self::get_mime_type($file),
		);

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

		return self::linewrap($message);
	}

	private function handle_attachments(&$headers, &$message)
	{
		if (empty($this->attachments))
			return false;

		// Create a unique boundary value
		$boundary = uniqid();

		// Create a new message split into sections with the correct headers and attachments appended
		$message = $this->insert_attachments($headers['Content-Type'], $headers['Content-Transfer-Encoding'], $message, $boundary);
		unset ($headers['Content-Type'], $headers['Content-Transfer-Encoding']);

		// Set the headers to indicate we have multipart data
		$headers['Content-Type'] = 'multipart/mixed; boundary="'.$boundary.'"';
		$headers['Content-Disposition'] = 'inline';

		return $boundary;
	}

	private function insert_attachments($message_type, $message_encoding, $message, $boundary)
	{
		// Create headers for the actual email message
		$headers = array(
			'Content-Type'					=> $message_type,
			'Content-Transfer-Encoding'		=> $message_encoding,
		);

		// Add the actual email message and its headers
		$data  = '--'.$boundary."\r\n";
		$data .= self::create_header_str($headers)."\r\n";
		$data .= $message."\r\n"; // No need to linewrap since it already should be wrapped

		// Add each attachment
		foreach ($this->attachments as $attachment)
		{
			// Fetch the contents of the attachment
			$contents = @file_get_contents($attachment['path']);
			if ($contents === false)
				continue;

			// Base64 encode the attachment so it can be attached
			$contents = base64_encode($contents);

			// Create headers for this attachment
			$headers = array(
				'Content-Type'					=> $attachment['mime'],
				'Content-Transfer-Encoding'		=> 'base64',
				'Content-Disposition'			=> 'attachment; filename="'.$attachment['name'].'"',
			);

			// Add this attachment and its headers
			$data .= '--'.$boundary."\r\n";
			$data .= self::create_header_str($headers)."\r\n";
			$data .= self::linewrap($contents)."\r\n";
		}

		// Terminating boundary
		$data .= '--'.$boundary.'--';

		return $data;
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

		// Create a copy of the headers and add in the current date
		$headers = array_merge($this->headers, array(
			'Date'	=> gmdate('r'),
		));

		// Insert any attachments
		$this->handle_attachments($headers, $message);

		// Add the from address (and encoding as UTF8 if required)
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

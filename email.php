<?php

/**
* Copyright (C) 2011 FluxBB (http://fluxbb.org)
* License: LGPL - GNU Lesser General Public License (http://www.gnu.org/licenses/lgpl.html)
*/

if (!function_exists('utf8_is_ascii'))
	require UTF8.'/utils/ascii.php';

if (!function_exists('utf8_wordwrap'))
	require UTF8.'/functions/wordwrap.php';

class Email
{
	const MAILER_TAG = 'FluxBB Mailer';
	const ATTACHMENT_LIMIT = 10485760; // 10Mb
	const LINE_WIDTH = 72;

	public static function encode_utf8($str)
	{
		// Only encode the string if it does contain UTF8
		if (!utf8_is_ascii($str))
			return '=?UTF-8?B?'.base64_encode($str).'?=';

		return $str;
	}

	public static function sanitize_email($email)
	{
		return filter_var($email, FILTER_SANITIZE_EMAIL);
	}

	public static function decode_address($address)
	{
		$name = null;
		$email = $email;

		if (preg_match('%(.+)\s*<(.+)>$%u', $address, $matches))
		{
			$name = trim($matches[1]);
			$email = $matches[2];
		}

		// Sanitize the email address part
		$email = self::sanitize_email($email);

		return array($name, $email);
	}

	public static function create_header_str($headers)
	{
		// Characters that should not occur in a single header
		$blacklist = array("\n", "\r", "\0");
		$str = '';

		// Append the header strings
		foreach ($headers as $key => $value)
			$str .= str_replace($blacklist, '', $key.': '.$value)."\r\n";

		return $str;
	}

	private static function get_mime_type($file)
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
	private $from_name;
	private $from_address;
	private $attachments;
	private $attachment_size;
	private $mailer;

	public function __construct($from, $mailer)
	{
		list ($this->from_name, $this->from_address) = self::decode_address($from);

		$this->mailer = $mailer;

		$this->message = '';
		$this->attachments = array();
		$this->attachment_size = 0;
		$this->headers = array(
			'MIME-Version'				=> '1.0',
			'Content-Transfer-Encoding'	=> '8bit',
			'Content-Type'				=> 'text/plain; charset="utf-8"',
			'X-Mailer'					=> self::MAILER_TAG,
		);
	}

	public function set_reply_to($reply_to)
	{
		$reply_to = self::sanitize_email($reply_to);
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
		if (!is_readable($file) || is_dir($file))
			throw new Exception('Unable to read file: '.$file);

		$size = filesize($file);
		if (($this->attachment_size + $size) > self::ATTACHMENT_LIMIT)
			throw new Exception('Total attachment size too large.');

		$this->attachment_size += $size;
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

		return utf8_wordwrap($message, self::LINE_WIDTH, "\r\n", true);
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
			$data .= wordwrap($contents, self::LINE_WIDTH, "\r\n", true)."\r\n"; // base64 so no need for utf8 support
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

		// Sanitize the recipients
		$to = array_map(array('self', 'sanitize_email'), $to);
		$cc = array_map(array('self', 'sanitize_email'), $cc);
		$bcc = array_map(array('self', 'sanitize_email'), $bcc);

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
		if ($this->from_name === null)
			$headers['From'] = $this->from_address;
		else
			$headers['From'] = self::encode_utf8($this->from_name.' <'.$this->from_address.'>');

		// Add the to, cc, and bcc headers - don't encode them since they must be a plain email
		if (!empty($to))
			$headers['To'] = implode(', ', $to);

		if (!empty($cc))
			$headers['Cc'] = implode(', ', $cc);

		if (!empty($bcc))
			$headers['Bcc'] = implode(', ', $bcc);

		return $this->mailer->send($this->from_address, $recipients, $message, $headers);
	}
}

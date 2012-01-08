<?php
/**
 * FluxBB
 *
 * LICENSE
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301, USA.
 *
 * @category	FluxBB
 * @package		Flux_Mailer
 * @copyright	Copyright (c) 2011 FluxBB (http://fluxbb.org)
 * @license		http://www.gnu.org/licenses/lgpl.html	GNU Lesser General Public License
 */


if (!function_exists('utf8_is_ascii'))
	require UTF8.'/utils/ascii.php';

if (!function_exists('utf8_wordwrap'))
	require UTF8.'/functions/wordwrap.php';

class Flux_Mailer_Email
{
	const MAILER_TAG = 'FluxBB Mailer';
	const ATTACHMENT_LIMIT = 10485760; // 10Mb
	const LINE_WIDTH = 72;

	public static function encodeUtf8($str)
	{
		// Only encode the string if it does contain UTF8
		if (!utf8_is_ascii($str))
			return '=?UTF-8?B?'.base64_encode($str).'?=';

		return $str;
	}

	public static function sanitizeEmail($email)
	{
		return filter_var($email, FILTER_SANITIZE_EMAIL);
	}

	public static function decodeAddress($address)
	{
		$name = null;
		$email = $address;

		if (preg_match('%(.+)\s*<(.+)>$%u', $address, $matches))
		{
			$name = trim($matches[1]);
			$email = $matches[2];
		}

		// Sanitize the email address part
		$email = self::sanitizeEmail($email);

		return array($name, $email);
	}

	public static function createHeaderStr($headers)
	{
		// Characters that should not occur in a single header
		$blacklist = array("\n", "\r", "\0");
		$str = '';

		// Append the header strings
		foreach ($headers as $key => $value)
			$str .= str_replace($blacklist, '', $key.': '.$value)."\r\n";

		return $str;
	}

	private static function getMimeType($file)
	{
		if (extension_loaded('fileinfo'))
		{
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mimeType = finfo_file($finfo, $file);
			finfo_close($finfo);
		}
		else
			$mimeType = @mime_content_type($file);

		return $mimeType;
	}

	private $message;
	private $headers;
	private $fromName;
	private $fromAddress;
	private $attachments;
	private $attachmentSize;
	private $mailer;

	public function __construct($from, $mailer)
	{
		list ($this->fromName, $this->fromAddress) = self::decodeAddress($from);

		$this->mailer = $mailer;

		$this->message = '';
		$this->attachments = array();
		$this->attachmentSize = 0;
		$this->headers = array(
			'MIME-Version'				=> '1.0',
			'Content-Transfer-Encoding'	=> '8bit',
			'Content-Type'				=> 'text/plain; charset="utf-8"',
			'X-Mailer'					=> self::MAILER_TAG,
		);
	}

	public function setReplyTo($replyTo)
	{
		list ($name, $email) = self::decodeAddress($replyTo);
		if ($name === null)
			$this->headers['Reply-To'] = $email;
		else
			$this->headers['Reply-To'] = '"'.self::encodeUtf8($name).'" <'.$email.'>';

		// Allow chaining
		return $this;
	}

	public function setSubject($subject)
	{
		$this->headers['Subject'] = self::encodeUtf8($subject);

		// Allow chaining
		return $this;
	}

	public function setBody($message)
	{
		$this->message = $message;

		// Allow chaining
		return $this;
	}

	public function appendBody($message)
	{
		$this->message .= $message;

		// Allow chaining
		return $this;
	}

	public function addAttachment($file)
	{
		if (!is_readable($file) || is_dir($file))
			throw new Exception('Unable to read file: '.$file);

		$size = filesize($file);
		if (($this->attachmentSize + $size) > self::ATTACHMENT_LIMIT)
			throw new Exception('Total attachment size too large.');

		$this->attachmentSize += $size;
		$this->attachments[] = array(
			'path'		=> $file,
			'name'		=> basename($file),
			'size'		=> $size,
			'mime'		=> self::getMimeType($file),
		);

		// Allow chaining
		return $this;
	}

	private function getMessage()
	{
		// Change \n and \r linefeeds into \r\n
		$message = preg_replace(array('%(?<!\r)\n%', '%\r(?!\n)%'), "\r\n", $this->message);

		// a single leading . signifies an end of the data, so double any legitimate ones
		$message = str_replace("\r\n.", "\r\n..", $message);
		// A single leading . at the start, so prepend another
		if ($message{0} == '.')
			$message = '.'.$message;

		return utf8_wordwrap($message, self::LINE_WIDTH, "\r\n");
	}

	private function handleAttachments(&$headers, &$message)
	{
		if (empty($this->attachments))
			return false;

		// Create a unique boundary value
		$boundary = uniqid();

		// Create a new message split into sections with the correct headers and attachments appended
		$message = $this->insertAttachments($headers['Content-Type'], $headers['Content-Transfer-Encoding'], $message, $boundary);
		unset ($headers['Content-Type'], $headers['Content-Transfer-Encoding']);

		// Set the headers to indicate we have multipart data
		$headers['Content-Type'] = 'multipart/mixed; boundary="'.$boundary.'"';
		$headers['Content-Disposition'] = 'inline';

		return $boundary;
	}

	private function insertAttachments($messageType, $messageEncoding, $message, $boundary)
	{
		// Create headers for the actual email message
		$headers = array(
			'Content-Type'					=> $messageType,
			'Content-Transfer-Encoding'		=> $messageEncoding,
		);

		// Add the actual email message and its headers
		$data  = '--'.$boundary."\r\n";
		$data .= self::createHeaderStr($headers)."\r\n";
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
			$data .= self::createHeaderStr($headers)."\r\n";
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
		$to = array_map(array('self', 'sanitizeEmail'), $to);
		$cc = array_map(array('self', 'sanitizeEmail'), $cc);
		$bcc = array_map(array('self', 'sanitizeEmail'), $bcc);

		// Create a list of all recipients
		$recipients = array_merge($to, $cc, $bcc);

		$message = $this->getMessage();

		// Create a copy of the headers and add in the current date
		$headers = array_merge($this->headers, array(
			'Date'	=> gmdate('r'),
		));

		// Insert any attachments
		$this->handleAttachments($headers, $message);

		// Add the from address (and encoding as UTF8 if required)
		if ($this->fromName === null)
			$headers['From'] = $this->fromAddress;
		else
			$headers['From'] = '"'.self::encodeUtf8($this->fromName).'" <'.$this->fromAddress.'>';

		// Add the to, cc, and bcc headers - don't encode them since they must be a plain email
		if (!empty($to))
			$headers['To'] = implode(', ', $to);

		if (!empty($cc))
			$headers['Cc'] = implode(', ', $cc);

		if (!empty($bcc))
			$headers['Bcc'] = implode(', ', $bcc);

		return $this->mailer->send($this->fromAddress, $recipients, $message, $headers);
	}
}

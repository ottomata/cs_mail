<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
 * CSMail is the preferred way to send emails out to CouchSurfing users.
 * It abstracts the logic for determination of desired user content type, 
 * locale translation, and bulk & batch sending.  As a developer using this
 * library, all you need to do is create HTML and TEXT Views for your email,
 * instantiate a CSMail object, set the subject, sender and recipients 
 * (User_Models), and send.  CSMail will handle everything else.  
 * 
 * CSMail might try to send the email right away using the csemail helper 
 * (Swift -> postfix), or it might insert into the classic CS Email Spooler
 * system.  For more information about the classic Spooler System, see
 * http://tech.couchsurfing.org/index.php/Spool_System and public/cs/lib/spool.lib
 * 
 * 
 * Both an html view and the text view must be passed into the constructor.
 * This will ensure that we support and honor those users who chose not to receive
 * html emails, and that we always ALSO have a nicely formatted text email for
 * every email that we send.
 * 
 * Usage:
 * 	$email = new CSMail('path/to/text/view.text', 'path/to/html/view.html', $data);
 * 	$email->from_user	= new User_Model(1);
 * 	$email->to_users	= array(new User_Model(7), new User_Model(99));
 * 	$email->subject		= 'This is gonna be a great email';
 * 	$email->view_var1	= 'Cool!';
 * 	$email->view_var2	= 123456;
 * 	$email->priority	= CSMail::PRIORITY_HIGH
 *  $email->honor_user_privacy_setting	= false;	// forces CSMail to send this email regardless of the user.privacy_no_send_any_email setting
 *  $email->send_to_deleted_users		= true;		// forces CSMail to send emails to users even if their accounts are 'deleted'
 * 	$email->send();
 * 
 * 
 * 
 * NOTE: You may also set to_users to an array of user_ids:
 *  $email->to_users = array(7,99);
 * 
 * NOTE:	from_user, to_users, and subject are required variables.  
 * 			They must be set before calling the send() method.
 * 			If they are not, an exception will be thrown when
 * 			send() is called.  You may set them by passing them 
 * 			as key => values in the $data array to the constructor, 
 * 			or explicitly as shown above.
 *
 * NOTE:    Originally, this library could only handle sending
 *          to one user at time.  This was done by setting the
 *          'to_user' property.  This property has been deprecated,
 *          but there may be locations in the code that still use
 *          this property.  See the __get() and __set() methods
 *          for how backwards compatibility is maintained.
 * 
 * TODO:    Instead of storing a local array of to_users, use an ORM iterator.
 * 
 * @package    CSKohana
 * @copyright  (c) 2010 CouchSurfing
 */
class CSMail 
{
	/**
	 * CSMail email priority levels.  These were ported over
	 * from the classic spool.lib mailer system, and generally
	 * don't apply to emails we send via Kohana.
	 */
	
	/**
	 * High priority emails are sent immediately.
	 * @const integer 
	 */
	const PRIORITY_HIGH   = 0;
	
	/**
	 * Normal priority emails are most likely sent immediately.
	 * @const integer 
	 */
	const PRIORITY_NORMAL = 1;
	
	/**
	 * Low priority emails are most likely spooled for later sending.
	 * @const integer
	 */
	const PRIORITY_LOW    = 2;
	
	
	
	/**
	 * Current CSMail email priority level.  Default is CSMail::PRIORITY_NORMAL
	 * @var integer 
	 */
	public $priority = CSMail::PRIORITY_NORMAL;
	
   	/**  
     * The number of characters that we will word wrap all email bodies at.
     * SMTP has a max line length limit of 1000 chars, and postfix
     * will forcefully insert a line break if a line goes over this number of
     * characters.  We want to manually word-wrap our emails before they get to 
     * postfix so this won't happen.
     * @const integer
     */ 
	const SMTP_MAX_LINE_LENGTH = 900;
 	 

   /*
    * these are paths to the email layout templates.
    * the text and html view paths that are passed 
    * in the constructor will be rendered inside of 
    * the respective email layout.  If you would
    * like to use a different layout, you may change
    * these after instantiating your CSMail class.
    *   e.g   $csmail->html_template_view = 'email/communications_layout.html';
    */ 
	
	/**
	 * Relative view path to the default html email template.
	 * @var string
	 */
	public $html_template_view = 'email/default_template.html';
	/**
	 * Relative view path to the default text email template.
	 * @var string
	 */
	public $text_template_view = 'email/default_template.text';
	
	/**
	 * If false, then a to_user will be sent this email
	 * even if privacy_no_send_any_emails is set to 'Y'.
	 * Default is true.
	 * 
	 * @var boolean
	 */
	public $honor_user_privacy_setting = true;
	
	/**
	 * By default this is false.  If set to true, then 
	 * CSMail will not filter out deleted users from the 
	 * list of its recipients. 
	 * 
	 * @var boolean 
	 */
	public $should_send_to_deleted_users = false;

	
	/**
	 * The $data array will be passed to each view for rendering.
	 * Each entry in this array will be available to the view as
	 * local variables.
	 * 
	 * @var array
	 */
	protected 	$data;
	
	// Views for email content.  The names of these views
	// are provided to the constructor.
	
	/**
	 * Relative view path to the html email view, 
	 * not including a surrounding template.
	 * This is the html body of the email.
	 * @var string
	 */
	protected	$html_content_view;		
	
	/**
	 * Relative view path to the text email view,
	 * not including a surrounding template.
	 * This is the text body of the email.
	 * @var string
	 */
	protected   $text_content_view;
	
	/**
	 * Array of recipient User_Models.
	 * @var array
	 */
	public $to_users;
	
	/**
	 * Sender User_Model.
	 * @var User_Model
	 */
	public $from_user;
	
	
	/**
	 * Creates a new CSMail instance
	 * 
	 * @param string	$html_view_name	The path to the html view. 
	 * @param string	$text_view_name	The path to the text view.
	 * 
	 * @param array 	$data			data array to be passed to views.
	 */
	public function __construct($html_view_name, $text_view_name, $data = null) 
	{	
		// instantiate the text and html Views from the provided view paths.
		$this->html_content_view = new View($html_view_name);
		$this->text_content_view = new View($text_view_name);

		if (isset($data)) 
		{
			$this->data = $data;
		}
	}
	

	
	/**
	 * Renders and sends the email.  Before calling send(), make sure you have set
	 * from_user, to_users, and subject.
	 * 
	 * @return integer      number of users successfully sent to, false on failure
	 * @throws InvalidArgumentExpcetion    if none of the required variables are set properly.
	 */
	public function send() 
	{
		// Make sure the required variables are set, throw exception if they are not
		if (!isset($this->to_users) || (!is_array($this->to_users) && get_class($this->to_users) != 'ORM_Iterator') )
		{
			throw new InvalidArgumentException("The CSMail to_users property must be set to an array of real Users real before calling CSMail send()");
		}
		if (!isset($this->from_user) || get_class($this->from_user) != 'User_Model' || $this->from_user->user_id == 0) 
		{
			throw new InvalidArgumentException("The CSMail from_user must be set to a real User before calling CSMail send()");
		}
		if (!isset($this->subject) || !is_string($this->subject)) 
		{
			throw new InvalidArgumentException("The CSMail subject must be set to a string before calling CSMail send()");
		}
		
		// separate the to_users into groups of individual sendable buik emails.
		// The resulting array is keyed by first by content type, and
		// then by locale.  This effectively groups emails by distinct
		// bodies, allowing us to send the minimum number of emails.
		$grouped_to_users =  $this->separate_to_users_into_groups();
		
		// set the from_user in the data array so the views have
		// access to it if they want.
		$this->data['from_user'] = $this->from_user;
		
		// set short_subject based on the real subject
		// TODO:  Should subject(s) be translated?  
		$this->short_subject = preg_replace('/\[.*\]/', '', $this->subject);
		
		// Give the view
		$this->text_content_view->set($this->data);
		$this->html_content_view->set($this->data);
		
		$sent_count = 0;  // keep track of the total recipients that were emailed
		
		// loop through each of the grouped_to_users and 
		// send an email for each content type / locale group
		foreach ($grouped_to_users as $content_type => $locales) 
		{
			// loop through each of the locales for this content type
			foreach ($locales as $locale => $to_users) 
			{			
				
				// set the to_users in the data array so that
				// the views can have access to them if they want.
				$this->data['to_users'] = $to_users;
				// The date format locale in email body views will be taken from the result of the
				// CS_Date_Time::get_date_format_by_locale() function.

				// if we are only sending an email to ONE user, then it is trivial to
				// look up this users' preferred date format.
				// else, just use this locale's default date format.
				$user_date_time = (count($to_users) == 1) ? $to_users[0]->get_date_time_object() : new CS_Date_Time( array( 'date_format_locale' => $locale ) );
				
				// Render email bodies.
				// The $bodies array here will contain text (and possibly html) 
				// email bodies for immediate sending.  
				$bodies = $this->build_email_bodies($content_type, $locale, $user_date_time);
				
				// if this email should be sent out now, then pass it off to
				// the csemail helper for sending.
				if ($this->should_send_immediately($this->priority)) 
				{
					$result = $this->send_email($to_users, $bodies);
					// YAY! all went well!  Continue processing the next email
					if ($result)
					{
						$sent_count += $result;
									
						$log_message = "Sent $content_type email '" . $this->short_subject . "' to $result $locale recipients.";
						// if we sent to fewer users than we expected, then log this too.
						if ($result < count($to_users)) 
						{
							$log_message .= " Attempted to send to " . count($to_users) . '.';
						}
						logger::log('info', $log_message);
						
						// continue here so we move on to the next email.
						// if we don't continue, then this email would
						// also be saved into the classic CS Spooler System.  
						// This should only happen if this sending here fails, or if
						// the email should not send immediately (due to being low priority).
						continue;
					}
						
					// log a warning message and insert the email into the
					// CS Email Spooler system
					else
					{	
						logger::log('warning', "Failed sending $content_type email '" . $this->short_subject . "' to " . count($to_users) . " email addresses immediately.  Attempting to insert into spool system...");
					}
				}


				
				// We will only reach this point in the code if the above $this->send_email()
				// failed, or if we are supposed to send this email thorugh
				// the spooler system. Now save this email into the spool_temp table.	
				
				// $bodies are keyed by $mime_type (text/html), not $content_type (html).
				// Get the $mime_type from the 'content_type'.
				$mime_type = array_shift(Kohana::config('mimes.'.$content_type));	
				
				// Insert the email into the classic spooler system.
				$result = $this->spool_email($to_users, $bodies[$mime_type]);
				if ($result === false)
				{
					logger::log('error', "Could not spool email '" . $this->short_subject . "' to " . count($to_users) . " recipients.");
				}
				
				// log the result
				if ($result)
				{
					$sent_count += $result;
					
					$log_message = "Saved $content_type email '" . $this->short_subject . "' into the spool system for $result $locale recipients.";
					if ($result < count($to_users)) 
					{
						$log_message .= " Attempted to save spool recipients for " . count($to_users) . '.';
					}
					logger::log('info', $log_message);
				}
				else	
				{
					logger::log('error', "Failed saving $content_type email '" . $this->short_subject . "' for " . count($to_users) . " $locale recipients into the spool system.");
				}	
			}
		}
		
		logger::log('info', "Sent email to $sent_count users");
		return $sent_count;
	}
	
	
	/**
	 * Sends an email via the csemail helper.
	 * $to_users should be an array of User_Models, and $bodies
	 * Should be an array of email bodies keyed by mime type suitable for
	 * passing to csemail::send()
	 * 
	 * The group of to_users passed here will be chunked into
	 * batch sizes (defined in config/csmail.php) and passed to the
	 * csemail helper.
	 * 
	 * @param	array 	$to_users
	 * @param	array 	$bodies
	 * 
	 * @return 	integer sent count, false on first failure
	 */
	protected function send_email($to_users, $bodies)
	{
		// The default from email for all CSMails is configured in the
		// config/csmail.php config file.  The csemail helper (and Swift)
		// will take an array of email => name when setting up senders
		// or recipients.
		$from_email = Kohana::config('csmail.default_sender_email');
		$from_name  = Kohana::config('csmail.default_sender_name');
		
		
		// csemail (and Swift) can take an array of (email => name) key/pairs.
		$from = array($from_email => $from_name);
		$recipients   = $this->build_recipient_list($to_users);
		
		// Only send batch_size emails at once.  Loop through 
		// each batch and send out one email for each batch.
		$recipient_batches = array_chunk($recipients, Kohana::config('csmail.batch_size'), true);
		
		$success = false;
		
		$sent_count = 0;
		foreach ($recipient_batches as $recipients)
		{
			// send the email with the csemail helper
			$result = csemail::send($from, $recipients, $this->subject, $bodies);
			
			// if we ever succeed with any batch, then go ahead and set success to true.
			// We might fail every batch except one this way, but at least
			// we won't send out duplicate emails.
			if ($result) 
			{
				$sent_count += $result;
				$success = true;
			}
			else 
			{
				// This csemail::send() call failed, AND we have not yet successfully completed any recipient batches
				// for this email yet, then go ahead and return false now.  This means that no emails
				// have yet been sent, so we can safely assume that we will not be sending duplicates
				// if the calling code tries again later.
				if (!$success) 
				{
					logger::log('error', "Could not send email. csemail::send() to " . count($to) . " recipients failed.");
					return false;
				}
				// else, this particular mail() call for a recipient batch failed, but other
				// batches for this email succeeded.  Not much we can do here
				// other than log a message about it.
				else 
				{
					logger::log('error', "csemail::send() failed, but other calls to csemail::send() for this email batch have succeeded.  Considering this a success so we don't inadvertently send multiple email duplicates.");
				}
			}			
		}

		return $sent_count;
	}
	
	
	
	/**
	 * Saves a spool_temp record.  If this is called, then this
	 * email will be picked up by the classic CS Email Spooler System and 
	 * sent out later.  If there are multiple recipients,
	 * a corresponding spool_recipient_temp record will be created
	 * for each one.
	 * 
	 * @param array $to_users   User_Model recipients
	 * @param string $body      rendered email body text
	 * 
	 * @return integer          number of user email spooled, or false on failure.
	 */
	protected function spool_email($to_users, $body)
	{
		// build an array of recipients from the to_user models.
		// This will be passed to the Spool_temp_Model::create_spool method.
		$recipients = array();
		// loop through each to_user and get user_id and email, and email format
		foreach ($to_users as $to_user) 
		{
			$recipients[]= array(
				'user_id'      => $to_user->user_id,
				'email'        => $to_user->email,
				'email_format' => $to_user->email_format,
			);
		}
		
		// create a spool_temp record.
		$spool_temp = Spool_temp_Model::create_spool(
			$this->from_user->user_id,
			Kohana::config('csmail.default_sender_email'),  
			$recipients, 
			$this->subject,
			$body,
			$this->priority		
		);
		
		if ($spool_temp->spool_id)
		{
			return count($to_users);
		}
		else
		{
			return false;
		}
	}
	


	
	/**
	 * Creates an email body from the views based
	 * on the passed in content type and locale
	 * 
	 * @param string $content_type     either 'html' or 'text'
	 * @param CS_Date_Time $user_date_time   a CS_Date_Time object that will be passed to the views.
	 * @return array    Array of rendered email bodies, each keyed by mime-type.
	 */
	protected function build_email_bodies($content_type, $locale, $user_date_time)
	{	
		// Make sure the required variables are set, throw exception if they are not
		if ($content_type != 'html' && $content_type != 'text') 
		{
			throw new InvalidArgumentException('Failed creating email body.  content_type must be either "html" or "text": "' . $content_type . "'");
		}
		
		/*
		 * NOTE:  This will possibly result in users recieving emails
		 * that are not rendered in their prefered date format. 
		 * We currently allow users to specify different locale preferences
		 * for the language local and the date format locale.
		 * If we want to continue to maintain this and to respect 
		 * the user's date format preference in emails, we will have to 
		 * also use separate_to_users_into_groups() to create
		 * another group of email bodies.  This would require 
		 * using separate_to_users_into_groups() to build an array of users like this:
		 *    array(
		 * 		'html' => array(
		 * 			'en_US' => array(
		 *    			'date_format_locale_A' => array($user_model, $user_model ...),
		 *    			'date_format_locale_B' => array($user_model, $user_model ...)
		 * 			)
		 * 			'es_ES' => array(
		 *    			'date_format_locale_A' => array($user_model, $user_model ...),
		 *    			'date_format_locale_C' => array($user_model, $user_model ...)
		 * 			)
		 * 		)
		 * 		'text' => array(
		 * 			'en_US' => array(
		 *    			'date_format_locale_D' => array($user_model, $user_model ...),
		 *    			'date_format_locale_B' => array($user_model, $user_model ...)
		 * 			)
		 * 			'es_ES' => array(
		 *    			'date_format_locale_F' => array($user_model, $user_model ...),
		 *    			'date_format_locale_E' => array($user_model, $user_model ...)
		 * 			)
		 * 		)
		 * 	)
		 * 
		 * which is way more complicated than things need to be.
		 * for now, CSMail only respects html vs. text and main language locale preferences.
		 */

		// Change the global locale while we render these bodies.
		// we will reset it back to the currently online user's locale
		// once the bodies are rendered.
		// There should be a better way to run i18n translations
		// without having to change the global current locale setting,
		// but alas, there is not.
		$online_user_locale = cslang::get_user_locale(); 
		cslang::force_current_locale( $locale ); 
		
		// contents will contain the rendered email view, not including
		// the rendered template_view
		$contents = array();
		// only render the html content view if we are sending an html email
		if ($content_type == 'html')
		{
			// Initialize the view variables
			$this->html_content_view->set($this->data);

			// Render the html content.
			$contents['html'] = $this->html_content_view->render();
		}
		// always render the text content view
		// Initialize the view variables.
		$this->text_content_view->set($this->data);
		
		// Set the user_date_time the view will use so that dates are translated correctly:
		$this->text_content_view->set('date_time', $user_date_time);
		
		// render the text content.
		$contents['text'] = $this->text_content_view->render();


		// build an array of final email bodies for each mime multipart.
		// these wil be passed to the csemail helper for Swift mailing.
		// $bodies contains the fully rendered email, including the 
		// content view inside of the default layout.
		$bodies = array();

		// Loop through each of the email $contents that we have
		// rendered. For each one, pass the $content to the layout
		// view as a view variable.
		foreach ($contents as $format => $content) 
		{	
			// Create a new view for this email format.

			// if the format is html, then use the html template
			if ($format == 'html') 
			{
				$email_view = new View($this->html_template_view, $this->data);
			}
			// else if the format is text, then use the text template.
			elseif ($format == 'text')
			{
				$email_view = new View($this->text_template_view, $this->data);
			}
			
			// set the view's user_date_time object so that it can 
			// render dates in the proper format.
			$email_view->_user_date_time = $user_date_time;

			// Set the view $content variable of this email layout template 
			// to the previously rendered $content string.  This is used 
			// to insert the body view content into the template.
			$email_view->content = $content;

			// Now render the view into the $bodies array.  
			// $bodies should be keyed by Mime Type and will be passed to 
			// csemail::send() (and Swift).

			// Get the Mime Type from config/mimes.php.
			$mime_type = array_shift(Kohana::config('mimes.'.$format));
			
			// wordwrap the final rendered email body to SMTP_MAX_LINE_LENGTH
			// and save it in the $bodies array. 
			$bodies[$mime_type] = wordwrap($email_view->render(), self::SMTP_MAX_LINE_LENGTH);
		}
		
		// now that we are done rendering the email body views, 
		// set the global local back to the currently logged in user's locale.
		cslang::force_current_locale( $online_user_locale ); 
		
		// return $bodies we just rendered
		return $bodies;
	}
	
	/**
	 * Takes an array of User_Models
	 * and returns an array of email => name pairs 
	 * suitable for passing to csemail::send().
	 * 
	 * @return array
	 */
	protected function build_recipient_list($to_users)
	{
		$recipient_list = array();
		foreach ($to_users as $to_user) {
			$recipient_list[$to_user->email]= $to_user->get_name();
		}
		return $recipient_list;
	}
	
	
	/**
	 * Return array of this structure:
	 * 
	 * array(
	 * 		'html' => array(
	 * 			'en_US' => array($user_model, $user_model ...)
	 * 			'es_ES' => array($user_model, $user_model ...)
	 * 		)
	 * 		'text' => array(
	 *			'en_US' => array($user_model, $user_model ...)
	 * 			'es_ES' => array($user_model, $user_model ...)
	 * 		)
	 * )
	 * 
	 * This array effectively groups all of the to_users into
	 * arrays of bulk-sendable emails.  These are first grouped by
	 * content type, and then by language locale.  The send() method
	 * uses this to figure out which users to render which email for.
	 * 
	 * @return  array
	 * @throws  InvalidArgumentException if any elements in the to_users array are not User_Models
	 */
	protected function separate_to_users_into_groups()
	{
		// DEVELOPMENT and ALPHA environments are not allowed to
		// send emails to real users.   Reassign the to_users array
		// to something appropriate based on the environment.
		if (!CSEnvironment::is(CSEnvironment::BETA | CSEnvironment::PRODUCTION))
		{
			$this->reassign_to_users();
		}
		
			
		// loop through each of the to_users and separate them all out
		// into arrays of sendable groups.
		$grouped_to_users = array();
		foreach ($this->to_users as $to_user) 
		{
			// user_ids are allowed.  
			if (is_int($to_user))
			{
				$to_user = new User_Model($to_user);
			}
			
			// Make sure we are working with a real User
			if (get_class($to_user) != 'User_Model') 
			{
				throw new InvalidArgumentException("All items in the CSMail to_users array must be User_Models, passed in a " . get_class($to_user));
			}
			
			
			
			// if the user is deleted and we are not supposed to send email
			// to deleted users, then skip this user
			if ($this->should_send_to_deleted_users == FALSE && $to_user->is_deleted == 'Y')
			{
				continue;
			}
			// Make sure the to_user actually wants to receive email.
			// If they don't, then just skip this to_user.
			if ($this->honor_user_privacy_setting && $to_user->privacy_no_send_any_emails == 'Y') 
			{
				continue;
			}
			
			
			
			// by default send an html email.
			// this is just in case the user does not have a valid email_format
			// set.  This might never happen, but just in case...
			$email_format = 'html';
			if ($to_user->email_format == 'text')
				$email_format = 'text';
			else if ($to_user->email_format != 'html')
				logger::log('warning', 'User ' . $to_user->user_id . ' has an invalid email_format "' . $to_user->email_format . '.  Defaulting to html."');

			// if we are using a fake User here (in the case of development environments)
			// then set locale to en_US.
			$to_user_locale = cslang::get_user_locale($to_user->user_id);
			
			// save this to_user in the $separated_to_users array
			$grouped_to_users[$email_format][$to_user_locale][]= $to_user;
		}
		
		return $grouped_to_users;
	}
	
	
	
	/**
	 * Using CSMail in the alpha environment is a very special case.
	 * We never want to send out real emails to the intended users in alpha.
	 * This function checks to see if we are running in ALPHA, and if we are
	 * it modifies the to_users array so that it will only contain the
	 * currently logged in user.  This will force any emails initiated in
	 * ALPHA to be sent to the logged in user.
	 * 
	 * @throws Exception if the environment is ALPHA and a user is not logged in.
	 */
	protected function reassign_to_users()
	{		
		// if attempting to send an email in ALPHA,
		if (CSEnvironment::is(CSEnvironment::ALPHA))
		{
			// loop through each of the to_users,
			// and only keep ones that are to couchsurfing.org (or .com)
			// addresses.  
			foreach ($this->to_users as $index => $to_user) 
			{
				if (strpos($to_user->email, '@couchsurfing.') === false)
				{
					unset($this->to_users[$index]);
				}
			}

			// if a user is logged in, then add this user to the list of to_users
			if ($logged_in_user = cshelper::get_current_user())
			{
				$this->to_users[]= $logged_in_user;
			}
			
			// if there are no to_users to sent to, then throw an Exception.
			if (empty($this->to_users))
			{
				throw new Exception("Could not determine an email recipient in ALPHA environment.");		
			}
		}	
	}
	
	
	
	
	/**
	 * Returns true if the $priority should be sent
	 * immediately through SMTP (Swift).
	 * @param integer $priority
	 * @return boolean
	 */
	public static function should_send_immediately($priority) 
	{
		// priorities in this csmail.send_immediate_priorities array will 
		// be sent through SMTP immediately, bypassing 
		// the spooler system.  This is configured in the config/csmail.php
		// config file.
		return in_array($priority, Kohana::config('csmail.send_immediate_priorities'));
	}



	/**
	 * Sets a value in the $data array
	 * @param string $name
	 * @param mixed $value
	 */
	public function __set($name, $value) 
	{
		// For backwards compatibility.  The to_user property used to be used
		// when CSMail was only able to send to one user at a time.  It now
		// uses the 'to_users' array to store its recipient User_Models.
		// if we are setting the to_user property (this is probably used in 
		// the couchmanager module), then go ahead and set to to_users 
		// to be an array containing only this value.  
		if ($name === 'to_user')
		{
			// log a message warning the developer that this property has been
			logger::log('debug', 'The "to_user" property on CSMail is deprecated.  Please use the "to_users" array property instead');
			$this->to_users = array($value);
		}
		
		
		return $this->data[$name] = $value;
	}
	
	/**
	 *  Gets a value from the $data array
	 * @param string $name
	 */
	public function __get($name) 
	{
		// if we are getting the to_user property, then we need to 
		// retrieve something from the to_users array to maintain backwards
		// compatibility.  Return the first element in the to_users property
		// if it exists.
		if ($name === 'to_user' && array_key_exists(0, $this->to_users))
		{
			logger::log('debug', 'The "to_user" property on CSMail is deprecated.  Please use the "to_users" array property instead');
			return $this->to_users[0];
		}

		
		if (array_key_exists($name, $this->data))
		{
			return $this->data[$name];
		}
		else
		{
			return null;
		}
	}
	
	/**
	 * Checks if  data is set.
	 *
	 * @param   string   $name
	 * @return  boolean
	 */
	public function __isset($name)
	{
		return isset($this->data[$name]);
	}
	
	/**
	 * Unsets  data.
	 *
	 * @param   string   $name
	 * @return  void
	 */
	public function __unset($name)
	{
		unset($this->data[$name]);
	}
}

<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

if ( ! class_exists( 'PHPMailer', false ) ) {
    require_once ABSPATH . WPINC . '/class-phpmailer.php';
}

add_action('plugins_loaded', function() {
    global $phpmailer;

    $phpmailer = new PostsmtpMailer(true);
});

class PostsmtpMailer extends PHPMailer {

    private $options;

    private $error;

    private $transcript = '';

    public function __construct($exceptions = null)
    {
        parent::__construct($exceptions);

        $this->set_vars();
        $this->hooks();

    }

    public function set_vars() {
        $this->options = PostmanOptions::getInstance();
        $this->Debugoutput = function($str, $level) {
            $this->transcript .= $str;
        };
    }

    public function hooks() {
        if ( $this->options->getTransportType() == 'smtp' ) {
            add_action( 'phpmailer_init', array( $this, 'phpmailer_smtp_init' ) );
        }
    }

    /**
     * @param PHPMailer $mail
     */
    public function phpmailer_smtp_init($mail) {
        $mail->SMTPDebug = 3;
        $mail->isSMTP();
        $mail->Host = $this->options->getHostname();

        if ( $this->options->getAuthenticationType() !== 'none' ) {
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->options->getUsername();
            $mail->Password   = $this->options->getPassword();
        }

        if ( $this->options->getEncryptionType() !== 'none' ) {
            $mail->SMTPSecure = $this->options->getEncryptionType();
        }

        $mail->Port = $this->options->getPort();

        if ( $this->options->isPluginSenderEmailEnforced() ) {
            $mail->setFrom( $this->options->getMessageSenderEmail() , $this->options->getMessageSenderName () );
        }
    }

    public function send()
    {
        require_once dirname(__DIR__) . '/PostmanWpMail.php';

        // create a PostmanWpMail instance
        $postmanWpMail = new PostmanWpMail();
        $postmanWpMail->init();

        $backtrace = debug_backtrace();

        list($to, $subject, $body, $headers, $attachments) = array_pad( $backtrace[1]['args'], 5, null );

        // build the message
        $postmanMessage = $postmanWpMail->processWpMailCall( $to, $subject, $body, $headers, $attachments );

        // build the email log entry
        $log = new PostmanEmailLog();
        $log->originalTo = $to;
        $log->originalSubject = $subject;
        $log->originalMessage = $body;
        $log->originalHeaders = $headers;

        // get the transport and create the transportConfig and engine
        $transport = PostmanTransportRegistry::getInstance()->getActiveTransport();

        add_filter( 'postman_wp_mail_result', [ $this, 'postman_wp_mail_result' ] );

        try {

            if ( $send_email = apply_filters( 'post_smtp_do_send_email', true ) ) {
                $result = $this->options->getTransportType() !== 'smtp' ?
                    $postmanWpMail->send( $to, $subject, $body, $headers, $attachments ) :
                    $this->sendSmtp();
            }


            do_action( 'post_smtp_on_success', $log, $postmanMessage, $this->transcript, $transport );

            return $result;

        } catch (phpmailerException $exc) {

            $this->error = $exc;

            $this->mailHeader = '';

            do_action( 'post_smtp_on_failed', $log, $postmanMessage, $this->transcript, $transport, $exc->getMessage() );

            $this->setError($exc->getMessage());
            if ($this->exceptions) {
                throw $exc;
            }
            return false;
        }

    }

    public function sendSmtp() {
        if (!$this->preSend()) {
            return false;
        }
        return $this->postSend();
    }


    public  function postman_wp_mail_result() {
        $result = [
            'time' => '',
            'exception' => $this->error,
            'transcript' => $this->transcript,
        ];
        return $result;
    }
}
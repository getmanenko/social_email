<?php
/**
 * Created by Vitaly Iegorov <egorov@samsonos.com>
 * on 18.04.14 at 14:06
 */
namespace samson\social;

/**
 * Generic class for user registration and authorization via Email
 * @author Vitaly Egorov <egorov@samsonos.com>
 * @copyright 2014 SamsonOS
 * @version 0.0.2
 */
class Email extends Core
{
    /* Module identifier */
    public $id = 'socialemail';

    /** Database hashed email column name */
    public $dbHashEmailField = 'hash_email';

    /** Database hashed password column name */
    public $dbHashPasswordField = 'hash_password';

    /* Database user email field */
    public $dbConfirmField = 'hash_confirm';

    /**
     * External callable register handler
     * @var callback
     */
    public $registerHandler;

    /**
     * External callable confirm handler
     * @var callback
     */
    public $confirmHandler;

    /**
     * External callable authorize handler
     * @var callback
     */
    public $authorizeHandler;

    /** Module preparation */
    public function prepare()
    {
        // Create and check general database table fields configuration
        db()->createField($this, $this->dbTable, 'dbConfirmField', 'VARCHAR('.self::$hashLength.')');
        db()->createField($this, $this->dbTable, 'dbHashEmailField', 'VARCHAR('.self::$hashLength.')');
        db()->createField($this, $this->dbTable, 'dbHashPasswordField', 'VARCHAR('.self::$hashLength.')');

        return parent::prepare();
    }

    /**
     * Authorize user via email
     * @param string $hashedEmail       Hashed user email
     * @param string $hashedPassword    Hashed user password
     * @param mixed  $user              Variable to return created user object
     *
     * @return int EmailStatus value
     */
    public function authorize($hashedEmail, $hashedPassword, & $user = null)
    {
        // Status code
        $status = false;

        // Check if this email is registered
        if (!dbQuery($this->dbTable)->cond($this->dbHashEmailField, $hashedEmail)->first($user)) {
            // Check if passwords match
            if ($user[$this->dbHashPasswordField] === $hashedPassword) {
                $status = EmailStatus::SUCCESS_EMAIL_AUTHORIZE;
            } else { // Wrong password
                $status = EmailStatus::ERROR_EMAIL_AUTHORIZE_WRONGPWD;
            }
        } else { // Email not found
            $status = EmailStatus::ERROR_EMAIL_AUTHORIZE_NOTFOUND;
        }

        // Call external authorize handler if present
        if (is_callable($this->authorizeHandler)) {
            // Call external handler - if it fails - return false
            if (!call_user_func_array($this->authorizeHandler, array(&$user))) {
                $status = EmailStatus::ERROR_EMAIL_AUTHORIZE_HANDLER;
            }
        }

        return $status;
    }

    /**
     * Register new user
     *
     * @param string $email          User email address
     * @param string $hashedPassword User hashed password string
     * @param mixed  $user           Variable to return created user object
     * @param bool   $valid          Flag that email is already confirmed
     *
     * @return int EmailStatus value
     */
    public function register($email, $hashedPassword = null, & $user = null, $valid = false)
    {
        // Status code
        $status = false;

        // Check if this email is not already registered
        if (!dbQuery($this->dbTable)->cond($this->dbEmailField, $email)->first($user)) {

            // Create empty db record instance
            /**@var $user \samson\activerecord\dbRecord */
            $user = new $this->dbTable(false);
            $user[$this->dbEmailField]          = $email;
            $user[$this->dbHashEmailField]      = $this->hash($email);

            // If password is passed
            if (isset($hashedPassword)) {
                $user[$this->dbHashPasswordField] = $hashedPassword;
            } else { // Generate random password
                $user[$this->dbHashPasswordField] = $this->generatePassword();
            }

            // If this email is not valid or confirmed
            if (!$valid) {
                $user[$this->dbConfirmField] = $this->hash($email.time());
            } else { // Email is already confirmed
                $user[$this->dbConfirmField] = 1;
            }

            // Save object to database
            $user->save();

            // Everything is OK
            $status = EmailStatus::SUCCESS_EMAIL_REGISTERED;

        } else { // Email not found
            $status =  EmailStatus::ERROR_EMAIL_REGISTER_FOUND;
        }

        // Call external register handler if present
        if (is_callable($this->registerHandler)) {
            // Call external handler - if it fails - return false
            if (!call_user_func_array($this->registerHandler, array(&$user, $status))) {
                $status = EmailStatus::ERROR_EMAIL_REGISTER_HANDLER;
            }
        }

        return $status;
    }

    /**
     * Generic email confirmation handler
     * @param string $hashedEmail   Hashed user email
     * @param string $hashedCode    Hashed user email confirmation code
     * @param mixed $user           Variable to return created user object
     *
     * @return int EmailStatus value
     */
    public function confirm($hashedEmail, $hashedCode, & $user = null)
    {
        // Status code
        $status = false;

        // Find user record by hashed email
        if(dbQuery($this->dbTable)->cond($this->dbEmailField, $hashedEmail)->first($user)) {

            // If this email is confirmed
            if($user[$this->dbConfirmField] == 1) {
                $status = EmailStatus::SUCCESS_EMAIL_CONFIRMED_ALREADY;
            } else if ($user[$this->dbConfirmField] === $hashedCode) {
                // If user confirmation codes matches

                // Set db data that this email is confirmed
                $user[$this->dbConfirmField] = 1;
                $user->save();

                // Everything is OK
                $status = EmailStatus::SUCCESS_EMAIL_CONFIRMED;
            }
        } else {
            $status = EmailStatus::ERROR_EMAIL_CONFIRM_NOTFOUND;
        }

        // Call external confirm handler if present
        if (is_callable($this->confirmHandler)) {
            // Call external handler - if it fails - return false
            if (!call_user_func_array($this->confirmHandler, array(&$user, $status))) {
                $status = EmailStatus::ERROR_EMAIL_CONFIRM_HANDLER;
            }
        }

        return $status;
    }

    /**
     * Generic universal asynchronous registration controller
     * method expects that all necessary registration data(email, hashed password)
     * would be passed via $_POST.
     *
     * @return array Asynchronous response array
     */
    public function __async_register()
    {
        $result = array('status' => '0');

        // Check if email field is passed
        if (!isset($_POST[$this->dbEmailField])) {
            $result['email_error'] = "\n".'['.$this->dbEmailField.'] field is not passed';
        }

        // Check if hashed password field is passed
        if (!isset($_POST[$this->dbHashPasswordField])) {
            $result['email_error'] = "\n".'['.$this->dbHashPasswordField.'] field is not passed';
        }

        // If we have all data needed
        if (isset($_POST[$this->dbHashPasswordField]) && isset($_POST[$this->dbEmailField])) {
            if (($status = $this->register($_POST[$this->dbEmailField], $_POST[$this->dbHashPasswordField])) == EmailStatus::SUCCESS_EMAIL_REGISTERED) {
                $result['status'] = '1';
            }

            // Save email register status
            $result['email_status'] = EmailStatus::toString($status);
        }

        return $result;
    }

    /**
     * Generic universal asynchronous authorization controller
     *
     * @param string $hashEmail    User hashed email for authorization
     * @param string $hashPassword User hashed password for authorization
     *
     * @return array Asynchronous response array
     */
    public function __async_authorize($hashEmail = null, $hashPassword = null)
    {
        $result = array('status' => '0');

        // Get hashed email field by all possible methods
        if (!isset($hashEmail)) {
            if (isset($_POST) && isset($_POST['hashEmail'])) {
                $hashEmail = $_POST['hashEmail'];
            } else if (isset($_GET) && isset($_GET['hashEmail'])) {
                $hashEmail = $_GET['hashEmail'];
            } else {
                $result['email_error'] = "\n".'[hashEmail] field is not passed';
            }
        }

        // Get hashed password field by all possible methods
        if (!isset($hashPassword)) {
            if (isset($_POST) && isset($_POST['hashPassword'])) {
                $hashPassword = $_POST['hashPassword'];
            } else if (isset($_GET) && isset($_GET['hashPassword'])) {
                $hashPassword = $_GET['hashPassword'];
            } else {
                $result['email_error'] = "\n".'[hashPassword] field is not passed';
            }
        }

        // If we have authorization data
        if(isset($hashEmail) && isset($hashPassword)) {
            // Try to authorize
            if (($status = $this->authorize($hashEmail, $hashPassword, $user)) === EmailStatus::SUCCESS_EMAIL_AUTHORIZE) {
                $result['status'] = '1';
            }

            // Save email authorize status
            $result['email_status'] = EmailStatus::toString($status);
        }

        return $result;
    }

    /**
     * Generic universal synchronous authorization controller
     *
     * @param string $hashEmail    User hashed email for authorization
     * @param string $hashPassword User hashed password for authorization
     */
    public function __authorize($hashEmail = null, $hashPassword = null)
    {
        // Perform asynchronous authorization
        $asyncResult = $this->__async_authorize($hashEmail, $hashPassword);

        if ($asyncResult) {

        }
    }

    /**
     * Generic universal synchronous registration controller
     */
    public function __register()
    {
        // Perform asynchronous authorization
        $asyncResult = $this->__async_register();

        if ($asyncResult) {

        }
    }
}
 
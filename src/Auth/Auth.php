<?php
namespace Framework\Auth;

use Framework\Auth\AuthToken;
use Framework\Auth\Access;
use Framework\Auth\Credential;
use Framework\Auth\Token;
use Framework\Auth\Reset;
use Framework\Auth\Spam;
use Framework\Auth\Storage;
use Framework\Config\Config;
use Framework\NLS\NLS;
use Framework\File\Path;
use Framework\File\File;
use Framework\Log\ActionLog;
use Framework\Schema\Model;
use Framework\Utils\Arrays;
use Framework\Utils\DateTime;
use Framework\Utils\Status;
use Framework\Utils\Strings;

/**
 * The Auth
 */
class Auth {

    private static string $refreshToken = "";
    private static bool   $sendRefresh  = false;

    private static int    $accessLevel  = 0;
    private static ?Model $credential   = null;
    private static ?Model $admin        = null;
    private static int    $credentialID = 0;
    private static int    $adminID      = 0;
    private static int    $userID       = 0;
    private static int    $apiID        = 0;


    /**
     * Validates the Credential
     * @param string       $jwtToken
     * @param string       $refreshToken Optional.
     * @param string|null  $langcode     Optional.
     * @param integer|null $timezone     Optional.
     * @return boolean
     */
    public static function validateCredential(string $jwtToken, string $refreshToken = "", ?string $langcode = null, ?int $timezone = null): bool {
        Reset::deleteOld();
        Storage::deleteOld();
        AuthToken::deleteOld();

        // Validate the tokens
        if (!AuthToken::isValid($jwtToken, $refreshToken)) {
            return false;
        }

        // Retrieve the Token data
        [ $credentialID, $adminID ] = AuthToken::getCredentials($jwtToken, $refreshToken);
        $credential = Credential::getOne($credentialID, true);
        if ($credential->isEmpty() || $credential->isDeleted) {
            return false;
        }

        // Retrieve the Admin
        $admin = Model::createEmpty();
        if (!empty($adminID)) {
            $admin = Credential::getOne($adminID, true);
        }

        // Update the Refresh Token
        self::$refreshToken = $refreshToken;
        $newRefreshToken = AuthToken::updateRefreshToken($refreshToken);
        if (!empty($newRefreshToken) && $newRefreshToken !== $refreshToken) {
            self::$refreshToken = $newRefreshToken;
            self::$sendRefresh  = true;
        }

        // Set the new Language and Timezone if required
        self::setLanguageTimezone($credential, $admin, $langcode, $timezone);

        // Set the Credential
        self::setCredential($credential, $admin, $credential->currentUser);

        // Start or reuse a log session
        if (self::isLoggedAsUser()) {
            ActionLog::startSession(self::$adminID);
        } else {
            ActionLog::startSession(self::$credentialID);
        }
        return true;
    }

    /**
     * Sets the Language and Timezone if required
     * @param Model        $credential
     * @param Model        $admin
     * @param string|null  $langcode
     * @param integer|null $timezone
     * @return boolean
     */
    private static function setLanguageTimezone(Model $credential, Model $admin, ?string $langcode = null, ?int $timezone = null): bool {
        $model = $credential;
        if (!$admin->isEmpty()) {
            $model = $admin;
        }

        if (!empty($langcode) && !$model->has("language")) {
            Credential::setLanguage($model->id, $langcode);
            $model->language = $langcode;
        }
        if (!empty($timezone)) {
            Credential::setTimezone($model->id, $timezone);
            $model->timezone = $timezone;
        }
        return true;
    }

    /**
     * Validates and Sets the auth as API
     * @param string $token
     * @return boolean
     */
    public static function validateAPI(string $token): bool {
        if (Token::isValid($token)) {
            self::$apiID       = Token::getOne($token)->id;
            self::$accessLevel = Access::API();
            return true;
        }
        return false;
    }

    /**
     * Validates and Sets the auth as API Internal
     * @return boolean
     */
    public static function validateInternal(): bool {
        self::$apiID       = -1;
        self::$accessLevel = Access::API();
        return true;
    }



    /**
     * Checks the Spam Protection for the Login
     * @return boolean
     */
    public static function spamProtection(): bool {
        return Spam::protect();
    }

    /**
     * Logins the given Credential
     * @param Model $credential
     * @return boolean
     */
    public static function login(Model $credential): bool {
        $isNew = self::$credentialID !== $credential->id;
        self::setCredential($credential, null, $credential->currentUser);

        Credential::updateLoginTime($credential->id);
        ActionLog::startSession($credential->id, true);

        $path = Path::getTempPath($credential->id, false);
        File::emptyDir($path);
        Reset::delete($credential->id);

        if ($isNew) {
            self::$refreshToken = AuthToken::createRefreshToken($credential->id);
            self::$sendRefresh  = true;
        }
        return true;
    }

    /**
     * Logouts the Current Credential
     * @return boolean
     */
    public static function logout(): bool {
        AuthToken::deleteRefreshToken(self::$refreshToken);
        ActionLog::endSession();

        self::$refreshToken = "";
        self::$sendRefresh  = false;
        self::$accessLevel  = Access::General();
        self::$credential   = null;
        self::$credentialID = 0;
        self::$adminID      = 0;
        self::$userID       = 0;
        self::$apiID        = 0;
        return true;
    }

    /**
     * Returns true if the Credential can login
     * @param Model $credential
     * @return boolean
     */
    public static function canLogin(Model $credential): bool {
        return (
            !$credential->isEmpty() &&
            !$credential->isDeleted &&
            Status::isActive($credential->status)
        );
    }



    /**
     * Logins as the given Credential from an Admin account
     * @param integer $credentialID
     * @return boolean
     */
    public static function loginAs(int $credentialID): bool {
        $admin = self::$credential;
        $user  = Credential::getOne($credentialID, true);

        if (self::canLoginAs($admin, $user)) {
            self::setCredential($user, $admin, $user->currentUser);
            return true;
        }
        return false;
    }

    /**
     * Logouts as the current Credential and logins back as the Admin
     * @return integer
     */
    public static function logoutAs(): int {
        if (!self::isLoggedAsUser()) {
            return 0;
        }

        if (self::canLoginAs(self::$admin, self::$credential)) {
            self::setCredential(self::$admin);
            return self::$credential->id;
        }
        return 0;
    }

    /**
     * Returns the Credential to Login from the given Email
     * @param string $email
     * @return Model
     */
    public static function getLoginCredential(string $email): Model {
        $parts = Strings::split($email, "|");
        $user  = null;

        if (!empty($parts[0]) && !empty($parts[1])) {
            $admin = Credential::getByEmail($parts[0], true);
            $user  = Credential::getByEmail($parts[1], true);

            if (self::canLoginAs($admin, $user)) {
                $user->password = $admin->password;
                $user->salt     = $admin->salt;
                $user->adminID  = $admin->id;
            }
        } else {
            $user = Credential::getByEmail($email, true);
        }
        return $user;
    }

    /**
     * Returns true if the Admin can login as the User
     * @param Model $admin
     * @param Model $user
     * @return boolean
     */
    public static function canLoginAs(Model $admin, Model $user): bool {
        return (
            self::canLogin($admin) &&
            !$user->isEmpty() &&
            $admin->level >= $user->level &&
            Access::inAdmins($admin->level)
        );
    }



    /**
     * Sets the Credential
     * @param Model      $credential
     * @param Model|null $admin      Optional.
     * @param integer    $userID     Optional.
     * @return boolean
     */
    public static function setCredential(Model $credential, ?Model $admin = null, int $userID = 0): bool {
        self::$credential   = $credential;
        self::$credentialID = $credential->id;
        self::$accessLevel  = !empty($credential->userLevel) ? $credential->userLevel : $credential->level;
        self::$userID       = $userID;

        $language = $credential->language;
        $timezone = $credential->timezone;
        if (!empty($admin) && !$admin->isEmpty()) {
            self::$admin   = $admin;
            self::$adminID = $admin->id;
            $language = $admin->language;
            $timezone = $admin->timezone;
        } else {
            self::$admin   = null;
            self::$adminID = 0;
        }

        if (!empty($language)) {
            NLS::setLanguage($language);
        }

        $levels = Config::getArray("authTimezone");
        if (!empty($timezone) && (empty($levels) || Arrays::contains($levels, $credential->level))) {
            DateTime::setTimezone($timezone);
        }
        return true;
    }

    /**
     * Sets the Current User
     * @param integer $userID
     * @param integer $accessLevel
     * @return boolean
     */
    public static function setCurrentUser(int $userID, int $accessLevel): bool {
        self::$userID      = $userID;
        self::$accessLevel = $accessLevel;
        ActionLog::endSession();
        ActionLog::startSession(self::$credentialID, true);
        return true;
    }

    /**
     * Creates and returns the JWT token
     * @return string
     */
    public static function getToken(): string {
        if (!self::hasCredential()) {
            return "";
        }

        // The general data
        $data = [
            "accessLevel"      => self::$accessLevel,
            "credentialID"     => self::$credentialID,
            "adminID"          => self::$adminID,
            "userID"           => self::$userID,
            "email"            => self::$credential->email,
            "name"             => self::$credential->credentialName,
            "language"         => self::$credential->language,
            "avatar"           => self::$credential->avatar,
            "reqPassChange"    => self::$credential->reqPassChange,
            "askNotifications" => self::$credential->askNotifications,
            "loggedAsUser"     => self::isLoggedAsUser(),
        ];

        // Add fields from the Config
        $fields = Config::getArray("authFields");
        foreach ($fields as $field) {
            $data[$field] = self::$credential->get($field);
        }

        return AuthToken::createJWT($data);
    }

    /**
     * Returns the Refresh Token
     * @return string
     */
    public static function getRefreshToken(): string {
        if (!self::hasCredential() || !self::$sendRefresh) {
            return "";
        }
        return self::$refreshToken;
    }



    /**
     * Returns the Credential Model
     * @return Model
     */
    public static function getCredential(): Model {
        return self::$credential;
    }

    /**
     * Returns the Credential ID
     * @return integer
     */
    public static function getID(): int {
        return self::$credentialID;
    }

    /**
     * Returns the Admin ID
     * @return integer
     */
    public static function getAdminID(): int {
        return self::$adminID;
    }

    /**
     * Returns the Credential Current User
     * @return integer
     */
    public static function getUserID(): int {
        return self::$userID;
    }

    /**
     * Returns the Access Level
     * @return integer
     */
    public static function getAccessLevel(): int {
        return self::$accessLevel;
    }

    /**
     * Returns the path used to store the temp files
     * @return string
     */
    public static function getTempPath(): string {
        if (empty(self::$credentialID)) {
            return "";
        }
        return Path::getTempPath(self::$credentialID);
    }



    /**
     * Returns true if the User is Logged in
     * @return boolean
     */
    public static function isLoggedIn(): bool {
        return !empty(self::$credentialID) || !empty(self::$apiID);
    }

    /**
     * Returns true or false if the admin is logged as an user
     * @return boolean
     */
    public static function isLoggedAsUser(): bool {
        return !empty(self::$adminID);
    }

    /**
     * Returns true if there is a Credential
     * @return boolean
     */
    public static function hasCredential(): bool {
        return !empty(self::$credentialID);
    }

    /**
     * Returns true if there is an API
     * @return boolean
     */
    public static function hasAPI(): bool {
        return !empty(self::$apiID);
    }



    /**
     * Returns true if the password is correct for the current auth
     * @param string $password
     * @return boolean
     */
    public static function isPasswordCorrect(string $password): bool {
        return Credential::isPasswordCorrect(self::$credentialID, $password);
    }

    /**
     * Returns true if the user has that level
     * @param integer $requested
     * @return boolean
     */
    public static function grant(int $requested): bool {
        return Access::grant(self::$accessLevel, $requested);
    }

    /**
     * Returns true if the user has that level
     * @param integer $requested
     * @return boolean
     */
    public static function requiresLogin(int $requested): bool {
        return !Access::isGeneral($requested) && !self::isLoggedIn();
    }

    /**
     * Returns a value depending on the call name
     * @param string $function
     * @param array  $arguments
     * @return mixed
     */
    public static function __callStatic(string $function, array $arguments) {
        return Access::$function(self::$accessLevel);
    }
}
